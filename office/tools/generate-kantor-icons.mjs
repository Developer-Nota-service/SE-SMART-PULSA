#!/usr/bin/env node
/* Membuat ikon PWA Kantor Agent AI (192 & 512) tanpa alat gambar eksternal.
 * Jalankan: node office/tools/generate-kantor-icons.mjs
 * Hasil: web/public/kantor/icon-192.png dan icon-512.png */

import { deflateSync } from 'node:zlib'
import { writeFileSync, mkdirSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const HERE = dirname(fileURLToPath(import.meta.url))
const OUT = join(HERE, '..', '..', 'web', 'public', 'kantor')

const CRC_TABLE = (() => {
  const table = new Uint32Array(256)
  for (let n = 0; n < 256; n++) {
    let c = n
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1
    table[n] = c >>> 0
  }
  return table
})()

function crc32(buf) {
  let c = 0xffffffff
  for (const byte of buf) c = CRC_TABLE[(c ^ byte) & 0xff] ^ (c >>> 8)
  return (c ^ 0xffffffff) >>> 0
}

function chunk(type, data) {
  const name = Buffer.from(type, 'ascii')
  const length = Buffer.alloc(4)
  length.writeUInt32BE(data.length)
  const crc = Buffer.alloc(4)
  crc.writeUInt32BE(crc32(Buffer.concat([name, data])))
  return Buffer.concat([length, name, data, crc])
}

function encodePng(width, height, rgba) {
  const raw = Buffer.alloc((width * 4 + 1) * height)
  for (let y = 0; y < height; y++) {
    raw[y * (width * 4 + 1)] = 0
    rgba.copy(raw, y * (width * 4 + 1) + 1, y * width * 4, (y + 1) * width * 4)
  }
  const ihdr = Buffer.alloc(13)
  ihdr.writeUInt32BE(width, 0)
  ihdr.writeUInt32BE(height, 4)
  ihdr[8] = 8
  ihdr[9] = 6
  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk('IHDR', ihdr),
    chunk('IDAT', deflateSync(raw, { level: 9 })),
    chunk('IEND', Buffer.alloc(0)),
  ])
}

class Canvas {
  constructor(size) {
    this.size = size
    this.px = Buffer.alloc(size * size * 4)
  }

  set(x, y, [r, g, b, a = 255]) {
    if (x < 0 || y < 0 || x >= this.size || y >= this.size) return
    const i = (y * this.size + x) * 4
    if (a === 255) {
      this.px[i] = r; this.px[i + 1] = g; this.px[i + 2] = b; this.px[i + 3] = 255
      return
    }
    const alpha = a / 255
    this.px[i] = Math.round(this.px[i] * (1 - alpha) + r * alpha)
    this.px[i + 1] = Math.round(this.px[i + 1] * (1 - alpha) + g * alpha)
    this.px[i + 2] = Math.round(this.px[i + 2] * (1 - alpha) + b * alpha)
    this.px[i + 3] = Math.max(this.px[i + 3], a)
  }

  rect(x, y, w, h, color) {
    for (let yy = y; yy < y + h; yy++) for (let xx = x; xx < x + w; xx++) this.set(xx, yy, color)
  }

  roundRect(x, y, w, h, r, color) {
    for (let yy = 0; yy < h; yy++) {
      for (let xx = 0; xx < w; xx++) {
        const dx = Math.max(r - xx, 0, xx - (w - 1 - r))
        const dy = Math.max(r - yy, 0, yy - (h - 1 - r))
        if (dx * dx + dy * dy <= r * r) this.set(x + xx, y + yy, color)
      }
    }
  }

  circle(cx, cy, radius, color) {
    for (let y = -radius; y <= radius; y++) {
      const span = Math.floor(Math.sqrt(radius * radius - y * y))
      for (let x = -span; x <= span; x++) this.set(cx + x, cy + y, color)
    }
  }

  diamond(cx, cy, rx, ry, color) {
    for (let y = -ry; y <= ry; y++) {
      const span = Math.round(rx * (1 - Math.abs(y) / ry))
      for (let x = -span; x <= span; x++) this.set(cx + x, cy + y, color)
    }
  }

  shearRect(cx, top, w, h, shear, color) {
    for (let y = 0; y < h; y++) {
      const shift = Math.round((y - h / 2) * shear)
      const start = Math.round(cx - w / 2) + shift
      for (let x = 0; x < w; x++) this.set(start + x, top + y, color)
    }
  }

  scaled(size) {
    const out = new Canvas(size)
    const factor = this.size / size
    for (let y = 0; y < size; y++) {
      for (let x = 0; x < size; x++) {
        const sx = Math.min(this.size - 1, Math.floor(x * factor))
        const sy = Math.min(this.size - 1, Math.floor(y * factor))
        const i = (sy * this.size + sx) * 4
        out.set(x, y, [this.px[i], this.px[i + 1], this.px[i + 2], this.px[i + 3]])
      }
    }
    return out
  }
}

const NAVY = [15, 30, 48]
const PANEL = [26, 45, 64]
const MINT = [101, 226, 194]
const GOLD = [240, 200, 121]
const SKIN = [217, 158, 118]
const HAIR = [53, 43, 43]
const WOOD = [128, 91, 64]
const WOOD_LIGHT = [187, 144, 96]
const SCREEN = [32, 57, 75]
const SCREEN_EDGE = [123, 167, 173]
const OUTLINE = [24, 49, 62]

function drawIcon(size) {
  const base = 512
  const c = new Canvas(base)

  // Latar aplikasi.
  c.roundRect(0, 0, base, base, 0, NAVY)
  c.roundRect(14, 14, base - 28, base - 28, 104, PANEL)
  c.roundRect(14, 14, base - 28, 6, 3, [42, 64, 88])

  // Lantai isometrik.
  c.diamond(256, 318, 190, 100, [28, 58, 72])
  c.diamond(256, 318, 150, 78, [34, 70, 84])

  // Karakter agen.
  c.circle(232, 196, 30, HAIR)
  c.circle(232, 208, 24, SKIN)
  c.rect(206, 226, 52, 54, MINT)
  c.rect(206, 226, 52, 6, [140, 240, 214])
  c.rect(206, 226, 4, 54, OUTLINE)
  c.rect(254, 226, 4, 54, OUTLINE)
  c.rect(250, 244, 12, 26, SKIN)

  // Meja.
  c.shearRect(256, 278, 214, 40, 0.5, WOOD)
  c.shearRect(256, 278, 214, 8, 0.5, WOOD_LIGHT)
  c.shearRect(256, 312, 214, 8, 0.5, [73, 55, 47])

  // Monitor di atas meja.
  c.rect(316, 236, 62, 44, SCREEN_EDGE)
  c.rect(322, 242, 50, 32, SCREEN)
  c.rect(326, 247, 42, 4, MINT)
  c.rect(326, 255, 30, 4, [101, 226, 194, 150])
  c.rect(340, 280, 14, 8, WOOD_LIGHT)

  // Penanda status.
  c.circle(366, 190, 17, GOLD)
  c.circle(366, 190, 9, NAVY)

  return c.scaled(size).px
}

mkdirSync(OUT, { recursive: true })
for (const size of [192, 512]) {
  const png = encodePng(size, size, drawIcon(size))
  writeFileSync(join(OUT, `icon-${size}.png`), png)
  console.log(`ditulis web/public/kantor/icon-${size}.png (${png.length} byte)`)
}
