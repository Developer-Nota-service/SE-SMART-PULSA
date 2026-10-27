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
