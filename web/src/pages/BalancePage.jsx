import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext.jsx'
import BalanceCard from '../components/BalanceCard.jsx'
import CopyButton from '../components/CopyButton.jsx'
import StatusBadge from '../components/StatusBadge.jsx'
import { EmptyState, ErrorState, LoadingState } from '../components/StateViews.jsx'
import { ArrowInIcon, ArrowOutIcon } from '../components/Icons.jsx'
import { api } from '../lib/api.js'
import { dateTime, rupiah } from '../lib/format.js'

const MIN = 10000
const MAX = 2000000
const PRESETS = [20000, 50000, 100000, 250000, 500000]

export default function BalancePage() {
  const { user } = useAuth()
  const [deposits, setDeposits] = useState([])
  const [entries, setEntries] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [amount, setAmount] = useState('50000')
  const [fieldError, setFieldError] = useState('')
  const [submitError, setSubmitError] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [created, setCreated] = useState(null)

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

  const numericAmount = Number(String(amount).replace(/\D/g, '')) || 0

  async function submit(event) {
    event.preventDefault()
    setFieldError('')
    setSubmitError('')

    if (numericAmount < MIN || numericAmount > MAX) {
      setFieldError(`Nominal deposit harus antara ${rupiah(MIN)} dan ${rupiah(MAX)}.`)
      return
    }

    setSubmitting(true)
    try {
      const deposit = await api.createDeposit(numericAmount)
      setCreated(deposit)
      setAmount('50000')
      load()
    } catch (requestError) {
      if (requestError.errors?.amount) setFieldError(requestError.errors.amount[0])
      else setSubmitError(requestError.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="stack">
      <BalanceCard account={user} compact />

      <section className="card">
        <div className="section-head">
          <h2 className="section-title">Isi saldo</h2>
          <span className="section-hint">Min {rupiah(MIN)}</span>
        </div>

        <form onSubmit={submit} noValidate>
          <label className="field">
            <span className="field__label">Nominal deposit</span>
            <input
              className="input input--amount"
              inputMode="numeric"
              value={amount}
              onChange={(event) => {
                setAmount(event.target.value.replace(/\D/g, '').slice(0, 7))
                setFieldError('')
                setCreated(null)
              }}
              placeholder="50000"
            />
            <span className="field__hint">
              {numericAmount > 0 ? rupiah(numericAmount) : 'Masukkan nominal'} · maksimal{' '}
              {rupiah(MAX)}
            </span>
            {fieldError ? <span className="field__error">{fieldError}</span> : null}
          </label>

          <div className="chips">
            {PRESETS.map((preset) => (
              <button
                key={preset}
                type="button"
                className={`chip${numericAmount === preset ? ' is-active' : ''}`}
                onClick={() => {
                  setAmount(String(preset))
                  setFieldError('')
                  setCreated(null)
                }}
              >
                {rupiah(preset)}
              </button>
            ))}
          </div>

          {submitError ? (
            <p className="form-message" role="alert">
              {submitError}
            </p>
          ) : null}

          <button type="submit" className="btn btn--primary btn--block" disabled={submitting}>
            {submitting ? 'Membuat pesanan…' : 'Buat pesanan deposit'}
          </button>
        </form>

        {created ? (
          <div className="deposit-result">
            <span className="badge badge--demo badge--xs">DEMO</span>
            <p className="deposit-result__title">Pesanan deposit dibuat</p>
            <div className="detail-order">
              <span className="detail-order__label">Nomor pesanan</span>
              <div className="detail-order__number">
                <span>{created.orderNumber}</span>
                <CopyButton value={created.orderNumber} />
              </div>
            </div>
            <div className="rows">
              <div className="row">
                <span className="row__label">Nominal</span>
                <span className="row__value">{rupiah(created.amount)}</span>
              </div>
              <div className="row">
                <span className="row__label">Status</span>
                <span className="row__value">
                  <StatusBadge status={created.status} />
                </span>
              </div>
            </div>
            <p className="note note--muted">
              Saldo belum bertambah. Pembayaran Midtrans belum diaktifkan pada tahap ini — saldo
              hanya dikreditkan setelah backend memverifikasi notifikasi pembayaran yang sah.
            </p>
          </div>
        ) : null}
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
