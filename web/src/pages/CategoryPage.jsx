import { useCallback, useEffect, useState } from 'react'
import { Navigate, useParams } from 'react-router-dom'
import CategoryGrid from '../components/CategoryGrid.jsx'
import ProductCard from '../components/ProductCard.jsx'
import { EmptyState, ErrorState, LoadingState } from '../components/StateViews.jsx'
import { api } from '../lib/api.js'
import { CATEGORIES, categoryLabel } from '../lib/format.js'

const CATEGORY_KEYS = CATEGORIES.map((item) => item.key)

// Produk hanya tampil setelah pengguna memilih kategori dari beranda.
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

      <section>
        <div className="section-head">
          <h2 className="section-title">Pilih produk</h2>
        </div>

        {loading ? (
          <LoadingState label="Memuat produk…" />
        ) : error ? (
          <ErrorState message={error} onRetry={load} />
        ) : products.length === 0 ? (
          <EmptyState
            title="Belum ada produk"
            description="Produk untuk kategori ini belum tersedia."
          />
        ) : (
          <div className="product-grid">
            {products.map((product) => (
              <ProductCard key={product.id} product={product} />
            ))}
          </div>
        )}
      </section>
    </div>
  )
}
