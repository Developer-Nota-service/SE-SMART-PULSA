async function request(path, options = {}) {
  const response = await fetch(path, {
    headers: { 'Content-Type': 'application/json' },
    ...options,
  })
  const data = await response.json().catch(() => ({}))
  if (!response.ok) {
    throw new Error(data.error || 'Terjadi kesalahan pada server')
  }
  return data
}

// Postgres returns BIGINT columns as strings; convert them to numbers so price
// and balance maths stay numeric.
function withNumbers(row, fields) {
  for (const field of fields) {
    if (row[field] != null) row[field] = Number(row[field])
  }
  return row
}

export const getAccount = async () => withNumbers(await request('/api/account'), ['balance'])

export const getProducts = async () =>
  (await request('/api/products')).map((product) => withNumbers(product, ['price']))

export const getTransactions = async () =>
  (await request('/api/transactions?limit=30')).map((transaction) =>
    withNumbers(transaction, ['amount']),
  )

export const topUp = async (amount) =>
  withNumbers(
    await request('/api/topup', { method: 'POST', body: JSON.stringify({ amount }) }),
    ['balance'],
  )

export const createTransaction = async (productId, customerNumber) => {
  const data = await request('/api/transactions', {
    method: 'POST',
    body: JSON.stringify({ productId, customerNumber }),
  })
  return {
    transaction: withNumbers(data.transaction, ['amount']),
    balance: Number(data.balance),
  }
}
