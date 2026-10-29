import { useCallback, useEffect, useState } from 'react'
import { api } from '../../lib/api.js'
import { dateTime, rupiah } from '../../lib/format.js'
import { EmptyState, ErrorState, LoadingState } from '../StateViews.jsx'
import StatusBadge from '../StatusBadge.jsx'

const FILTERS = [
  { value: '', label: 'Semua' },
  { value: 'pending', label: 'Menunggu' },
  { value: 'processing', label: 'Diproses' },
  { value: 'paid', label: 'Terkirim' },
  { value: 'rejected', label: 'Ditolak' },
]

const VERIFICATION = {
  unverified: 'Nama belum dicek admin',
  matched: 'Nama sudah cocok',
  mismatch: 'Nama tidak cocok',
}

/**
 * Antrean penarikan saldo. Admin memproses, menandai terkirim, atau menolak —
 * penolakan mengembalikan saldo pengguna secara otomatis.
 */
export default function AdminWithdrawals() {
  const [status, setStatus] = useState('')
  const [items, setItems] = useState([])
  const [notes, setNotes] = useState({})
  const [busy, setBusy] = useState(null)
  const [notice, setNotice] = useState('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      setItems(await api.adminWithdrawals(status ? { status } : {}))
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setLoading(false)
    }
  }, [status])

  useEffect(() => {
    load()
  }, [load])

  const act = async (row, action) => {
    setBusy(row.id)
    setNotice('')
    setError('')
    try {
      const updated = await api.adminWithdrawalAction(row.id, action, notes[row.id] || '')
      if (action === 'reject') {
        setNotice(
          `${updated.reference} ditolak. Saldo ${rupiah(updated.amount)} kembali ke ${updated.user.name} (saldo kini ${rupiah(updated.userBalance)}).`,
        )
      } else {
        setNotice(`${updated.reference} diperbarui menjadi ${updated.status}.`)
      }
      setNotes((current) => ({ ...current, [row.id]: '' }))
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
        <LoadingState label="Memuat penarikan…" />
      ) : error && items.length === 0 ? (
        <ErrorState message={error} onRetry={load} />
      ) : items.length === 0 ? (
        <EmptyState
          title="Tidak ada pengajuan"
          description="Pengajuan penarikan pengguna akan muncul di sini."
        />
      ) : (
        <div className="stack">
          {items.map((row) => {
            const open = row.status === 'pending' || row.status === 'processing'
            return (
              <article className="card admin-card" key={row.id}>
                <div className="admin-card__head">
                  <div>
                    <p className="admin-card__title">{rupiah(row.amount)}</p>
                    <p className="admin-card__sub">
                      {row.reference} · {dateTime(row.createdAt)}
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
                    <span className="row__label">Tujuan</span>
                    <span className="row__value">
                      {row.providerName} · {row.accountNumber}
                      <br />
                      <small>{row.accountHolder}</small>
                    </span>
                  </div>
                  <div className="row">
                    <span className="row__label">Verifikasi nama</span>
                    <span className="row__value">
                      {VERIFICATION[row.verificationStatus] || row.verificationStatus}
                    </span>
                  </div>
                  {row.note ? (
                    <div className="row">
                      <span className="row__label">Catatan</span>
                      <span className="row__value">{row.note}</span>
                    </div>
                  ) : null}
                  {row.processedAt ? (
                    <div className="row">
                      <span className="row__label">Diproses</span>
                      <span className="row__value">{dateTime(row.processedAt)}</span>
                    </div>
                  ) : null}
                </div>

                <a
                  className="admin-link"
                  href="https://cekrekening.id/"
                  target="_blank"
                  rel="noreferrer"
                >
                  Cek nomor rekening di cekrekening.id →
                </a>

                {open ? (
                  <>
                    <label className="field field--compact">
                      <span className="field__label">Catatan admin (opsional)</span>
                      <input
                        className="input"
                        placeholder="Contoh: sudah ditransfer 28 Okt"
                        value={notes[row.id] || ''}
                        onChange={(event) =>
                          setNotes((current) => ({ ...current, [row.id]: event.target.value }))
                        }
                      />
                    </label>
                    <div className="admin-card__actions">
                      {row.status === 'pending' ? (
                        <button
                          type="button"
                          className="btn btn--outline btn--sm"
                          disabled={busy === row.id}
                          onClick={() => act(row, 'process')}
                        >
                          Proses
                        </button>
                      ) : null}
                      <button
                        type="button"
                        className="btn btn--primary btn--sm"
                        disabled={busy === row.id}
                        onClick={() => act(row, 'paid')}
                      >
                        Tandai terkirim
                      </button>
                      <button
                        type="button"
                        className="btn btn--danger-ghost btn--sm"
                        disabled={busy === row.id}
                        onClick={() => act(row, 'reject')}
                      >
                        Tolak &amp; kembalikan saldo
                      </button>
                    </div>
                  </>
                ) : null}
              </article>
            )
          })}
        </div>
      )}
    </>
  )
}
