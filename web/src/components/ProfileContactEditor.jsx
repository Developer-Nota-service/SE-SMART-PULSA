import { useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext.jsx'
import { api } from '../lib/api.js'

/**
 * Ubah email & nomor WhatsApp. Perubahan baru diterapkan setelah kode OTP yang
 * dikirim ke nomor WhatsApp BARU dikonfirmasi, jadi nomor tetap terverifikasi.
 */
export default function ProfileContactEditor({ user, onDone, onCancel }) {
  const { refresh } = useAuth()
  const [form, setForm] = useState({ email: user?.email ?? '', phone: user?.phone ?? '' })
  const [verification, setVerification] = useState(null)
  const [code, setCode] = useState('')
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState('')
  const [busy, setBusy] = useState(false)
  const [seconds, setSeconds] = useState(0)

  useEffect(() => {
    if (seconds <= 0) return undefined
    const timer = setTimeout(() => setSeconds((value) => value - 1), 1000)
    return () => clearTimeout(timer)
  }, [seconds])

  async function requestCode(event) {
    event.preventDefault()
    setBusy(true)
    setErrors({})
    setMessage('')
    try {
      const data = await api.requestContactChange({
        email: form.email.trim(),
        phone: form.phone.replace(/\D/g, ''),
      })
      setVerification(data.verification)
      setSeconds(data.verification?.resendAfterSeconds ?? 0)
      setCode('')
    } catch (error) {
      setErrors(error.errors || {})
      setMessage(error.message)
    } finally {
      setBusy(false)
    }
  }

  async function confirmCode(event) {
    event.preventDefault()
    setBusy(true)
    setErrors({})
    setMessage('')
    try {
      await api.verifyContactChange(code)
      await refresh()
      onDone()
    } catch (error) {
      setErrors(error.errors || {})
      setMessage(error.message)
    } finally {
      setBusy(false)
    }
  }

  if (verification) {
    return (
      <form className="contact-editor" onSubmit={confirmCode} noValidate>
        <p className="section-hint">
          {verification.devCode
            ? `Mode sandbox: kode OTP Anda ${verification.devCode}.`
            : `Kode 6 angka dikirim ke WhatsApp ${verification.phoneMasked}.`}
        </p>
        <label className="field">
          <span className="field__label">Kode OTP dari WhatsApp baru</span>
          <input
            className="input otp__code"
            inputMode="numeric"
            maxLength={6}
            value={code}
            onChange={(event) => {
              setCode(event.target.value.replace(/\D/g, '').slice(0, 6))
              setErrors({})
              setMessage('')
            }}
            placeholder="······"
          />
          {errors.code ? <span className="field__error">{errors.code[0]}</span> : null}
        </label>

        {message ? (
          <p className="form-message" role="alert">
            {message}
          </p>
        ) : null}

        <button
          type="submit"
          className="btn btn--primary btn--block"
          disabled={busy || code.length !== 6}
        >
          {busy ? 'Memverifikasi…' : 'Simpan kontak baru'}
        </button>

        <div className="contact-editor__actions">
          <button
            type="button"
            className="btn btn--ghost btn--sm"
            onClick={requestCode}
            disabled={busy || (seconds > 0 && !verification.devCode)}
          >
            {seconds > 0 && !verification.devCode ? `Kirim ulang (${seconds}s)` : 'Kirim ulang kode'}
          </button>
          <button
            type="button"
            className="btn btn--ghost btn--sm"
            onClick={() => {
              setVerification(null)
              setMessage('')
              setErrors({})
            }}
          >
            Ubah data
          </button>
        </div>
      </form>
    )
  }

  return (
    <form className="contact-editor" onSubmit={requestCode} noValidate>
      <label className="field">
        <span className="field__label">Email</span>
        <input
          className="input"
          type="email"
          inputMode="email"
          value={form.email}
          onChange={(event) => {
            setForm((current) => ({ ...current, email: event.target.value }))
            setErrors({})
            setMessage('')
          }}
          autoComplete="email"
        />
        {errors.email ? <span className="field__error">{errors.email[0]}</span> : null}
      </label>

      <label className="field">
        <span className="field__label">Nomor WhatsApp</span>
        <input
          className="input"
          inputMode="numeric"
          value={form.phone}
          onChange={(event) => {
            setForm((current) => ({ ...current, phone: event.target.value }))
            setErrors({})
            setMessage('')
          }}
          autoComplete="tel"
          placeholder="08xxxxxxxxxx"
        />
        {errors.phone ? <span className="field__error">{errors.phone[0]}</span> : null}
        <span className="field__hint">
          Kode OTP dikirim ke nomor baru. Nomor lama tetap aktif sampai kode dikonfirmasi.
        </span>
      </label>

      {message ? (
        <p className="form-message" role="alert">
          {message}
        </p>
      ) : null}

      <button type="submit" className="btn btn--primary btn--block" disabled={busy}>
        {busy ? 'Mengirim kode…' : 'Kirim kode OTP'}
      </button>

      <div className="contact-editor__actions">
        <button type="button" className="btn btn--ghost btn--sm" onClick={onCancel} disabled={busy}>
          Batal
        </button>
      </div>
    </form>
  )
}
