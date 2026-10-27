import { useEffect } from 'react'
import { categoryLabel, dateTime, rupiah } from '../lib/format.js'
import CopyButton from './CopyButton.jsx'
import StatusBadge from './StatusBadge.jsx'
import { CloseIcon } from './Icons.jsx'

export default function OrderDetailSheet({ order, onClose }) {
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
        aria-label="Detail pesanan"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="sheet__grip" />
        <div className="sheet__head">
          <h2 className="sheet__title">Detail Pesanan</h2>
          <button type="button" className="icon-btn" onClick={onClose} aria-label="Tutup">
            <CloseIcon />
          </button>
        </div>

        <div className="detail-order">
          <span className="detail-order__label">Nomor pesanan</span>
          <div className="detail-order__number">
            <span>{order.orderNumber}</span>
            <CopyButton value={order.orderNumber} />
          </div>
        </div>

        <div className="rows">
          <div className="row">
            <span className="row__label">Produk</span>
            <span className="row__value">{order.product?.name}</span>
          </div>
          <div className="row">
            <span className="row__label">Kategori</span>
            <span className="row__value">{categoryLabel(order.product?.category)}</span>
          </div>
          <div className="row">
            <span className="row__label">Nomor tujuan</span>
            <span className="row__value">{order.customerNumber}</span>
          </div>
          <div className="row">
            <span className="row__label">Waktu</span>
            <span className="row__value">{dateTime(order.createdAt)}</span>
          </div>
          <div className="row">
            <span className="row__label">Status</span>
            <span className="row__value">
              <StatusBadge status={order.status} />
            </span>
          </div>
          <div className="row row--total">
            <span className="row__label">Total bayar</span>
            <span className="row__value">{rupiah(order.amount)}</span>
          </div>
        </div>

        <p className="note note--muted">
          Pesanan ini memakai produk DEMO. Tidak ada pengiriman pulsa/token nyata.
        </p>
      </div>
    </div>
  )
}
