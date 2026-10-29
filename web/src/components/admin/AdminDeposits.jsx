import { useCallback, useEffect, useState } from 'react'
import { api } from '../../lib/api.js'
import { dateTime, rupiah } from '../../lib/format.js'
import { EmptyState, ErrorState, LoadingState } from '../StateViews.jsx'
import StatusBadge from '../StatusBadge.jsx'

const FILTERS = [
  { value: '', label: 'Semua' },
  { value: 'pending', label: 'Menunggu' },
  { value: 'paid', label: 'Lunas' },
  { value: 'canceled', label: 'Dibatalkan' },
  { value: 'expired', label: 'Kedaluwarsa' },
]

/**
 * Daftar deposit seluruh pengguna. Deposit yang menggantung bisa diselaraskan
 * dengan status resmi Midtrans, atau dibatalkan bila memang tidak dibayar.
 */
export default function AdminDeposits() {
  const [status, setStatus] = useState('')
  const [items, setItems] = useState([])
  const [busy, setBusy] = useState(null)
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      setItems(await api.adminDeposits(status ? { status } : {}))
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setLoading(false)
    }
  }, [status])

  useEffect(() => {
    load()
  }, [load])

  const run = async (row, kind) => {
    setBusy(`${kind}-${row.id}`)
    setNotice('')
    setError('')
    try {
      if (kind === 'sync') {
        const result = await api.adminDepositSync(row.id)
        setNotice(
          `${row.orderNumber}: Midtrans melaporkan status "${result.midtransStatus}" — deposit kini ${result.deposit.status}.`,
        )
      } else {
        const updated = await api.adminDepositCancel(row.id)
        setNotice(`${updated.orderNumber} dibatalkan. Tidak ada saldo yang berubah.`)
      }
      await load()
    } catch (actionError) {
      setError(actionError.message)
    } finally {
      setBusy(null)
    }
  }

  return (
    <>
      <div className="chips admin-tabs">
        {FILTERS.map((filter) => (
          <button
            key={filter.value || 'all'}
            type="button"
            className={`chip${status === filter.value ? ' is-active' : ''}`}
            onClick={() => setStatus(filter.value)}
          >
            {filter.label}
          </button>
        ))}
      </div>

      {notice ? <p className="form-message form-message--ok">{notice}</p> : null}
      {error ? <p className="form-message">{error}</p> : null}

      {loading ? (
        <LoadingState label="Memuat deposit…" />
      ) : error && items.length === 0 ? (
        <ErrorState message={error} onRetry={load} />
      ) : items.length === 0 ? (
        <EmptyState title="Tidak ada deposit" description="Deposit pengguna akan muncul di sini." />
      ) : (
        <div className="stack">
          {items.map((row) => (
            <article className="card admin-card" key={row.id}>
              <div className="admin-card__head">
                <div>
                  <p className="admin-card__title">{rupiah(row.amount)}</p>
                  <p className="admin-card__sub">
                    {row.orderNumber} · {dateTime(row.createdAt)}
                  </p>
                </div>
                <StatusBadge status={row.status} />
              </div>

              <div className="rows">
                <div className="row">
                  <span className="row__label">Pengguna</span>
                  <span className="row__value">
                    {row.user.name}
                    <br />
                    <small>{row.user.phone}</small>
                  </span>
                </div>
                <div className="row">
                  <span className="row__label">Umur pesanan</span>
                  <span className="row__value">
                    {row.status === 'pending'
                      ? `${row.ageMinutes} menit${row.stale ? ' · belum dibayar' : ''}`
                      : '—'}
                  </span>
                </div>
                {row.paidAt ? (
                  <div className="row">
                    <span className="row__label">Dibayar</span>
                    <span className="row__value">{dateTime(row.paidAt)}</span>
                  </div>
                ) : null}
              </div>

              {row.status === 'pending' ? (
                <div className="admin-card__actions">
                  <button
                    type="button"
                    className="btn btn--outline btn--sm"
                    disabled={busy === `sync-${row.id}`}
                    onClick={() => run(row, 'sync')}
                  >
                    Sinkronkan Midtrans
                  </button>
                  <button
                    type="button"
                    className="btn btn--danger-ghost btn--sm"
                    disabled={busy === `cancel-${row.id}`}
                    onClick={() => run(row, 'cancel')}
                  >
                    Batalkan
                  </button>
                </div>
              ) : null}
            </article>
          ))}
        </div>
      )}
    </>
  )
}
