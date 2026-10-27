# AGENTS.md

## How this app runs

`docker-compose.base44.yml` (not the repo's own compose, there is none) runs three dev
services from the bind-mounted source — nothing is baked into an image:

- `web` — Vite dev server, host port **3000** → container 5173. This is the preview entry.
- `api` — Express on internal port 8000, run with `node --watch` (restarts on edit).
- `db` — Postgres 16, data in the `pgdata` volume.

The browser only ever talks to port 3000: Vite proxies `/api` to `http://api:8000`
(`VITE_API_PROXY`), so the app is single-origin and needs no CORS config. Don't expose
8000 publicly.

`migrate` is a one-shot service that applies `api/src/schema.sql` and seeds the product
catalogue; the API waits for it to exit 0. Both it and the API run `npm install` into the
shared `api_node_modules` volume — sequentially, never at the same time.

## Known traps

- **Postgres BIGINT arrives as a string** through node-postgres (`price`, `amount`,
  `balance`). Comparing two of them in JS compares text, so `"250000" < "6300"` is `true`.
  That false "Saldo tidak cukup" bug was fixed twice: `web/src/api.js` coerces numeric
  fields with `withNumbers()`, and the balance check lives in SQL
  (`UPDATE ... WHERE balance >= $1`) in `api/src/server.js`. Keep both.
- Product filtering uses `activeOperator` (derived), not the `operator` state directly —
  switching category must not render an empty grid for one frame.
- Healthchecks intentionally probe existing read-only endpoints (`/api/account`, `/`).
  Don't add a health route to the app for Compose.
- `web/vite.config.js` appends `.${BASE44_SANDBOX_HOST_DOMAIN}` to `allowedHosts` only
  when `BASE44_PREVIEW_MODE === "1"`; unset keeps Vite's localhost-only default.

## Verifying

```bash
docker compose -f docker-compose.base44.yml ps          # all healthy, migrate exited 0
curl -s localhost:3000/api/account                      # {"balance":"250000",...}
curl -s localhost:3000/api/products | head -c 200       # 65 seeded products
docker compose -f docker-compose.base44.yml logs --tail 20 api
```

Seed data: one demo agent account (`Agen SE-SMART`, balance Rp250.000) and 65 products
(36 pulsa, 24 paket data, 5 token PLN). Top up and purchases both mutate that account, so
reset it with
`psql -U pulsa -d pulsa -c "DELETE FROM transactions; UPDATE accounts SET balance = 250000;"`.

## Not wired to real providers

Payment and the PPOB supply side are simulated: top up credits the wallet immediately and
a purchase always succeeds. Connecting a real payment gateway or an H2H pulsa provider
needs that provider's credentials, which are not in this repo.
