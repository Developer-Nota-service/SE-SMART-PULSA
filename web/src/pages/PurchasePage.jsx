import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext.jsx'
import CopyButton from '../components/CopyButton.jsx'
import PaymentChannelPicker from '../components/PaymentChannelPicker.jsx'
import PaymentOptions from '../components/PaymentOptions.jsx'
import ProductDetails from '../components/ProductDetails.jsx'
import PurchaseConfirmSheet from '../components/PurchaseConfirmSheet.jsx'
import { ErrorState, LoadingState } from '../components/StateViews.jsx'
import { CheckIcon } from '../components/Icons.jsx'
import { api } from '../lib/api.js'
import { feeFor } from '../lib/payments.js'
import {
  categoryLabel,
  GAME_INPUT_HINT,
  isVoucherCategory,
  rupiah,
  targetLabel,
  VOUCHER_INJECT_NOTE,
} from '../lib/format.js'

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
  const [confirming, setConfirming] = useState(false)
  const [result, setResult] = useState(null)
  // Kode promo diperiksa server sebelum bayar supaya potongan yang terlihat
  // sama dengan yang nanti ditagih (server menghitung ulang saat transaksi).
  const [promoCode, setPromoCode] = useState('')
  const [promo, setPromo] = useState(null)
  const [promoError, setPromoError] = useState('')
  const [checkingPromo, setCheckingPromo] = useState(false)
  // Akun pribadi memilih metode Midtrans karena biaya layanannya berbeda.
  const [methods, setMethods] = useState([])
  const [channel, setChannel] = useState('')
  const idempotencyKey = useRef(newIdempotencyKey())

  // Voucher inject tidak dikirim ke email: nomor voucher kosong pembeli itulah
  // tujuan transaksi yang diteruskan ke Digiflazz.
  const voucherInject = product?.category === 'voucher_inject'

  const load = useCallback(async () => {
    setLoading(true)
    setLoadError('')
    // Produk berganti: buang hasil/promo produk sebelumnya.
    setResult(null)
    setPromo(null)
    setPromoCode('')
    setPromoError('')
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

  useEffect(() => {
    if (!isPersonal) return undefined
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
  }, [isPersonal])

  // Validasi dulu, lalu tampilkan konfirmasi; transaksi baru dikirim setelah
  // pembeli menyetujui peringatan kesalahan pengisian nomor.
  function submit(event) {
    event.preventDefault()
    setFieldError('')
    setSubmitError('')

    if (voucherInject) {
      if (!/^\d{8,20}$/.test(customerNumber)) {
        setFieldError('Nomor voucher kosong wajib diisi 8-20 digit angka.')
        return
      }
    } else if (product.category === 'game') {
      // Digiflazz meminta ID game / username, kadang gabungan ID + Zone.
      if (!/^[A-Za-z0-9|.()_#\[\] -]{3,60}$/.test(customerNumber)) {
        setFieldError('ID game harus 3-60 karakter sesuai petunjuk produk.')
        return
      }
    } else if (isVoucherCategory(product.category)) {
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(customerNumber)) {
        setFieldError('Email penerima voucher tidak valid.')
        return
      }
    } else if (!/^\d{6,16}$/.test(customerNumber)) {
      setFieldError('Nomor tujuan harus 6-16 digit angka.')
      return
    }

    if (isPersonal && !channel) {
      setSubmitError('Pilih metode pembayaran terlebih dahulu.')
      return
    }

    setConfirming(true)
  }

  async function applyPromo() {
    const code = promoCode.trim()
    if (!code || !product) return

    setCheckingPromo(true)
    setPromoError('')
    try {
      const data = await api.validatePromo({ code, productId: product.id })
      setPromo(data)
      setPromoCode(data.code)
    } catch (requestError) {
      setPromo(null)
      setPromoError(requestError.errors?.promoCode?.[0] || requestError.message)
    } finally {
      setCheckingPromo(false)
    }
  }

  function removePromo() {
    setPromo(null)
    setPromoCode('')
    setPromoError('')
  }

  async function purchase() {
    setConfirming(false)
    if (product.disturbed) return

    const payload = { productId: product.id, customerNumber, promoCode: promo?.code || undefined }
    if (isPersonal) payload.method = channel

    setSubmitting(true)
    try {
      if (isPersonal) {
        const data = await api.directPurchase(payload)

        if (!data.payment?.redirectUrl) {
          setSubmitError('Tautan pembayaran Midtrans tidak tersedia.')
          return
        }

        window.location.assign(data.payment.redirectUrl)
        return
      }

      const data = await api.purchase(payload, idempotencyKey.current)
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
            {result.transaction.voucherNumber ? (
              <div className="row">
                <span className="row__label">Voucher kosong di-inject</span>
                <span className="row__value">{result.transaction.voucherNumber}</span>
              </div>
            ) : null}
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
  const gameTarget = product.category === 'game'
  const fieldLabel = voucherInject
    ? 'Nomor voucher kosong yang akan di-inject'
    : targetLabel(product.category)
  const fieldPlaceholder = voucherInject
    ? 'Contoh: 1234567890123456'
    : emailTarget
      ? 'nama@email.com'
      : gameTarget
        ? 'Contoh: 12345678 atau 12345678|Asia'
        : 'Contoh: 081234567890'
  const fieldHint = voucherInject
    ? 'Isi nomor yang tercetak pada voucher kosong Telkomsel Anda (8-20 digit). Nomor ini yang dikirim ke supplier untuk di-inject.'
    : gameTarget
      ? GAME_INPUT_HINT
      : `Pastikan ${emailTarget ? 'email' : 'nomor'} benar. Transaksi yang sudah diproses tidak dapat dibatalkan.`
  // Server mengirim `price` sesuai tipe akun (reseller atau pribadi).
  const unitPrice = product.price
  const discount = promo?.discount || 0
  const payable = promo?.total ?? unitPrice
  // Biaya layanan Midtrans ikut ditagihkan ke pembeli; nominal final dihitung server.
  const selectedMethod = methods.find((item) => item.code === channel) || null
  const fee = isPersonal ? feeFor(payable, selectedMethod) : 0
  const total = payable + fee
  const priceLabel = isPersonal ? 'harga publik' : 'harga reseller'
  const method = isPersonal ? 'midtrans' : 'balance'
  const insufficient = !isPersonal && user ? user.balance < payable : false
  // Harga supplier melewati harga max admin: server menolak pembelian, jadi
  // tombol bayar dimatikan dan pembeli melihat keterangan gangguan.
  const disturbed = Boolean(product.disturbed)

  return (
    <div className="stack">
      <button type="button" className="back-link" onClick={() => navigate(-1)}>
        ← Kembali
      </button>

      <section className="card">
        <h2 className="section-title">{product.name}</h2>
        <p className="section-hint">
          {product.operator} · {categoryLabel(product.category)}
        </p>

        {disturbed ? (
          <p className="form-message" role="alert">
            Produk sedang gangguan: harga supplier melebihi harga max yang ditetapkan. Produk ini
            tidak bisa dibeli sekarang — silakan pilih produk lain atau coba lagi nanti.
          </p>
        ) : null}

        <div className="price-row">
          <span className="price-row__label">Harga</span>
          <span className="price-row__value">{rupiah(product.price)}</span>
        </div>

        {promo ? (
          <>
            <div className="info-row">
              <span className="info-row__label">Promo {promo.code}</span>
              <span className="info-row__value">−{rupiah(discount)}</span>
            </div>
            <div className="info-row">
              <span className="info-row__label">Total setelah promo</span>
              <span className="info-row__value">{rupiah(payable)}</span>
            </div>
          </>
        ) : null}

        <ProductDetails product={product} />

        <form onSubmit={submit} noValidate>
          {voucherInject ? <p className="category-note">{VOUCHER_INJECT_NOTE}</p> : null}

          <label className="field">
            <span className="field__label">{fieldLabel}</span>
            <input
              className="input"
              type={emailTarget ? 'email' : 'text'}
              inputMode={emailTarget ? 'email' : gameTarget ? 'text' : 'numeric'}
              autoComplete={emailTarget ? 'email' : 'tel'}
              value={customerNumber}
              onChange={(event) => {
                const raw = event.target.value
                const value = emailTarget
                  ? raw.trim().slice(0, 100)
                  : gameTarget
                    ? raw.replace(/[^A-Za-z0-9|.()_#\[\] -]/g, '').slice(0, 60)
                    : raw.replace(/\D/g, '').slice(0, voucherInject ? 20 : 16)
                setCustomerNumber(value)
                setFieldError('')
                setSubmitError('')
              }}
              placeholder={fieldPlaceholder}
            />
            <span className="field__hint">{fieldHint}</span>
            {fieldError ? <span className="field__error">{fieldError}</span> : null}
          </label>

          {voucherInject ? (
            <p className="category-note category-note--warn">
              Periksa kembali nomor voucher kosong. Kesalahan pengetikan nomor bukan tanggung
              jawab kami dan transaksi yang sudah diproses tidak dapat dibatalkan.
            </p>
          ) : null}

          <div className="field">
            <span className="field__label">Kode promo (opsional)</span>
            <div className="promo-code-row">
              <input
                className="input"
                value={promoCode}
                autoComplete="off"
                onChange={(event) => {
                  setPromoCode(
                    event.target.value
                      .toUpperCase()
                      .replace(/[^A-Z0-9 ]/g, '')
                      .slice(0, 24),
                  )
                  setPromoError('')
                }}
                placeholder="Contoh: HEMAT10"
              />
              {promo ? (
                <button type="button" className="btn btn--ghost btn--sm" onClick={removePromo}>
                  Hapus
                </button>
              ) : (
                <button
                  type="button"
                  className="btn btn--outline btn--sm"
                  onClick={applyPromo}
                  disabled={checkingPromo || promoCode.trim() === ''}
                >
                  {checkingPromo ? 'Memeriksa…' : 'Terapkan'}
                </button>
              )}
            </div>
            {promoError ? <span className="field__error">{promoError}</span> : null}
            {promo ? (
              <span className="field__hint">
                {promo.title || promo.code} · potongan {promo.discountPercent}% (
                {rupiah(promo.discount)})
              </span>
            ) : (
              <span className="field__hint">
                Punya kode promo? Masukkan di sini sebelum menekan tombol bayar.
              </span>
            )}
          </div>

          {isPersonal ? null : (
            <div className="info-row">
              <span className="info-row__label">Saldo Anda</span>
              <span className="info-row__value">{user ? rupiah(user.balance) : '—'}</span>
            </div>
          )}

          {isPersonal ? (
            <>
              <PaymentChannelPicker
                methods={methods}
                value={channel}
                amount={payable}
                onChange={setChannel}
              />

              {selectedMethod ? (
                <div className="rows">
                  <div className="row">
                    <span className="row__label">Harga {priceLabel}</span>
                    <span className="row__value">{rupiah(payable)}</span>
                  </div>
                  <div className="row">
                    <span className="row__label">Biaya layanan {selectedMethod.label}</span>
                    <span className="row__value">{fee > 0 ? rupiah(fee) : 'Gratis'}</span>
                  </div>
                  <div className="row row--total">
                    <span className="row__label">Total dibayar</span>
                    <span className="row__value">{rupiah(total)}</span>
                  </div>
                </div>
              ) : null}
            </>
          ) : (
            <PaymentOptions
              method={method}
              price={payable}
              priceLabel={priceLabel}
              balance={user?.balance ?? 0}
            />
          )}

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
              disabled={submitting || insufficient || disturbed}
            >
              {submitting
                ? 'Memproses…'
                : disturbed
                  ? 'Produk sedang gangguan'
                  : insufficient
                    ? 'Saldo tidak cukup'
                    : method === 'midtrans'
                      ? `Bayar ${rupiah(total)}`
                      : `Konfirmasi ${rupiah(payable)}`}
            </button>
          </div>
        </form>
      </section>

      <p className="note note--muted">
        Harga mengikuti tipe akun Anda ({priceLabel}) dan ditentukan oleh server. Halaman ini hanya
        mengirim {voucherInject ? 'nomor voucher kosong' : 'nomor tujuan'}.
      </p>

      {confirming ? (
        <PurchaseConfirmSheet
          product={product}
          customerNumber={customerNumber}
          voucherInject={voucherInject}
          amount={payable}
          fee={fee}
          channelLabel={isPersonal ? selectedMethod?.label : ''}
          promo={promo}
          methodLabel={
            isPersonal
              ? `Midtrans · ${selectedMethod?.label ?? 'pilih metode'}`
              : 'Saldo'
          }
          onConfirm={purchase}
          onClose={() => setConfirming(false)}
        />
      ) : null}
    </div>
  )
}
