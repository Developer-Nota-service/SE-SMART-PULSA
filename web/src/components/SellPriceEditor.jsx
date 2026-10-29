import { useEffect, useState } from 'react'
import { api } from '../lib/api.js'
import { rupiah } from '../lib/format.js'

/**
 * Harga jual yang tampil di struk milik reseller. Pembeli di konter hanya
 * melihat harga ini, bukan total bayar (harga modal) di aplikasi.
 */
export default function SellPriceEditor({ order, onChange }) {
  const [value, setValue] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    setValue(order.sellPrice === null || order.sellPrice === undefined ? '' : String(order.sellPrice))
    setError('')
    setSaved(false)
  }, [order.id, order.sellPrice])

  const save = async () => {
    setBusy(true)
    setError('')
    setSaved(false)
    try {
      const updated = await api.updateSellPrice(order.id, value === '' ? null : Number(value))
      if (onChange) onChange(updated)
      setSaved(true)
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="sell-price">
      <div className="sell-price__head">
        <span className="field__label">Harga jual di struk</span>
        {order.sellPrice !== null && order.sellPrice !== undefined ? (
          <span className="sell-price__badge">Harga sendiri</span>
        ) : null}
      </div>

      <div className="sell-price__row">
        <input
          className="input"
          type="number"
          inputMode="numeric"
          min="0"
          placeholder={String(order.amount)}
          value={value}
          onChange={(event) => {
            setValue(event.target.value.replace(/\D/g, ''))
            setSaved(false)
          }}
          aria-label="Harga jual yang dicetak di struk"
        />
        <button type="button" className="btn btn--outline btn--sm" onClick={save} disabled={busy}>
          {busy ? 'Menyimpan…' : 'Simpan'}
        </button>
      </div>

      {error ? <p className="field__error">{error}</p> : null}
      {saved ? <p className="note">Harga jual tersimpan dan dipakai di struk.</p> : null}

      <p className="note note--muted">
        Struk (cetak/WhatsApp) menampilkan harga ini, bukan total bayar {rupiah(order.amount)}.
        Kosongkan lalu simpan untuk kembali memakai total bayar.
      </p>
    </div>
  )
}
