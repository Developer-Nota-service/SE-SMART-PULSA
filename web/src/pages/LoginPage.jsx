import { useState } from 'react'
import { Navigate, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext.jsx'
import { ShieldIcon } from '../components/Icons.jsx'

const DEMO = { email: 'demo@sesmartpulsa.id', password: 'demo12345' }

export default function LoginPage() {
  const { user, login, register } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [mode, setMode] = useState('login')
  const [form, setForm] = useState({ name: '', email: '', phone: '', password: '' })
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState('')
  const [submitting, setSubmitting] = useState(false)

  if (user) return <Navigate to={location.state?.from || '/'} replace />

  const update = (field) => (event) => {
    setForm((current) => ({ ...current, [field]: event.target.value }))
    setErrors((current) => ({ ...current, [field]: undefined }))
    setMessage('')
  }

  async function submit(event) {
    event.preventDefault()
    setSubmitting(true)
    setMessage('')
    setErrors({})
    try {
      if (mode === 'login') {
        await login(form.email.trim(), form.password)
      } else {
        await register({
          name: form.name.trim(),
          email: form.email.trim(),
          phone: form.phone.replace(/\D/g, ''),
          password: form.password,
        })
      }
      navigate(location.state?.from || '/', { replace: true })
    } catch (error) {
      setErrors(error.errors || {})
      setMessage(error.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="auth">
      <div className="auth__hero">
        <span className="brand-mark brand-mark--lg">SE</span>
        <h1 className="auth__title">SE SMART PULSA</h1>
        <p className="auth__subtitle">
          Isi pulsa, paket data, token listrik, dan tagihan dalam satu aplikasi.
        </p>
      </div>

      <div className="auth__card">
        <div className="segmented" role="tablist" aria-label="Pilih mode">
          <button
            type="button"
            role="tab"
            aria-selected={mode === 'login'}
            className={`segmented__btn${mode === 'login' ? ' is-active' : ''}`}
            onClick={() => setMode('login')}
          >
            Masuk
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={mode === 'register'}
            className={`segmented__btn${mode === 'register' ? ' is-active' : ''}`}
            onClick={() => setMode('register')}
          >
            Daftar
          </button>
        </div>

        <form onSubmit={submit} noValidate>
          {mode === 'register' ? (
            <label className="field">
              <span className="field__label">Nama lengkap</span>
              <input
                className="input"
                value={form.name}
                onChange={update('name')}
                autoComplete="name"
                placeholder="Nama sesuai identitas"
              />
              {errors.name ? <span className="field__error">{errors.name[0]}</span> : null}
            </label>
          ) : null}

          <label className="field">
            <span className="field__label">Email</span>
            <input
              className="input"
              type="email"
              inputMode="email"
              value={form.email}
              onChange={update('email')}
              autoComplete="email"
              placeholder="nama@email.com"
            />
            {errors.email ? <span className="field__error">{errors.email[0]}</span> : null}
          </label>

          {mode === 'register' ? (
            <label className="field">
              <span className="field__label">Nomor HP</span>
              <input
                className="input"
                inputMode="numeric"
                value={form.phone}
                onChange={update('phone')}
                autoComplete="tel"
                placeholder="08xxxxxxxxxx"
              />
              {errors.phone ? <span className="field__error">{errors.phone[0]}</span> : null}
            </label>
          ) : null}

          <label className="field">
            <span className="field__label">Password</span>
            <input
              className="input"
              type="password"
              value={form.password}
              onChange={update('password')}
              autoComplete={mode === 'login' ? 'current-password' : 'new-password'}
              placeholder={mode === 'register' ? 'Minimal 8 karakter' : 'Password akun'}
            />
            {errors.password ? <span className="field__error">{errors.password[0]}</span> : null}
          </label>

          {message ? (
            <p className="form-message" role="alert">
              {message}
            </p>
          ) : null}

          <button type="submit" className="btn btn--primary btn--block" disabled={submitting}>
            {submitting ? 'Memproses…' : mode === 'login' ? 'Masuk' : 'Daftar sekarang'}
          </button>
        </form>

        <div className="demo-box">
          <span className="badge badge--demo badge--xs">DEMO</span>
          <p>
            Akun demo: <strong>{DEMO.email}</strong> · password <strong>{DEMO.password}</strong>
          </p>
          <button
            type="button"
            className="btn btn--outline btn--sm"
            onClick={() => {
              setMode('login')
              setForm({ name: '', email: DEMO.email, phone: '', password: DEMO.password })
              setMessage('')
              setErrors({})
            }}
          >
            Isi akun demo
          </button>
        </div>

        <p className="auth__note">
          <ShieldIcon /> Autentikasi tahap 1 memakai backend demo lokal. Verifikasi akhir tetap di
          server.
        </p>
      </div>
    </div>
  )
}
