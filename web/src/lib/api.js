const BASE_URL = (import.meta.env.VITE_API_BASE_URL || '/api/v1').replace(/\/+$/, '')

// The Laravel backend authenticates with a Bearer token, the local demo API with
// an httpOnly cookie. Both flows are supported: the token is only kept when the
// backend actually returns one.
const TOKEN_KEY = 'sesp_token'

function readToken() {
  try {
    return sessionStorage.getItem(TOKEN_KEY)
  } catch (error) {
    return null
  }
}

let token = readToken()

function setToken(value) {
  token = value || null
  try {
    if (token) sessionStorage.setItem(TOKEN_KEY, token)
    else sessionStorage.removeItem(TOKEN_KEY)
  } catch (error) {
    // Storage can be unavailable (private mode); the in-memory token still works.
  }
}

export class ApiError extends Error {
  constructor(message, status, errors, data) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors || {}
    this.data = data || null
  }
}

function query(params = {}) {
  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') search.set(key, value)
  }
  const text = search.toString()
  return text ? `?${text}` : ''
}

// Accepts both a bare payload and the { data: ... } envelope so the frontend can
// point at the Laravel backend later without rewriting every call site.
function unwrap(payload) {
  if (payload && typeof payload === 'object' && 'data' in payload) return payload.data
  return payload
}

// Laravel keeps the balance in `wallet.balance_idr`; the demo API puts it on the
// user object. Views always read `user.balance`.
function normalizeUser(payload) {
  if (!payload?.user) return payload
  const balance = payload.wallet?.balance_idr ?? payload.user.balance
  return { ...payload, user: { ...payload.user, balance: balance ?? 0 } }
}

async function request(path, { method = 'GET', body, headers } = {}) {
  let response
  try {
    response = await fetch(`${BASE_URL}${path}`, {
      method,
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        ...(body ? { 'Content-Type': 'application/json' } : {}),
        ...headers,
      },
      body: body ? JSON.stringify(body) : undefined,
    })
  } catch (error) {
    throw new ApiError('Tidak dapat terhubung ke server. Periksa koneksi Anda.', 0)
  }

  if (response.status === 401) {
    setToken(null)
    window.dispatchEvent(new Event('sesp:unauthorized'))
  }

  const text = await response.text()
  let payload = null
  if (text) {
    try {
      payload = JSON.parse(text)
    } catch (error) {
      payload = null
    }
  }

  if (!response.ok) {
    throw new ApiError(
      payload?.message || 'Terjadi kesalahan pada server.',
      response.status,
      payload?.errors,
      payload?.data,
    )
  }

  return unwrap(payload)
}

export const api = {
  login: async (email, password) => {
    const data = normalizeUser(await request('/login', { method: 'POST', body: { email, password } }))
    if (data?.token) setToken(data.token)
    return data
  },
  // Registrasi tidak langsung membuat sesi: akun harus diverifikasi lewat OTP
  // WhatsApp dulu, jadi responsnya hanya berisi data verifikasi.
  register: (payload) =>
    request('/register', {
      method: 'POST',
      // Laravel requires password_confirmation; the form only asks for one password.
      body: { ...payload, password_confirmation: payload.password },
    }),
  verifyOtp: async (email, code) => {
    const data = normalizeUser(
      await request('/verify-otp', { method: 'POST', body: { email, code } }),
    )
    if (data?.token) setToken(data.token)
    return data
  },
  resendOtp: (email) => request('/resend-otp', { method: 'POST', body: { email } }),
  me: async () => normalizeUser(await request('/me')),
  logout: async () => {
    try {
      return await request('/logout', { method: 'POST' })
    } finally {
      setToken(null)
    }
  },

  products: (params) => request(`/products${query(params)}`),
  product: (id) => request(`/products/${id}`),

  transactions: (params) => request(`/transactions${query(params)}`),
  transaction: (id) => request(`/transactions/${id}`),
  purchase: (payload, idempotencyKey) =>
    request('/transactions', {
      method: 'POST',
      body: payload,
      headers: idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : {},
    }),
  directPurchase: (payload) => request('/transactions/direct', { method: 'POST', body: payload }),

  balanceEntries: () => request('/balance/entries'),
  deposits: () => request('/deposits'),
  createDeposit: (amount) => request('/deposits', { method: 'POST', body: { amount } }),

  // Sinkronkan pesanan pending dengan status resmi Midtrans (cadangan webhook).
  syncPayments: (orderNumber) =>
    request('/midtrans/sync', {
      method: 'POST',
      body: orderNumber ? { orderNumber } : {},
    }),
}
