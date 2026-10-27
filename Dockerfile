# Satu image, satu domain: Node menyajikan API di /api/v1 dan React di /.
FROM node:22-slim AS web
WORKDIR /app/web
COPY web/package.json web/package-lock.json ./
RUN npm ci
COPY web/ ./
RUN npm run build

FROM node:22-slim
WORKDIR /app
ENV NODE_ENV=production
COPY api/package.json api/package-lock.json ./api/
RUN npm --prefix api ci --omit=dev
COPY api/src ./api/src
COPY --from=web /app/web/dist ./web/dist
EXPOSE 8000
CMD ["sh", "-c", "node api/src/setup-db.js && exec node api/src/server.js"]
