import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext.jsx'
import BalanceCard from '../components/BalanceCard.jsx'
import DepositForm from '../components/DepositForm.jsx'
import WithdrawForm from '../components/WithdrawForm.jsx'
import StatusBadge from '../components/StatusBadge.jsx'
import { EmptyState, ErrorState, LoadingState } from '../components/StateViews.jsx'
import { ArrowInIcon, ArrowOutIcon } from '../components/Icons.jsx'
import { api } from '../lib/api.js'
import { dateTime, rupiah } from '../lib/format.js'
import usePaymentSync from '../lib/usePaymentSync.js'

export default function BalancePage() {
  const { user, updateBalance } = useAuth()
  const isPersonal = user?.accountType === 'personal'
  const [deposits, setDeposits] = useState([])
  const [withdrawals, setWithdrawals] = useState([])
  const [entries, setEntries] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const [depositList, withdrawalList, entryList] = await Promise.all([
        api.deposits(),
        api.withdrawals(),
        api.balanceEntries(),
      ])
      setDeposits(depositList)
      setWithdrawals(withdrawalList)
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
          <h2 className="section-title">Saldo pengembalian dana</h2>
          <p className="section-hint">
            Transaksi akun pribadi dibayar langsung lewat Midtrans. Bila transaksi gagal di
            supplier dan Midtrans tidak bisa mengembalikan dananya, nominalnya otomatis masuk ke
            saldo ini dan bisa ditarik ke rekening/e-wallet tujuan. Isi saldo hanya tersedia untuk
            akun reseller.
          </p>
        </section>
      ) : (
        <DepositForm onCreated={load} />
      )}

      <WithdrawForm balance={user?.balance} onCreated={(balance) => {
        updateBalance(balance)
        load()
      }} />

      <section>
        <div className="section-head">
          <h2 className="section-title">Riwayat penarikan</h2>
          <span className="section-hint">{withdrawals.length} pengajuan</span>
        </div>

        {loading ? (
          <LoadingState label="Memuat penarikan…" />
        ) : withdrawals.length === 0 ? (
          <EmptyState
            title="Belum ada penarikan"
            description="Pengajuan tarik saldo akan tercatat di sini."
          />
        ) : (
          <div className="tx-list">
            {withdrawals.map((withdrawal) => (
              <div key={withdrawal.id} className="tx">
                <span className="tx__icon tx__icon--debit">
                  <ArrowOutIcon />
                </span>
                <span className="tx__body">
                  <span className="tx__name">
                    Tarik saldo ke {withdrawal.providerName}
                  </span>
                  <span className="tx__meta">
                    {withdrawal.accountNumber} · {withdrawal.verifiedHolder || withdrawal.accountHolder}
                  </span>
                  <span className="tx__time">
                    {dateTime(withdrawal.createdAt)} · {withdrawal.reference}
                  </span>
                </span>
                <span className="tx__right">
                  <span className="tx__amount">−{rupiah(withdrawal.amount)}</span>
                  <StatusBadge status={withdrawal.status} />
                </span>
              </div>
            ))}
          </div>
        )}
      </section>

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
          <EmptyState
            title="Belum ada deposit"
            description={
              isPersonal
                ? 'Akun pribadi tidak membuat deposit; saldo hanya dari pengembalian dana.'
                : 'Buat pesanan deposit pertama Anda.'
            }
          />
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
