import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext.jsx'
import BalanceCard from '../components/BalanceCard.jsx'
import CategoryGrid from '../components/CategoryGrid.jsx'
import HomeBanners from '../components/HomeBanners.jsx'
import HomeLeaderboard from '../components/HomeLeaderboard.jsx'
import HomePromos from '../components/HomePromos.jsx'
import TransactionItem from '../components/TransactionItem.jsx'
import { EmptyState, ErrorState, LoadingState } from '../components/StateViews.jsx'
import { api } from '../lib/api.js'
import { CATEGORIES } from '../lib/format.js'

// Beranda menampilkan konten yang diatur admin (banner, promo, peringkat),
// kategori, dan transaksi terbaru; daftar produk muncul di halaman kategori.
export default function HomePage() {
  const { user } = useAuth()
  const [dashboard, setDashboard] = useState(null)
  const [transactions, setTransactions] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const [content, txList] = await Promise.all([api.dashboard(), api.transactions()])
      setDashboard(content)
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
    <div className="stack home-page">
      <section
        className={`home-page__hero${dashboard?.banners?.length ? ' has-banner' : ''}`}
        aria-label="Layanan SE Smart Pulsa"
      >
        <div className="home-welcome">
          <span className="home-welcome__eyebrow">SE SMART PULSA · CEPAT · AMAN</span>
          <h1>
            Top Up &amp; Bayar Tagihan <span>Jadi Lebih Mudah.</span>
          </h1>
          <p>Semua kebutuhan digital Anda, praktis dalam satu tempat.</p>
          <Link to="/kategori/pulsa" className="btn home-welcome__action">
            Mulai Transaksi <span aria-hidden="true">→</span>
          </Link>
          <div className="home-welcome__proof" aria-label="Layanan terpercaya">
            <span>Transaksi aman</span>
            <span>Proses cepat</span>
            <span>Layanan 24 jam</span>
          </div>
        </div>
        <HomeBanners banners={dashboard?.banners} />
      </section>

      <BalanceCard account={user} />

      <section className="home-page__categories">
        <div className="section-head">
          <h2 className="section-title">Kategori</h2>
          <span className="section-hint">{CATEGORIES.length} layanan</span>
        </div>
        <CategoryGrid />
      </section>

      <HomePromos promos={dashboard?.promos} />

      <HomeLeaderboard leaderboard={dashboard?.leaderboard} />

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
