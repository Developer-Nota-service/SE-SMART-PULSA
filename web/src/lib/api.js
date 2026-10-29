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

async function request(path, { method = 'GET', body, form, headers } = {}) {
  let response
  try {
    response = await fetch(`${BASE_URL}${path}`, {
      method,
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        // FormData mengatur Content-Type (boundary) sendiri.
        ...(body ? { 'Content-Type': 'application/json' } : {}),
        ...headers,
      },
      body: form || (body ? JSON.stringify(body) : undefined),
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
  // Lupa sandi: kode WhatsApp → email akun + token reset → sandi baru.
  requestPasswordReset: (phone) =>
    request('/password/forgot', { method: 'POST', body: { phone } }),
  verifyPasswordReset: (phone, code) =>
    request('/password/verify', { method: 'POST', body: { phone, code } }),
  resetPassword: async (resetToken, password) => {
    const data = normalizeUser(
      await request('/password/reset', { method: 'POST', body: { resetToken, password } }),
    )
    if (data?.token) setToken(data.token)
    return data
  },
  me: async () => normalizeUser(await request('/me')),
  logout: async () => {
    try {
      return await request('/logout', { method: 'POST' })
    } finally {
      setToken(null)
    }
  },

  // Konten beranda: banner, promo aktif, peringkat pembelian otomatis.
  dashboard: () => request('/dashboard'),

  products: (params) => request(`/products${query(params)}`),
  product: (id) => request(`/products/${id}`),

  // Pemberitahuan akun: transaksi gagal + status refund, status penarikan.
  notifications: () => request('/notifications'),
  markNotificationsRead: () => request('/notifications/read', { method: 'POST', body: {} }),

  transactions: (params) => request(`/transactions${query(params)}`),
  transaction: (id) => request(`/transactions/${id}`),
  // Harga jual di struk (khusus reseller); null = pakai total bayar.
  updateSellPrice: (id, sellPrice) =>
    request(`/transactions/${id}/sell-price`, { method: 'PATCH', body: { sellPrice } }),
  purchase: (payload, idempotencyKey) =>
    request('/transactions', {
      method: 'POST',
      body: payload,
      headers: idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : {},
    }),
  directPurchase: (payload) => request('/transactions/direct', { method: 'POST', body: payload }),
  // Cek kode promo di halaman beli; server mengembalikan potongan & total akhir.
  validatePromo: (payload) => request('/promos/validate', { method: 'POST', body: payload }),

  updateName: (name) => request('/profile', { method: 'PATCH', body: { name } }),
  requestContactChange: (payload) => request('/profile/contact', { method: 'POST', body: payload }),
  verifyContactChange: (channel, code) =>
    request('/profile/contact/verify', { method: 'POST', body: { channel, code } }),
  updateAccountType: (accountType) =>
    request('/profile/account-type', { method: 'PATCH', body: { accountType } }),
  uploadAvatar: (file) => {
    const form = new FormData()
    form.append('avatar', file)
    return request('/profile/avatar', { method: 'POST', form })
  },

  balanceEntries: () => request('/balance/entries'),
  deposits: () => request('/deposits'),
  createDeposit: (amount) => request('/deposits', { method: 'POST', body: { amount } }),

  // Penarikan saldo: daftar bank/e-wallet dan pengajuan.
  withdrawalChannels: () => request('/withdrawals/channels'),
  withdrawals: () => request('/withdrawals'),
  createWithdrawal: (payload) => request('/withdrawals', { method: 'POST', body: payload }),

  // Konsol admin (hanya akun ber-role admin).
  adminOverview: () => request('/admin/overview'),
  adminWithdrawals: (params) => request(`/admin/withdrawals${query(params)}`),
  adminWithdrawalAction: (id, action, note) =>
    request(`/admin/withdrawals/${id}`, { method: 'POST', body: { action, note } }),
  adminDeposits: (params) => request(`/admin/deposits${query(params)}`),
  adminDepositSync: (id) => request(`/admin/deposits/${id}/sync`, { method: 'POST', body: {} }),
  adminDepositCancel: (id) => request(`/admin/deposits/${id}/cancel`, { method: 'POST', body: {} }),
  adminTransactions: (params) => request(`/admin/transactions${query(params)}`),
  adminMarkRefundPaid: (id, note) =>
    request(`/admin/transactions/${id}/refund`, { method: 'POST', body: { note } }),
  adminUsers: (params) => request(`/admin/users${query(params)}`),
  adminProducts: (params) => request(`/admin/products${query(params)}`),
  adminUpdateProduct: (id, body) =>
    request(`/admin/products/${id}`, { method: 'PATCH', body }),
  adminBulkPrice: (body) =>
    request('/admin/products/bulk-price', { method: 'POST', body }),
  // Tarik harga modal terbaru dari Digiflazz; harga jual admin tidak ditimpa.
  adminSyncDigiflazz: () =>
    request('/admin/products/sync-digiflazz', { method: 'POST', body: {} }),
  adminCheckTransaction: (id) =>
    request(`/admin/transactions/${id}/check`, { method: 'POST', body: {} }),

  // Pengaturan beranda pengguna: banner, promo, peringkat pembelian.
  adminDashboard: () => request('/admin/dashboard'),
  adminCreateBanner: (form) => request('/admin/banners', { method: 'POST', form }),
  // Simpan banner lengkap dengan gambar (multipart) — PATCH tidak membaca file.
  adminSaveBanner: (id, form) => request(`/admin/banners/${id}`, { method: 'POST', form }),
  adminUpdateBanner: (id, body) => request(`/admin/banners/${id}`, { method: 'PATCH', body }),
  adminDeleteBanner: (id) => request(`/admin/banners/${id}`, { method: 'DELETE' }),
  adminCreatePromo: (body) => request('/admin/promos', { method: 'POST', body }),
  adminUpdatePromo: (id, body) => request(`/admin/promos/${id}`, { method: 'PATCH', body }),
  adminDeletePromo: (id) => request(`/admin/promos/${id}`, { method: 'DELETE' }),
  adminUpdateDashboardSettings: (body) =>
    request('/admin/dashboard-settings', { method: 'PATCH', body }),

  // Sinkronkan pesanan pending dengan status resmi Midtrans (cadangan webhook).
  syncPayments: (orderNumber) =>
    request('/midtrans/sync', {
      method: 'POST',
      body: orderNumber ? { orderNumber } : {},
    }),
}
