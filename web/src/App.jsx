import { useCallback, useEffect, useMemo, useState } from 'react'
import Header from './components/Header.jsx'
import BalanceCard from './components/BalanceCard.jsx'
import CategoryTabs from './components/CategoryTabs.jsx'
import OperatorChips from './components/OperatorChips.jsx'
import ProductGrid from './components/ProductGrid.jsx'
import PurchaseSheet from './components/PurchaseSheet.jsx'
import TopupSheet from './components/TopupSheet.jsx'
import TransactionList from './components/TransactionList.jsx'
import BottomNav from './components/BottomNav.jsx'
import Toast from './components/Toast.jsx'
import { getAccount, getProducts, getTransactions } from './api.js'

const CATEGORIES = [
  { key: 'pulsa', label: 'Pulsa' },
  { key: 'data', label: 'Paket Data' },
  { key: 'pln', label: 'Token PLN' },
]

const CATEGORY_TITLES = {
  pulsa: 'Pulsa semua operator',
  data: 'Paket data internet',
  pln: 'Token listrik PLN',
}

export default function App() {
  const [account, setAccount] = useState(null)
  const [products, setProducts] = useState([])
  const [transactions, setTransactions] = useState([])
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState('')
  const [tab, setTab] = useState('beli')
  const [category, setCategory] = useState('pulsa')
  const [operator, setOperator] = useState('')
  const [selectedProduct, setSelectedProduct] = useState(null)
  const [sheet, setSheet] = useState(null)
  const [toast, setToast] = useState('')

  const loadData = useCallback(async () => {
    try {
      const [accountData, productData, transactionData] = await Promise.all([
        getAccount(),
        getProducts(),
        getTransactions(),
      ])
      setAccount(accountData)
      setProducts(productData)
      setTransactions(transactionData)
      setLoadError('')
    } catch (error) {
      setLoadError(error.message)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    loadData()
  }, [loadData])

  const operators = useMemo(() => {
    const list = []
    for (const product of products) {
      if (product.category !== category) continue
      if (!list.includes(product.operator)) list.push(product.operator)
    }
    return list
  }, [products, category])

  const activeOperator = operators.includes(operator) ? operator : operators[0] || ''

  const visibleProducts = useMemo(
    () =>
      products.filter(
        (product) => product.category === category && product.operator === activeOperator,
      ),
    [products, category, activeOperator],
  )

  function handleSelect(product) {
    setSelectedProduct(product)
    setSheet('purchase')
  }

  function handlePaid(data) {
    setAccount((current) => (current ? { ...current, balance: data.balance } : current))
    setTransactions((current) => [
      {
        ...data.transaction,
        category: selectedProduct?.category,
        operator: selectedProduct?.operator,
      },
      ...current,
    ])
    setToast('Transaksi berhasil')
  }

  function handleToppedUp(updatedAccount) {
    setAccount(updatedAccount)
    setSheet(null)
    setToast('Top up berhasil, saldo sudah masuk')
  }

  return (
    <div className="app">
      <Header account={account} />

      <main className="content">
        <BalanceCard
          account={account}
          onTopUp={() => setSheet('topup')}
          onHistory={() => setTab('riwayat')}
        />

        {loadError ? <p className="state">{loadError}</p> : null}

        {tab === 'beli' ? (
          <>
            <CategoryTabs categories={CATEGORIES} value={category} onChange={setCategory} />
            <OperatorChips operators={operators} value={activeOperator} onChange={setOperator} />

            <div className="section__head">
              <h2 className="section__title">{CATEGORY_TITLES[category]}</h2>
              <span className="section__hint">{visibleProducts.length} produk</span>
            </div>

            {loading ? (
              <p className="state">Memuat produk…</p>
            ) : (
              <ProductGrid products={visibleProducts} onSelect={handleSelect} />
            )}
          </>
        ) : (
          <>
            <div className="section__head">
              <h2 className="section__title">Riwayat transaksi</h2>
              <span className="section__hint">{transactions.length} transaksi</span>
            </div>

            {loading ? (
              <p className="state">Memuat transaksi…</p>
            ) : (
              <TransactionList transactions={transactions} />
            )}
          </>
        )}
      </main>

      <BottomNav value={tab} onChange={setTab} />

      {sheet === 'purchase' && selectedProduct ? (
        <PurchaseSheet
          product={selectedProduct}
          balance={account?.balance ?? 0}
          onClose={() => {
            setSheet(null)
            setSelectedProduct(null)
          }}
          onPaid={handlePaid}
        />
      ) : null}

      {sheet === 'topup' ? (
        <TopupSheet onClose={() => setSheet(null)} onToppedUp={handleToppedUp} />
      ) : null}

      <Toast message={toast} onDone={() => setToast('')} />
    </div>
  )
}
