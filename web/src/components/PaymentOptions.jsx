import { rupiah } from '../lib/format.js'

export default function PaymentOptions({
  method,
  onChange,
  price,
  priceLabel,
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
          <span className="pay-option__title">Saldo · {priceLabel}</span>
          <span className="pay-option__meta">Saldo Anda {rupiah(balance)}</span>
        </span>
        <span className="pay-option__price">{rupiah(price)}</span>
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
          <span className="pay-option__title">Bayar langsung · Midtrans</span>
          <span className="pay-option__meta">Kartu, QRIS, atau e-wallet</span>
        </span>
        <span className="pay-option__price">{rupiah(price)}</span>
      </label>
    </div>
  )
}
