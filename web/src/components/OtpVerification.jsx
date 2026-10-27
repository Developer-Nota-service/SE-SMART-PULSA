import { useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext.jsx'
import { ShieldIcon } from './Icons.jsx'

// Ringkasan status pengiriman kode dari server.
function describe(verification) {
  if (!verification) return ''

  if (verification.devCode) {
    return `Fonnte belum terhubung. Mode sandbox: kode OTP Anda ${verification.devCode}.`
  }

  if (verification.status === 'not_configured') {
    return 'Kode OTP belum bisa dikirim: gateway WhatsApp (Fonnte) belum dikonfigurasi.'
  }

  if (verification.status === 'cooldown') {
    return `Kode sebelumnya baru dikirim. Tunggu ${verification.resendAfterSeconds} detik untuk minta kode baru.`
  }

  const minutes = Math.max(1, Math.round((verification.expiresInSeconds || 300) / 60))
  return `Kode 6 angka dikirim ke WhatsApp ${verification.phoneMasked}. Berlaku ${minutes} menit.`
}

export default function OtpVerification({ email, initial, onCancel }) {
  const { verifyOtp, resendOtp } = useAuth()
  const [verification, setVerification] = useState(initial)
  const [code, setCode] = useState('')
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [resending, setResending] = useState(false)
  const [seconds, setSeconds] = useState(initial?.resendAfterSeconds ?? 0)

  useEffect(() => {
    if (seconds <= 0) return undefined
    const timer = setTimeout(() => setSeconds((value) => value - 1), 1000)
    return () => clearTimeout(timer)
  }, [seconds])

  async function submit(event) {
    event.preventDefault()
    setSubmitting(true)
    setError('')
    try {
      // Sesudah kode benar, AuthContext mengisi user dan halaman ini berpindah.
      await verifyOtp(email, code)
    } catch (failure) {
      setError(failure.errors?.code?.[0] || failure.message || 'Verifikasi gagal.')
    } finally {
      setSubmitting(false)
    }
  }

  async function resend() {
    setResending(true)
    setError('')
    try {
      const next = await resendOtp(email)
      setVerification(next)
      setSeconds(next.resendAfterSeconds ?? 0)
      setCode('')
    } catch (failure) {
      setError(failure.message || 'Kode gagal dikirim ulang.')
    } finally {
      setResending(false)
    }
  }

  return (
    <div className="otp">
      <span className="otp__icon">
        <ShieldIcon />
      </span>
      <h2 className="otp__title">Verifikasi akun</h2>
      <p className="otp__text">{describe(verification)}</p>
      <p className="otp__meta">Untuk email {email}</p>

      <form onSubmit={submit} noValidate>
        <label className="field">
          <span className="field__label">Kode OTP</span>
          <input
            className="input otp__code"
            inputMode="numeric"
            autoComplete="one-time-code"
            maxLength={6}
            value={code}
            onChange={(event) => {
              setCode(event.target.value.replace(/\D/g, '').slice(0, 6))
              setError('')
            }}
            placeholder="······"
          />
          <span className="field__hint">Kode hanya berlaku sekali dan tidak pernah diminta admin.</span>
        </label>

        {error ? (
          <p className="form-message" role="alert">
            {error}
          </p>
        ) : null}

        <button
          type="submit"
          className="btn btn--primary btn--block"
          disabled={submitting || code.length !== 6}
        >
          {submitting ? 'Memverifikasi…' : 'Verifikasi & masuk'}
        </button>
      </form>

      <div className="otp__actions">
        <button
          type="button"
          className="btn btn--ghost btn--sm"
          onClick={resend}
          disabled={resending || (seconds > 0 && verification?.devCode === null)}
        >
          {seconds > 0 && verification?.devCode === null ? `Kirim ulang (${seconds}s)` : 'Kirim ulang kode'}
        </button>
        <button type="button" className="btn btn--ghost btn--sm" onClick={onCancel}>
          Ubah data
        </button>
      </div>
    </div>
  )
}
