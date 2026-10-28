import { useEffect, useState } from 'react'
import StatusBadge from './StatusBadge.jsx'
import { api } from '../lib/api.js'
import { dateTime, rupiah } from '../lib/format.js'

const MIN = 100000
const STEP = 100000
const PRESETS = [100000, 200000, 500000, 1000000]

const isAccountNumber = (value) => /^\d{6,20}$/.test(value)

/**
 * Form tarik saldo untuk akun reseller. Nominal wajib kelipatan Rp100.000 dan
 * nama pemilik rekening diisi pengguna; admin memeriksanya sebelum dana dikirim.
 */
export default function WithdrawForm({ balance = 0, onCreated }) {
  const [channels, setChannels] = useState({ bank: [], ewallet: [], minAmount: MIN, stepAmount: STEP, processingDays: '1-7 hari kerja' })
  const [amount, setAmount] = useState('')
  const [channelType, setChannelType] = useState('bank')
  const [providerCode, setProviderCode] = useState('')
  const [accountNumber, setAccountNumber] = useState('')
  const [manualHolder, setManualHolder] = useState('')
  const [fieldError, setFieldError] = useState('')
  const [submitError, setSubmitError] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [created, setCreated] = useState(null)

  const numericAmount = Number(String(amount).replace(/\D/g, '')) || 0
  const providers = channels[channelType] || []

  useEffect(() => {
    api
      .withdrawalChannels()
      .then((data) => {
        setChannels(data)
        setProviderCode(data.bank?.[0]?.code || '')
      })
      .catch(() => {
        // Daftar tujuan gagal dimuat: form tetap tampil dengan pesan kosong.
      })
  }, [])

  function resetResult() {
    setCreated(null)
    setFieldError('')
    setSubmitError('')
  }

  function switchChannel(next) {
    resetResult()
    setChannelType(next)
    setProviderCode(channels[next]?.[0]?.code || '')
  }

  async function submit(event) {
    event.preventDefault()
    setFieldError('')
    setSubmitError('')

    if (numericAmount < MIN || numericAmount % STEP !== 0) {
      setFieldError(`Penarikan minimal ${rupiah(MIN)} dan harus kelipatan ${rupiah(STEP)}.`)
      return
    }

    if (numericAmount > Number(balance || 0)) {
      setFieldError('Saldo tidak cukup untuk penarikan ini.')
      return
    }

    if (!providerCode || !isAccountNumber(accountNumber)) {
      setFieldError('Pilih bank/e-wallet tujuan dan masukkan nomor rekening yang benar.')
      return
    }

    const accountHolder = manualHolder.trim()

    if (!accountHolder) {
      setFieldError('Masukkan nama pemilik rekening sesuai buku tabungan/aplikasi.')
      return
    }

    setSubmitting(true)
    try {
      const withdrawal = await api.createWithdrawal({
        amount: numericAmount,
        channelType,
        providerCode,
        accountNumber,
        accountHolder,
      })
      setCreated(withdrawal)
      setAmount('')
      setAccountNumber('')
      setManualHolder('')
      onCreated?.(withdrawal.balance)
    } catch (requestError) {
      const errors = requestError.errors || {}
      const firstError = Object.values(errors)[0]?.[0]
      if (firstError) setFieldError(firstError)
      else setSubmitError(requestError.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <section className="card">
      <div className="section-head">
        <h2 className="section-title">Tarik saldo</h2>
        <span className="section-hint">Min {rupiah(channels.minAmount || MIN)}</span>
      </div>

      <form onSubmit={submit} noValidate>
        <label className="field">
          <span className="field__label">Nominal penarikan</span>
          <input
            className="input input--amount"
            inputMode="numeric"
            value={amount}
            onChange={(event) => {
              setAmount(event.target.value.replace(/\D/g, '').slice(0, 9))
              resetResult()
            }}
            placeholder="100000"
          />
          <span className="field__hint">
            {numericAmount > 0 ? rupiah(numericAmount) : 'Kelipatan Rp100.000'} · saldo{' '}
            {rupiah(balance)}
          </span>
        </label>

        <div className="chips">
          {PRESETS.filter((preset) => preset <= Number(balance || 0)).map((preset) => (
            <button
              key={preset}
              type="button"
              className={`chip${numericAmount === preset ? ' is-active' : ''}`}
              onClick={() => {
                setAmount(String(preset))
                resetResult()
              }}
            >
              {rupiah(preset)}
            </button>
          ))}
        </div>

        <div className="field">
          <span className="field__label">Tujuan penarikan</span>
          <div className="transfer-tabs">
            <button
              type="button"
              className={`transfer-tab${channelType === 'bank' ? ' is-active' : ''}`}
              onClick={() => switchChannel('bank')}
            >
              Rekening bank
            </button>
            <button
              type="button"
              className={`transfer-tab${channelType === 'ewallet' ? ' is-active' : ''}`}
              onClick={() => switchChannel('ewallet')}
            >
              E-wallet
            </button>
          </div>
        </div>

        <label className="field">
          <span className="field__label">{channelType === 'bank' ? 'Bank tujuan' : 'E-wallet tujuan'}</span>
          <select
            className="input"
            value={providerCode}
            onChange={(event) => {
              setProviderCode(event.target.value)
              resetResult()
            }}
          >
            {providers.map((provider) => (
              <option key={provider.code} value={provider.code}>
                {provider.name}
              </option>
            ))}
          </select>
        </label>

        <label className="field">
          <span className="field__label">Nomor rekening / nomor e-wallet</span>
          <input
            className="input"
            inputMode="numeric"
            value={accountNumber}
            onChange={(event) => {
              setAccountNumber(event.target.value.replace(/\D/g, '').slice(0, 20))
              resetResult()
            }}
            placeholder={channelType === 'bank' ? 'Contoh: 1234567890' : 'Contoh: 081234567890'}
          />
          <span className="field__hint">
            Periksa kembali nomor rekening/e-wallet sebelum mengajukan.{' '}
            <a
              className="hint-link"
              href="https://cekrekening.id/"
              target="_blank"
              rel="noopener noreferrer"
            >
              Cek nomor di cekrekening.id
            </a>
          </span>
        </label>

        <label className="field">
          <span className="field__label">Nama pemilik rekening</span>
          <input
            className="input"
            value={manualHolder}
            onChange={(event) => {
              setManualHolder(event.target.value)
              resetResult()
            }}
            placeholder="Nama sesuai buku tabungan"
          />
          <span className="field__hint">Admin mencocokkan nama dan nomor rekening sebelum dana dikirim.</span>
        </label>

        <ul className="terms-list">
          <li>Minimal {rupiah(MIN)}, hanya kelipatan {rupiah(STEP)}.</li>
          <li>Dana diproses {channels.processingDays || '1-7 hari kerja'} ke rekening/e-wallet tujuan.</li>
          <li>Saldo langsung terpotong saat pengajuan; pengajuan yang sedang diproses belum bisa dibatalkan.</li>
          <li>Nama pemilik rekening harus sama dengan nama akun Anda.</li>
        </ul>

        {fieldError ? <span className="field__error">{fieldError}</span> : null}

        {submitError ? (
          <p className="form-message" role="alert">
            {submitError}
          </p>
        ) : null}

        <button type="submit" className="btn btn--primary btn--block" disabled={submitting}>
          {submitting ? 'Mengirim pengajuan…' : 'Ajukan penarikan'}
        </button>
      </form>

      {created ? (
        <div className="deposit-result">
          <p className="deposit-result__title">Pengajuan penarikan dibuat</p>
          <div className="rows">
            <div className="row">
              <span className="row__label">Nomor pengajuan</span>
              <span className="row__value">{created.reference}</span>
            </div>
            <div className="row">
              <span className="row__label">Nominal</span>
              <span className="row__value">{rupiah(created.amount)}</span>
            </div>
            <div className="row">
              <span className="row__label">Tujuan</span>
              <span className="row__value">
                {created.providerName} · {created.accountNumber}
              </span>
            </div>
            <div className="row">
              <span className="row__label">Status</span>
              <span className="row__value">
                <StatusBadge status={created.status} />
              </span>
            </div>
            <div className="row">
              <span className="row__label">Diajukan</span>
              <span className="row__value">{dateTime(created.createdAt)}</span>
            </div>
          </div>
          <p className="note note--muted">
            Dana dikirim ke {created.verifiedHolder || created.accountHolder} dalam{' '}
            {created.estimate}. Pantau status di riwayat penarikan.
          </p>
        </div>
      ) : null}
    </section>
  )
}
