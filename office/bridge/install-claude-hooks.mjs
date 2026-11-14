#!/usr/bin/env node
/* Pasang hook Claude Code supaya aktivitas sesi muncul di Kantor Agent AI.
 *
 *   node office/bridge/install-claude-hooks.mjs [--settings <path>] [--dry-run]
 *
 * Menggabungkan entri baru ke ~/.claude/settings.json tanpa menghapus hook
 * yang sudah ada, dan membuat cadangan settings.json sebelum diubah. */

import { copyFileSync, existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { homedir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const HERE = dirname(fileURLToPath(import.meta.url))
const HOOK_PATH = join(HERE, 'claude-hook.mjs')

const args = process.argv.slice(2)
const dryRun = args.includes('--dry-run')
const settingsIndex = args.indexOf('--settings')
const settingsPath = settingsIndex >= 0 && args[settingsIndex + 1]
  ? args[settingsIndex + 1]
  : process.env.KANTOR_CLAUDE_SETTINGS || join(homedir(), '.claude', 'settings.json')

const EVENTS = [
  ['SessionStart', false],
  ['UserPromptSubmit', false],
  ['PreToolUse', true],
  ['PostToolUse', true],
  ['Notification', false],
  ['Stop', false],
  ['SessionEnd', false],
]

const command = `node "${HOOK_PATH}"`

let settings = {}
if (existsSync(settingsPath)) {
  try {
    settings = JSON.parse(readFileSync(settingsPath, 'utf8')) || {}
  } catch (err) {
    console.error(`Settings bukan JSON valid, tidak diubah: ${settingsPath}`)
    console.error(err.message)
    process.exit(1)
  }
}

settings.hooks = settings.hooks && typeof settings.hooks === 'object' ? settings.hooks : {}

const added = []
for (const [event, usesMatcher] of EVENTS) {
  const list = Array.isArray(settings.hooks[event]) ? settings.hooks[event] : []
  const exists = list.some((group) =>
    Array.isArray(group?.hooks) && group.hooks.some((hook) => String(hook?.command || '').includes('claude-hook.mjs')))
  if (!exists) {
    const group = { hooks: [{ type: 'command', command }] }
    if (usesMatcher) group.matcher = '*'
    list.push(group)
    added.push(event)
  }
  settings.hooks[event] = list
}

if (!added.length) {
  console.log('Hook Kantor Agent AI sudah terpasang; tidak ada perubahan.')
  process.exit(0)
}

const output = `${JSON.stringify(settings, null, 2)}\n`
if (dryRun) {
  console.log(`Akan menambahkan hook: ${added.join(', ')}`)
  console.log(output)
  process.exit(0)
}

mkdirSync(dirname(settingsPath), { recursive: true })
if (existsSync(settingsPath)) {
  const backup = `${settingsPath}.bak-${Date.now()}`
  copyFileSync(settingsPath, backup)
  console.log(`Cadangan: ${backup}`)
}
writeFileSync(settingsPath, output)
console.log(`Hook terpasang di ${settingsPath}: ${added.join(', ')}`)
console.log(`Kirim aktivitas ke bridge melalui ${HOOK_PATH}`)
