import { useCallback, useEffect, useState } from 'react'
import { api } from '../../lib/api.js'
import { dateTime, rupiah } from '../../lib/format.js'
import { EmptyState, ErrorState, LoadingState } from '../StateViews.jsx'

/**
 * Daftar akun pengguna: saldo, tipe akun, status verifikasi, jumlah aktivitas,
 * dan login terakhir — titik awal saat ada laporan masalah dari pengguna.
 */
export default function AdminUsers() {
  const [search, setSearch] = useState('')
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      setItems(await api.adminUsers({ q: search }))
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setLoading(false)
    }
  }, [search])

  useEffect(() => {
    load()
  }, [load])

  return (
    <>
      <label className="field field--compact">
        <span className="field__label">Cari nama, email, atau nomor WhatsApp</span>
        <input
          className="input"
          placeholder="Budi / 0812…"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
        />
      </label>

      {loading ? (
        <LoadingState label="Memuat pengguna…" />
      ) : error ? (
        <ErrorState message={error} onRetry={load} />
      ) : items.length === 0 ? (
        <EmptyState title="Tidak ada pengguna" description="Coba ubah kata kunci pencarian." />
      ) : (
        <div className="stack">
          {items.map((row) => (
            <article className="card admin-card" key={row.id}>
              <div className="admin-card__head">
                <div>
                  <p className="admin-card__title">{row.name}</p>
                  <p className="admin-card__sub">
                    {row.email} · {row.phone}
                  </p>
                </div>
                <div className="admin-card__tags">
                  <span className="badge badge--soft">{row.accountType}</span>
                  {row.role === 'admin' ? <span className="badge badge--admin">ADMIN</span> : null}
                </div>
              </div>

              <div className="rows">
                <div className="row">
                  <span className="row__label">Saldo</span>
                  <span className="row__value">{rupiah(row.balance)}</span>
                </div>
                <div className="row">
                  <span className="row__label">Aktivitas</span>
                  <span className="row__value">
                    {row.counts.transactions} transaksi · {row.counts.deposits} deposit ·{' '}
                    {row.counts.withdrawals} penarikan
                  </span>
                </div>
                <div className="row">
                  <span className="row__label">Verifikasi</span>
                  <span className="row__value">
                    {row.phoneVerified ? 'WhatsApp aktif' : 'WhatsApp belum aktif'}
                  </span>
                </div>
                <div className="row">
                  <span className="row__label">Login terakhir</span>
                  <span className="row__value">
                    {row.lastLoginAt ? dateTime(row.lastLoginAt) : 'Belum pernah'}
                  </span>
                </div>
                <div className="row">
                  <span className="row__label">Bergabung</span>
                  <span className="row__value">{dateTime(row.createdAt)}</span>
                </div>
              </div>
            </article>
          ))}
        </div>
      )}
    </>
  )
}
