import { useEffect } from 'react'
import { PURCHASE_DISCLAIMER, rupiah, targetLabel } from '../lib/format.js'
import { CloseIcon } from './Icons.jsx'

/**
 * Konfirmasi terakhir sebelum transaksi dikirim. Pembeli melihat lagi tujuan
 * yang diketik dan peringatan bahwa kesalahan pengisian nomor bukan tanggung
 * jawab pengelola.
 */
export default function PurchaseConfirmSheet({
  product,
  customerNumber,
  voucherInject,
  amount,
  promo,
  methodLabel,
  onConfirm,
  onClose,
}) {
  useEffect(() => {
    const previous = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    const onKey = (event) => {
      if (event.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKey)
    return () => {
      document.body.style.overflow = previous
      window.removeEventListener('keydown', onKey)
    }
  }, [onClose])

  return (
    <div className="overlay" onClick={onClose}>
      <div
        className="sheet"
        role="dialog"
        aria-label="Konfirmasi pembelian"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="sheet__grip" />
        <div className="sheet__head">
          <h2 className="sheet__title">Konfirmasi Pembelian</h2>
          <button type="button" className="icon-btn" onClick={onClose} aria-label="Tutup">
            <CloseIcon />
          </button>
        </div>

        <div className="rows">
          <div className="row">
            <span className="row__label">Produk</span>
            <span className="row__value">{product.name}</span>
          </div>
          <div className="row">
            <span className="row__label">
              {voucherInject ? 'Nomor voucher kosong' : targetLabel(product.category)}
            </span>
            <span className="row__value">{customerNumber}</span>
          </div>
          {promo?.discount > 0 ? (
            <div className="row">
              <span className="row__label">Potongan promo {promo.code}</span>
              <span className="row__value">−{rupiah(promo.discount)}</span>
            </div>
          ) : null}
          <div className="row row--total">
            <span className="row__label">Total bayar</span>
            <span className="row__value">{rupiah(amount)}</span>
          </div>
        </div>

        <p className="category-note category-note--warn">{PURCHASE_DISCLAIMER}</p>
        <p className="note note--muted">Pembayaran: {methodLabel}.</p>

        <div className="button-row">
          <button type="button" className="btn btn--outline" onClick={onClose}>
            Periksa lagi
          </button>
          <button type="button" className="btn btn--primary" onClick={onConfirm}>
            Ya, lanjutkan
          </button>
        </div>
      </div>
    </div>
  )
}
