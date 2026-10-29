import { useCallback, useEffect, useState } from 'react'
import { api } from '../../lib/api.js'
import { CATEGORIES, categoryLabel, dateTime, rupiah } from '../../lib/format.js'
import { CloseIcon } from '../Icons.jsx'
import { EmptyState, ErrorState, LoadingState } from '../StateViews.jsx'

const FILTERS = [{ key: '', label: 'Semua' }, ...CATEGORIES]

const marginPercent = (row) =>
  row.price > 0 ? Math.round(((row.retailPrice - row.price) / row.price) * 100) : 0

/** Margin harga reseller di atas harga modal supplier (null untuk produk demo). */
const marginOverCost = (row) =>
  row.costPrice > 0 ? Math.round(((row.price - row.costPrice) / row.costPrice) * 100) : null

/**
 * Halaman "Harga" konsol admin: harga reseller (`price`) dan harga publik
 * (`retailPrice`) tiap produk, bisa diubah satu per satu atau digeser massal
 * per kategori. Produk nonaktif tidak muncul di katalog pengguna.
 */
export default function AdminProducts() {
  const [category, setCategory] = useState('')
  const [search, setSearch] = useState('')
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [editing, setEditing] = useState(null)
  const [notice, setNotice] = useState('')

  const [bulkMode, setBulkMode] = useState('percent')
  const [bulkValue, setBulkValue] = useState('')
  const [bulkBusy, setBulkBusy] = useState(false)
  const [bulkError, setBulkError] = useState('')

  const [syncBusy, setSyncBusy] = useState(false)
  const [syncError, setSyncError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      setItems(await api.adminProducts({ category, q: search }))
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setLoading(false)
    }
  }, [category, search])

  useEffect(() => {
    load()
  }, [load])

  const applyBulk = async () => {
    const value = Number(bulkValue)
    if (!category || !bulkValue || Number.isNaN(value)) return

    setBulkBusy(true)
    setBulkError('')
    setNotice('')
    try {
      const result = await api.adminBulkPrice({ category, mode: bulkMode, value })
      setNotice(`Harga ${result.updated} produk ${categoryLabel(category)} diperbarui.`)
      setBulkValue('')
      await load()
    } catch (requestError) {
      setBulkError(requestError.errors?.value?.[0] || requestError.message)
    } finally {
      setBulkBusy(false)
    }
  }

  const syncPrices = async () => {
    setSyncBusy(true)
    setSyncError('')
    setNotice('')
    try {
      const result = await api.adminSyncDigiflazz()
      setNotice(
        `Harga modal ${result.products} produk diperbarui dari Digiflazz (${result.active} aktif).`,
      )
      await load()
    } catch (requestError) {
      setSyncError(requestError.message)
    } finally {
      setSyncBusy(false)
    }
  }

  return (
    <>
      <div className="section-head">
        <h2 className="section-title">Harga produk</h2>
        <span className="section-hint">{items.length} produk</span>
      </div>

      <div className="chips admin-tabs">
        {FILTERS.map((filter) => (
          <button
            key={filter.key || 'all'}
            type="button"
            className={`chip${category === filter.key ? ' is-active' : ''}`}
            onClick={() => setCategory(filter.key)}
          >
            {filter.label}
          </button>
        ))}
      </div>

      <label className="field field--compact">
        <span className="field__label">Cari produk, SKU, atau operator</span>
        <input
          className="input"
          placeholder="Telkomsel 10.000 / TSL10"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
        />
      </label>

      <div className="card admin-card">
        <p className="admin-card__title">Harga modal Digiflazz</p>
        <p className="admin-card__sub">
          Tarik harga modal dan provider terbaru dari Digiflazz. Harga jual yang sudah Anda atur
          tidak berubah — hanya produk baru yang dihitung otomatis dari margin.
        </p>
        <button
          type="button"
          className="btn btn--primary btn--sm"
          disabled={syncBusy}
          onClick={syncPrices}
        >
          {syncBusy ? 'Menarik harga…' : 'Ambil harga terbaru'}
        </button>
        {syncError ? (
          <p className="field__error" role="alert">
            {syncError}
          </p>
        ) : null}
      </div>

      {category ? (
        <div className="card admin-card">
          <p className="admin-card__title">Ubah harga massal</p>
          <p className="admin-card__sub">
            Berlaku untuk {items.length} produk {categoryLabel(category)} yang tampil di bawah.
          </p>
          <div className="admin-bulk">
            <select
              className="input admin-bulk__mode"
              value={bulkMode}
              onChange={(event) => setBulkMode(event.target.value)}
            >
              <option value="percent">Persen (%)</option>
              <option value="amount">Rupiah (Rp)</option>
            </select>
            <input
              className="input"
              inputMode="decimal"
              placeholder={bulkMode === 'percent' ? 'contoh: 5 atau -5' : 'contoh: 500'}
              value={bulkValue}
              onChange={(event) => setBulkValue(event.target.value)}
            />
            <button
              type="button"
              className="btn btn--primary btn--sm"
              disabled={bulkBusy || !bulkValue}
              onClick={applyBulk}
            >
              {bulkBusy ? 'Menyimpan…' : 'Terapkan'}
            </button>
          </div>
          {bulkError ? (
            <p className="field__error" role="alert">
              {bulkError}
            </p>
          ) : null}
        </div>
      ) : null}

      {notice ? <p className="admin-note">{notice}</p> : null}

      {loading ? (
        <LoadingState label="Memuat produk…" />
      ) : error ? (
        <ErrorState message={error} onRetry={load} />
      ) : items.length === 0 ? (
        <EmptyState
          title="Tidak ada produk"
          description="Coba pilih kategori lain atau ubah kata kunci pencarian."
        />
      ) : (
        <div className="stack">
          {items.map((row) => (
            <article className="card admin-card" key={row.id}>
              <div className="admin-card__head">
                <div>
                  <p className="admin-card__title">{row.name}</p>
                  <p className="admin-card__sub">
                    {row.sku} · {row.operator}
                  </p>
                </div>
                <div className="admin-card__tags">
                  {!row.active ? (
                    <span className="badge badge--xs badge--muted">Nonaktif</span>
                  ) : row.isDemo ? (
                    <span className="badge badge--xs badge--demo">DEMO</span>
                  ) : null}
                </div>
              </div>

              <div className="admin-price">
                <div>
                  <span className="admin-price__label">Modal</span>
                  <span className="admin-price__value">
                    {row.costPrice ? rupiah(row.costPrice) : '–'}
                  </span>
                </div>
                <div>
                  <span className="admin-price__label">Reseller</span>
                  <span className="admin-price__value">{rupiah(row.price)}</span>
                </div>
                <div>
                  <span className="admin-price__label">Publik</span>
                  <span className="admin-price__value">{rupiah(row.retailPrice)}</span>
                </div>
                <div>
                  <span className="admin-price__label">Selisih</span>
                  <span className="admin-price__value">{marginPercent(row)}%</span>
                </div>
              </div>

              {row.costPrice ? (
                <p className="admin-card__sub">
                  {row.sellerName ? `${row.sellerName} · ` : ''}
                  margin modal {marginOverCost(row)}%
                  {row.maxCostPrice ? ` · harga max ${rupiah(row.maxCostPrice)}` : ''}
                  {row.maxCostPrice && row.maxCostPrice < row.costPrice
                    ? ' (di bawah modal — transaksi akan ditolak Digiflazz)'
                    : ''}
                  {row.costSyncedAt ? ` · modal diperbarui ${dateTime(row.costSyncedAt)}` : ''}
                </p>
              ) : null}

              <button
                type="button"
                className="btn btn--ghost btn--sm"
                onClick={() => setEditing(row)}
              >
                Ubah harga
              </button>
            </article>
          ))}
        </div>
      )}

      {editing ? (
        <PriceSheet
          product={editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            setNotice('Harga produk diperbarui.')
            load()
          }}
        />
      ) : null}
    </>
  )
}

function PriceSheet({ product, onClose, onSaved }) {
  const [price, setPrice] = useState(String(product.price))
  const [retailPrice, setRetailPrice] = useState(String(product.retailPrice))
  const [maxCostPrice, setMaxCostPrice] = useState(
    product.maxCostPrice ? String(product.maxCostPrice) : '',
  )
  const [active, setActive] = useState(product.active)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    const previous = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    const onKey = (event) => {
      if (event.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKey)
    return () => {
      document.body.style.overflow = previous
      window.removeEventListener('keydown', onKey)
    }
  }, [onClose])

  const save = async (event) => {
    event.preventDefault()
    setBusy(true)
    setError('')
    try {
      await api.adminUpdateProduct(product.id, {
        price: Number(price),
        retailPrice: Number(retailPrice),
        maxCostPrice: maxCostPrice ? Number(maxCostPrice) : null,
        active,
      })
      onSaved()
    } catch (requestError) {
      setError(
        requestError.errors?.retailPrice?.[0] ||
          requestError.errors?.price?.[0] ||
          requestError.message,
      )
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="overlay" onClick={onClose}>
      <form
        className="sheet"
        role="dialog"
        aria-label="Ubah harga produk"
        onClick={(event) => event.stopPropagation()}
        onSubmit={save}
      >
        <div className="sheet__grip" />
        <div className="sheet__head">
          <h2 className="sheet__title">Ubah harga</h2>
          <button type="button" className="icon-btn" onClick={onClose} aria-label="Tutup">
            <CloseIcon />
          </button>
        </div>

        <p className="admin-card__sub" style={{ marginBottom: 12 }}>
          {product.name} · {product.sku}
        </p>

        <label className="field">
          <span className="field__label">Harga reseller (Rp)</span>
          <input
            className="input"
            inputMode="numeric"
            value={price}
            onChange={(event) => setPrice(event.target.value.replace(/\D/g, ''))}
          />
        </label>

        <label className="field">
          <span className="field__label">Harga publik (Rp)</span>
          <input
            className="input"
            inputMode="numeric"
            value={retailPrice}
            onChange={(event) => setRetailPrice(event.target.value.replace(/\D/g, ''))}
          />
        </label>

        {product.costPrice ? (
          <label className="field">
            <span className="field__label">Harga max modal (Rp) — opsional</span>
            <input
              className="input"
              inputMode="numeric"
              placeholder="Tanpa batas"
              value={maxCostPrice}
              onChange={(event) => setMaxCostPrice(event.target.value.replace(/\D/g, ''))}
            />
            <span className="field__hint">
              Modal Digiflazz sekarang {rupiah(product.costPrice)}. Bila harga modal naik melewati
              angka ini, transaksi ditolak Digiflazz dan saldo pembeli dikembalikan.
            </span>
          </label>
        ) : null}

        <label className="admin-toggle">
          <input
            type="checkbox"
            checked={active}
            onChange={(event) => setActive(event.target.checked)}
          />
          <span>Tayangkan produk ini di katalog</span>
        </label>

        {error ? (
          <p className="field__error" role="alert">
            {error}
          </p>
        ) : null}

        <button type="submit" className="btn btn--primary" disabled={busy}>
          {busy ? 'Menyimpan…' : 'Simpan harga'}
        </button>
      </form>
    </div>
  )
}
