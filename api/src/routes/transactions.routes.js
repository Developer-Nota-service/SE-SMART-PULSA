import express from 'express'
import { pool } from '../db.js'
import { requireAuth } from '../auth.js'
import { withUniqueOrderNumber } from '../lib/orders.js'

const router = express.Router()

const CATEGORIES = ['pulsa', 'data', 'pln_token', 'pln_bill', 'ewallet', 'game', 'voucher']
const STATUSES = ['success', 'pending', 'failed']

const serialize = (row) => ({
  id: row.id,
  orderNumber: row.order_number,
  customerNumber: row.customer_number,
  amount: Number(row.amount),
  status: row.status,
  createdAt: row.created_at,
  product: {
    id: row.product_id,
    name: row.product_name,
    category: row.category,
    operator: row.operator,
    isDemo: row.is_demo,
  },
})

router.get('/transactions', requireAuth, async (req, res, next) => {
  try {
    const status = String(req.query.status ?? '')
    const category = String(req.query.category ?? '')
    const from = String(req.query.from ?? '')
    const to = String(req.query.to ?? '')

    if (status && !STATUSES.includes(status)) {
      return res.status(422).json({ message: 'Status tidak dikenal.' })
    }
    if (category && !CATEGORIES.includes(category)) {
      return res.status(422).json({ message: 'Kategori tidak dikenal.' })
    }

    const { rows } = await pool.query(
      `SELECT t.id, t.order_number, t.customer_number, t.amount, t.status, t.created_at,
              p.id AS product_id, p.name AS product_name, p.category, p.operator, p.is_demo
       FROM transactions t
       JOIN products p ON p.id = t.product_id
       WHERE t.user_id = $1
         AND ($2 = '' OR t.status = $2)
         AND ($3 = '' OR p.category = $3)
         AND ($4 = '' OR t.created_at >= ($4)::date)
         AND ($5 = '' OR t.created_at < (($5)::date + interval '1 day'))
       ORDER BY t.created_at DESC, t.id DESC
       LIMIT 100`,
      [req.user.id, status, category, from, to],
    )
    res.json({ data: rows.map(serialize) })
  } catch (error) {
    next(error)
  }
})

router.get('/transactions/:id', requireAuth, async (req, res, next) => {
  try {
    const id = Number(req.params.id)
    if (!Number.isInteger(id)) return res.status(422).json({ message: 'Transaksi tidak valid.' })

    const { rows } = await pool.query(
      `SELECT t.id, t.order_number, t.customer_number, t.amount, t.status, t.created_at,
              p.id AS product_id, p.name AS product_name, p.category, p.operator, p.is_demo
       FROM transactions t
       JOIN products p ON p.id = t.product_id
       WHERE t.id = $1 AND t.user_id = $2`,
      [id, req.user.id],
    )
    if (!rows[0]) return res.status(404).json({ message: 'Transaksi tidak ditemukan.' })
    res.json({ data: serialize(rows[0]) })
  } catch (error) {
    next(error)
  }
})

// Price, balance and status are decided here. The client only names the product
// and the destination number.
router.post('/transactions', requireAuth, async (req, res, next) => {
  const client = await pool.connect()
  try {
    const productId = Number(req.body?.productId)
    const customerNumber = String(req.body?.customerNumber ?? '').trim()
    const idempotencyKey = req.get('Idempotency-Key') || null

    if (!Number.isInteger(productId)) {
      return res.status(422).json({ message: 'Produk tidak valid.' })
    }
    if (!/^\d{6,16}$/.test(customerNumber)) {
      return res.status(422).json({
        message: 'Nomor tujuan harus 6-16 digit angka.',
        errors: { customerNumber: ['Nomor tujuan harus 6-16 digit angka.'] },
      })
    }

    if (idempotencyKey) {
      const previous = await pool.query(
        `SELECT t.id, t.order_number, t.customer_number, t.amount, t.status, t.created_at,
                p.id AS product_id, p.name AS product_name, p.category, p.operator, p.is_demo
         FROM transactions t
         JOIN products p ON p.id = t.product_id
         WHERE t.user_id = $1 AND t.idempotency_key = $2`,
        [req.user.id, idempotencyKey],
      )
      if (previous.rows[0]) {
        return res.status(200).json({ data: { transaction: serialize(previous.rows[0]) } })
      }
    }

    await client.query('BEGIN')

    const user = (
      await client.query('SELECT id FROM users WHERE id = $1 FOR UPDATE', [req.user.id])
    ).rows[0]
    if (!user) throw Object.assign(new Error('Pengguna tidak ditemukan.'), { status: 404 })

    const product = (
      await client.query(
        'SELECT id, name, price, category, operator, is_demo FROM products WHERE id = $1 AND active = TRUE',
        [productId],
      )
    ).rows[0]
    if (!product) throw Object.assign(new Error('Produk tidak tersedia.'), { status: 404 })

    // Charge atomically; the balance check runs in SQL so BIGINT text never
    // reaches a JavaScript comparison.
    const charged = await client.query(
      `UPDATE users SET balance = balance - $1
       WHERE id = $2 AND balance >= $1
       RETURNING balance`,
      [product.price, req.user.id],
    )
    if (!charged.rows[0]) {
      throw Object.assign(new Error('Saldo tidak cukup. Silakan isi saldo terlebih dahulu.'), {
        status: 402,
      })
    }

    const transaction = await withUniqueOrderNumber('TRX', async (orderNumber) => {
      const { rows } = await client.query(
        `INSERT INTO transactions
           (user_id, product_id, order_number, customer_number, amount, status, idempotency_key)
         VALUES ($1, $2, $3, $4, $5, 'success', $6)
         RETURNING id, order_number, customer_number, amount, status, created_at`,
        [req.user.id, product.id, orderNumber, customerNumber, product.price, idempotencyKey],
      )
      return rows[0]
    })

    await client.query(
      `INSERT INTO balance_entries
         (user_id, direction, amount, balance_after, reference, description)
       VALUES ($1, 'debit', $2, $3, $4, $5)`,
      [req.user.id, product.price, charged.rows[0].balance, transaction.order_number, product.name],
    )

    await client.query('COMMIT')

    res.status(201).json({
      data: {
        transaction: {
          ...serialize({
            ...transaction,
            product_id: product.id,
            product_name: product.name,
            category: null,
            operator: null,
            is_demo: true,
          }),
        },
        balance: Number(charged.rows[0].balance),
      },
    })
  } catch (error) {
    await client.query('ROLLBACK').catch(() => {})
    if (error.code === '23505' && /idempotency/.test(String(error.constraint || error.detail))) {
      return res.status(409).json({ message: 'Transaksi ini sudah diproses.' })
    }
    next(error)
  } finally {
    client.release()
  }
})

export default router
