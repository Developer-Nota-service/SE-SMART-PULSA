import { Link } from 'react-router-dom'
import { rupiah } from '../lib/format.js'

export default function BalanceCard({ account, compact = false }) {
  return (
    <section className={`balance-card${compact ? ' balance-card--compact' : ''}`}>
      <span className="balance-card__label">Saldo aktif</span>
      <strong className="balance-card__value">{account ? rupiah(account.balance) : '—'}</strong>
      <span className="balance-card__meta">
        {account ? `${account.name} · ${account.phone}` : 'Memuat akun…'}
      </span>

      <div className="balance-card__actions">
        <Link to="/saldo" className="btn btn--light">
          Isi Saldo
        </Link>
        <Link to="/transaksi" className="btn btn--ghost-light">
          Riwayat
        </Link>
      </div>
    </section>
  )
}
