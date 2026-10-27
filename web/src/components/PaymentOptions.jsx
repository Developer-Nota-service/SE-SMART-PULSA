import { rupiah } from '../lib/format.js'

export default function PaymentOptions({
  method,
  onChange,
  balancePrice,
  retailPrice,
  balance,
  balanceDisabled,
}) {
  return (
    <div className="pay-options" role="radiogroup" aria-label="Metode pembayaran">
      <label
        className={`pay-option${method === 'balance' ? ' is-active' : ''}${
          balanceDisabled ? ' is-disabled' : ''
        }`}
      >
        <input
          type="radio"
          name="payment_method"
          value="balance"
          checked={method === 'balance'}
          disabled={balanceDisabled}
          onChange={() => onChange('balance')}
        />
        <span className="pay-option__body">
          <span className="pay-option__title">Saldo · harga reseller</span>
          <span className="pay-option__meta">Saldo Anda {rupiah(balance)}</span>
        </span>
        <span className="pay-option__price">{rupiah(balancePrice)}</span>
      </label>

      <label className={`pay-option${method === 'midtrans' ? ' is-active' : ''}`}>
        <input
          type="radio"
          name="payment_method"
          value="midtrans"
          checked={method === 'midtrans'}
          onChange={() => onChange('midtrans')}
        />
        <span className="pay-option__body">
          <span className="pay-option__title">Bayar langsung · harga publik</span>
          <span className="pay-option__meta">Kartu, QRIS, atau e-wallet via Midtrans</span>
        </span>
        <span className="pay-option__price">{rupiah(retailPrice)}</span>
      </label>
    </div>
  )
}
