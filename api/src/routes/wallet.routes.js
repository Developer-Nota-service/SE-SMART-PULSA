import express from 'express'
import { pool } from '../db.js'
import { requireAuth } from '../auth.js'
import { withUniqueOrderNumber } from '../lib/orders.js'

const router = express.Router()

const MIN_DEPOSIT = 10000
const MAX_DEPOSIT = 2000000

const serializeDeposit = (row) => ({
  id: row.id,
  orderNumber: row.order_number,
  amount: Number(row.amount),
  status: row.status,
  createdAt: row.created_at,
  paidAt: row.paid_at,
})

router.get('/balance/entries', requireAuth, async (req, res, next) => {
  try {
    const { rows } = await pool.query(
      `SELECT id, direction, amount, balance_after, reference, description, created_at
       FROM balance_entries
       WHERE user_id = $1
       ORDER BY created_at DESC, id DESC
       LIMIT 100`,
      [req.user.id],
    )
    res.json({
      data: rows.map((row) => ({
        id: row.id,
        direction: row.direction,
        amount: Number(row.amount),
        balanceAfter: Number(row.balance_after),
        reference: row.reference,
        description: row.description,
        createdAt: row.created_at,
      })),
    })
  } catch (error) {
    next(error)
  }
})

router.get('/deposits', requireAuth, async (req, res, next) => {
  try {
    const { rows } = await pool.query(
      `SELECT id, order_number, amount, status, created_at, paid_at
       FROM deposits
       WHERE user_id = $1
       ORDER BY created_at DESC, id DESC
       LIMIT 50`,
      [req.user.id],
    )
    res.json({ data: rows.map(serializeDeposit) })
  } catch (error) {
    next(error)
  }
})

// Creates a pending deposit only. The balance is credited exclusively by the
// verified Midtrans notification handled on the Laravel backend, never here and
// never from the browser.
router.post('/deposits', requireAuth, async (req, res, next) => {
  try {
    const amount = Number(req.body?.amount)
    if (!Number.isInteger(amount) || amount < MIN_DEPOSIT || amount > MAX_DEPOSIT) {
      return res.status(422).json({
        message: `Nominal deposit harus antara Rp${MIN_DEPOSIT.toLocaleString('id-ID')} dan Rp${MAX_DEPOSIT.toLocaleString('id-ID')}.`,
        errors: { amount: ['Nominal deposit di luar batas yang diizinkan.'] },
      })
    }

    const deposit = await withUniqueOrderNumber('DEP', async (orderNumber) => {
      const { rows } = await pool.query(
        `INSERT INTO deposits (user_id, order_number, amount, status)
         VALUES ($1, $2, $3, 'pending')
         RETURNING id, order_number, amount, status, created_at, paid_at`,
        [req.user.id, orderNumber, amount],
      )
      return rows[0]
    })

    res.status(201).json({ data: serializeDeposit(deposit) })
  } catch (error) {
    next(error)
  }
})

export default router
