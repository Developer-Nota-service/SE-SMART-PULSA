import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../../lib/api.js'
import { dateTime, rupiah } from '../../lib/format.js'
import { EmptyState, ErrorState, LoadingState } from '../StateViews.jsx'

const VIEWS = [
  { key: 'all', label: 'Semua' },
  { key: 'tripay', label: 'TriPay lebih murah' },
  { key: 'digiflazz', label: 'Digiflazz lebih murah' },
]

/**
 * Banding harga modal: harga supplier Digiflazz (yang tersimpan di produk) diadu
 * dengan daftar harga TriPay. Padanan dicari dari nama produk, jadi produk yang
 * namanya beda antar supplier muncul sebagai "tanpa padanan".
 */
export default function AdminCostComparison() {
  const [search, setSearch] = useState('')
  const [view, setView] = useState('all')
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [needsSetup, setNeedsSetup] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    setNeedsSetup(false)
    try {
      setData(await api.adminCostComparison({ q: search, view }))
    } catch (requestError) {
      setData(null)
      if (requestError.status === 422 && requestError.data?.configured === false) {
        setNeedsSetup(true)
      } else {
        setError(requestError.message)
      }
    } finally {
      setLoading(false)
    }
  }, [search, view])

  useEffect(() => {
    load()
  }, [load])

  const summary = data?.summary

  return (
    <>
      <div className="section-head">
        <h2 className="section-title">Banding harga modal</h2>
        <Link to="/admin/harga" className="section-hint">
          Kembali ke harga
        </Link>
      </div>

      <div className="chips admin-tabs">
        {VIEWS.map((option) => (
          <button
            key={option.key}
            type="button"
            className={`chip${view === option.key ? ' is-active' : ''}`}
            onClick={() => setView(option.key)}
          >
            {option.label}
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

      {loading ? (
        <LoadingState label="Membandingkan harga supplier…" />
      ) : needsSetup ? (
        <div className="card admin-card">
          <p className="admin-card__title">TriPay belum tersambung</p>
          <p className="admin-card__sub">
            Isi <strong>TRIPAY_API_KEY</strong> dari tripay.id → <em>Profile → API &amp;
            Callback</em> di tab Secrets. Harga modal Digiflazz tetap tampil normal di tab
            Harga; halaman ini hanya menambah kolom pembanding dari TriPay.
          </p>
        </div>
      ) : error ? (
        <ErrorState message={error} onRetry={load} />
      ) : !data ? null : (
        <>
          <div className="card admin-card">
            <p className="admin-card__title">Ringkasan</p>
            <p className="admin-card__sub">
              {summary.matched} dari {summary.products} produk bermodal Digiflazz punya
              padanan di TriPay · {summary.unmatched} tanpa padanan · {summary.tripayOnly}{' '}
              produk TriPay tanpa pasangan · harga TriPay ditarik {dateTime(data.fetchedAt)}
            </p>
            <div className="admin-price">
              <div>
                <span className="admin-price__label">TriPay lebih murah</span>
                <span className="admin-price__value">{summary.tripayCheaper} produk</span>
              </div>
              <div>
                <span className="admin-price__label">Digiflazz lebih murah</span>
                <span className="admin-price__value">{summary.digiflazzCheaper} produk</span>
              </div>
              <div>
                <span className="admin-price__label">Harga sama</span>
                <span className="admin-price__value">{summary.same} produk</span>
              </div>
              <div>
                <span className="admin-price__label">Potensi hemat</span>
                <span className="admin-price__value">{rupiah(summary.potentialSaving)}</span>
              </div>
            </div>
            <p className="admin-card__sub">
              Potensi hemat = jumlah selisih seluruh produk yang lebih murah di TriPay,
              dihitung per transaksi bila semuanya dipindah ke TriPay. Selisih di bawah
              belum dipotong biaya deposit/transfer.
            </p>
          </div>

          {data.items.length === 0 ? (
            <EmptyState
              title="Tidak ada produk"
              description="Coba ubah filter atau kata kunci pencarian."
            />
          ) : (
            <div className="stack">
              {data.items.map((row) => (
                <article className="card admin-card" key={row.id}>
                  <div className="admin-card__head">
                    <div>
                      <p className="admin-card__title">{row.name}</p>
                      <p className="admin-card__sub">
                        {row.sku} · {row.operator}
                      </p>
                    </div>
                    <div className="admin-card__tags">
                      {row.cheaper === 'tripay' ? (
                        <span className="badge badge--xs badge--ok">
                          TriPay −{rupiah(row.diff)}
                        </span>
                      ) : row.cheaper === 'digiflazz' ? (
                        <span className="badge badge--xs badge--muted">
                          Digiflazz −{rupiah(Math.abs(row.diff))}
                        </span>
                      ) : row.cheaper === 'sama' ? (
                        <span className="badge badge--xs badge--soft">Sama</span>
                      ) : (
                        <span className="badge badge--xs badge--warn">Tanpa padanan</span>
                      )}
                    </div>
                  </div>

                  <div className="admin-price">
                    <div>
                      <span className="admin-price__label">Modal Digiflazz</span>
                      <span className="admin-price__value">{rupiah(row.costPrice)}</span>
                    </div>
                    <div>
                      <span className="admin-price__label">Modal TriPay</span>
                      <span className="admin-price__value">
                        {row.tripayCost === null ? '–' : rupiah(row.tripayCost)}
                      </span>
                    </div>
                    <div>
                      <span className="admin-price__label">Harga reseller</span>
                      <span className="admin-price__value">{rupiah(row.price)}</span>
                    </div>
                    <div>
                      <span className="admin-price__label">Selisih</span>
                      <span className="admin-price__value">
                        {row.diff === null ? '–' : rupiah(Math.abs(row.diff))}
                      </span>
                    </div>
                  </div>

                  {row.tripaySku ? (
                    <p className="admin-card__sub">Kode produk TriPay {row.tripaySku}</p>
                  ) : null}
                </article>
              ))}
            </div>
          )}

          {data.total > data.items.length ? (
            <p className="admin-note">
              Menampilkan {data.items.length} dari {data.total} produk — persempit dengan
              kata kunci.
            </p>
          ) : null}
        </>
      )}
    </>
  )
}
