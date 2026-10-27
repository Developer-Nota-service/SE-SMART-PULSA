import express from 'express'
import bcrypt from 'bcryptjs'
import { pool } from '../db.js'
import {
  SESSION_COOKIE,
  destroySession,
  issueSession,
  publicUser,
  requireAuth,
  sessionCookieOptions,
  writeAudit,
} from '../auth.js'

const router = express.Router()

const MAX_FAILED_ATTEMPTS = 5
const ATTEMPT_WINDOW_MINUTES = 15

router.post('/register', async (req, res, next) => {
  try {
    const name = String(req.body?.name ?? '').trim()
    const email = String(req.body?.email ?? '').trim().toLowerCase()
    const phone = String(req.body?.phone ?? '').replace(/\D/g, '')
    const password = String(req.body?.password ?? '')

    const errors = {}
    if (name.length < 2) errors.name = ['Nama minimal 2 karakter.']
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errors.email = ['Format email tidak valid.']
    if (!/^0\d{8,14}$/.test(phone)) errors.phone = ['Nomor HP diawali 0 dan 9-15 digit.']
    if (password.length < 8) errors.password = ['Password minimal 8 karakter.']
    if (Object.keys(errors).length) {
      return res.status(422).json({ message: 'Data pendaftaran belum lengkap.', errors })
    }

    const existing = await pool.query('SELECT id FROM users WHERE email = $1', [email])
    if (existing.rowCount) {
      return res.status(422).json({
        message: 'Email sudah terdaftar.',
        errors: { email: ['Email sudah terdaftar.'] },
      })
    }

    const passwordHash = await bcrypt.hash(password, 10)
    const { rows } = await pool.query(
      `INSERT INTO users (name, email, phone, password_hash, role, balance)
       VALUES ($1, $2, $3, $4, 'user', 0)
       RETURNING id, name, email, phone, role, balance`,
      [name, email, phone, passwordHash],
    )

    const token = await issueSession(rows[0].id)
    res.cookie(SESSION_COOKIE, token, sessionCookieOptions)
    await writeAudit(rows[0].id, 'auth.register')
    res.status(201).json({ data: { user: publicUser(rows[0]) } })
  } catch (error) {
    next(error)
  }
})

router.post('/login', async (req, res, next) => {
  try {
    const email = String(req.body?.email ?? '').trim().toLowerCase()
    const password = String(req.body?.password ?? '')

    if (!email || !password) {
      return res.status(422).json({
        message: 'Email dan password wajib diisi.',
        errors: {
          ...(email ? {} : { email: ['Email wajib diisi.'] }),
          ...(password ? {} : { password: ['Password wajib diisi.'] }),
        },
      })
    }

    const attempts = await pool.query(
      `SELECT count(*)::int AS total FROM login_attempts
       WHERE identifier = $1 AND succeeded = false
         AND attempted_at > now() - ($2 || ' minutes')::interval`,
      [email, ATTEMPT_WINDOW_MINUTES],
    )
    if (attempts.rows[0].total >= MAX_FAILED_ATTEMPTS) {
      return res.status(429).json({
        message: `Terlalu banyak percobaan masuk. Coba lagi dalam ${ATTEMPT_WINDOW_MINUTES} menit.`,
      })
    }

    const found = await pool.query(
      'SELECT id, name, email, phone, role, balance, password_hash FROM users WHERE email = $1',
      [email],
    )
    const user = found.rows[0]
    const valid = user ? await bcrypt.compare(password, user.password_hash) : false

    await pool.query('INSERT INTO login_attempts (identifier, succeeded) VALUES ($1, $2)', [
      email,
      valid,
    ])

    if (!valid) {
      return res.status(401).json({
        message: 'Email atau password salah.',
        errors: { email: ['Email atau password salah.'] },
      })
    }

    const token = await issueSession(user.id)
    res.cookie(SESSION_COOKIE, token, sessionCookieOptions)
    await writeAudit(user.id, 'auth.login')
    res.json({ data: { user: publicUser(user) } })
  } catch (error) {
    next(error)
  }
})

router.get('/me', requireAuth, async (req, res, next) => {
  try {
    const lastLogin = await pool.query(
      `SELECT created_at FROM audit_logs
       WHERE user_id = $1 AND action = 'auth.login'
       ORDER BY created_at DESC LIMIT 1`,
      [req.user.id],
    )
    res.json({
      data: {
        user: publicUser(req.user),
        lastLoginAt: lastLogin.rows[0]?.created_at ?? null,
      },
    })
  } catch (error) {
    next(error)
  }
})

router.post('/logout', async (req, res, next) => {
  try {
    await destroySession(req.cookies?.[SESSION_COOKIE])
    res.clearCookie(SESSION_COOKIE, { ...sessionCookieOptions, maxAge: undefined })
    res.json({ data: { ok: true } })
  } catch (error) {
    next(error)
  }
})

export default router
