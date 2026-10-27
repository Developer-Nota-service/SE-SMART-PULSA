import { existsSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import express from 'express'
import cookieParser from 'cookie-parser'
import authRoutes from './routes/auth.routes.js'
import catalogRoutes from './routes/catalog.routes.js'
import walletRoutes from './routes/wallet.routes.js'
import transactionRoutes from './routes/transactions.routes.js'

const app = express()
app.disable('x-powered-by')
app.use(express.json({ limit: '100kb' }))
app.use(cookieParser())

app.use('/api/v1', authRoutes)
app.use('/api/v1', catalogRoutes)
app.use('/api/v1', walletRoutes)
app.use('/api/v1', transactionRoutes)

// Satu domain di produksi: Node menyajikan hasil build React dari web/dist
// (dibuat saat build deploy). Di dev folder ini tidak dipakai — Vite yang melayani.
const webDist = fileURLToPath(new URL('../../web/dist', import.meta.url))

if (existsSync(webDist)) {
  app.use(express.static(webDist, { index: false }))
  app.get('*', (req, res) => {
    if (req.path.startsWith('/api/')) {
      return res.status(404).json({ message: 'Endpoint tidak ditemukan.' })
    }
    return res.sendFile(path.join(webDist, 'index.html'))
  })
}

app.use((req, res) => {
  res.status(404).json({ message: 'Endpoint tidak ditemukan.' })
})

app.use((error, req, res, next) => {
  const status = error.status || 500
  if (status >= 500) console.error(error)
  res.status(status).json({ message: error.message || 'Terjadi kesalahan pada server.' })
})

const port = Number(process.env.PORT) || 8000
app.listen(port, '0.0.0.0', () => {
  console.log(`SE SMART PULSA demo api listening on ${port}`)
})
