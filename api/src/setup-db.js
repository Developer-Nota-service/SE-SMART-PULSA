import { readFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import bcrypt from 'bcryptjs'
import { pool } from './db.js'

const schemaPath = fileURLToPath(new URL('./schema.sql', import.meta.url))

const CATEGORIES = ['pulsa', 'data', 'pln_token', 'pln_bill', 'ewallet', 'game', 'voucher']

const operators = [
  { key: 'telkomsel', label: 'Telkomsel', markup: 1500, dataOffset: 5000 },
  { key: 'indosat', label: 'Indosat', markup: 1200, dataOffset: 4000 },
  { key: 'xl', label: 'XL', markup: 1200, dataOffset: 3000 },
  { key: 'axis', label: 'Axis', markup: 1300, dataOffset: 2000 },
  { key: 'tri', label: 'Tri', markup: 1400, dataOffset: 1000 },
  { key: 'smartfren', label: 'Smartfren', markup: 1400, dataOffset: 0 },
]

const pulsaNominals = [5000, 10000, 20000, 25000, 50000, 100000]
const dataPackages = [
  { label: '2 GB / 30 hari', price: 25000 },
  { label: '5 GB / 30 hari', price: 45000 },
  { label: '10 GB / 30 hari', price: 75000 },
  { label: '25 GB / 30 hari', price: 125000 },
]
const plnTokenNominals = [20000, 50000, 100000, 200000, 500000]
const ewalletOperators = ['GoPay', 'OVO', 'DANA', 'ShopeePay']
const ewalletNominals = [10000, 25000, 50000, 100000]
const games = [
  { operator: 'Mobile Legends', items: [['86 Diamonds', 22000], ['172 Diamonds', 44000], ['257 Diamonds', 65000], ['706 Diamonds', 175000]] },
  { operator: 'Free Fire', items: [['70 Diamonds', 10000], ['140 Diamonds', 19000], ['355 Diamonds', 47000]] },
  { operator: 'PUBG Mobile', items: [['60 UC', 15000], ['325 UC', 75000]] },
]
const vouchers = [
  { operator: 'Google Play', name: 'Voucher Google Play Rp50.000', price: 52000 },
  { operator: 'Steam', name: 'Voucher Steam Wallet Rp60.000', price: 62000 },
  { operator: 'Netflix', name: 'Voucher Netflix 1 Bulan', price: 54000 },
  { operator: 'Spotify', name: 'Voucher Spotify Premium 1 Bulan', price: 55000 },
]

const thousands = (value) => String(value).replace(/\B(?=(\d{3})+(?!\d))/g, '.')

function buildProducts() {
  const products = []
  const push = (product) => products.push({ isDemo: true, sortOrder: 0, ...product })

  for (const operator of operators) {
    pulsaNominals.forEach((nominal, index) => {
      push({
        sku: `PULSA-${operator.key.toUpperCase()}-${nominal}`,
        category: 'pulsa',
        operator: operator.label,
        name: `Pulsa ${thousands(nominal)}`,
        price: nominal + operator.markup,
        sortOrder: index,
      })
    })

    dataPackages.forEach((pkg, index) => {
      push({
        sku: `DATA-${operator.key.toUpperCase()}-${index + 1}`,
        category: 'data',
        operator: operator.label,
        name: pkg.label,
        price: pkg.price + operator.dataOffset,
        sortOrder: index,
      })
    })
  }

  plnTokenNominals.forEach((nominal, index) => {
    push({
      sku: `PLN-TOKEN-${nominal}`,
      category: 'pln_token',
      operator: 'PLN',
      name: `Token Listrik ${thousands(nominal)}`,
      price: nominal + 1600,
      sortOrder: index,
    })
  })

  push({
    sku: 'PLN-BILL-ADMIN',
    category: 'pln_bill',
    operator: 'PLN',
    name: 'Tagihan Listrik PLN (biaya admin)',
    price: 2500,
  })

  let ewalletIndex = 0
  for (const operator of ewalletOperators) {
    for (const nominal of ewalletNominals) {
      push({
        sku: `EWALLET-${operator.toUpperCase()}-${nominal}`,
        category: 'ewallet',
        operator,
        name: `Saldo ${operator} ${thousands(nominal)}`,
        price: nominal + 2000,
        sortOrder: ewalletIndex++,
      })
    }
  }

  for (const game of games) {
    game.items.forEach(([name, price], index) => {
      push({
        sku: `GAME-${game.operator.toUpperCase().replace(/\s+/g, '')}-${index + 1}`,
        category: 'game',
        operator: game.operator,
        name,
        price,
        sortOrder: index,
      })
    })
  }

  vouchers.forEach((voucher, index) => {
    push({
      sku: `VOUCHER-${voucher.operator.toUpperCase().replace(/\s+/g, '')}`,
      category: 'voucher',
      operator: voucher.operator,
      name: voucher.name,
      price: voucher.price,
      sortOrder: index,
    })
  })

  return products
}

async function tableExists(name) {
  const { rowCount } = await pool.query(
    `SELECT 1 FROM information_schema.tables
     WHERE table_schema = current_schema() AND table_name = $1`,
    [name],
  )
  return rowCount > 0
}

async function seedUsers() {
  const accounts = [
    { name: 'Budi Santoso', email: 'demo@sesmartpulsa.id', phone: '081234567890', password: 'demo12345', role: 'user' },
    { name: 'Admin SE SMART', email: 'admin@sesmartpulsa.id', phone: '081200000000', password: 'admin12345', role: 'admin' },
  ]

  for (const account of accounts) {
    const passwordHash = await bcrypt.hash(account.password, 10)
    await pool.query(
      `INSERT INTO users (name, email, phone, password_hash, role)
       VALUES ($1, $2, $3, $4, $5)
       ON CONFLICT (email) DO NOTHING`,
      [account.name, account.email, account.phone, passwordHash, account.role],
    )
  }

  const { rows } = await pool.query(
    `SELECT id, email FROM users WHERE email IN ('demo@sesmartpulsa.id', 'admin@sesmartpulsa.id')`,
  )
  return {
    demoId: rows.find((row) => row.email === 'demo@sesmartpulsa.id')?.id,
    adminId: rows.find((row) => row.email === 'admin@sesmartpulsa.id')?.id,
  }
}

async function seedProducts() {
  for (const product of buildProducts()) {
    await pool.query(
      `INSERT INTO products (sku, category, operator, name, price, sort_order, is_demo)
       VALUES ($1, $2, $3, $4, $5, $6, $7)
       ON CONFLICT (sku) DO NOTHING`,
      [product.sku, product.category, product.operator, product.name, product.price, product.sortOrder, product.isDemo],
    )
  }
}

async function seedDemoHistory(userId) {
  const existing = await pool.query(
    'SELECT count(*)::int AS total FROM transactions WHERE user_id = $1',
    [userId],
  )
  if (existing.rows[0].total > 0) return

  const product = async (sku) =>
    (
      await pool.query('SELECT id, name, price FROM products WHERE sku = $1', [sku])
    ).rows[0]

  let balance = 0

  const moveBalance = async (direction, amount, { daysAgo, reference, description }) => {
    balance += direction === 'credit' ? amount : -amount
    await pool.query(`UPDATE users SET balance = balance + $1 WHERE id = $2`, [
      direction === 'credit' ? amount : -amount,
      userId,
    ])
    await pool.query(
      `INSERT INTO balance_entries
         (user_id, direction, amount, balance_after, reference, description, created_at)
       VALUES ($1, $2, $3, $4, $5, $6, now() - ($7 || ' days')::interval)`,
      [userId, direction, amount, balance, reference, description, daysAgo],
    )
  }

  const addDeposit = async (orderNumber, amount, status, daysAgo) => {
    await pool.query(
      `INSERT INTO deposits (user_id, order_number, amount, status, created_at, paid_at)
       VALUES ($1, $2, $3, $4, now() - ($5 || ' days')::interval,
               CASE WHEN $4 = 'paid' THEN now() - ($5 || ' days')::interval + interval '3 minutes' END)`,
      [userId, orderNumber, amount, status, daysAgo],
    )
    if (status === 'paid') {
      await moveBalance('credit', amount, {
        daysAgo,
        reference: orderNumber,
        description: 'Deposit saldo berhasil',
      })
    }
  }

  const addTransaction = async (productSku, orderNumber, customerNumber, status, daysAgo) => {
    const item = await product(productSku)
    await pool.query(
      `INSERT INTO transactions
         (user_id, product_id, order_number, customer_number, amount, status, created_at)
       VALUES ($1, $2, $3, $4, $5, $6, now() - ($7 || ' days')::interval)`,
      [userId, item.id, orderNumber, customerNumber, item.price, status, daysAgo],
    )
    if (status === 'success') {
      await moveBalance('debit', item.price, {
        daysAgo,
        reference: orderNumber,
        description: item.name,
      })
    }
  }

  await addDeposit('DEP-20261019-0001', 100000, 'paid', 8)
  await addDeposit('DEP-20261020-0007', 20000, 'expired', 7)
  await addTransaction('PULSA-TELKOMSEL-10000', 'TRX-20261022-1042', '081234567890', 'success', 5)
  await addTransaction('GAME-MOBILELEGENDS-1', 'TRX-20261023-2210', '123456789', 'failed', 4)
  await addTransaction('PLN-TOKEN-50000', 'TRX-20261024-0815', '51234567890', 'success', 3)
  await addDeposit('DEP-20261025-0042', 150000, 'paid', 2)
  await addTransaction('DATA-XL-2', 'TRX-20261026-1501', '081298765432', 'pending', 1)
  await addDeposit('DEP-20261027-0011', 50000, 'pending', 0)
}

async function seed() {
  // The very first sandbox demo used an `accounts` table; this schema is user based.
  if (await tableExists('accounts')) {
    await pool.query('DROP TABLE IF EXISTS transactions, accounts CASCADE')
  }

  const schema = await readFile(schemaPath, 'utf8')
  await pool.query(schema)

  // Drop demo rows from older category naming (e.g. "pln").
  await pool.query(
    `DELETE FROM products WHERE category <> ALL($1::text[])`,
    [CATEGORIES],
  )

  const { demoId } = await seedUsers()
  await seedProducts()
  if (demoId) await seedDemoHistory(demoId)

  console.log('database ready')
}

seed()
  .then(() => pool.end())
  .catch((error) => {
    console.error(error)
    process.exit(1)
  })
