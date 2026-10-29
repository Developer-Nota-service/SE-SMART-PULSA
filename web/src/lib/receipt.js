import { categoryLabel, dateTime, statusMeta, targetLabel } from './format.js'

// Kertas thermal yang umum di konter: 58 mm (printer saku) dan 80 mm (kasir).
export const PAPER_OPTIONS = [
  { value: '58', label: '58 mm' },
  { value: '80', label: '80 mm' },
]

const PAPER_STORAGE_KEY = 'sesp_receipt_paper'
const MM_TO_PX = 6

export const readPaper = () => {
  try {
    return localStorage.getItem(PAPER_STORAGE_KEY) === '80' ? '80' : '58'
  } catch (error) {
    return '58'
  }
}

export const savePaper = (value) => {
  try {
    localStorage.setItem(PAPER_STORAGE_KEY, value === '80' ? '80' : '58')
  } catch (error) {
    // Penyimpanan bisa mati (mode privat); ukuran tetap dipakai selama sesi.
  }
}

const money = (value) => `Rp${Number(value || 0).toLocaleString('id-ID')}`

/**
 * Nomor tujuan hanya dipakai sebagai nomor WhatsApp bila memang nomor HP
 * Indonesia (08…/628…). ID game, nomor meter, atau email tidak pernah diubah
 * jadi nomor telepon.
 */
const waNumber = (value) => {
  const digits = String(value ?? '').replace(/\D/g, '')
  if (!digits) return ''
  const normal = digits.startsWith('0')
    ? `62${digits.slice(1)}`
    : digits.startsWith('8')
      ? `62${digits}`
      : digits
  return /^628\d{7,13}$/.test(normal) ? normal : ''
}

/** Harga yang tercetak: harga jual reseller bila diisi, kalau tidak total bayar. */
export const receiptTotal = (order) =>
  order.sellPrice === null || order.sellPrice === undefined
    ? Number(order.amount || 0)
    : Number(order.sellPrice)

/**
 * Data struk dipakai bersama oleh cetak thermal dan gambar JPG WhatsApp, jadi
 * keduanya selalu memuat isi yang sama.
 */
export function buildReceipt(order, { seller, paper = '58' } = {}) {
  const lines = [
    ['No. pesanan', order.orderNumber],
    ['Tanggal', dateTime(order.createdAt)],
    ['Produk', order.product?.name || '-'],
    ['Kategori', categoryLabel(order.product?.category)],
    [targetLabel(order.product?.category), order.customerNumber],
  ]

  if (order.serialNumber) lines.push(['No. seri', order.serialNumber])
  lines.push(['Status', statusMeta(order.status).label])

  return {
    orderNumber: order.orderNumber,
    store: seller?.name || 'SE SMART PULSA',
    phone: seller?.phone || '',
    lines,
    total: receiptTotal(order),
    footer:
      order.status === 'success'
        ? 'Terima kasih sudah berbelanja'
        : 'Simpan struk ini sebagai bukti pesanan',
    printedAt: dateTime(new Date()),
    paper: paper === '80' ? '80' : '58',
  }
}

const fontOf = (op) => `${op.weight || '600'} ${op.size}px "Courier New", ui-monospace, monospace`

// Bungkus teks per kata; kata yang sendirian lebih lebar dari kertas (mis.
// nomor pesanan) dipotong per huruf supaya tidak keluar dari struk.
const wrapText = (ctx, text, maxWidth) => {
  const words = String(text).split(/\s+/).filter(Boolean)
  const lines = []
  let current = ''

  for (const word of words) {
    const next = current ? `${current} ${word}` : word
    if (ctx.measureText(next).width <= maxWidth || !current) current = next
    else {
      lines.push(current)
      current = word
    }
  }
  if (current) lines.push(current)

  return lines.flatMap((line) => {
    if (ctx.measureText(line).width <= maxWidth) return [line]
    const pieces = []
    let piece = ''
    for (const char of line) {
      if (piece && ctx.measureText(piece + char).width > maxWidth) {
        pieces.push(piece)
        piece = char
      } else {
        piece += char
      }
    }
    if (piece) pieces.push(piece)
    return pieces
  })
}

const layout = (receipt, ctx, width) => {
  const pad = Math.round(3 * MM_TO_PX)
  const base = receipt.paper === '80' ? 22 : 19
  const maxWidth = width - pad * 2
  const ops = []

  ops.push({ text: receipt.store, size: base + 5, weight: '800', align: 'center', gapAfter: 2 })
  if (receipt.phone) {
    ops.push({ text: receipt.phone, size: base - 3, align: 'center', color: '#444444' })
  }
  ops.push({ divider: true, gapBefore: 6, gapAfter: 6 })

  for (const [label, value] of receipt.lines) {
    ops.push({ text: `${label}: ${value}`, size: base - 1, align: 'left', wrap: true, gapAfter: 3 })
  }

  ops.push({ divider: true, gapBefore: 6, gapAfter: 6 })
  ops.push({ text: 'TOTAL', size: base, weight: '700', align: 'left' })
  ops.push({ text: money(receipt.total), size: base + 9, weight: '800', align: 'left', gapAfter: 8 })
  ops.push({ text: receipt.footer, size: base - 2, align: 'center', wrap: true, gapBefore: 4, gapAfter: 2 })
  ops.push({ text: `Dicetak ${receipt.printedAt}`, size: base - 4, align: 'center', color: '#666666' })

  let height = pad
  const blocks = ops.map((op) => {
    if (op.divider) {
      height += (op.gapBefore || 0) + 8 + (op.gapAfter || 0)
      return { op, height: 8 }
    }

    ctx.font = fontOf(op)
    const lines = op.wrap ? wrapText(ctx, op.text, maxWidth) : [op.text]
    const lineHeight = Math.round(op.size * 1.4)
    const blockHeight = lines.length * lineHeight
    height += (op.gapBefore || 0) + blockHeight + (op.gapAfter || 0)

    return { op, lines, lineHeight, height: blockHeight }
  })

  return { blocks, pad, height: Math.ceil(height + pad) }
}

/** Struk digambar ke canvas supaya bisa dibagikan sebagai JPG (WhatsApp). */
export async function createReceiptImage(receipt) {
  const mm = receipt.paper === '80' ? 80 : 58
  const width = Math.round(mm * MM_TO_PX)
  const measure = document.createElement('canvas').getContext('2d')
  const { blocks, pad, height } = layout(receipt, measure, width)

  const scale = 2
  const canvas = document.createElement('canvas')
  canvas.width = width * scale
  canvas.height = height * scale

  const ctx = canvas.getContext('2d')
  ctx.scale(scale, scale)
  ctx.fillStyle = '#ffffff'
  ctx.fillRect(0, 0, width, height)
  ctx.textBaseline = 'top'

  let y = pad
  for (const block of blocks) {
    y += block.op.gapBefore || 0

    if (block.op.divider) {
      ctx.strokeStyle = '#9aa7b8'
      ctx.setLineDash([4, 3])
      ctx.beginPath()
      ctx.moveTo(pad, y + 4)
      ctx.lineTo(width - pad, y + 4)
      ctx.stroke()
      ctx.setLineDash([])
      y += block.height
      continue
    }

    ctx.font = fontOf(block.op)
    ctx.fillStyle = block.op.color || '#111827'
    block.lines.forEach((line, index) => {
      const x =
        block.op.align === 'center' ? (width - ctx.measureText(line).width) / 2 : pad
      ctx.fillText(line, x, y + index * block.lineHeight)
    })
    y += block.height + (block.op.gapAfter || 0)
  }

  const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.92))

  return {
    dataUrl: canvas.toDataURL('image/jpeg', 0.92),
    blob,
    width,
    height,
    filename: `struk-${String(receipt.orderNumber || 'pesanan').replace(/[^\w-]/g, '')}.jpg`,
  }
}

const escapeHtml = (value) =>
  String(value ?? '').replace(
    /[&<>"']/g,
    (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char],
  )

/**
 * Cetak struk ke printer thermal: jendela baru berisi HTML struk dengan lebar
 * kertas sesuai pilihan (58/80 mm), lalu dialog cetak dibuka otomatis. Ukuran
 * kertas dan margin bisa disesuaikan di dialog printer pengguna.
 */
export function printReceipt(receipt) {
  const mm = receipt.paper === '80' ? '80mm' : '58mm'
  const rows = receipt.lines
    .map(
      ([label, value]) =>
        `<div class="line"><span>${escapeHtml(label)}</span><b>${escapeHtml(value)}</b></div>`,
    )
    .join('')

  const html = `<!doctype html>
<html lang="id"><head><meta charset="utf-8" />
<title>Struk ${escapeHtml(receipt.orderNumber)}</title>
<style>
  @page { size: ${mm} auto; margin: 0; }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; background: #fff; color: #000; }
  body { width: ${mm}; padding: 3mm; font-family: "Courier New", ui-monospace, monospace; font-size: 10px; }
  .center { text-align: center; }
  .store { font-size: 13px; font-weight: 800; letter-spacing: 0.4px; }
  .phone { font-size: 9px; color: #333; }
  hr { border: 0; border-top: 1px dashed #000; margin: 5px 0; }
  .line { display: flex; justify-content: space-between; gap: 6px; font-size: 10px; line-height: 1.45; margin: 1px 0; }
  .line b { text-align: right; word-break: break-word; }
  .total { display: flex; justify-content: space-between; align-items: baseline; margin-top: 2px; }
  .total span { font-weight: 700; }
  .total b { font-size: 16px; }
  .foot { text-align: center; font-size: 9px; margin-top: 6px; }
  .printed { text-align: center; font-size: 8px; color: #444; margin-top: 3px; }
</style></head>
<body>
  <div class="center store">${escapeHtml(receipt.store)}</div>
  ${receipt.phone ? `<div class="center phone">${escapeHtml(receipt.phone)}</div>` : ''}
  <hr />
  ${rows}
  <hr />
  <div class="total"><span>TOTAL</span><b>${escapeHtml(money(receipt.total))}</b></div>
  <div class="foot">${escapeHtml(receipt.footer)}</div>
  <div class="printed">Dicetak ${escapeHtml(receipt.printedAt)}</div>
</body></html>`

  const win = window.open('', '_blank', 'width=420,height=680')
  if (!win) return false

  win.document.open()
  win.document.write(html)
  win.document.close()
  win.focus()
  window.setTimeout(() => {
    try {
      win.print()
    } catch (error) {
      // Dialog cetak bisa diblokir; jendela struk tetap terbuka untuk Ctrl+P.
    }
  }, 350)

  return true
}

export const receiptCaption = (receipt) =>
  [
    `*${receipt.store}*`,
    `Struk ${receipt.orderNumber}`,
    ...receipt.lines.map(([label, value]) => `${label}: ${value}`),
    `Total: ${money(receipt.total)}`,
  ].join('\n')

export function whatsappUrl(receipt, order) {
  const text = encodeURIComponent(receiptCaption(receipt))
  const number = waNumber(order.customerNumber)
  return number
    ? `https://wa.me/${number}?text=${text}`
    : `https://api.whatsapp.com/send?text=${text}`
}

/**
 * Kirim struk sebagai JPG: di ponsel memakai share sheet sistem (pilih
 * WhatsApp), di desktop gambar diunduh lalu WhatsApp dibuka dengan keterangan.
 */
export async function shareReceipt(receipt, order) {
  const image = await createReceiptImage(receipt)
  const file = new File([image.blob], image.filename, { type: 'image/jpeg' })

  if (typeof navigator !== 'undefined' && navigator.canShare?.({ files: [file] })) {
    try {
      await navigator.share({ files: [file], text: receiptCaption(receipt) })
      return { status: 'shared' }
    } catch (error) {
      if (error?.name === 'AbortError') return { status: 'cancelled' }
    }
  }

  return { status: 'manual', image }
}

export function downloadImage(image) {
  const link = document.createElement('a')
  link.href = image.dataUrl
  link.download = image.filename
  document.body.appendChild(link)
  link.click()
  link.remove()
}
