import { statusMeta } from '../lib/format.js'

export default function StatusBadge({ status }) {
  const meta = statusMeta(status)
  return <span className={`status status--${meta.tone}`}>{meta.label}</span>
}
