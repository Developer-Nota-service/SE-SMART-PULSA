import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext.jsx'
import BalanceCard from '../components/BalanceCard.jsx'
import DepositForm from '../components/DepositForm.jsx'
import StatusBadge from '../components/StatusBadge.jsx'
import { EmptyState, ErrorState, LoadingState } from '../components/StateViews.jsx'
import { ArrowInIcon, ArrowOutIcon } from '../components/Icons.jsx'
import { api } from '../lib/api.js'
import { dateTime, rupiah } from '../lib/format.js'
import usePaymentSync from '../lib/usePaymentSync.js'

export default function BalancePage() {
  const { user } = useAuth()
  const isPersonal = user?.accountType === 'personal'
  const [deposits, setDeposits] = useState([])
  const [entries, setEntries] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const [depositList, entryList] = await Promise.all([api.deposits(), api.balanceEntries()])
      setDeposits(depositList)
      setEntries(entryList)
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    load()
  }, [load])

  // Setelah kembali dari Midtrans, pastikan deposit yang sudah lunas tercatat.
  usePaymentSync(load)

  return (
    <div className="stack">
      <BalanceCard account={user} compact />

      {isPersonal ? (
        <section className="card">
          <h2 className="section-title">Akun pribadi tanpa saldo</h2>
          <p className="section-hint">
            Semua transaksi akun pribadi dibayar langsung lewat Midtrans dengan harga publik, jadi
            saldo tidak diperlukan. Isi saldo hanya tersedia untuk akun reseller.
          </p>
        </section>
      ) : (
        <DepositForm onCreated={load} />
      )}

      <section>
        <div className="section-head">
          <h2 className="section-title">Riwayat deposit</h2>
          <span className="section-hint">{deposits.length} pesanan</span>
        </div>

        {loading ? (
          <LoadingState label="Memuat deposit…" />
        ) : error ? (
          <ErrorState message={error} onRetry={load} />
        ) : deposits.length === 0 ? (
          <EmptyState title="Belum ada deposit" description="Buat pesanan deposit pertama Anda." />
        ) : (
          <div className="tx-list">
            {deposits.map((deposit) => (
              <div key={deposit.id} className="tx">
                <span className="tx__icon tx__icon--deposit">
                  <ArrowInIcon />
                </span>
                <span className="tx__body">
                  <span className="tx__name">Deposit saldo</span>
                  <span className="tx__meta">{deposit.orderNumber}</span>
                  <span className="tx__time">{dateTime(deposit.createdAt)}</span>
                </span>
                <span className="tx__right">
                  <span className="tx__amount">{rupiah(deposit.amount)}</span>
                  <StatusBadge status={deposit.status} />
                </span>
              </div>
            ))}
          </div>
        )}
      </section>

      <section>
        <div className="section-head">
          <h2 className="section-title">Riwayat saldo</h2>
          <span className="section-hint">{entries.length} catatan</span>
        </div>

        {loading ? (
          <LoadingState label="Memuat mutasi saldo…" />
        ) : entries.length === 0 ? (
          <EmptyState
            title="Belum ada mutasi saldo"
            description="Penambahan dan pemakaian saldo akan tercatat di sini."
          />
        ) : (
          <div className="tx-list">
            {entries.map((entry) => (
              <div key={entry.id} className="tx">
                <span className={`tx__icon tx__icon--${entry.direction}`}>
                  {entry.direction === 'credit' ? <ArrowInIcon /> : <ArrowOutIcon />}
                </span>
                <span className="tx__body">
                  <span className="tx__name">{entry.description}</span>
                  <span className="tx__meta">{entry.reference}</span>
                  <span className="tx__time">{dateTime(entry.createdAt)}</span>
                </span>
                <span className="tx__right">
                  <span
                    className={`tx__amount${entry.direction === 'credit' ? ' tx__amount--in' : ''}`}
                  >
                    {entry.direction === 'credit' ? '+' : '−'}
                    {rupiah(entry.amount)}
                  </span>
                  <span className="tx__meta">Saldo {rupiah(entry.balanceAfter)}</span>
                </span>
              </div>
            ))}
          </div>
        )}
      </section>
    </div>
  )
}
