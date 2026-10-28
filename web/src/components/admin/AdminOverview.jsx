import { useCallback, useEffect, useState } from 'react'
import { api } from '../../lib/api.js'
import { dateTime, rupiah } from '../../lib/format.js'
import { ErrorState, LoadingState } from '../StateViews.jsx'

function Stat({ label, value, hint }) {
  return (
    <div className="stat-card">
      <p className="stat-card__label">{label}</p>
      <p className="stat-card__value">{value}</p>
      {hint ? <p className="stat-card__hint">{hint}</p> : null}
    </div>
  )
}

/**
 * Ringkasan angka penting + antrean pekerjaan yang perlu ditindak admin:
 * penarikan menunggu, deposit menggantung, dan transaksi gagal.
 */
export default function AdminOverview({ onOpenTab }) {
  const [counts, setCounts] = useState(null)
  const [queue, setQueue] = useState({ withdrawals: [], deposits: [], transactions: [] })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const [overview, withdrawals, deposits, transactions] = await Promise.all([
        api.adminOverview(),
        api.adminWithdrawals({ status: 'pending' }),
        api.adminDeposits({ status: 'pending' }),
        api.adminTransactions({ status: 'failed' }),
      ])
      setCounts(overview.counts)
      setQueue({
        withdrawals: withdrawals.slice(0, 3),
        deposits: deposits.filter((row) => row.stale).slice(0, 3),
        transactions: transactions.slice(0, 3),
      })
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    load()
  }, [load])

  if (loading) return <LoadingState label="Memuat ringkasan…" />
  if (error) return <ErrorState message={error} onRetry={load} />
  if (!counts) return null

  const queueEmpty =
    queue.withdrawals.length === 0 &&
    queue.deposits.length === 0 &&
    queue.transactions.length === 0

  return (
    <>
      <div className="stat-grid">
        <Stat
          label="Penarikan menunggu"
          value={counts.withdrawalsPending}
          hint={rupiah(counts.withdrawalsPendingAmount)}
        />
        <Stat
          label="Deposit menunggu"
          value={counts.depositsPending}
          hint={rupiah(counts.depositsPendingAmount)}
        />
        <Stat
          label="Transaksi gagal"
          value={counts.transactionsFailed}
          hint={`${counts.transactionsPending} masih menunggu`}
        />
        <Stat
          label="Saldo pengguna"
          value={rupiah(counts.balanceTotal)}
          hint={`${counts.users} akun terdaftar`}
        />
        <Stat
          label="Refund manual"
          value={counts.refundsManual ?? 0}
          hint="kirim dana ke rekening pengguna"
        />
        <Stat label="Deposit masuk hari ini" value={rupiah(counts.depositsPaidToday)} />
        <Stat label="Transaksi hari ini" value={counts.transactionsToday} />
      </div>

      <div className="section-head">
        <h3 className="section-title">Perlu tindakan</h3>
        <button type="button" className="btn btn--ghost btn--sm" onClick={load}>
          Muat ulang
        </button>
      </div>

      {queueEmpty ? (
        <div className="card">
          <p className="state__title">Tidak ada yang menggantung</p>
          <p className="state__text">
            Semua penarikan, deposit, dan transaksi pengguna dalam kondisi aman.
          </p>
        </div>
      ) : (
        <div className="stack admin-queue">
          {queue.withdrawals.length > 0 ? (
            <div className="card admin-card">
              <div className="admin-card__head">
                <p className="admin-card__title">Penarikan menunggu</p>
                <button
                  type="button"
                  className="btn btn--ghost btn--sm"
                  onClick={() => onOpenTab('withdrawals')}
                >
                  Proses
                </button>
              </div>
              <div className="rows">
                {queue.withdrawals.map((row) => (
                  <div className="row" key={row.id}>
                    <span className="row__label">
                      {row.user.name} · {row.providerName}
                    </span>
                    <span className="row__value">{rupiah(row.amount)}</span>
                  </div>
                ))}
              </div>
            </div>
          ) : null}

          {queue.deposits.length > 0 ? (
            <div className="card admin-card">
              <div className="admin-card__head">
                <p className="admin-card__title">Deposit belum dibayar &gt; 30 menit</p>
                <button
                  type="button"
                  className="btn btn--ghost btn--sm"
                  onClick={() => onOpenTab('deposits')}
                >
                  Periksa
                </button>
              </div>
              <div className="rows">
                {queue.deposits.map((row) => (
                  <div className="row" key={row.id}>
                    <span className="row__label">
                      {row.user.name} · {dateTime(row.createdAt)}
                    </span>
                    <span className="row__value">{rupiah(row.amount)}</span>
                  </div>
                ))}
              </div>
            </div>
          ) : null}

          {queue.transactions.length > 0 ? (
            <div className="card admin-card">
              <div className="admin-card__head">
                <p className="admin-card__title">Transaksi gagal</p>
                <button
                  type="button"
                  className="btn btn--ghost btn--sm"
                  onClick={() => onOpenTab('transactions')}
                >
                  Lihat
                </button>
              </div>
              <div className="rows">
                {queue.transactions.map((row) => (
                  <div className="row" key={row.id}>
                    <span className="row__label">
                      {row.user.name} · {row.productName || row.orderNumber}
                    </span>
                    <span className="row__value">{rupiah(row.amount)}</span>
                  </div>
                ))}
              </div>
            </div>
          ) : null}
        </div>
      )}
    </>
  )
}
