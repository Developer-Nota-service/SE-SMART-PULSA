/* Uji bridge Kantor Agent AI tanpa dependensi tambahan.
 * Jalankan: node --test office/bridge/bridge.test.mjs */

import test from 'node:test'
import assert from 'node:assert/strict'
import { mkdtempSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { createBridge } from './agent-office-bridge.mjs'

const TOKEN = 'token-uji'

async function startBridge(overrides = {}) {
  const dir = mkdtempSync(join(tmpdir(), 'kantor-test-'))
  const bridge = createBridge({
    port: 0,
    host: '127.0.0.1',
    token: TOKEN,
    stateFile: join(dir, 'state.json'),
    workspace: dir,
    enabled: false,
    command: '/bin/echo',
    args: [],
    allowOrigin: '*',
    ...overrides,
  })
  await new Promise((resolve) => bridge.server.listen(0, '127.0.0.1', resolve))
  const url = `http://127.0.0.1:${bridge.server.address().port}`
  return { ...bridge, url, close: () => new Promise((resolve) => bridge.server.close(resolve)) }
}

const authed = (token = TOKEN) => ({ Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' })

test('health terbuka, state menolak token yang salah', async (t) => {
  const bridge = await startBridge()
  t.after(() => bridge.close())

  const health = await fetch(`${bridge.url}/health`)
  assert.equal(health.status, 200)

  const anonymous = await fetch(`${bridge.url}/api/state`)
  assert.equal(anonymous.status, 401)

  const wrong = await fetch(`${bridge.url}/api/state`, { headers: authed('salah') })
  assert.equal(wrong.status, 401)

  const ok = await fetch(`${bridge.url}/api/state`, { headers: authed() })
  assert.equal(ok.status, 200)
  const body = await ok.json()
  assert.equal(body.ok, true)
  assert.ok(body.agents.length >= 6)
})

test('tugas baru masuk antrean dan terlihat di state', async (t) => {
  const bridge = await startBridge()
  t.after(() => bridge.close())

  const created = await fetch(`${bridge.url}/api/tasks`, {
    method: 'POST',
    headers: authed(),
    body: JSON.stringify({ title: 'Periksa disk', detail: 'Lihat pemakaian /var', agent: 'monitor' }),
  })
  assert.equal(created.status, 201)
  const { task } = await created.json()
  assert.equal(task.status, 'queued')
  assert.equal(task.agent, 'monitor')

  const state = await (await fetch(`${bridge.url}/api/state`, { headers: authed() })).json()
  assert.ok(state.tasks.some((row) => row.id === task.id))
  assert.ok(state.events.some((row) => row.message.includes('Periksa disk')))
})

test('tugas dengan agen tak dikenal ditolak', async (t) => {
  const bridge = await startBridge()
  t.after(() => bridge.close())

  const res = await fetch(`${bridge.url}/api/tasks`, {
    method: 'POST',
    headers: authed(),
    body: JSON.stringify({ title: 'x', agent: 'tidak-ada' }),
  })
  assert.equal(res.status, 422)
})

test('run ditolak saat runner mati', async (t) => {
  const bridge = await startBridge()
  t.after(() => bridge.close())

  const { task } = await (await fetch(`${bridge.url}/api/tasks`, {
    method: 'POST',
    headers: authed(),
    body: JSON.stringify({ title: 'Cek log', agent: 'technician' }),
  })).json()

  const run = await fetch(`${bridge.url}/api/tasks/${task.id}/run`, { method: 'POST', headers: authed(), body: '{}' })
  assert.equal(run.status, 409)
})

test('hook Claude Code mengubah status agen sesi', async (t) => {
  const bridge = await startBridge()
  t.after(() => bridge.close())

  const hook = await fetch(`${bridge.url}/api/hooks/claude`, {
    method: 'POST',
    headers: authed(),
    body: JSON.stringify({
      hook_event_name: 'PreToolUse',
      session_id: 'sesi-uji-123456',
      cwd: '/srv/proyek',
      tool_name: 'Bash',
    }),
  })
  assert.equal(hook.status, 200)
  const body = await hook.json()
  assert.equal(body.agent.state, 'working')
  assert.match(body.agent.activity, /Bash/)

  const stop = await (await fetch(`${bridge.url}/api/hooks/claude`, {
    method: 'POST',
    headers: authed(),
    body: JSON.stringify({ hook_event_name: 'Stop', session_id: 'sesi-uji-123456' }),
  })).json()
  assert.equal(stop.agent.state, 'idle')

  const state = await (await fetch(`${bridge.url}/api/state`, { headers: authed() })).json()
  assert.equal(state.agents.filter((a) => a.sessionId === 'sesi-uji-123456').length, 1)
})

test('runner menjalankan tugas sampai selesai', async (t) => {
  const bridge = await startBridge({ enabled: true, command: '/bin/echo' })
  t.after(() => bridge.close())

  const { task } = await (await fetch(`${bridge.url}/api/tasks`, {
    method: 'POST',
    headers: authed(),
    body: JSON.stringify({ title: 'Tugas uji', detail: 'halo dunia', agent: 'hermes' }),
  })).json()

  let final = null
  for (let i = 0; i < 40; i++) {
    await new Promise((resolve) => setTimeout(resolve, 50))
    const state = await (await fetch(`${bridge.url}/api/state`, { headers: authed() })).json()
    final = state.tasks.find((row) => row.id === task.id)
    if (final && final.status === 'done') break
  }

  assert.equal(final.status, 'done')
  assert.match(final.result, /halo dunia/)
})
