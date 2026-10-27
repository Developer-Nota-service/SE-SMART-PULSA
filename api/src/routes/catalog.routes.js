import express from 'express'
import { pool } from '../db.js'
import { requireAuth } from '../auth.js'

const router = express.Router()

const CATEGORIES = ['pulsa', 'data', 'pln_token', 'pln_bill', 'ewallet', 'game', 'voucher']

const serializeProduct = (row) => ({
  id: row.id,
  sku: row.sku,
  category: row.category,
  operator: row.operator,
  name: row.name,
  price: Number(row.price),
  isDemo: row.is_demo,
})

router.get('/products', requireAuth, async (req, res, next) => {
  try {
    const category = String(req.query.category ?? '')
    if (category && !CATEGORIES.includes(category)) {
      return res.status(422).json({
        message: 'Kategori tidak dikenal.',
        errors: { category: ['Kategori tidak dikenal.'] },
      })
    }

    const { rows } = await pool.query(
      `SELECT id, sku, category, operator, name, price, is_demo
       FROM products
       WHERE active = TRUE AND ($1 = '' OR category = $1)
       ORDER BY category, operator, sort_order, price`,
      [category],
    )
    res.json({ data: rows.map(serializeProduct) })
  } catch (error) {
    next(error)
  }
})

router.get('/products/:id', requireAuth, async (req, res, next) => {
  try {
    const id = Number(req.params.id)
    if (!Number.isInteger(id)) {
      return res.status(422).json({ message: 'Produk tidak valid.' })
    }
    const { rows } = await pool.query(
      `SELECT id, sku, category, operator, name, price, is_demo
       FROM products WHERE id = $1 AND active = TRUE`,
      [id],
    )
    if (!rows[0]) return res.status(404).json({ message: 'Produk tidak ditemukan.' })
    res.json({ data: serializeProduct(rows[0]) })
  } catch (error) {
    next(error)
  }
})

export default router
