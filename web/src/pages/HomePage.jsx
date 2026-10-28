import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext.jsx'
import BalanceCard from '../components/BalanceCard.jsx'
import CategoryGrid from '../components/CategoryGrid.jsx'
import TransactionItem from '../components/TransactionItem.jsx'
import { EmptyState, ErrorState, LoadingState } from '../components/StateViews.jsx'
import { api } from '../lib/api.js'

// Beranda hanya menampilkan kategori; daftar produk muncul di halaman kategori.
export default function HomePage() {
  const { user } = useAuth()
  const [transactions, setTransactions] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const txList = await api.transactions()
      setTransactions(txList.slice(0, 3))
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setLoading(false)
    }
  }, [])

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
        <CategoryGrid />
      </section>

      <section>
        <div className="section-head">
          <h2 className="section-title">Transaksi terbaru</h2>
          <Link to="/transaksi" className="section-link">
            Lihat semua
          </Link>
        </div>

        {loading ? (
          <LoadingState label="Memuat transaksi…" />
        ) : error ? (
          <ErrorState message={error} onRetry={load} />
        ) : transactions.length === 0 ? (
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
