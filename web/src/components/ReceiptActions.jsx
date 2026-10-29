import { useState } from 'react'
import {
  PAPER_OPTIONS,
  buildReceipt,
  downloadImage,
  printReceipt,
  readPaper,
  savePaper,
  shareReceipt,
  whatsappUrl,
} from '../lib/receipt.js'
import { CloseIcon } from './Icons.jsx'

/**
 * Aksi struk di detail pesanan: cetak ke printer thermal (58/80 mm) atau kirim
 * struk sebagai gambar JPG lewat WhatsApp.
 */
export default function ReceiptActions({ order, seller }) {
  const [paper, setPaper] = useState(readPaper)
  const [image, setImage] = useState(null)
  const [busy, setBusy] = useState(false)
  const [note, setNote] = useState('')

  const receipt = () => buildReceipt(order, { seller, paper })

  const changePaper = (event) => {
    const value = event.target.value
    setPaper(value)
    savePaper(value)
  }

  const handlePrint = () => {
    setNote('')
    if (!printReceipt(receipt())) {
      setNote('Izinkan pop-up di browser ini untuk membuka jendela cetak struk.')
    }
  }

  const handleShare = async () => {
    setBusy(true)
    setNote('')
    try {
      const result = await shareReceipt(receipt(), order)
      if (result.status === 'manual') setImage(result.image)
      else if (result.status === 'shared') setNote('Struk dibagikan lewat aplikasi yang Anda pilih.')
    } catch (error) {
      setNote('Gagal membuat gambar struk. Silakan coba lagi.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="receipt">
      <div className="receipt__head">
        <span className="field__label">Struk pesanan</span>
        <select
          className="receipt__paper"
          value={paper}
          onChange={changePaper}
          aria-label="Ukuran kertas printer"
        >
          {PAPER_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      </div>

      <div className="receipt__actions">
        <button type="button" className="btn btn--outline btn--sm" onClick={handlePrint}>
          Cetak struk
        </button>
        <button
          type="button"
          className="btn btn--primary btn--sm"
          onClick={handleShare}
          disabled={busy}
        >
          {busy ? 'Menyiapkan…' : 'Kirim WhatsApp (JPG)'}
        </button>
      </div>

      {note ? <p className="note note--muted">{note}</p> : null}

      {image ? (
        <div className="overlay overlay--stacked" onClick={() => setImage(null)}>
          <div
            className="sheet"
            role="dialog"
            aria-label="Struk WhatsApp"
            onClick={(event) => event.stopPropagation()}
          >
            <div className="sheet__grip" />
            <div className="sheet__head">
              <h2 className="sheet__title">Struk WhatsApp (JPG)</h2>
              <button
                type="button"
                className="icon-btn"
                onClick={() => setImage(null)}
                aria-label="Tutup"
              >
                <CloseIcon />
              </button>
            </div>
            <img
              className="receipt__preview"
              src={image.dataUrl}
              alt="Pratinjau struk pesanan"
            />
            <div className="button-row">
              <button
                type="button"
                className="btn btn--outline btn--sm"
                onClick={() => downloadImage(image)}
              >
                Unduh JPG
              </button>
              <button
                type="button"
                className="btn btn--primary btn--sm"
                onClick={() =>
                  window.open(whatsappUrl(receipt(), order), '_blank', 'noopener')
                }
              >
                Buka WhatsApp
              </button>
            </div>
            <p className="note note--muted">
              Lampirkan gambar struk yang baru diunduh di chat WhatsApp pembeli.
            </p>
          </div>
        </div>
      ) : null}
    </div>
  )
}
