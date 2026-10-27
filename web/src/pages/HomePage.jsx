import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext.jsx'
import BalanceCard from '../components/BalanceCard.jsx'
import CategoryGrid from '../components/CategoryGrid.jsx'
import ProductCard from '../components/ProductCard.jsx'
import TransactionItem from '../components/TransactionItem.jsx'
import { EmptyState, ErrorState, LoadingState } from '../components/StateViews.jsx'
import { api } from '../lib/api.js'
import { categoryLabel } from '../lib/format.js'

export default function HomePage() {
  const { user } = useAuth()
  const [category, setCategory] = useState('pulsa')
  const [products, setProducts] = useState([])
  const [transactions, setTransactions] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const [productList, txList] = await Promise.all([
        api.products({ category }),
        api.transactions(),
      ])
      setProducts(productList)
      setTransactions(txList.slice(0, 3))
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setLoading(false)
    }
  }, [category])

  useEffect(() => {
    load()
  }, [load])

  return (
    <div className="stack">
      <BalanceCard account={user} />

      <section>
        <div className="section-head">
          <h2 className="section-title">Kategori</h2>
          <span className="section-hint">7 layanan</span>
        </div>
        <CategoryGrid value={category} onChange={setCategory} />
      </section>

      <section>
        <div className="section-head">
          <h2 className="section-title">Produk populer</h2>
          <span className="section-hint">{categoryLabel(category)}</span>
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

      <section>
        <div className="section-head">
          <h2 className="section-title">Transaksi terbaru</h2>
          <Link to="/transaksi" className="section-link">
            Lihat semua
          </Link>
        </div>

        {loading ? null : transactions.length === 0 ? (
          <EmptyState
            title="Belum ada transaksi"
            description="Transaksi yang Anda lakukan akan muncul di sini."
          />
        ) : (
          <div className="tx-list">
            {transactions.map((transaction) => (
              <TransactionItem key={transaction.id} transaction={transaction} />
            ))}
          </div>
        )}
      </section>
    </div>
  )
}
