import { useState } from 'react'
import { CheckIcon, CopyIcon } from './Icons.jsx'

export default function CopyButton({ value, label = 'Salin' }) {
  const [copied, setCopied] = useState(false)

  async function copy() {
    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(value)
      } else {
        const area = document.createElement('textarea')
        area.value = value
        area.setAttribute('readonly', '')
        area.style.position = 'fixed'
        area.style.opacity = '0'
        document.body.appendChild(area)
        area.select()
        document.execCommand('copy')
        document.body.removeChild(area)
      }
      setCopied(true)
      setTimeout(() => setCopied(false), 1800)
    } catch (error) {
      setCopied(false)
    }
  }

  return (
    <button type="button" className="copy-btn" onClick={copy} aria-label={`${label} ${value}`}>
      {copied ? <CheckIcon /> : <CopyIcon />}
      <span>{copied ? 'Tersalin' : label}</span>
    </button>
  )
}
