export function LoadingState({ label = 'Memuat data…' }) {
  return (
    <div className="state" role="status">
      <span className="spinner" />
      <p>{label}</p>
    </div>
  )
}

export function EmptyState({ title, description, action }) {
  return (
    <div className="state state--empty">
      <p className="state__title">{title}</p>
      {description ? <p className="state__text">{description}</p> : null}
      {action}
    </div>
  )
}

export function ErrorState({ message, onRetry }) {
  return (
    <div className="state state--error" role="alert">
      <p className="state__title">Gagal memuat data</p>
      <p className="state__text">{message}</p>
      {onRetry ? (
        <button type="button" className="btn btn--outline" onClick={onRetry}>
          Coba lagi
        </button>
      ) : null}
    </div>
  )
}
