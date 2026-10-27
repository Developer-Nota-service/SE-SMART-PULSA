import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext.jsx'
import CopyButton from '../components/CopyButton.jsx'
import PaymentOptions from '../components/PaymentOptions.jsx'
import { ErrorState, LoadingState } from '../components/StateViews.jsx'
import { CheckIcon } from '../components/Icons.jsx'
import { api } from '../lib/api.js'
import { categoryLabel, rupiah, targetLabel } from '../lib/format.js'

function newIdempotencyKey() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID()
  return `key-${Date.now()}-${Math.random().toString(16).slice(2)}`
}

export default function PurchasePage() {
  const { productId } = useParams()
  const navigate = useNavigate()
  const { user, updateBalance } = useAuth()
  // Metode bayar mengikuti tipe akun: reseller memakai saldo, akun pribadi
  // membayar langsung lewat Midtrans.
  const isPersonal = user?.accountType === 'personal'

  const [product, setProduct] = useState(null)
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState('')
  const [customerNumber, setCustomerNumber] = useState('')
  const [fieldError, setFieldError] = useState('')
  const [submitError, setSubmitError] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [result, setResult] = useState(null)
  const idempotencyKey = useRef(newIdempotencyKey())

  const load = useCallback(async () => {
    setLoading(true)
    setLoadError('')
    try {
      setProduct(await api.product(productId))
    } catch (requestError) {
      setLoadError(requestError.message)
    } finally {
      setLoading(false)
    }
  }, [productId])

  useEffect(() => {
    load()
  }, [load])

  async function submit(event) {
    event.preventDefault()
    setFieldError('')
    setSubmitError('')

    if (product.category === 'voucher') {
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(customerNumber)) {
        setFieldError('Email penerima voucher tidak valid.')
        return
      }
    } else if (!/^\d{6,16}$/.test(customerNumber)) {
      setFieldError('Nomor tujuan harus 6-16 digit angka.')
      return
    }

    setSubmitting(true)
    try {
      if (isPersonal) {
        const data = await api.directPurchase({ productId: product.id, customerNumber })

        if (!data.payment?.redirectUrl) {
          setSubmitError('Tautan pembayaran Midtrans tidak tersedia.')
          return
        }

        window.location.assign(data.payment.redirectUrl)
        return
      }

      const data = await api.purchase(
        { productId: product.id, customerNumber },
        idempotencyKey.current,
      )
      setResult(data)
      if (typeof data.balance === 'number') updateBalance(data.balance)
    } catch (requestError) {
      if (requestError.errors?.customerNumber) setFieldError(requestError.errors.customerNumber[0])
      else setSubmitError(requestError.message)
    } finally {
      setSubmitting(false)
    }
  }

  if (loading) return <LoadingState label="Memuat produk…" />
  if (loadError) return <ErrorState message={loadError} onRetry={load} />

  if (result) {
    return (
      <div className="stack">
        <section className="card card--success">
          <span className="success-mark">
            <CheckIcon />
          </span>
          <h2 className="success-title">Transaksi berhasil</h2>
          <p className="success-text">
            {result.transaction.product?.name} untuk {result.transaction.customerNumber}
          </p>

          <div className="detail-order">
            <span className="detail-order__label">Nomor pesanan</span>
            <div className="detail-order__number">
              <span>{result.transaction.orderNumber}</span>
              <CopyButton value={result.transaction.orderNumber} />
            </div>
          </div>

          <div className="rows">
            <div className="row">
              <span className="row__label">Total bayar</span>
              <span className="row__value">{rupiah(result.transaction.amount)}</span>
            </div>
            {typeof result.balance === 'number' ? (
              <div className="row">
                <span className="row__label">Sisa saldo</span>
                <span className="row__value">{rupiah(result.balance)}</span>
              </div>
            ) : null}
          </div>

          <p className="note note--muted">
            Produk DEMO — tidak ada pengiriman pulsa/token nyata pada tahap ini.
          </p>

          <div className="button-row">
            <button type="button" className="btn btn--outline" onClick={() => navigate('/')}>
              Beranda
            </button>
            <button
              type="button"
              className="btn btn--primary"
              onClick={() => navigate('/transaksi')}
            >
              Lihat transaksi
            </button>
          </div>
        </section>
      </div>
    )
  }

  const emailTarget = product.category === 'voucher'
  // Server mengirim `price` sesuai tipe akun (reseller atau pribadi).
  const unitPrice = product.price
  const priceLabel = isPersonal ? 'harga publik' : 'harga reseller'
  const method = isPersonal ? 'midtrans' : 'balance'
  const insufficient = !isPersonal && user ? user.balance < unitPrice : false

  return (
    <div className="stack">
      <button type="button" className="back-link" onClick={() => navigate(-1)}>
        ← Kembali
      </button>

      <section className="card">
        <span className="badge badge--demo badge--xs">DEMO</span>
        <h2 className="section-title">{product.name}</h2>
        <p className="section-hint">
          {product.operator} · {categoryLabel(product.category)}
        </p>

        <div className="price-row">
          <span className="price-row__label">Harga</span>
          <span className="price-row__value">{rupiah(product.price)}</span>
        </div>

        <form onSubmit={submit} noValidate>
          <label className="field">
            <span className="field__label">{targetLabel(product.category)}</span>
            <input
              className="input"
              type={emailTarget ? 'email' : 'text'}
              inputMode={emailTarget ? 'email' : 'numeric'}
              autoComplete={emailTarget ? 'email' : 'tel'}
              value={customerNumber}
              onChange={(event) => {
                const value = emailTarget
                  ? event.target.value.trim().slice(0, 100)
                  : event.target.value.replace(/\D/g, '').slice(0, 16)
                setCustomerNumber(value)
                setFieldError('')
                setSubmitError('')
              }}
              placeholder={emailTarget ? 'nama@email.com' : 'Contoh: 081234567890'}
            />
            <span className="field__hint">
              Pastikan {emailTarget ? 'email' : 'nomor'} benar. Transaksi yang sudah diproses tidak
              dapat dibatalkan.
            </span>
            {fieldError ? <span className="field__error">{fieldError}</span> : null}
          </label>

          {isPersonal ? null : (
            <div className="info-row">
              <span className="info-row__label">Saldo Anda</span>
              <span className="info-row__value">{user ? rupiah(user.balance) : '—'}</span>
            </div>
          )}

          <PaymentOptions
            method={method}
            price={unitPrice}
            priceLabel={priceLabel}
            balance={user?.balance ?? 0}
          />

          {insufficient ? (
            <p className="form-message" role="alert">
              Saldo tidak cukup untuk {priceLabel}.{' '}
              <Link to="/saldo" className="inline-link">
                Isi saldo
              </Link>{' '}
              agar bisa bertransaksi dengan harga reseller.
            </p>
          ) : null}

          {submitError ? (
            <p className="form-message" role="alert">
              {submitError}
            </p>
          ) : null}

          <div className="button-row">
            <button type="button" className="btn btn--outline" onClick={() => navigate(-1)}>
              Batal
            </button>
            <button
              type="submit"
              className="btn btn--primary"
              disabled={submitting || insufficient}
            >
              {submitting
                ? 'Memproses…'
                : insufficient
                  ? 'Saldo tidak cukup'
                  : method === 'midtrans'
                    ? `Bayar ${rupiah(unitPrice)}`
                    : `Konfirmasi ${rupiah(unitPrice)}`}
            </button>
          </div>
        </form>
      </section>

      <p className="note note--muted">
        Harga mengikuti tipe akun Anda ({priceLabel}) dan ditentukan oleh server. Halaman ini hanya
        mengirim nomor tujuan.
      </p>
    </div>
  )
}
