import { useCallback, useEffect, useState } from 'react'
import OrderDetailSheet from '../components/OrderDetailSheet.jsx'
import { EmptyState, ErrorState, LoadingState } from '../components/StateViews.jsx'
import TransactionItem from '../components/TransactionItem.jsx'
import { api } from '../lib/api.js'
import { CATEGORIES } from '../lib/format.js'
import usePaymentSync from '../lib/usePaymentSync.js'

const STATUS_OPTIONS = [
  { value: '', label: 'Semua status' },
  { value: 'success', label: 'Berhasil' },
  { value: 'pending', label: 'Menunggu' },
  { value: 'failed', label: 'Gagal' },
]

const EMPTY_FILTERS = { status: '', category: '', from: '', to: '' }

export default function TransactionsPage() {
  const [filters, setFilters] = useState(EMPTY_FILTERS)
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [selected, setSelected] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      setItems(await api.transactions(filters))
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setLoading(false)
    }
  }, [filters])

  useEffect(() => {
    load()
  }, [load])

  // Setelah kembali dari Midtrans, pastikan pembayaran yang lunas tercatat.
  usePaymentSync(load)

  const updateFilter = (field) => (event) =>
    setFilters((current) => ({ ...current, [field]: event.target.value }))

  const filtersActive = Object.values(filters).some(Boolean)

  return (
    <div className="stack">
      <div className="section-head">
        <h2 className="section-title">Riwayat transaksi</h2>
        <span className="section-hint">{loading ? '…' : `${items.length} pesanan`}</span>
      </div>

      <div className="filters">
        <label className="field field--compact">
          <span className="field__label">Status</span>
          <select className="input" value={filters.status} onChange={updateFilter('status')}>
            {STATUS_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </label>

        <label className="field field--compact">
          <span className="field__label">Kategori</span>
          <select className="input" value={filters.category} onChange={updateFilter('category')}>
            <option value="">Semua kategori</option>
            {CATEGORIES.map((category) => (
              <option key={category.key} value={category.key}>
                {category.label}
              </option>
            ))}
          </select>
        </label>

        <label className="field field--compact">
          <span className="field__label">Dari tanggal</span>
          <input className="input" type="date" value={filters.from} onChange={updateFilter('from')} />
        </label>

        <label className="field field--compact">
          <span className="field__label">Sampai tanggal</span>
          <input className="input" type="date" value={filters.to} onChange={updateFilter('to')} />
        </label>

        {filtersActive ? (
          <button
            type="button"
            className="btn btn--ghost btn--sm"
            onClick={() => setFilters(EMPTY_FILTERS)}
          >
            Reset filter
          </button>
        ) : null}
      </div>

      {loading ? (
        <LoadingState label="Memuat transaksi…" />
      ) : error ? (
        <ErrorState message={error} onRetry={load} />
      ) : items.length === 0 ? (
        <EmptyState
          title="Tidak ada transaksi"
          description={
            filtersActive
              ? 'Coba ubah filter tanggal, kategori, atau status.'
              : 'Transaksi yang Anda lakukan akan muncul di sini.'
          }
        />
      ) : (
        <div className="tx-list">
          {items.map((transaction) => (
            <TransactionItem
              key={transaction.id}
              transaction={transaction}
              onClick={setSelected}
            />
          ))}
        </div>
      )}

      {selected ? (
        <OrderDetailSheet
          order={selected}
          onUpdate={(updated) => {
            setItems((current) => current.map((item) => (item.id === updated.id ? updated : item)))
            setSelected(updated)
          }}
          onClose={() => setSelected(null)}
        />
      ) : null}
    </div>
  )
}
