const BASE_URL = (import.meta.env.VITE_API_BASE_URL || '/api/v1').replace(/\/+$/, '')

export class ApiError extends Error {
  constructor(message, status, errors) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors || {}
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

async function request(path, { method = 'GET', body, headers } = {}) {
  let response
  try {
    response = await fetch(`${BASE_URL}${path}`, {
      method,
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        ...(body ? { 'Content-Type': 'application/json' } : {}),
        ...headers,
      },
      body: body ? JSON.stringify(body) : undefined,
    })
  } catch (error) {
    throw new ApiError('Tidak dapat terhubung ke server. Periksa koneksi Anda.', 0)
  }

  if (response.status === 401) {
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
    )
  }

  return unwrap(payload)
}

export const api = {
  login: (email, password) => request('/login', { method: 'POST', body: { email, password } }),
  register: (payload) => request('/register', { method: 'POST', body: payload }),
  me: () => request('/me'),
  logout: () => request('/logout', { method: 'POST' }),

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

  balanceEntries: () => request('/balance/entries'),
  deposits: () => request('/deposits'),
  createDeposit: (amount) => request('/deposits', { method: 'POST', body: { amount } }),
}
