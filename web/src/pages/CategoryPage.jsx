import { useCallback, useEffect, useMemo, useState } from 'react'
import { Navigate, useParams } from 'react-router-dom'
import CategoryGrid from '../components/CategoryGrid.jsx'
import ProductCard from '../components/ProductCard.jsx'
import { EmptyState, ErrorState, LoadingState } from '../components/StateViews.jsx'
import { api } from '../lib/api.js'
import { CATEGORIES, categoryLabel } from '../lib/format.js'

const CATEGORY_KEYS = CATEGORIES.map((item) => item.key)

// Nama operator datang dari supplier dengan penulisan berbeda (TELKOMSEL vs
// Telkomsel), jadi pengelompokan memakai kunci huruf kecil dan label diambil
// dari penulisan yang paling banyak dipakai.
function groupByProvider(products) {
  const groups = new Map()

  for (const product of products) {
    const label = product.operator || 'Lainnya'
    const key = label.toLowerCase()
    if (!groups.has(key)) groups.set(key, { variants: new Map(), items: [] })
    const group = groups.get(key)
    group.items.push(product)
    group.variants.set(label, (group.variants.get(label) || 0) + 1)
  }

  return [...groups.entries()]
    .map(([key, group]) => ({
      key,
      label: [...group.variants.entries()].sort((a, b) => b[1] - a[1])[0][0],
      items: group.items,
    }))
    .sort((a, b) => b.items.length - a.items.length || a.label.localeCompare(b.label))
}

// Produk tampil setelah kategori dipilih: provider dipilih dulu pada deret
// menyamping, produk provider tersebut muncul menurun di bawahnya.
export default function CategoryPage() {
  const { category } = useParams()
  const valid = CATEGORY_KEYS.includes(category)
  const [products, setProducts] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [provider, setProvider] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    setProvider(null)
    try {
      setProducts(await api.products({ category }))
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setLoading(false)
    }
  }, [category])

  useEffect(() => {
    if (valid) load()
  }, [load, valid])

  const groups = useMemo(() => groupByProvider(products), [products])
  const active = useMemo(
    () => groups.find((group) => group.key === provider) || groups[0] || null,
    [groups, provider],
  )

  if (!valid) return <Navigate to="/" replace />

  return (
    <div className="stack">
      <section>
        <div className="section-head">
          <h2 className="section-title">{categoryLabel(category)}</h2>
          <span className="section-hint">
            {loading ? 'Memuat…' : `${products.length} produk`}
          </span>
        </div>
        <CategoryGrid value={category} />
      </section>

      {loading ? (
        <LoadingState label="Memuat produk…" />
      ) : error ? (
        <ErrorState message={error} onRetry={load} />
      ) : !active ? (
        <EmptyState
          title="Belum ada produk"
          description="Produk untuk kategori ini belum tersedia."
        />
      ) : (
        <section>
          <div className="chips provider-chips">
            {groups.map((group) => (
              <button
                key={group.key}
                type="button"
                className={`chip${group.key === active.key ? ' is-active' : ''}`}
                onClick={() => setProvider(group.key)}
              >
                {group.label}
                <span className="chip__count">{group.items.length}</span>
              </button>
            ))}
          </div>
          <div className="section-head provider-head">
            <h2 className="section-title">{active.label}</h2>
            <span className="section-hint">{active.items.length} produk</span>
          </div>
          <div className="product-grid">
            {active.items.map((product) => (
              <ProductCard key={product.id} product={product} />
            ))}
          </div>
        </section>
      )}
    </div>
  )
}
