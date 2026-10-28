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

// Produk tampil setelah kategori dipilih, dikelompokkan per provider.
export default function CategoryPage() {
  const { category } = useParams()
  const valid = CATEGORY_KEYS.includes(category)
  const [products, setProducts] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
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
      ) : groups.length === 0 ? (
        <EmptyState
          title="Belum ada produk"
          description="Produk untuk kategori ini belum tersedia."
        />
      ) : (
        <div className="group-list">
          {groups.map((group) => (
            <section key={group.key}>
              <div className="section-head">
                <h2 className="section-title">{group.label}</h2>
                <span className="section-hint">{group.items.length} produk</span>
              </div>
              <div className="product-grid">
                {group.items.map((product) => (
                  <ProductCard key={product.id} product={product} />
                ))}
              </div>
            </section>
          ))}
        </div>
      )}
    </div>
  )
}
