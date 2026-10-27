import express from 'express'
import { pool } from './db.js'

const app = express()
app.use(express.json())

const DEMO_ACCOUNT = 'SELECT id, name, phone, balance FROM accounts ORDER BY id LIMIT 1'

const handle = (fn) => (req, res, next) => fn(req, res, next).catch(next)

const fail = (status, message) => Object.assign(new Error(message), { status })

app.get('/api/account', handle(async (req, res) => {
  const { rows } = await pool.query(DEMO_ACCOUNT)
  if (!rows[0]) throw fail(404, 'Akun tidak ditemukan')
  res.json(rows[0])
}))

app.get('/api/products', handle(async (req, res) => {
  const { rows } = await pool.query(
    `SELECT id, sku, category, operator, name, price
     FROM products
     WHERE active
     ORDER BY category, operator, sort_order, price`,
  )
  res.json(rows)
}))

app.get('/api/transactions', handle(async (req, res) => {
  const limit = Math.min(Number(req.query.limit) || 20, 50)
  const { rows } = await pool.query(
    `SELECT t.id, t.customer_number, t.amount, t.status, t.reference, t.created_at,
            p.name AS product_name, p.category, p.operator
     FROM transactions t
     JOIN products p ON p.id = t.product_id
     ORDER BY t.created_at DESC, t.id DESC
     LIMIT $1`,
    [limit],
  )
  res.json(rows)
}))

app.post('/api/topup', handle(async (req, res) => {
  const amount = Math.trunc(Number(req.body?.amount))
  if (!Number.isFinite(amount) || amount < 10000 || amount > 5000000) {
    throw fail(400, 'Nominal top up harus antara Rp 10.000 dan Rp 5.000.000')
  }
  const { rows } = await pool.query(
    `UPDATE accounts SET balance = balance + $1
     WHERE id = (SELECT id FROM accounts ORDER BY id LIMIT 1)
     RETURNING id, name, phone, balance`,
    [amount],
  )
  res.json(rows[0])
}))

app.post('/api/transactions', handle(async (req, res) => {
  const productId = Number(req.body?.productId)
  const customerNumber = String(req.body?.customerNumber ?? '').trim()

  if (!Number.isInteger(productId)) throw fail(400, 'Produk tidak valid')
  if (!/^\d{6,16}$/.test(customerNumber)) {
    throw fail(400, 'Nomor tujuan harus 6-16 digit angka')
  }

  const client = await pool.connect()
  try {
    await client.query('BEGIN')

    const account = (await client.query(
      'SELECT id, balance FROM accounts ORDER BY id LIMIT 1 FOR UPDATE',
    )).rows[0]
    if (!account) throw fail(404, 'Akun tidak ditemukan')

    const product = (await client.query(
      'SELECT id, name, price FROM products WHERE id = $1 AND active',
      [productId],
    )).rows[0]
    if (!product) throw fail(404, 'Produk tidak tersedia')

    const reference = `TRX${Date.now().toString(36).toUpperCase()}${Math.floor(Math.random() * 900 + 100)}`

    // Charge atomically: BIGINT comes back from Postgres as text, so the
    // balance check lives in SQL instead of a JS comparison.
    const charged = await client.query(
      `UPDATE accounts SET balance = balance - $1
       WHERE id = $2 AND balance >= $1
       RETURNING balance`,
      [product.price, account.id],
    )
    if (!charged.rows[0]) {
      throw fail(402, 'Saldo tidak cukup. Silakan top up dulu.')
    }

    const transaction = (await client.query(
      `INSERT INTO transactions (account_id, product_id, customer_number, amount, status, reference)
       VALUES ($1, $2, $3, $4, 'success', $5)
       RETURNING id, customer_number, amount, status, reference, created_at`,
      [account.id, product.id, customerNumber, product.price, reference],
    )).rows[0]

    const balance = charged.rows[0].balance

    await client.query('COMMIT')

    res.status(201).json({
      transaction: { ...transaction, product_name: product.name },
      balance,
    })
  } catch (error) {
    await client.query('ROLLBACK').catch(() => {})
    throw error
  } finally {
    client.release()
  }
}))

app.use((error, req, res, next) => {
  const status = error.status || 500
  if (status >= 500) console.error(error)
  res.status(status).json({ error: error.message || 'Terjadi kesalahan pada server' })
})

const port = Number(process.env.PORT) || 8000
app.listen(port, '0.0.0.0', () => {
  console.log(`SE-SMART PULSA api listening on ${port}`)
})
