import { useCallback, useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { api } from '../../lib/api.js'
import { dateTime, refundLabel, rupiah } from '../../lib/format.js'
import { EmptyState, ErrorState, LoadingState } from '../StateViews.jsx'
import StatusBadge from '../StatusBadge.jsx'

const FILTERS = [
  { value: '', label: 'Semua' },
  { value: 'pending', label: 'Menunggu' },
  { value: 'success', label: 'Berhasil' },
  { value: 'failed', label: 'Gagal' },
]

/**
 * Semua transaksi pengguna (saldo maupun Midtrans), termasuk yang gagal —
 * supaya transaksi bermasalah bisa ditelusuri dari nomor pesanan atau nomor
 * tujuan.
 */
export default function AdminTransactions() {
  // Status ikut alamat halaman (/admin/transaksi?status=failed) supaya tautan
  // dari ringkasan langsung membuka antrean yang dimaksud.
  const [params, setParams] = useSearchParams()
  const status = params.get('status') || ''
  const setStatus = (value) => setParams(value ? { status: value } : {})
  const [search, setSearch] = useState('')
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [marking, setMarking] = useState(null)
  const [checking, setChecking] = useState(null)
  const [actionError, setActionError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      setItems(await api.adminTransactions({ status, q: search }))
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setLoading(false)
    }
  }, [status, search])

  useEffect(() => {
    load()
  }, [load])

  const checkSupplier = async (id) => {
    setChecking(id)
    setActionError('')
    try {
      await api.adminCheckTransaction(id)
      await load()
    } catch (requestError) {
      setActionError(requestError.message)
    } finally {
      setChecking(null)
    }
  }

  const markRefundPaid = async (id) => {
    setMarking(id)
    setActionError('')
    try {
      await api.adminMarkRefundPaid(id)
      await load()
    } catch (requestError) {
      setActionError(requestError.message)
    } finally {
      setMarking(null)
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

      <label className="field field--compact">
        <span className="field__label">Cari nomor pesanan atau nomor tujuan</span>
        <input
          className="input"
          placeholder="TRX-… / 0812…"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
        />
      </label>

      {actionError ? (
        <p className="field__error" role="alert">
          {actionError}
        </p>
      ) : null}

      {loading ? (
        <LoadingState label="Memuat transaksi…" />
      ) : error ? (
        <ErrorState message={error} onRetry={load} />
      ) : items.length === 0 ? (
        <EmptyState
          title="Tidak ada transaksi"
          description="Coba ubah status atau kata kunci pencarian."
        />
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
                    <small>{row.user.email}</small>
                  </span>
                </div>
                <div className="row">
                  <span className="row__label">Produk</span>
                  <span className="row__value">{row.productName || '—'}</span>
                </div>
                <div className="row">
                  <span className="row__label">Tujuan</span>
                  <span className="row__value">{row.customerNumber}</span>
                </div>
                <div className="row">
                  <span className="row__label">Pembayaran</span>
                  <span className="row__value">
                    {row.paymentMethod === 'midtrans' ? 'Midtrans' : 'Saldo'}
                  </span>
                </div>
                {row.supplierMessage && row.status !== 'success' ? (
                  <div className="row">
                    <span className="row__label">Pesan supplier</span>
                    <span className="row__value">{row.supplierMessage}</span>
                  </div>
                ) : null}
                {row.refundStatus ? (
                  <div className="row">
                    <span className="row__label">Pengembalian dana</span>
                    <span className="row__value">{refundLabel(row)}</span>
                  </div>
                ) : null}
              </div>

              {row.status === 'pending' ? (
                <button
                  type="button"
                  className="btn btn--ghost btn--sm"
                  disabled={checking === row.id}
                  onClick={() => checkSupplier(row.id)}
                >
                  {checking === row.id ? 'Memeriksa…' : 'Periksa ke supplier'}
                </button>
              ) : null}

              {row.refundStatus === 'failed' ? (
                <button
                  type="button"
                  className="btn btn--ghost btn--sm"
                  disabled={marking === row.id}
                  onClick={() => markRefundPaid(row.id)}
                >
                  {marking === row.id ? 'Menyimpan…' : 'Tandai refund sudah dikirim'}
                </button>
              ) : null}
            </article>
          ))}
        </div>
      )}
    </>
  )
}
