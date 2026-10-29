import { useState } from 'react'
import { useAuth } from '../auth/AuthContext.jsx'
import { api } from '../lib/api.js'

const OPTIONS = [
  {
    key: 'reseller',
    title: 'Reseller',
    meta: 'Harga khusus · bayar pakai saldo',
    detail: 'Isi saldo dulu, lalu transaksi dipotong dari saldo dengan harga reseller.',
  },
  {
    key: 'personal',
    title: 'Pribadi',
    meta: 'Harga publik · bayar via Midtrans',
    detail: 'Bayar langsung via Midtrans. Saldo hanya menampung pengembalian dana dan bisa ditarik.',
  },
]

/**
 * Pindah tipe akun tanpa daftar ulang. Harga produk, metode bayar, dan seluruh
 * fitur akun mengikuti tipe yang dipilih (dihitung server).
 */
export default function AccountTypeSwitcher({ user }) {
  const { refresh } = useAuth()
  const current = user?.accountType ?? 'reseller'
  const [selected, setSelected] = useState(current)
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')

  async function save() {
    setBusy(true)
    setError('')
    setMessage('')
    try {
      const data = await api.updateAccountType(selected)
      await refresh()
      setMessage(data?.message || 'Tipe akun diperbarui.')
    } catch (failure) {
      setError(failure.message || 'Tipe akun gagal diubah.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="card">
      <div className="section-head">
        <h2 className="section-title">Tipe akun</h2>
        <span className="section-hint">Harga &amp; metode bayar ikut tipe</span>
      </div>

      <div className="pay-options" role="radiogroup" aria-label="Tipe akun">
        {OPTIONS.map((option) => (
          <label
            key={option.key}
            className={`pay-option${selected === option.key ? ' is-active' : ''}`}
          >
            <input
              type="radio"
              name="accountType"
              value={option.key}
              checked={selected === option.key}
              onChange={() => {
                setSelected(option.key)
                setMessage('')
                setError('')
              }}
            />
            <span className="pay-option__body">
              <span className="pay-option__title">
                {option.title}
                {current === option.key ? <span className="badge badge--xs badge--ok">Aktif</span> : null}
              </span>
              <span className="pay-option__meta">{option.meta}</span>
              <span className="pay-option__meta">{option.detail}</span>
            </span>
          </label>
        ))}
      </div>

      {selected !== current ? (
        <p className="section-hint">
          {selected === 'personal'
            ? 'Setelah pindah: harga jadi harga publik dan isi saldo dinonaktifkan. Saldo dari pengembalian dana tetap bisa ditarik; selesaikan deposit pending dulu.'
            : 'Setelah pindah: harga jadi harga reseller dan pembayaran memakai saldo (isi saldo diaktifkan).'}
        </p>
      ) : null}

      {error ? (
        <p className="form-message" role="alert">
          {error}
        </p>
      ) : null}
      {message ? <p className="form-message form-message--ok">{message}</p> : null}

      <button
        type="button"
        className="btn btn--primary btn--block"
        onClick={save}
        disabled={busy || selected === current}
      >
        {busy
          ? 'Menyimpan…'
          : `Ganti ke akun ${selected === 'personal' ? 'pribadi' : 'reseller'}`}
      </button>
    </section>
  )
}
