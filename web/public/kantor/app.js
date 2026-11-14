/* Kantor Agent AI — PWA kantor virtual untuk memantau dan mengendalikan
 * sesi Claude Code yang berjalan di VPS melalui bridge (office/bridge).
 * Tanpa bridge terpasang, aplikasi berjalan dalam mode demo lokal. */

const CFG_KEY = 'kantor.bridge.v1';
const POLL_MS = 4000;

const DEFAULT_AGENTS = [
  { id: 'hermes', name: 'Hermes', role: 'Kepala Koordinator', description: 'Menerima instruksi direktur dan membagi pekerjaan ke tim.', shirt: '#367c88', hair: '#352b2b' },
  { id: 'monitor', name: 'Agent Monitoring', role: 'Pemantauan Server & Website', description: 'Pemeriksaan uptime, resource, dan log layanan.', shirt: '#526fbd', hair: '#49372c' },
  { id: 'technician', name: 'Agent Teknisi', role: 'Diagnosis & Perbaikan', description: 'Analisis gangguan dan usulan perbaikan teknis.', shirt: '#9b7545', hair: '#25252a' },
  { id: 'sales', name: 'Agent Penjualan', role: 'Operasional PPOB', description: 'Pemantauan produk, transaksi, dan layanan pelanggan.', shirt: '#5d916b', hair: '#392b25' },
  { id: 'security', name: 'Agent Keamanan', role: 'Keamanan Sistem', description: 'Pemeriksaan konfigurasi dan aktivitas mencurigakan.', shirt: '#9b5d78', hair: '#30272a' },
  { id: 'reports', name: 'Agent Laporan', role: 'Laporan Operasional', description: 'Ringkasan pekerjaan harian dan laporan untuk direktur.', shirt: '#9b8b50', hair: '#3c3028' },
];

const SEATS = [
  { id: 'hermes', x: 150, y: 118 },
  { id: 'monitor', x: 420, y: 104 },
  { id: 'technician', x: 214, y: 190 },
  { id: 'sales', x: 452, y: 184 },
  { id: 'security', x: 206, y: 262 },
  { id: 'reports', x: 396, y: 260 },
];

const STATE_LABEL = { working: 'Bekerja', idle: 'Siaga', needs: 'Butuh Anda', offline: 'Offline' };
const TASK_LABEL = { queued: 'Antre', working: 'Dikerjakan', done: 'Selesai', failed: 'Gagal' };
const LEVELS = new Set(['info', 'success', 'warn', 'error']);

const $ = (id) => document.getElementById(id);
const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

const view = {
  cfg: loadCfg(),
  selected: 'hermes',
  data: null,
  connected: false,
  error: '',
  bubbleTimer: 0,
  installEvent: null,
  busy: false,
};

/* ---------------------------------------------------------------- config */

function loadCfg() {
  try {
    const raw = localStorage.getItem(CFG_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw);
    if (!parsed || typeof parsed.url !== 'string' || typeof parsed.token !== 'string') return null;
    return { url: parsed.url.replace(/\/+$/, ''), token: parsed.token };
  } catch {
    return null;
  }
}

function saveCfg(cfg) {
  view.cfg = cfg;
  if (cfg) localStorage.setItem(CFG_KEY, JSON.stringify(cfg));
  else localStorage.removeItem(CFG_KEY);
}

function normalizeUrl(value) {
  let url = String(value || '').trim().replace(/\/+$/, '');
  if (!url) return '';
  if (!/^https?:\/\//i.test(url)) url = `https://${url}`;
  try {
    const parsed = new URL(url);
    if (!/^https?:$/.test(parsed.protocol)) return '';
    return parsed.toString().replace(/\/+$/, '');
  } catch {
    return '';
  }
}

/* ------------------------------------------------------------ demo data */

function ago(ms) {
  const s = Math.max(1, Math.round(ms / 1000));
  if (s < 60) return `${s} dtk lalu`;
  const m = Math.round(s / 60);
  if (m < 60) return `${m} mnt lalu`;
  const h = Math.round(m / 60);
  if (h < 24) return `${h} jam lalu`;
  return `${Math.round(h / 24)} hari lalu`;
}

function buildDemo() {
  const now = Date.now();
  const agents = DEFAULT_AGENTS.map((a) => ({
    ...a,
    state: 'idle',
    activity: 'Menunggu instruksi',
    updatedAt: now,
  }));

  const tasks = [
    { id: 3, title: 'Audit uptime layanan PPOB', detail: 'Periksa status endpoint dan latensi tiap 5 menit.', agent: 'monitor', status: 'done', createdAt: now - 5400e3, updatedAt: now - 4800e3, result: 'Mode demo — semua endpoint 200, latensi rata-rata 182 ms.' },
    { id: 2, title: 'Rapikan log Nginx yang membesar', detail: 'Putar log harian dan buang arsip lebih dari 14 hari.', agent: 'technician', status: 'working', createdAt: now - 2100e3, updatedAt: now - 300e3 },
    { id: 1, title: 'Ringkas penjualan mingguan', detail: 'Buat ringkasan transaksi sukses 7 hari terakhir.', agent: 'reports', status: 'queued', createdAt: now - 900e3, updatedAt: now - 900e3 },
  ];

  const events = [
    { id: 6, agent: 'technician', message: 'Memeriksa /var/log/nginx/access.log (412 MB)', level: 'info', createdAt: now - 240e3 },
    { id: 5, agent: 'monitor', message: 'Pemeriksaan uptime selesai: semua layanan 200', level: 'success', createdAt: now - 900e3 },
    { id: 4, agent: 'hermes', message: 'Tugas baru dibagi: ringkasan penjualan mingguan', level: 'info', createdAt: now - 950e3 },
    { id: 3, agent: 'reports', message: 'Menunggu antrean eksekusi', level: 'info', createdAt: now - 1000e3 },
    { id: 2, agent: 'security', message: 'Tidak ada login gagal berulang dalam 24 jam', level: 'success', createdAt: now - 3600e3 },
    { id: 1, agent: 'hermes', message: 'Sesi kantor dimulai (mode demo)', level: 'info', createdAt: now - 6000e3 },
  ];

  const demo = { agents, tasks, events, seq: 100, runner: { enabled: false, workspace: '/srv/proyek' }, demo: true };
  applyDemoStates(demo);
  return demo;
}

function applyDemoStates(demo) {
  for (const agent of demo.agents) {
    const active = demo.tasks.find((t) => t.agent === agent.id && t.status === 'working');
    const queued = demo.tasks.find((t) => t.agent === agent.id && t.status === 'queued');
    if (active) {
      agent.state = 'working';
      agent.activity = active.title;
    } else if (queued) {
      agent.state = 'idle';
      agent.activity = 'Menunggu giliran: ' + queued.title;
    } else {
      agent.state = 'idle';
      agent.activity = 'Menunggu instruksi';
    }
  }
}

function demoAddEvent(demo, agent, message, level = 'info') {
  demo.events.unshift({ id: ++demo.seq, agent, message, level, createdAt: Date.now() });
  demo.events = demo.events.slice(0, 60);
}

function tickDemo() {
  const demo = view.demo;
  if (!demo) return;
  const agent = demo.agents[Math.floor(Math.random() * demo.agents.length)];
  const working = demo.tasks.find((t) => t.agent === agent.id && t.status === 'working');
  const queued = demo.tasks.find((t) => t.agent === agent.id && t.status === 'queued');

  if (working) {
    working.status = 'done';
    working.updatedAt = Date.now();
    working.result = `Mode demo — pekerjaan "${working.title}" ditandai selesai. Hubungkan bridge VPS agar Claude Code benar-benar menjalankannya.`;
    demoAddEvent(demo, agent.id, `Selesai: ${working.title}`, 'success');
  } else if (queued && Math.random() < 0.6) {
    queued.status = 'working';
    queued.updatedAt = Date.now();
    demoAddEvent(demo, agent.id, `Mulai mengerjakan: ${queued.title}`, 'info');
  } else {
    const notes = {
      hermes: 'Membagi ulang prioritas antrean tugas',
      monitor: 'Cek berkala: disk 41%, RAM 38%, layanan normal',
      technician: 'Meninjau log aplikasi tanpa temuan kritis',
      sales: 'Rekap transaksi terakhir: semua sukses',
      security: 'Pemindaian konfigurasi: tidak ada perubahan',
      reports: 'Menyiapkan ringkasan untuk direktur',
    };
    demoAddEvent(demo, agent.id, notes[agent.id] || 'Aktivitas rutin', 'info');
  }

  applyDemoStates(demo);
  render();
}

/* --------------------------------------------------------- bridge client */

async function api(path, options = {}) {
  if (!view.cfg) throw new Error('Bridge belum dihubungkan.');
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 8000);
  try {
    const headers = { Accept: 'application/json' };
    if (options.body) headers['Content-Type'] = 'application/json';
    headers.Authorization = `Bearer ${view.cfg.token}`;
    const res = await fetch(view.cfg.url + path, {
      ...options,
      headers,
      cache: 'no-store',
      signal: controller.signal,
    });
    let body = null;
    try { body = await res.json(); } catch { body = null; }
    if (res.status === 401) throw new Error('Token bridge tidak cocok.');
    if (!res.ok) throw new Error((body && (body.error || body.message)) || `Bridge menjawab ${res.status}.`);
    return body;
  } catch (err) {
    if (err.name === 'AbortError') throw new Error('Bridge tidak menjawab (timeout).');
    if (err instanceof TypeError) throw new Error('Tidak bisa menghubungi bridge. Periksa alamat dan HTTPS-nya.');
    throw err;
  } finally {
    clearTimeout(timer);
  }
}

async function refresh() {
  if (!view.cfg) {
    view.data = view.demo;
    view.connected = false;
    render();
    return;
  }
  try {
    const data = await api('/api/state');
    view.data = data;
    view.connected = true;
    view.error = '';
  } catch (err) {
    view.connected = false;
    view.error = err.message;
  }
  render();
}

/* ------------------------------------------------------------- rendering */

function currentData() {
  return view.cfg ? view.data : view.demo;
}

// Warna hanya berupa hex; data bridge tidak boleh menyisipkan CSS lain.
function safeColor(value, fallback) {
  return /^#[0-9a-f]{3,8}$/i.test(String(value || '')) ? value : fallback;
}

function agentColor(agent) {
  const fallback = DEFAULT_AGENTS.find((a) => a.id === agent.id);
  return safeColor(agent.shirt, fallback ? fallback.shirt : '#527a8a');
}

function render() {
  const data = currentData();
  if (!data) return;
  const agents = Array.isArray(data.agents) ? data.agents : [];
  if (!agents.some((a) => a.id === view.selected) && agents.length) view.selected = agents[0].id;

  renderConnection();
  renderStats(data, agents);
  renderStations(agents);
  renderAgentPanel(agents);
  renderTaskAgentOptions(agents);
  renderTasks(data.tasks || [], data.runner);
  renderFeed(data.events || []);
  renderAgentList(agents);
  renderBoard(data.tasks || []);
}

function renderConnection() {
  const pill = $('connPill');
  const text = $('connText');
  const live = $('liveTag');
  if (view.cfg && view.connected) {
    pill.dataset.mode = 'live';
    text.textContent = 'LIVE';
    live.textContent = 'TERHUBUNG VPS';
    live.classList.remove('off');
  } else if (view.cfg) {
    pill.dataset.mode = 'offline';
    text.textContent = 'TERPUTUS';
    live.textContent = 'TIDAK TERHUBUNG';
    live.classList.add('off');
  } else {
    pill.dataset.mode = 'demo';
    text.textContent = 'DEMO';
    live.textContent = 'DEMO LOKAL';
    live.classList.add('off');
  }
}

function renderStats(data, agents) {
  const working = agents.filter((a) => a.state === 'working').length;
  const queued = (data.tasks || []).filter((t) => t.status === 'queued' || t.status === 'working').length;
  const events = (data.events || []).length;

  $('stats').innerHTML = [
    `<div class="stat"><small>AGEN</small><strong>${agents.length}</strong></div>`,
    `<div class="stat"><small>BEKERJA</small><strong class="gold">${working}</strong></div>`,
    `<div class="stat"><small>ANTRE</small><strong>${queued}</strong></div>`,
    `<div class="stat"><small>EVENT</small><strong class="mint">${events}</strong></div>`,
  ].join('');
}

function renderStations(agents) {
  const byId = new Map(agents.map((a) => [a.id, a]));
  const extra = agents.filter((a) => !SEATS.some((s) => s.id === a.id));

  const seats = SEATS.map((seat, index) => {
    const agent = byId.get(seat.id);
    if (!agent) return '';
    return stationMarkup(agent, seat, index % 3 === 1);
  });

  const extraSeats = extra.slice(0, 4).map((agent, i) =>
    stationMarkup(agent, { id: agent.id, x: 140 + i * 130, y: 330 }, false));

  $('stations').innerHTML = seats.concat(extraSeats).join('');
  document.querySelectorAll('.station').forEach((el) => {
    el.addEventListener('click', () => selectAgent(el.dataset.agent));
  });
}

function stationMarkup(agent, seat, small) {
  const state = agent.state || 'idle';
  const shirt = agentColor(agent);
  const hair = safeColor(agent.hair, '#352b2b');
  return `
    <div class="station${agent.id === view.selected ? ' selected' : ''}" data-agent="${esc(agent.id)}" data-state="${esc(state)}"
         style="left:${seat.x}px;top:${seat.y}px${small ? ';transform:scale(.92)' : ''}">
      <div class="desk"><i class="screen"></i><i class="paper"></i></div>
      <div class="person" style="--shirt:${esc(shirt)};--hair:${esc(hair)}" role="button" tabindex="0"
           aria-label="${esc(agent.name)} — ${esc(STATE_LABEL[state] || state)}">
        <i class="head"></i><i class="hair"></i><i class="body"></i><i class="arm"></i><i class="legs"></i>
        <span class="badge">!</span>
        <span class="tag">${esc(shortName(agent.name))}</span>
      </div>
    </div>`;
}

function shortName(name) {
  const text = String(name || 'AGEN').replace(/^Agent\s+/i, '');
  return text.length > 11 ? `${text.slice(0, 10)}…` : text.toUpperCase();
}

function renderAgentPanel(agents) {
  const agent = agents.find((a) => a.id === view.selected);
  const panel = $('agentPanel');
  if (!agent) {
    panel.innerHTML = '<p class="empty">Belum ada agen. Hubungkan bridge di VPS untuk melihat sesi Claude Code.</p>';
    return;
  }
  const state = agent.state || 'idle';
  const tasks = (currentData().tasks || []).filter((t) => t.agent === agent.id);
  panel.innerHTML = `
    <div class="agent-card">
      <div class="avatar" style="--shirt:${esc(agentColor(agent))}"></div>
      <div class="who">
        <strong>${esc(agent.name)}</strong>
        <small>${esc(agent.role || 'Agen')}</small>
        <div style="margin-top:6px"><span class="pill ${esc(state)}"><i></i>${esc(STATE_LABEL[state] || state)}</span></div>
      </div>
      <button class="ghost-btn" id="panelPrompt" type="button">Perintah</button>
    </div>
    <p><b>Aktivitas:</b> ${esc(agent.activity || 'Menunggu instruksi')}</p>
    <p class="hint">${esc(agent.description || '')} · ${tasks.length} tugas tercatat${agent.sessionId ? ` · sesi ${esc(String(agent.sessionId).slice(0, 8))}` : ''}</p>`;
  const button = $('panelPrompt');
  if (button) button.addEventListener('click', () => openPrompt(agent));
}

function renderTaskAgentOptions(agents) {
  const select = $('taskAgent');
  if (!select) return;
  const value = select.value;
  select.innerHTML = agents.map((a) => `<option value="${esc(a.id)}">${esc(a.name)} — ${esc(a.role || '')}</option>`).join('');
  if (agents.some((a) => a.id === value)) select.value = value;
  else if (agents.some((a) => a.id === view.selected)) select.value = view.selected;
}

function renderTasks(tasks, runner) {
  const list = $('taskList');
  const sorted = [...tasks].sort((a, b) => (b.createdAt || 0) - (a.createdAt || 0));
  if (!sorted.length) {
    list.innerHTML = '<p class="empty">Belum ada tugas. Tambahkan tugas pertama di atas.</p>';
    return;
  }
  const agents = new Map((currentData().agents || []).map((a) => [a.id, a]));
  list.innerHTML = sorted.slice(0, 40).map((task) => {
    const agent = agents.get(task.agent);
    const canRun = runner && runner.enabled && task.status === 'queued';
    return `<div class="task">
      <div class="task__top">
        <div>
          <div class="task__title">${esc(task.title)}</div>
          <div class="task__meta">${esc(agent ? agent.name : task.agent || 'kantor')} · ${esc(ago(Date.now() - (task.createdAt || Date.now())))}</div>
        </div>
        <span class="chip ${esc(task.status)}">${esc(TASK_LABEL[task.status] || task.status)}</span>
      </div>
      ${task.detail ? `<div class="task__detail">${esc(task.detail)}</div>` : ''}
      ${task.result ? `<div class="task__result">${esc(task.result)}</div>` : ''}
      ${task.error ? `<div class="task__result">${esc(task.error)}</div>` : ''}
      ${canRun ? `<div class="task__actions"><button class="ghost-btn" data-run="${esc(task.id)}" type="button">Jalankan sekarang</button></div>` : ''}
    </div>`;
  }).join('');

  list.querySelectorAll('[data-run]').forEach((button) => {
    button.addEventListener('click', () => runTask(button.dataset.run, button));
  });

  $('taskHint').textContent = view.cfg
    ? (runner && runner.enabled
      ? `Eksekusi aktif di ${runner.workspace || 'VPS'} — tugas baru langsung dijalankan Claude Code.`
      : 'Bridge tersambung, tetapi eksekusi masih dimatikan. Jalankan installer dengan --enable-run di VPS.')
    : 'Mode demo: tugas hanya disimpan di HP ini. Hubungkan bridge VPS agar Claude Code mengerjakannya.';
}

function renderFeed(events) {
  const feed = $('feed');
  if (!events.length) {
    feed.innerHTML = '<p class="empty">Belum ada aktivitas tercatat.</p>';
    return;
  }
  feed.innerHTML = events.slice(0, 40).map((event) => {
    const level = LEVELS.has(event.level) ? event.level : 'info';
    const agent = (currentData().agents || []).find((a) => a.id === event.agent);
    return `<div class="feed__row">
      <i class="${esc(level)}"></i>
      <div><b>${esc(agent ? agent.name : (event.agent || 'SISTEM'))}</b> · ${esc(event.message)}
      <small>${esc(ago(Date.now() - (event.createdAt || Date.now())))}</small></div>
    </div>`;
  }).join('');
}

function renderAgentList(agents) {
  const list = $('agentList');
  if (!agents.length) {
    list.innerHTML = '<p class="empty">Belum ada agen.</p>';
    return;
  }
  list.innerHTML = agents.map((agent) => {
    const state = agent.state || 'idle';
    return `<button class="row-item" type="button" data-agent="${esc(agent.id)}">
      <span class="mini-avatar" style="--shirt:${esc(agentColor(agent))}"></span>
      <span><strong>${esc(agent.name)}</strong><small>${esc(agent.activity || agent.role || '')}</small></span>
      <span class="pill ${esc(state)}"><i></i>${esc(STATE_LABEL[state] || state)}</span>
    </button>`;
  }).join('');
  list.querySelectorAll('[data-agent]').forEach((el) => {
    el.addEventListener('click', () => {
      selectAgent(el.dataset.agent);
      switchView('office');
    });
  });
}

function renderBoard(tasks) {
  const active = tasks.filter((t) => t.status === 'working' || t.status === 'queued').slice(0, 3);
  $('board').innerHTML = `PAPAN TUGAS<br>──────────<br>${active.length
    ? active.map((t) => `• ${esc(t.title.slice(0, 22))}`).join('<br>')
    : '• Belum ada tugas'}`;
}

/* --------------------------------------------------------------- actions */

function selectAgent(id) {
  view.selected = id;
  render();
  const agent = (currentData().agents || []).find((a) => a.id === id);
  if (agent) showBubble(agent);
}

function showBubble(agent) {
  const bubble = $('bubble');
  const seat = SEATS.find((s) => s.id === agent.id);
  if (!seat) {
    bubble.hidden = true;
    return;
  }
  bubble.hidden = false;
  bubble.innerHTML = `<b>${esc(agent.name)}</b>${esc(agent.activity || 'Menunggu instruksi')}`;
  const left = Math.min(Math.max(seat.x - 24, 6), 720 - 200);
  const top = seat.y > 70 ? seat.y - 56 : seat.y + 100;
  bubble.style.left = `${left}px`;
  bubble.style.top = `${top}px`;
  clearTimeout(view.bubbleTimer);
  view.bubbleTimer = setTimeout(() => { bubble.hidden = true; }, 7000);
}

function openSheet(html) {
  $('sheetContent').innerHTML = html;
  $('sheet').hidden = false;
}

function closeSheet() {
  $('sheet').hidden = true;
  $('sheetContent').innerHTML = '';
}

function openPrompt(agent) {
  openSheet(`
    <h3 id="sheetTitle">Beri perintah — ${esc(agent.name)}</h3>
    <p class="sub">Perintah dikirim sebagai tugas ke bridge. Claude Code di VPS mengerjakannya bila eksekusi aktif.</p>
    <form class="form" id="promptForm">
      <label>Perintah untuk agen
        <textarea id="promptText" rows="3" maxlength="2000" placeholder="Contoh: periksa error terakhir di log aplikasi dan ringkas penyebabnya" required></textarea>
      </label>
      <button class="primary-btn" type="submit" id="promptSend">Kirim ke antrean</button>
    </form>`);
  const form = $('promptForm');
  const text = $('promptText');
  text.focus();
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const value = text.value.trim();
    if (!value || view.busy) return;
    view.busy = true;
    $('promptSend').disabled = true;
    try {
      await createTask({ title: value.slice(0, 120), detail: value, agent: agent.id });
      closeSheet();
      toast(`Perintah untuk ${agent.name} masuk antrean.`);
      switchView('tasks');
    } catch (err) {
      toast(err.message, true);
    } finally {
      view.busy = false;
      const button = $('promptSend');
      if (button) button.disabled = false;
    }
  });
}

async function createTask({ title, detail, agent }) {
  if (!title) throw new Error('Judul tugas wajib diisi.');
  if (view.cfg) {
    await api('/api/tasks', { method: 'POST', body: JSON.stringify({ title, detail, agent }) });
    await refresh();
    return;
  }

  const demo = view.demo;
  const task = {
    id: ++demo.seq,
    title,
    detail,
    agent,
    status: 'queued',
    createdAt: Date.now(),
    updatedAt: Date.now(),
  };
  demo.tasks.unshift(task);
  demoAddEvent(demo, agent, `Tugas baru: ${title}`, 'info');
  applyDemoStates(demo);
  render();
}

async function runTask(id, button) {
  if (!view.cfg) {
    toast('Mode demo belum menjalankan tugas nyata. Hubungkan bridge VPS.', true);
    return;
  }
  button.disabled = true;
  try {
    await api(`/api/tasks/${encodeURIComponent(id)}/run`, { method: 'POST', body: '{}' });
    toast('Tugas dikirim ke runner.');
    await refresh();
  } catch (err) {
    toast(err.message, true);
    button.disabled = false;
  }
}

function toast(message, isError = false) {
  const el = $('toast');
  el.textContent = message;
  el.classList.toggle('error', Boolean(isError));
  el.hidden = false;
  clearTimeout(toast.timer);
  toast.timer = setTimeout(() => { el.hidden = true; }, 3600);
}

function switchView(name) {
  document.querySelectorAll('.view').forEach((section) => {
    section.hidden = section.dataset.view !== name;
  });
  document.querySelectorAll('.tabbar button').forEach((button) => {
    button.classList.toggle('active', button.dataset.tab === name);
  });
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

/* -------------------------------------------------------------- settings */

function renderSettings() {
  $('bridgeUrl').value = view.cfg ? view.cfg.url : '';
  $('bridgeToken').value = view.cfg ? view.cfg.token : '';
  $('bridgeMsg').textContent = view.cfg
    ? (view.connected ? 'Tersambung ke bridge VPS.' : `Belum tersambung: ${view.error || 'menunggu balasan'}`)
    : 'Belum ada bridge. Aplikasi berjalan dalam mode demo.';
  $('bridgeDisconnect').disabled = !view.cfg;
  $('aboutText').textContent = 'Kantor Agent AI · memantau sesi Claude Code melalui bridge lokal di VPS. Mode demo tidak menjalankan perintah apa pun.';
}

async function saveBridge(event) {
  event.preventDefault();
  const url = normalizeUrl($('bridgeUrl').value);
  const token = $('bridgeToken').value.trim();
  if (!url) {
    $('bridgeMsg').textContent = 'Alamat bridge tidak valid. Contoh: https://kantor.domainanda.com';
    return;
  }
  if (!token) {
    $('bridgeMsg').textContent = 'Token bridge wajib diisi.';
    return;
  }

  const button = $('bridgeSave');
  button.disabled = true;
  $('bridgeMsg').textContent = 'Menguji koneksi…';
  const previous = view.cfg;
  view.cfg = { url, token };
  try {
    await api('/api/state');
    saveCfg({ url, token });
    view.connected = true;
    view.error = '';
    await refresh();
    renderSettings();
    toast('Bridge VPS tersambung.');
  } catch (err) {
    view.cfg = previous;
    view.connected = false;
    $('bridgeMsg').textContent = err.message;
  } finally {
    button.disabled = false;
  }
}

function disconnectBridge() {
  saveCfg(null);
  view.connected = false;
  view.error = '';
  view.data = null;
  view.demo = buildDemo();
  render();
  renderSettings();
  toast('Bridge dilepas. Kembali ke mode demo.');
}

/* ------------------------------------------------------------------ init */

function fitScene() {
  const scene = $('scene');
  const world = $('world');
  const width = scene.clientWidth || 360;
  const scale = Math.min(1.02, Math.max(0.34, width / 720));
  world.style.transform = `translateX(-50%) scale(${scale})`;
  scene.style.height = `${Math.round(430 * scale)}px`;
}

function bindEvents() {
  document.querySelectorAll('.tabbar button').forEach((button) => {
    button.addEventListener('click', () => switchView(button.dataset.tab));
  });

  $('btnPrompt').addEventListener('click', () => {
    const agent = (currentData().agents || []).find((a) => a.id === view.selected);
    if (agent) openPrompt(agent);
  });

  $('taskForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const title = $('taskTitle').value.trim();
    const detail = $('taskDetail').value.trim();
    const agent = $('taskAgent').value;
    if (!title || view.busy) return;
    const submit = $('taskSubmit');
    view.busy = true;
    submit.disabled = true;
    try {
      await createTask({ title, detail, agent });
      $('taskForm').reset();
      renderTaskAgentOptions(currentData().agents || []);
      toast('Tugas masuk antrean.');
    } catch (err) {
      toast(err.message, true);
    } finally {
      view.busy = false;
      submit.disabled = false;
    }
  });

  $('bridgeForm').addEventListener('submit', saveBridge);
  $('bridgeDisconnect').addEventListener('click', disconnectBridge);

  $('sheet').addEventListener('click', (event) => {
    if (event.target.closest('[data-close-sheet]')) closeSheet();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeSheet();
  });

  $('connPill').addEventListener('click', () => switchView('settings'));

  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    view.installEvent = event;
    $('installBtn').hidden = false;
  });

  $('installBtn').addEventListener('click', async () => {
    if (!view.installEvent) {
      toast('Gunakan menu browser → "Instal aplikasi" / "Tambahkan ke Layar Utama".');
      return;
    }
    view.installEvent.prompt();
    view.installEvent = null;
    $('installBtn').hidden = true;
  });

  window.addEventListener('resize', fitScene);
  new ResizeObserver(fitScene).observe($('scene'));
}

function registerServiceWorker() {
  if (!('serviceWorker' in navigator)) return;
  if (!/^https:$/.test(location.protocol) && location.hostname !== 'localhost') return;
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('./sw.js').catch(() => { /* offline shell opsional */ });
  });
}

function start() {
  view.demo = buildDemo();
  bindEvents();
  fitScene();
  renderSettings();
  render();

  if (view.cfg) refresh();
  else render();

  setInterval(() => {
    if (document.visibilityState === 'hidden') return;
    if (view.cfg) refresh();
    else tickDemo();
  }, POLL_MS);

  registerServiceWorker();
}

start();
