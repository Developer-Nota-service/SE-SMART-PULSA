import crypto from 'node:crypto'

export function makeOrderNumber(prefix) {
  const now = new Date()
  const stamp = [
    now.getFullYear(),
    String(now.getMonth() + 1).padStart(2, '0'),
    String(now.getDate()).padStart(2, '0'),
  ].join('')
  const suffix = String(crypto.randomInt(0, 10000)).padStart(4, '0')
  return `${prefix}-${stamp}-${suffix}`
}

// Runs `insert(orderNumber)` until the generated order number is unique.
export async function withUniqueOrderNumber(prefix, insert) {
  for (let attempt = 0; attempt < 5; attempt += 1) {
    try {
      return await insert(makeOrderNumber(prefix))
    } catch (error) {
      const duplicate = error.code === '23505' && /order_number/.test(String(error.constraint || error.detail))
      if (!duplicate) throw error
    }
  }
  throw new Error('Gagal membuat nomor pesanan unik. Coba lagi.')
}
