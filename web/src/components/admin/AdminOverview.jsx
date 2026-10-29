import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
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

const dayLabel = (value) => {
  const date = new Date(`${value}T00:00:00`)
  if (Number.isNaN(date.getTime())) return value
  return date.toLocaleDateString('id-ID', { weekday: 'short', day: 'numeric', month: 'short' })
}

/**
 * Ringkasan angka penting + antrean pekerjaan yang perlu ditindak admin:
 * penarikan menunggu, deposit menggantung, dan transaksi gagal.
 */
export default function AdminOverview() {
  const navigate = useNavigate()
  const [counts, setCounts] = useState(null)
  const [salesDaily, setSalesDaily] = useState([])
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
      setSalesDaily(overview.salesDaily ?? [])
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

  const maxRevenue = Math.max(...salesDaily.map((row) => row.revenue), 1)
  const bestDay = salesDaily.reduce(
    (best, row) => (row.revenue > (best?.revenue ?? 0) ? row : best),
    null,
  )

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
        <Stat
          label="Penjualan hari ini"
          value={rupiah(counts.salesToday)}
          hint={`${counts.salesTodayOrders ?? 0} pesanan sukses`}
        />
        <Stat
          label="Penjualan 7 hari"
          value={rupiah(counts.salesWeek)}
          hint={`${counts.salesWeekOrders ?? 0} pesanan sukses`}
        />
        <Stat label="Transaksi hari ini" value={counts.transactionsToday} />
      </div>

      <section>
        <div className="section-head">
          <h3 className="section-title">Penjualan harian</h3>
          <span className="section-hint">7 hari terakhir</span>
        </div>

        <div className="card admin-card">
          {salesDaily.length === 0 ? (
            <p className="admin-card__sub">Belum ada data penjualan.</p>
          ) : (
            <>
              <div className="sales-chart" aria-hidden="true">
                {salesDaily.map((row) => (
                  <div className="sales-chart__col" key={row.date}>
                    <span className="sales-chart__value">
                      {row.revenue > 0 ? rupiah(row.revenue) : ''}
                    </span>
                    <span
                      className={`sales-chart__bar${row.date === salesDaily.at(-1)?.date ? ' is-today' : ''}`}
                      style={{ height: `${Math.max((row.revenue / maxRevenue) * 100, 4)}%` }}
                    />
                    <span className="sales-chart__label">{dayLabel(row.date)}</span>
                  </div>
                ))}
              </div>

              <div className="rows">
                {[...salesDaily].reverse().map((row) => (
                  <div className="row" key={row.date}>
                    <span className="row__label">
                      {dayLabel(row.date)} · {row.orders} pesanan
                      {bestDay && bestDay.date === row.date && row.revenue > 0 ? (
                        <span className="badge badge--xs badge--ok">Terbaik</span>
                      ) : null}
                    </span>
                    <span className="row__value">{rupiah(row.revenue)}</span>
                  </div>
                ))}
              </div>
            </>
          )}
        </div>
      </section>

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
                  onClick={() => navigate('/admin/keuangan?tab=penarikan')}
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
                  onClick={() => navigate('/admin/keuangan')}
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
                  onClick={() => navigate('/admin/transaksi?status=failed')}
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
