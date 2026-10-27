import { useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext.jsx'
import { api } from '../lib/api.js'
import { ShieldIcon } from './Icons.jsx'

// Ringkasan status pengiriman kode dari server.
function describe(verification) {
  if (!verification) {
    return 'Jika nomor ini terdaftar, kode verifikasi WhatsApp akan dikirim ke nomor tersebut.'
  }

  if (verification.devCode) {
    return `Fonnte belum terhubung. Mode sandbox: kode OTP Anda ${verification.devCode}.`
  }

  if (verification.status === 'failed') {
    return 'Kode verifikasi gagal dikirim ke WhatsApp. Periksa nomor Anda lalu minta kode baru.'
  }

  if (verification.status === 'cooldown') {
    return `Kode sebelumnya baru dikirim. Tunggu ${verification.resendAfterSeconds} detik untuk minta kode baru.`
  }

  const minutes = Math.max(1, Math.round((verification.expiresInSeconds || 300) / 60))
  return `Kode 6 angka dikirim ke WhatsApp ${verification.targetMasked}. Berlaku ${minutes} menit.`
}

export default function PasswordReset({ onCancel }) {
  const { refresh } = useAuth()
  const [step, setStep] = useState('phone')
  const [phone, setPhone] = useState('')
  const [code, setCode] = useState('')
  const [verification, setVerification] = useState(null)
  const [reset, setReset] = useState(null)
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [seconds, setSeconds] = useState(0)

  useEffect(() => {
    if (seconds <= 0) return undefined
    const timer = setTimeout(() => setSeconds((value) => value - 1), 1000)
    return () => clearTimeout(timer)
  }, [seconds])

  const digits = phone.replace(/\D/g, '')

  async function requestCode(event) {
    event?.preventDefault()
    setSubmitting(true)
    setError('')
    try {
      const data = await api.requestPasswordReset(digits)
      setVerification(data?.verification ?? null)
      setNotice(data?.message || describe(data?.verification))
      setSeconds(data?.verification?.resendAfterSeconds ?? 0)
      setCode('')
      setStep('code')
    } catch (failure) {
      setError(failure.errors?.phone?.[0] || failure.message || 'Kode gagal dikirim.')
    } finally {
      setSubmitting(false)
    }
  }

  async function verifyCode(event) {
    event.preventDefault()
    setSubmitting(true)
    setError('')
    try {
      const data = await api.verifyPasswordReset(digits, code)
      setReset(data)
      setNotice('')
      setStep('password')
    } catch (failure) {
      setError(failure.errors?.code?.[0] || failure.message || 'Kode verifikasi salah.')
    } finally {
      setSubmitting(false)
    }
  }

  async function savePassword(event) {
    event.preventDefault()
    setError('')
    if (password.length < 8) {
      setError('Password minimal 8 karakter.')
      return
    }
    if (password !== confirm) {
      setError('Konfirmasi password belum sama.')
      return
    }
    setSubmitting(true)
    try {
      await api.resetPassword(reset.resetToken, password)
      // Sesi baru sudah aktif: halaman login otomatis berpindah ke beranda.
      await refresh()
    } catch (failure) {
      setError(failure.errors?.password?.[0] || failure.message || 'Password gagal diganti.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="otp">
      <span className="otp__icon">
        <ShieldIcon />
      </span>
      <h2 className="otp__title">Lupa sandi</h2>

      {step === 'phone' ? (
        <>
          <p className="otp__text">
            Masukkan nomor WhatsApp yang terdaftar. Kami kirim kode untuk menampilkan email akun
            dan mengatur sandi baru.
          </p>
          <form onSubmit={requestCode} noValidate>
            <label className="field">
              <span className="field__label">Nomor WhatsApp</span>
              <input
                className="input"
                inputMode="numeric"
                autoComplete="tel"
                value={phone}
                onChange={(event) => {
                  setPhone(event.target.value.replace(/\D/g, ''))
                  setError('')
                }}
                placeholder="08xxxxxxxxxx"
              />
              {error ? <span className="field__error">{error}</span> : null}
            </label>
            <button
              type="submit"
              className="btn btn--primary btn--block"
              disabled={submitting || digits.length < 9}
            >
              {submitting ? 'Mengirim…' : 'Kirim kode WhatsApp'}
            </button>
          </form>
        </>
      ) : null}

      {step === 'code' ? (
        <>
          <p className="otp__text">{notice || describe(verification)}</p>
          <form onSubmit={verifyCode} noValidate>
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
              {submitting ? 'Memeriksa…' : 'Verifikasi kode'}
            </button>
          </form>
          <div className="otp__actions">
            <button
              type="button"
              className="btn btn--ghost btn--sm"
              onClick={requestCode}
              disabled={submitting || (seconds > 0 && verification?.devCode === null)}
            >
              {seconds > 0 && verification?.devCode === null ? `Kirim ulang (${seconds}s)` : 'Kirim ulang kode'}
            </button>
            <button
              type="button"
              className="btn btn--ghost btn--sm"
              onClick={() => {
                setStep('phone')
                setNotice('')
                setError('')
              }}
            >
              Ubah nomor
            </button>
          </div>
        </>
      ) : null}

      {step === 'password' && reset ? (
        <>
          <p className="otp__text">Nomor WhatsApp terverifikasi. Berikut email akun Anda:</p>
          <p className="reset__email">{reset.email}</p>
          <form onSubmit={savePassword} noValidate>
            <label className="field">
              <span className="field__label">Password baru</span>
              <input
                className="input"
                type="password"
                autoComplete="new-password"
                value={password}
                onChange={(event) => {
                  setPassword(event.target.value)
                  setError('')
                }}
                placeholder="Minimal 8 karakter"
              />
            </label>
            <label className="field">
              <span className="field__label">Ulangi password baru</span>
              <input
                className="input"
                type="password"
                autoComplete="new-password"
                value={confirm}
                onChange={(event) => {
                  setConfirm(event.target.value)
                  setError('')
                }}
                placeholder="Ulangi password"
              />
            </label>
            {error ? (
              <p className="form-message" role="alert">
                {error}
              </p>
            ) : null}
            <button type="submit" className="btn btn--primary btn--block" disabled={submitting}>
              {submitting ? 'Menyimpan…' : 'Simpan sandi & masuk'}
            </button>
          </form>
        </>
      ) : null}

      {step !== 'code' ? (
        <div className="otp__actions">
          <button type="button" className="btn btn--ghost btn--sm" onClick={onCancel}>
            Kembali ke masuk
          </button>
        </div>
      ) : null}
    </div>
  )
}
