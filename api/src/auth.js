import crypto from 'node:crypto'
import { pool } from './db.js'

export const SESSION_COOKIE = 'sesp_session'
const SESSION_DAYS = 7

export const sessionCookieOptions = {
  httpOnly: true,
  sameSite: 'lax',
  // false so the cookie also works on plain http://localhost during development.
  // The preview proxy terminates TLS; set to true behind a dedicated HTTPS host.
  secure: process.env.COOKIE_SECURE === '1',
  path: '/',
  maxAge: SESSION_DAYS * 24 * 60 * 60 * 1000,
}

export async function issueSession(userId) {
  const token = crypto.randomBytes(32).toString('hex')
  await pool.query(
    `INSERT INTO sessions (token, user_id, expires_at)
     VALUES ($1, $2, now() + ($3 || ' days')::interval)`,
    [token, userId, SESSION_DAYS],
  )
  return token
}

export async function destroySession(token) {
  if (token) await pool.query('DELETE FROM sessions WHERE token = $1', [token])
}

export async function requireAuth(req, res, next) {
  try {
    const token = req.cookies?.[SESSION_COOKIE]
    if (!token) return res.status(401).json({ message: 'Sesi tidak ditemukan. Silakan masuk.' })

    const { rows } = await pool.query(
      `SELECT s.token, u.id, u.name, u.email, u.phone, u.role, u.balance
       FROM sessions s
       JOIN users u ON u.id = s.user_id
       WHERE s.token = $1 AND s.expires_at > now()`,
      [token],
    )
    if (!rows[0]) return res.status(401).json({ message: 'Sesi telah berakhir. Silakan masuk lagi.' })

    req.sessionToken = token
    req.user = rows[0]
    next()
  } catch (error) {
    next(error)
  }
}

export function requireAdmin(req, res, next) {
  if (!req.user) return res.status(401).json({ message: 'Silakan masuk terlebih dahulu.' })
  if (req.user.role !== 'admin') {
    return res.status(403).json({ message: 'Akses hanya untuk administrator.' })
  }
  next()
}

export async function writeAudit(userId, action, detail = {}) {
  await pool.query('INSERT INTO audit_logs (user_id, action, detail) VALUES ($1, $2, $3)', [
    userId,
    action,
    JSON.stringify(detail),
  ])
}

export const publicUser = (row) => ({
  id: row.id,
  name: row.name,
  email: row.email,
  phone: row.phone,
  role: row.role,
  balance: Number(row.balance),
})
