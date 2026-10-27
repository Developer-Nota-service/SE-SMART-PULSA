import { useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext.jsx'
import { api } from '../lib/api.js'

const LABELS = {
  email: { title: 'Email', hint: 'Kode 6 angka dikirim ke email' },
  phone: { title: 'Nomor WhatsApp', hint: 'Kode 6 angka dikirim ke WhatsApp' },
}

/**
 * Ubah email & nomor WhatsApp. Setiap kontak yang berubah diverifikasi sendiri:
 * kode untuk email baru dikirim ke email baru, kode untuk nomor baru dikirim ke
 * WhatsApp nomor baru. Kontak baru hanya diterapkan setelah kode-nya benar,
 * sehingga keduanya tetap terverifikasi.
 */
export default function ProfileContactEditor({ user, onDone, onCancel }) {
  const { refresh } = useAuth()
  const [form, setForm] = useState({ email: user?.email ?? '', phone: user?.phone ?? '' })
  const [pending, setPending] = useState(null)
  const [codes, setCodes] = useState({})
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState('')
  const [busy, setBusy] = useState('')
  const [seconds, setSeconds] = useState(0)

  useEffect(() => {
    if (seconds <= 0) return undefined
    const timer = setTimeout(() => setSeconds((value) => value - 1), 1000)
    return () => clearTimeout(timer)
  }, [seconds])

  async function requestCode(event) {
    event.preventDefault()
    setBusy('request')
    setErrors({})
    setMessage('')
    try {
      const data = await api.requestContactChange({
        email: form.email.trim(),
        phone: form.phone.replace(/\D/g, ''),
      })
      const next = data.pending || {}
      setPending(next)
      setCodes({})
      setSeconds(
        Object.values(next).reduce((longest, item) => Math.max(longest, item.resendAfterSeconds ?? 0), 0),
      )
    } catch (error) {
      setErrors(error.errors || {})
      setMessage(error.message)
    } finally {
      setBusy('')
    }
  }

  async function confirmCode(event, channel) {
    event.preventDefault()
    setBusy(channel)
    setErrors({})
    setMessage('')
    try {
      const data = await api.verifyContactChange(channel, codes[channel])
      await refresh()
      const remaining = { ...pending }
      delete remaining[channel]
      if (Object.keys(remaining).length === 0) {
        onDone()
        return
      }
      setPending(remaining)
      setMessage(data.message || '')
    } catch (error) {
      setErrors({ [channel]: error.errors?.code?.[0] || error.message })
      setMessage(error.message)
    } finally {
      setBusy('')
    }
  }

  if (pending) {
    const channels = Object.keys(pending)
    const resendBlocked = seconds > 0 && channels.every((channel) => !pending[channel].devCode)

    return (
      <div className="contact-editor">
        {channels.map((channel) => {
          const verification = pending[channel]
          const info = LABELS[channel]

          return (
            <form key={channel} onSubmit={(event) => confirmCode(event, channel)} noValidate>
              <p className="section-hint">
                {verification.devCode
                  ? `Mode sandbox: kode OTP untuk ${info.title.toLowerCase()} baru adalah ${verification.devCode}.`
                  : `${info.hint} ${verification.targetMasked}.`}
              </p>

              <label className="field">
                <span className="field__label">Kode OTP dari {info.title.toLowerCase()} baru</span>
                <input
                  className="input otp__code"
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  maxLength={6}
                  value={codes[channel] ?? ''}
                  onChange={(event) => {
                    const value = event.target.value.replace(/\D/g, '').slice(0, 6)
                    setCodes((current) => ({ ...current, [channel]: value }))
                    setErrors({})
                    setMessage('')
                  }}
                  placeholder="······"
                />
                {errors[channel] ? <span className="field__error">{errors[channel]}</span> : null}
              </label>

              <button
                type="submit"
                className="btn btn--primary btn--block"
                disabled={busy !== '' || (codes[channel] ?? '').length !== 6}
              >
                {busy === channel ? 'Memverifikasi…' : `Simpan ${info.title.toLowerCase()}`}
              </button>
            </form>
          )
        })}

        {message ? (
          <p className="form-message" role="alert">
            {message}
          </p>
        ) : null}

        <div className="contact-editor__actions">
          <button
            type="button"
            className="btn btn--ghost btn--sm"
            onClick={requestCode}
            disabled={busy !== '' || resendBlocked}
          >
            {resendBlocked ? `Kirim ulang (${seconds}s)` : 'Kirim ulang kode'}
          </button>
          <button
            type="button"
            className="btn btn--ghost btn--sm"
            onClick={() => {
              setPending(null)
              setCodes({})
              setErrors({})
              setMessage('')
            }}
            disabled={busy !== ''}
          >
            Ubah data
          </button>
        </div>
      </div>
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
        <span className="field__hint">
          Kode OTP dikirim ke email baru. Email lama tetap aktif sampai kode dikonfirmasi.
        </span>
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

      <button type="submit" className="btn btn--primary btn--block" disabled={busy !== ''}>
        {busy === 'request' ? 'Mengirim kode…' : 'Kirim kode verifikasi'}
      </button>

      <div className="contact-editor__actions">
        <button type="button" className="btn btn--ghost btn--sm" onClick={onCancel} disabled={busy !== ''}>
          Batal
        </button>
      </div>
    </form>
  )
}
