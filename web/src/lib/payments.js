/**
 * Cermin App\Support\PaymentFee untuk tampilan saja.
 *
 * Midtrans memotong biayanya dari dana yang disettlekan, jadi tagihan
 * di-*gross-up* supaya dana bersih = nilai pesanan:
 *   total = ceil((nilai + flat) / (1 - persen/100)) · biaya = total - nilai
 *
 * Nominal final tetap dihitung server saat pesanan dibuat.
 */
export function feeFor(amount, method) {
  const value = Number(amount) || 0

  if (!method || value <= 0) return 0

  const percent = Math.min(99, Math.max(0, Number(method.percent) || 0))
  const flat = Math.max(0, Number(method.flat) || 0)
  const total = Math.ceil((value + flat) / (1 - percent / 100))

  return Math.max(0, total - value)
}
