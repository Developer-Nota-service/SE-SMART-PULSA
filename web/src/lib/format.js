export const rupiah = (value) =>
  `Rp${Number(value || 0).toLocaleString('id-ID', { maximumFractionDigits: 0 })}`

export const dateTime = (value) => {
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '—'
  return date.toLocaleString('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

export const dateOnly = (value) => {
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '—'
  return date.toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' })
}

export const initials = (name = '') =>
  name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('')

export const CATEGORIES = [
  { key: 'pulsa', label: 'Pulsa', icon: 'signal' },
  { key: 'data', label: 'Paket Data', icon: 'wifi' },
  { key: 'pln_token', label: 'Token Listrik', icon: 'bolt' },
  { key: 'pln_bill', label: 'Tagihan Listrik', icon: 'bill' },
  { key: 'ewallet', label: 'E-Wallet', icon: 'wallet' },
  { key: 'game', label: 'Top Up Game', icon: 'game' },
  { key: 'voucher', label: 'Voucher Digital', icon: 'ticket' },
]

export const categoryLabel = (key) =>
  CATEGORIES.find((category) => category.key === key)?.label || 'Lainnya'

const STATUS = {
  success: { label: 'Berhasil', tone: 'success' },
  pending: { label: 'Menunggu', tone: 'pending' },
  failed: { label: 'Gagal', tone: 'danger' },
  paid: { label: 'Berhasil', tone: 'success' },
  expired: { label: 'Kedaluwarsa', tone: 'muted' },
  canceled: { label: 'Dibatalkan', tone: 'muted' },
}

export const statusMeta = (status) =>
  STATUS[status] || { label: status || '—', tone: 'muted' }

export const targetLabel = (category) => {
  switch (category) {
    case 'pln_token':
    case 'pln_bill':
      return 'ID pelanggan / nomor meter'
    case 'game':
      return 'ID game / User ID'
    case 'voucher':
      return 'Email penerima voucher'
    case 'ewallet':
      return 'Nomor HP akun e-wallet'
    default:
      return 'Nomor HP tujuan'
  }
}
