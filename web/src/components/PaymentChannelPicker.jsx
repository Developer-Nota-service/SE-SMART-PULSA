import { rupiah } from '../lib/format.js'
import { feeFor } from '../lib/payments.js'

/**
 * Pemilih metode pembayaran Midtrans untuk deposit reseller dan pembelian
 * langsung akun pribadi. Biaya layanan tiap metode berbeda, jadi metode harus
 * dipilih sebelum tagihan dibuat; nominal di bawah adalah tampilan saja —
 * server menghitung ulang biaya saat pesanan dibuat.
 */
export default function PaymentChannelPicker({
  methods = [],
  value,
  amount = 0,
  onChange,
  label = 'Metode pembayaran',
}) {
  if (!methods.length) return null

  return (
    <div className="field">
      <span className="field__label">{label}</span>
      <div className="pay-options" role="radiogroup" aria-label={label}>
        {methods.map((method) => {
          const fee = feeFor(amount, method)
          // Tarif Midtrans: persen, persen + flat, atau flat saja.
          const rate =
            method.percent > 0
              ? `${method.percent}%${method.flat > 0 ? ` + ${rupiah(method.flat)}` : ''}`
              : method.flat > 0
                ? 'flat'
                : 'tanpa biaya'
          return (
            <label
              key={method.code}
              className={`pay-option${value === method.code ? ' is-active' : ''}`}
            >
              <input
                type="radio"
                name="payment_channel"
                value={method.code}
                checked={value === method.code}
                onChange={() => onChange?.(method.code)}
              />
              <span className="pay-option__body">
                <span className="pay-option__title">{method.label}</span>
                <span className="pay-option__meta">
                  {amount > 0 ? `biaya ${rupiah(fee)} (${rate})` : rate}
                </span>
              </span>
              {amount > 0 ? (
                <span className="pay-option__price">{rupiah(amount + fee)}</span>
              ) : null}
            </label>
          )
        })}
      </div>
      <span className="field__hint">
        Biaya layanan mengikuti tarif metode yang dipilih dan ditambahkan ke tagihan Midtrans Anda.
      </span>
    </div>
  )
}
