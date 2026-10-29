import { useEffect, useState } from 'react'
import CopyButton from './CopyButton.jsx'
import PaymentChannelPicker from './PaymentChannelPicker.jsx'
import StatusBadge from './StatusBadge.jsx'
import { api } from '../lib/api.js'
import { rupiah } from '../lib/format.js'
import { feeFor } from '../lib/payments.js'

const MIN = 10000
const MAX = 2000000
const PRESETS = [20000, 50000, 100000, 250000, 500000]

/**
 * Form isi saldo — hanya dirender untuk akun reseller (akun pribadi membayar
 * langsung lewat Midtrans dan tidak memakai saldo).
 */
export default function DepositForm({ onCreated }) {
  const [amount, setAmount] = useState('50000')
  const [fieldError, setFieldError] = useState('')
  const [submitError, setSubmitError] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [created, setCreated] = useState(null)
  // Metode Midtrans menentukan biaya layanan; tagihan = nominal + biaya.
  const [methods, setMethods] = useState([])
  const [channel, setChannel] = useState('')

  const numericAmount = Number(String(amount).replace(/\D/g, '')) || 0
  const selectedMethod = methods.find((method) => method.code === channel) || null
  const fee = feeFor(numericAmount, selectedMethod)
  const total = numericAmount + fee

  useEffect(() => {
    let active = true
    api
      .paymentMethods()
      .then((data) => {
        if (!active) return
        const list = data?.methods || []
        setMethods(list)
        setChannel((current) => current || list[0]?.code || '')
      })
      .catch(() => {})
    return () => {
      active = false
    }
  }, [])

  async function submit(event) {
    event.preventDefault()
    setFieldError('')
    setSubmitError('')

    if (numericAmount < MIN || numericAmount > MAX) {
      setFieldError(`Nominal deposit harus antara ${rupiah(MIN)} dan ${rupiah(MAX)}.`)
      return
    }

    if (!channel) {
      setFieldError('Pilih metode pembayaran terlebih dahulu.')
      return
    }

    setSubmitting(true)
    try {
      const deposit = await api.createDeposit(numericAmount, channel)
      setCreated(deposit)
      setAmount('50000')
      onCreated?.()
    } catch (requestError) {
      if (requestError.errors?.amount) setFieldError(requestError.errors.amount[0])
      else setSubmitError(requestError.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
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

        <PaymentChannelPicker
          methods={methods}
          value={channel}
          amount={numericAmount}
          onChange={(code) => {
            setChannel(code)
            setCreated(null)
          }}
        />

        {numericAmount > 0 && selectedMethod ? (
          <div className="rows">
            <div className="row">
              <span className="row__label">Nominal saldo</span>
              <span className="row__value">{rupiah(numericAmount)}</span>
            </div>
            <div className="row">
              <span className="row__label">Biaya layanan</span>
              <span className="row__value">{fee > 0 ? rupiah(fee) : 'Gratis'}</span>
            </div>
            <div className="row row--total">
              <span className="row__label">Total dibayar</span>
              <span className="row__value">{rupiah(total)}</span>
            </div>
          </div>
        ) : null}

        {submitError ? (
          <p className="form-message" role="alert">
            {submitError}
          </p>
        ) : null}

        <button type="submit" className="btn btn--primary btn--block" disabled={submitting}>
          {submitting
            ? 'Membuat pesanan…'
            : total > numericAmount
              ? `Buat pesanan · ${rupiah(total)}`
              : 'Buat pesanan deposit'}
        </button>
      </form>

      {created ? (
        <div className="deposit-result">
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
              <span className="row__label">Nominal saldo</span>
              <span className="row__value">{rupiah(created.amount)}</span>
            </div>
            {created.feeAmount > 0 ? (
              <div className="row">
                <span className="row__label">Biaya layanan</span>
                <span className="row__value">{rupiah(created.feeAmount)}</span>
              </div>
            ) : null}
            {created.feeAmount > 0 ? (
              <div className="row">
                <span className="row__label">Total dibayar</span>
                <span className="row__value">{rupiah(created.total)}</span>
              </div>
            ) : null}
            <div className="row">
              <span className="row__label">Status</span>
              <span className="row__value">
                <StatusBadge status={created.status} />
              </span>
            </div>
          </div>
          {created.payment?.redirectUrl ? (
            <a className="btn btn--primary btn--block" href={created.payment.redirectUrl}>
              Lanjut ke pembayaran Midtrans
            </a>
          ) : null}
          <p className="note note--muted">
            {created.payment?.redirectUrl
              ? 'Saldo bertambah otomatis setelah pembayaran terkonfirmasi Midtrans — buka halaman Saldo bila saldo belum berubah.'
              : `${created.paymentError || 'Pembayaran Midtrans belum dikonfigurasi.'} Buat pesanan baru setelah konfigurasi selesai.`}
          </p>
        </div>
      ) : null}
    </section>
  )
}
