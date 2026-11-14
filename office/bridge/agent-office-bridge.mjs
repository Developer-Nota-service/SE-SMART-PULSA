#!/usr/bin/env node
/* Kantor Agent AI — bridge server untuk VPS.
 *
 * Menyimpan status kantor (agen, tugas, aktivitas), menerima hook Claude Code,
 * dan (bila diizinkan) menjalankan `claude -p` di workspace VPS. Tanpa
 * dependensi npm: hanya modul bawaan Node >= 18.
 *
 * Contoh:
 *   node agent-office-bridge.mjs --port 3334 --token <rahasia> --allow-run
 *
 * Keamanan: token wajib untuk semua endpoint /api. Bind default 127.0.0.1;
 * pasang di belakang Nginx/HTTPS (lihat office/install-kantor-bridge.sh). */

import http from 'node:http'
import { spawn } from 'node:child_process'
import { existsSync, mkdirSync, readFileSync, renameSync, writeFileSync } from 'node:fs'
import { createHash, randomBytes, timingSafeEqual } from 'node:crypto'
import { basename, join, resolve } from 'node:path'
import { homedir } from 'node:os'
import { pathToFileURL } from 'node:url'

export const VERSION = '1.0.0'
const MAX_BODY = 64 * 1024
const MAX_EVENTS = 80
const MAX_TASKS = 200
const MAX_AGENTS = 12
const RUN_TIMEOUT_MS = 15 * 60 * 1000
const RUN_OUTPUT_LIMIT = 20000

const SHIRT_COLORS = ['#367c88', '#526fbd', '#9b7545', '#5d916b', '#9b5d78', '#9b8b50', '#4d7e9c', '#8a6f9c']

export const DEFAULT_AGENTS = [
  { id: 'hermes', name: 'Hermes', role: 'Kepala Koordinator', description: 'Menerima instruksi direktur dan membagi pekerjaan ke tim.', shirt: '#367c88', hair: '#352b2b' },
  { id: 'monitor', name: 'Agent Monitoring', role: 'Pemantauan Server & Website', description: 'Pemeriksaan uptime, resource, dan log layanan.', shirt: '#526fbd', hair: '#49372c' },
  { id: 'technician', name: 'Agent Teknisi', role: 'Diagnosis & Perbaikan', description: 'Analisis gangguan dan usulan perbaikan teknis.', shirt: '#9b7545', hair: '#25252a' },
  { id: 'sales', name: 'Agent Penjualan', role: 'Operasional PPOB', description: 'Pemantauan produk, transaksi, dan layanan pelanggan.', shirt: '#5d916b', hair: '#392b25' },
  { id: 'security', name: 'Agent Keamanan', role: 'Keamanan Sistem', description: 'Pemeriksaan konfigurasi dan aktivitas mencurigakan.', shirt: '#9b5d78', hair: '#30272a' },
  { id: 'reports', name: 'Agent Laporan', role: 'Laporan Operasional', description: 'Ringkasan pekerjaan harian dan laporan untuk direktur.', shirt: '#9b8b50', hair: '#3c3028' },
]

/* ------------------------------------------------------------------ util */

function parseArgs(argv) {
  const out = {}
  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i]
    if (!arg.startsWith('--')) continue
    const key = arg.slice(2)
    const next = argv[i + 1]
    if (next === undefined || next.startsWith('--')) out[key] = true
    else { out[key] = next; i++ }
  }
  return out
}

function splitArgs(value) {
  return String(value || '').split(/\s+/).filter(Boolean)
}

function hashToken(token) {
  return createHash('sha256').update(String(token)).digest()
}

function safeEqual(a, b) {
  const left = hashToken(a)
  const right = hashToken(b)
  return timingSafeEqual(left, right)
}

function readTokenFile(path) {
  try {
    const value = readFileSync(path, 'utf8').trim()
    return value || ''
  } catch {
    return ''
  }
}

function freshToken() {
  return randomBytes(24).toString('hex')
}

export function defaultState() {
  return {
    version: 1,
    seq: 1,
    agents: DEFAULT_AGENTS.map((agent) => ({
      ...agent,
      state: 'idle',
      activity: 'Menunggu instruksi',
      updatedAt: Date.now(),
    })),
    tasks: [],
    events: [],
    sessions: {},
  }
}

function loadState(file) {
  try {
    const parsed = JSON.parse(readFileSync(file, 'utf8'))
    if (!parsed || typeof parsed !== 'object' || !Array.isArray(parsed.agents)) throw new Error('bentuk salah')
    const base = defaultState()
    const merged = { ...base, ...parsed }
    // Agen bawaan selalu ada supaya tampilan kantor tidak kosong.
    for (const agent of base.agents) {
      if (!merged.agents.some((row) => row.id === agent.id)) merged.agents.push(agent)
    }
    merged.tasks = Array.isArray(merged.tasks) ? merged.tasks : []
    merged.events = Array.isArray(merged.events) ? merged.events : []
    merged.sessions = merged.sessions && typeof merged.sessions === 'object' ? merged.sessions : {}
    merged.seq = Number.isFinite(merged.seq) ? merged.seq : 1
    return merged
  } catch {
    return defaultState()
  }
}

/* ----------------------------------------------------------- state layer */

export function createStore({ stateFile, saveDelay = 150 }) {
  const state = loadState(stateFile)
  let timer = null

  const save = () => {
    if (timer) return
    timer = setTimeout(() => {
      timer = null
      try {
        const tmp = `${stateFile}.tmp`
        writeFileSync(tmp, JSON.stringify(state, null, 2))
        renameSync(tmp, stateFile)
      } catch (err) {
        console.error('[kantor] gagal menyimpan state:', err.message)
      }
    }, saveDelay)
  }

  const agent = (id) => state.agents.find((row) => row.id === id)

  const touchAgent = (id, patch) => {
    const target = agent(id)
    if (!target) return null
    Object.assign(target, patch, { updatedAt: Date.now() })
    save()
    return target
  }

  const pushEvent = (agentId, message, level = 'info') => {
    const event = {
      id: state.seq++,
      agent: agentId || null,
      message: String(message || '').slice(0, 400),
      level: ['info', 'success', 'warn', 'error'].includes(level) ? level : 'info',
      createdAt: Date.now(),
    }
    state.events.unshift(event)
    state.events = state.events.slice(0, MAX_EVENTS)
    save()
    return event
  }

  const addAgent = (row) => {
    const sessionAgents = state.agents.filter((a) => !DEFAULT_AGENTS.some((d) => d.id === a.id))
    if (sessionAgents.length >= MAX_AGENTS - DEFAULT_AGENTS.length) {
      const oldest = sessionAgents[sessionAgents.length - 1]
      state.agents = state.agents.filter((a) => a.id !== oldest.id)
    }
    state.agents.push(row)
    save()
    return row
  }

  const addTask = (row) => {
    state.tasks.unshift(row)
    state.tasks = state.tasks.slice(0, MAX_TASKS)
    save()
    return row
  }

  const snapshot = (runner) => {
    const agents = state.agents
    const tasks = state.tasks.slice(0, 60)
    return {
      ok: true,
      version: VERSION,
      now: Date.now(),
      runner: {
        enabled: Boolean(runner.enabled),
        workspace: runner.workspace,
        command: runner.command,
      },
      stats: {
        agents: agents.length,
        working: agents.filter((a) => a.state === 'working').length,
        needs: agents.filter((a) => a.state === 'needs').length,
        queued: state.tasks.filter((t) => t.status === 'queued' || t.status === 'working').length,
        events: state.events.length,
      },
      agents,
      tasks,
      events: state.events.slice(0, 60),
    }
  }

  return { state, save, agent, touchAgent, pushEvent, addAgent, addTask, snapshot }
}

/* -------------------------------------------------------- claude code hooks */

const HOOK_DEFAULTS = {
  SessionStart: { state: 'working', activity: 'Sesi baru dimulai' },
  UserPromptSubmit: { state: 'working', activity: 'Mengerjakan permintaan direktur' },
  PreToolUse: { state: 'working', activity: 'Menjalankan alat' },
  PostToolUse: { state: 'working', activity: 'Alat selesai' },
  PreCompact: { state: 'working', activity: 'Meringkas konteks' },
  Notification: { state: 'needs', activity: 'Butuh perhatian direktur' },
  SubagentStop: { state: 'working', activity: 'Subagent selesai' },
  Stop: { state: 'idle', activity: 'Selesai — menunggu instruksi' },
  SessionEnd: { state: 'idle', activity: 'Sesi ditutup' },
}

export function applyClaudeHook(store, payload = {}) {
  const hook = String(payload.hook_event_name || payload.hook_event || payload.event || 'Notification')
  const sessionId = String(payload.session_id || payload.sessionId || 'tanpa-sesi')
  const base = HOOK_DEFAULTS[hook] || { state: 'working', activity: hook }

  let agentId = store.state.sessions[sessionId]
  if (payload.agent && store.agent(String(payload.agent))) {
    agentId = String(payload.agent)
  } else if (!agentId || !store.agent(agentId)) {
    const short = sessionId.slice(0, 6)
    const cwd = payload.cwd ? basename(String(payload.cwd)) : ''
    agentId = `ses-${short}`
    if (!store.agent(agentId)) {
      store.addAgent({
        id: agentId,
        name: (cwd ? `Sesi ${cwd}` : `Sesi ${short}`).slice(0, 28),
        role: 'Claude Code',
        description: payload.cwd ? `Sesi di ${payload.cwd}` : 'Sesi Claude Code di VPS',
        shirt: SHIRT_COLORS[hashToken(sessionId)[0] % SHIRT_COLORS.length],
        hair: '#352b2b',
        state: base.state,
        activity: base.activity,
        sessionId,
        updatedAt: Date.now(),
      })
    }
  }

  store.state.sessions[sessionId] = agentId

  let activity = base.activity
  if (hook === 'SessionStart') {
    activity = payload.cwd ? `Sesi baru: ${basename(String(payload.cwd))}` : 'Sesi baru dimulai'
  } else if (hook === 'UserPromptSubmit') {
    const prompt = String(payload.prompt || payload.user_prompt || '').replace(/\s+/g, ' ').trim()
    activity = prompt ? `Mengerjakan: ${prompt.slice(0, 70)}` : base.activity
  } else if (hook === 'PreToolUse') {
    activity = `Menjalankan ${payload.tool_name || 'alat'}`
  } else if (hook === 'PostToolUse') {
    activity = `${payload.tool_name || 'Alat'} selesai`
  } else if (hook === 'Notification') {
    activity = String(payload.message || payload.notification || base.activity).slice(0, 160)
  }

  store.touchAgent(agentId, { state: base.state, activity })
  const level = hook === 'Notification' ? 'warn' : hook === 'Stop' || hook === 'SessionEnd' ? 'success' : 'info'
  store.pushEvent(agentId, activity, level)

  return { agent: store.agent(agentId), hook }
}

/* ---------------------------------------------------------------- runner */

function createRunner({ enabled, workspace, command, args, store }) {
  let active = false
  const queue = []

  const finish = (task, status, text, level) => {
    task.status = status
    task.updatedAt = Date.now()
    if (status === 'done') task.result = String(text || '').trim().slice(0, 4000) || 'Selesai tanpa keluaran.'
    else task.error = String(text || 'Gagal menjalankan perintah.').slice(0, 2000)
    store.touchAgent(task.agent, {
      state: 'idle',
      activity: status === 'done' ? `Selesai: ${task.title}` : `Gagal: ${task.title}`,
    })
    store.pushEvent(task.agent, status === 'done' ? `Selesai: ${task.title}` : `Gagal: ${task.title}`, level)
    active = false
    drain()
  }

  const run = (task) => {
    if (!task) { active = false; drain(); return }
    active = true
    task.status = 'working'
    task.updatedAt = Date.now()
    store.touchAgent(task.agent, { state: 'working', activity: task.title })
    store.pushEvent(task.agent, `Menjalankan: ${task.title}`, 'info')

    const prompt = task.detail ? `${task.title}\n\n${task.detail}` : task.title
    let output = ''
    let settled = false
    let child

    try {
      child = spawn(command, [...args, '-p', prompt], { cwd: workspace, env: process.env, stdio: ['ignore', 'pipe', 'pipe'] })
    } catch (err) {
      finish(task, 'failed', `Tidak bisa memulai perintah: ${err.message}`, 'error')
      return
    }

    const timer = setTimeout(() => child.kill('SIGKILL'), RUN_TIMEOUT_MS)
    const collect = (chunk) => {
      output += chunk.toString()
      if (output.length > RUN_OUTPUT_LIMIT) output = output.slice(-RUN_OUTPUT_LIMIT)
    }
    child.stdout.on('data', collect)
    child.stderr.on('data', collect)
    child.on('error', (err) => {
      if (settled) return
      settled = true
      clearTimeout(timer)
      finish(task, 'failed', `Perintah tidak tersedia: ${err.message}`, 'error')
    })
    child.on('close', (code) => {
      if (settled) return
      settled = true
      clearTimeout(timer)
      if (code === 0) finish(task, 'done', output, 'success')
      else finish(task, 'failed', output || `Perintah keluar dengan kode ${code}.`, 'error')
    })
  }

  function drain() {
    if (!enabled || active) return
    const id = queue.shift()
    if (id === undefined) return
    run(store.state.tasks.find((task) => task.id === id))
  }

  return {
    enabled,
    workspace,
    command,
    enqueue(id) {
      if (!enabled) return false
      queue.push(id)
      drain()
      return true
    },
    get busy() { return active || queue.length > 0 },
  }
}

/* ------------------------------------------------------------------ http */

export function createBridge(config) {
  const store = createStore({ stateFile: config.stateFile })
  const runner = createRunner({ ...config, store })
  const hits = new Map()

  const rateLimited = (ip) => {
    const now = Date.now()
    const row = hits.get(ip) || { count: 0, reset: now + 60_000 }
    if (now > row.reset) { row.count = 0; row.reset = now + 60_000 }
    row.count += 1
    hits.set(ip, row)
    return row.count > 240
  }

  const send = (res, status, body, type = 'application/json; charset=utf-8') => {
    const raw = typeof body === 'string' || Buffer.isBuffer(body) ? body : JSON.stringify(body)
    res.writeHead(status, {
      'Content-Type': type,
      'Content-Length': Buffer.byteLength(raw),
      'Cache-Control': 'no-store',
      'Access-Control-Allow-Origin': config.allowOrigin,
      'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
      'Access-Control-Allow-Headers': 'Authorization, Content-Type',
      'Access-Control-Max-Age': '600',
      Vary: 'Origin',
    })
    res.end(raw)
  }

  const readJson = (req) => new Promise((resolvePromise, rejectPromise) => {
    let size = 0
    const chunks = []
    req.on('data', (chunk) => {
      size += chunk.length
      if (size > MAX_BODY) {
        rejectPromise(new Error('payload terlalu besar'))
        req.destroy()
        return
      }
      chunks.push(chunk)
    })
    req.on('end', () => {
      if (!chunks.length) return resolvePromise({})
      try {
        resolvePromise(JSON.parse(Buffer.concat(chunks).toString('utf8')))
      } catch {
        rejectPromise(new Error('body bukan JSON yang valid'))
      }
    })
    req.on('error', rejectPromise)
  })

  const authorized = (req) => {
    const header = String(req.headers.authorization || '')
    let token = ''
    if (header.toLowerCase().startsWith('bearer ')) token = header.slice(7).trim()
    if (!token) {
      const url = new URL(req.url, 'http://localhost')
      token = url.searchParams.get('token') || ''
    }
    return Boolean(token) && Boolean(config.token) && safeEqual(token, config.token)
  }

  const server = http.createServer(async (req, res) => {
    const ip = req.socket.remoteAddress || 'unknown'
    if (req.method === 'OPTIONS') return send(res, 204, '')

    const url = new URL(req.url, 'http://localhost')
    const path = url.pathname.replace(/\/+$/, '') || '/'

    if (path === '/health') return send(res, 200, { ok: true, name: 'kantor-agent-bridge', version: VERSION })

    if (path === '/' && req.method === 'GET') {
      return send(res, 200, `Kantor Agent AI bridge v${VERSION} aktif.\nGunakan aplikasi Kantor Agent AI dengan alamat ini dan token bridge.\n`, 'text/plain; charset=utf-8')
    }

    if (!path.startsWith('/api')) return send(res, 404, { error: 'not_found' })

    if (!authorized(req)) return send(res, 401, { error: 'token_tidak_valid' })
    if (req.method === 'POST' && rateLimited(ip)) return send(res, 429, { error: 'terlalu_banyak_permintaan' })

    try {
      if (path === '/api/state' && req.method === 'GET') {
        return send(res, 200, store.snapshot(runner))
      }

      if (path === '/api/events' && req.method === 'POST') {
        const body = await readJson(req)
        const message = String(body.message || '').trim()
        if (!message) return send(res, 422, { error: 'message_wajib' })
        const event = store.pushEvent(body.agent ? String(body.agent) : null, message, body.level)
        return send(res, 201, { ok: true, event })
      }

      if (path === '/api/tasks' && req.method === 'POST') {
        const body = await readJson(req)
        const title = String(body.title || '').trim()
        const detail = String(body.detail || '').trim()
        const agent = String(body.agent || 'hermes')
        if (!title) return send(res, 422, { error: 'judul_wajib' })
        if (title.length > 160 || detail.length > 2000) return send(res, 422, { error: 'teks_terlalu_panjang' })
        if (!store.agent(agent)) return send(res, 422, { error: 'agen_tidak_dikenal' })
        const task = store.addTask({
          id: store.state.seq++,
          title,
          detail,
          agent,
          status: 'queued',
          createdAt: Date.now(),
          updatedAt: Date.now(),
          source: 'app',
        })
        store.pushEvent(agent, `Tugas baru: ${title}`, 'info')
        runner.enqueue(task.id)
        return send(res, 201, { ok: true, task, runner: { enabled: runner.enabled } })
      }

      const runMatch = path.match(/^\/api\/tasks\/(\d+)\/run$/)
      if (runMatch && req.method === 'POST') {
        const task = store.state.tasks.find((row) => row.id === Number(runMatch[1]))
        if (!task) return send(res, 404, { error: 'tugas_tidak_ada' })
        if (!runner.enabled) return send(res, 409, { error: 'runner_mati — jalankan installer dengan --enable-run di VPS' })
        if (task.status === 'working') return send(res, 409, { error: 'tugas_sedang_dikerjakan' })
        task.status = 'queued'
        task.updatedAt = Date.now()
        runner.enqueue(task.id)
        return send(res, 200, { ok: true, task })
      }

      const promptMatch = path.match(/^\/api\/agents\/([\w-]+)\/prompt$/)
      if (promptMatch && req.method === 'POST') {
        const body = await readJson(req)
        const prompt = String(body.prompt || '').trim()
        const agentId = promptMatch[1]
        if (!prompt) return send(res, 422, { error: 'perintah_wajib' })
        if (!store.agent(agentId)) return send(res, 404, { error: 'agen_tidak_dikenal' })
        const task = store.addTask({
          id: store.state.seq++,
          title: prompt.slice(0, 120),
          detail: prompt,
          agent: agentId,
          status: 'queued',
          createdAt: Date.now(),
          updatedAt: Date.now(),
          source: 'prompt',
        })
        store.pushEvent(agentId, `Perintah baru: ${prompt.slice(0, 80)}`, 'info')
        runner.enqueue(task.id)
        return send(res, 201, { ok: true, task })
      }

      if (path === '/api/hooks/claude' && req.method === 'POST') {
        const body = await readJson(req)
        const result = applyClaudeHook(store, body)
        return send(res, 200, { ok: true, agent: result.agent, hook: result.hook })
      }

      return send(res, 404, { error: 'not_found' })
    } catch (err) {
      return send(res, 400, { error: err.message || 'permintaan_tidak_valid' })
    }
  })

  return { server, store, runner }
}

/* ------------------------------------------------------------------ main */

export function runFromCli(argv = process.argv.slice(2)) {
  const opts = parseArgs(argv)

  if (opts.help) {
    console.log(`Kantor Agent AI bridge v${VERSION}

Pemakaian:
  node agent-office-bridge.mjs [opsi]

Opsi:
  --port <n>             Port HTTP (default 3334, env KANTOR_PORT)
  --host <alamat>        Alamat bind (default 127.0.0.1, env KANTOR_HOST)
  --token <rahasia>      Token akses (env KANTOR_TOKEN, atau file <state>/token)
  --state <dir>          Folder state (default ~/.kantor-office, env KANTOR_STATE_DIR)
  --workspace <dir>      Folder kerja Claude Code (default cwd, env KANTOR_WORKSPACE)
  --allow-run            Izinkan menjalankan perintah (env KANTOR_ALLOW_RUN=1)
  --claude-cmd <cmd>     Perintah Claude (default "claude", env KANTOR_CLAUDE_CMD)
  --claude-args <args>   Argumen tambahan, dipisah spasi (env KANTOR_CLAUDE_ARGS)
  --allow-origin <asal>  Origin CORS (default *, env KANTOR_ALLOW_ORIGIN)
  --help                 Tampilkan bantuan ini`)
    return null
  }

  const stateDir = resolve(String(opts.state || process.env.KANTOR_STATE_DIR || join(homedir(), '.kantor-office')))
  mkdirSync(stateDir, { recursive: true })

  const tokenFile = join(stateDir, 'token')
  let token = String(opts.token || process.env.KANTOR_TOKEN || '')
  if (!token && existsSync(tokenFile)) token = readTokenFile(tokenFile)
  if (!token) {
    token = freshToken()
    writeFileSync(tokenFile, `${token}\n`, { mode: 0o600 })
    console.log(`[kantor] token baru dibuat: ${tokenFile}`)
  }

  const config = {
    port: Number(opts.port || process.env.KANTOR_PORT || 3334),
    host: String(opts.host || process.env.KANTOR_HOST || '127.0.0.1'),
    token,
    stateFile: join(stateDir, 'state.json'),
    workspace: resolve(String(opts.workspace || process.env.KANTOR_WORKSPACE || process.cwd())),
    enabled: opts['allow-run'] === true || process.env.KANTOR_ALLOW_RUN === '1',
    command: String(opts['claude-cmd'] || process.env.KANTOR_CLAUDE_CMD || 'claude'),
    args: splitArgs(opts['claude-args'] || process.env.KANTOR_CLAUDE_ARGS || ''),
    allowOrigin: String(opts['allow-origin'] || process.env.KANTOR_ALLOW_ORIGIN || '*'),
  }

  if (!existsSync(config.workspace)) {
    console.error(`[kantor] workspace tidak ditemukan: ${config.workspace}`)
    process.exit(1)
  }

  const { server } = createBridge(config)
  server.listen(config.port, config.host, () => {
    console.log(`[kantor] bridge aktif di http://${config.host}:${config.port}`)
    console.log(`[kantor] workspace: ${config.workspace}`)
    console.log(`[kantor] eksekusi Claude: ${config.enabled ? `aktif (${config.command})` : 'mati (pakai --allow-run)'}`)
    if (config.host === '0.0.0.0' || config.host === '::') {
      console.log('[kantor] peringatan: port terbuka ke jaringan. Pasang token kuat dan HTTPS (Nginx).')
    }
  })

  const stop = () => server.close(() => process.exit(0))
  process.on('SIGINT', stop)
  process.on('SIGTERM', stop)
  return { server, config }
}

const isDirect = process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href
if (isDirect) runFromCli()
