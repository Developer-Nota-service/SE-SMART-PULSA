import { Link } from 'react-router-dom'
import { rupiah } from '../lib/format.js'

export default function ProductCard({ product }) {
  return (
    <Link to={`/beli/${product.id}`} className="product">
      <span className="product__top">
        <span className="product__operator">{product.operator}</span>
        {product.disturbed ? (
          <span className="badge badge--muted badge--xs">Gangguan</span>
        ) : null}
      </span>
      <span className="product__name">{product.name}</span>
      <span className="product__price">{rupiah(product.price)}</span>
    </Link>
  )
}
