#!/usr/bin/env node
/* Hook Claude Code → Kantor Agent AI bridge.
 *
 * Dipasang oleh install-claude-hooks.mjs. Membaca payload hook dari stdin,
 * menambahkan identitas agen bila ada, lalu mengirimnya ke bridge. Selalu
 * keluar dengan kode 0 supaya tidak pernah menghambat sesi Claude Code. */

import { existsSync, readFileSync } from 'node:fs'
import { homedir } from 'node:os'
import { join } from 'node:path'

function loadEnvFile() {
  const path = process.env.KANTOR_ENV_FILE || join(homedir(), '.config', 'kantor-office', 'bridge.env')
  const out = {}
  try {
    if (!existsSync(path)) return out
    for (const line of readFileSync(path, 'utf8').split('\n')) {
      const match = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/)
      if (!match) continue
      out[match[1]] = match[2].replace(/^["']|["']$/g, '')
    }
  } catch {
    /* abaikan: hook tidak boleh mengganggu Claude */
  }
  return out
}

async function readStdin() {
  if (process.stdin.isTTY) return ''
  const chunks = []
  for await (const chunk of process.stdin) chunks.push(chunk)
  return Buffer.concat(chunks).toString('utf8')
}

async function main() {
  const env = loadEnvFile()
  const base = String(process.env.KANTOR_BRIDGE_URL || env.KANTOR_BRIDGE_URL || 'http://127.0.0.1:3334').replace(/\/+$/, '')
  const token = String(process.env.KANTOR_TOKEN || env.KANTOR_TOKEN || '')
  const agent = String(process.env.KANTOR_AGENT || env.KANTOR_AGENT || '')
  if (!token) return

  const raw = await readStdin()
  let payload = {}
  try {
    payload = raw ? JSON.parse(raw) : {}
  } catch {
    payload = { hook_event_name: 'Notification', message: raw.slice(0, 200) }
  }
  if (agent && !payload.agent) payload.agent = agent

  try {
    await fetch(`${base}/api/hooks/claude`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify(payload),
      signal: AbortSignal.timeout(3000),
    })
  } catch {
    /* bridge mungkin mati; abaikan saja */
  }
}

main().finally(() => process.exit(0))
