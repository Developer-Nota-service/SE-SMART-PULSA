import { rupiah } from '../lib/format.js'

/**
 * Metode bayar ditentukan oleh tipe akun, bukan dipilih pengguna:
 * reseller memakai saldo, akun pribadi membayar langsung lewat Midtrans.
 */
export default function PaymentOptions({ method, price, priceLabel, balance }) {
  const isBalance = method === 'balance'

  return (
    <div className="pay-options" role="group" aria-label="Metode pembayaran">
      <div className="pay-option is-active">
        <span className="pay-option__body">
          <span className="pay-option__title">
            {isBalance ? `Saldo · ${priceLabel}` : 'Bayar langsung · Midtrans'}
          </span>
          <span className="pay-option__meta">
            {isBalance ? `Saldo Anda ${rupiah(balance)}` : 'Kartu, QRIS, atau e-wallet'}
          </span>
        </span>
        <span className="pay-option__price">{rupiah(price)}</span>
      </div>
    </div>
  )
}
