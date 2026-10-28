// WA Gateway DIWAN — Baileys + Express
// Jalankan: node server.js
// Endpoint: POST /send, GET /status, GET /qr

import express from 'express';
import P from 'pino';
import makeWASocket, {
  useMultiFileAuthState,
  makeCacheableSignalKeyStore,
  DisconnectReason,
  fetchLatestBaileysVersion,
} from '@whiskeysockets/baileys';
import { Boom } from '@hapi/boom';
import QRCode from 'qrcode';
import { readFileSync, existsSync, renameSync } from 'fs';

const logger = P({ level: process.env.LOG_LEVEL || 'warn' });

const PORT = process.env.PORT || 3001;
const AUTH_DIR = './auth_state';
const API_KEY = process.env.API_KEY || 'diwan-wa-secret';
// Masa berlaku tiap QR, dalam milidetik. Baileys default memakai 20 detik untuk QR berikutnya.
const QR_TIMEOUT_MS = 5 * 60 * 1000;

// Anti-spam: jeda default antar pesan broadcast (detik), bisa di-override per request
const DEFAULT_DELAY_MIN = 8;
const DEFAULT_DELAY_MAX = 15;

let sock = null;
let qrCode = null;
let connectionStatus = 'disconnected'; // disconnected | connecting | open
let pairingRequested = false;
let pairingTimeout = null;

// Cache pesan terkirim agar getMessage bisa mengembalikannya saat protokol WA minta ulang (retry/relay)
const sentMessages = new Map();
const SENT_CACHE_MAX = 100;

// Rate limiter: antrian serial agar pesan tidak dikirim bersamaan
let sendQueue = Promise.resolve();
function enqueue(fn, delayMin, delayMax) {
  const min = Math.max(3, delayMin || DEFAULT_DELAY_MIN);
  const max = Math.max(min + 1, delayMax || DEFAULT_DELAY_MAX);
  const delay = (Math.random() * (max - min) + min) * 1000;
  sendQueue = sendQueue.then(() => fn()).then(() => new Promise(r => setTimeout(r, delay)));
  return sendQueue;
}

async function startWhatsApp() {
  const { state, saveCreds } = await useMultiFileAuthState(AUTH_DIR);
  const { version } = await fetchLatestBaileysVersion();

  sock = makeWASocket({
    version,
    auth: {
      creds: state.creds,
      keys: makeCacheableSignalKeyStore(state.keys, logger),
    },
    browser: ['DIWAN WA Gateway', 'Chrome', '131.0'],
    generateHighQualityLinkPreview: false,
    defaultQueryTimeoutMs: 60000,
    qrTimeout: QR_TIMEOUT_MS,
    logger,
    getMessage: async (key) => sentMessages.get(key.id),
  });

  sock.ev.on('creds.update', saveCreds);

  sock.ev.on('connection.update', (update) => {
    const { connection, lastDisconnect, qr } = update;

    if (qr && !pairingRequested) {
      qrCode = qr;
      connectionStatus = 'connecting';
      console.log('[WA] QR code diperbarui — buka http://localhost:' + PORT + '/qr untuk scan');
    }

    if (connection === 'close') {
      connectionStatus = 'disconnected';
      qrCode = null;
      pairingRequested = false;
      if (pairingTimeout) clearTimeout(pairingTimeout);
      pairingTimeout = null;

      const statusCode = (lastDisconnect?.error)?.output?.statusCode;
      const shouldReconnect = statusCode !== DisconnectReason.loggedOut;

      console.log('[WA] Koneksi terputus. Status:', statusCode, shouldReconnect ? '→ reconnect...' : '→ logged out');

      if (shouldReconnect) {
        setTimeout(startWhatsApp, 3000);
      } else {
        console.log('[WA] Akun di-logout. Hapus folder auth_state/ dan scan ulang QR.');
      }
    }

    if (connection === 'open') {
      connectionStatus = 'open';
      qrCode = null;
      pairingRequested = false;
      if (pairingTimeout) clearTimeout(pairingTimeout);
      pairingTimeout = null;
      console.log('[WA] Terhubung!');
    }
  });
}

// --- Express API ---

const app = express();
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

function authMiddleware(req, res, next) {
  const token = req.headers['authorization'] || '';
  if (token !== API_KEY) {
    return res.status(401).json({ ok: false, error: 'Token tidak valid' });
  }
  next();
}

// Status koneksi
app.get('/status', authMiddleware, (req, res) => {
  res.json({
    ok: true,
    status: connectionStatus,
    hasQr: qrCode !== null,
  });
});

// Kode pasangan alternatif untuk menghubungkan perangkat tanpa memindai QR.
// Hanya boleh diminta saat socket baru menunggu proses tautan, bukan saat sesi sudah terdaftar.
app.post('/pairing-code', authMiddleware, async (req, res) => {
  const phone = String(req.body.phone || '').replace(/\D/g, '');
  if (!/^\d{10,15}$/.test(phone) || !phone.startsWith('62')) {
    return res.status(400).json({ ok: false, error: 'Nomor harus memakai kode negara, misalnya 62812xxxxxxx.' });
  }
  if (!sock || connectionStatus !== 'connecting' || !qrCode || sock.authState?.creds?.registered) {
    return res.status(409).json({ ok: false, error: 'Gateway belum siap untuk ditautkan. Pastikan koneksi sedang menunggu QR atau kode pasangan.' });
  }
  if (pairingRequested) {
    return res.status(409).json({ ok: false, error: 'Permintaan kode pasangan sedang diproses. Tunggu hasilnya atau mulai ulang gateway.' });
  }

  pairingRequested = true;
  qrCode = null;
  try {
    const code = await sock.requestPairingCode(phone);
    pairingTimeout = setTimeout(() => {
      if (pairingRequested && connectionStatus !== 'open') {
        pairingRequested = false;
        console.log('[WA] Sesi kode pasangan berakhir; menunggu QR terbaru.');
      }
      pairingTimeout = null;
    }, 120000);
    res.json({ ok: true, code });
  } catch (err) {
    pairingRequested = false;
    if (pairingTimeout) clearTimeout(pairingTimeout);
    pairingTimeout = null;
    console.error('[WA] Gagal membuat kode pasangan:', err.message);
    res.status(500).json({ ok: false, error: 'WhatsApp gagal membuat kode pasangan. Tunggu QR baru lalu coba lagi.' });
  }
});

// Kerangka halaman QR -- satu tampilan konsisten untuk ketiga kondisi (terhubung/menunggu/scan).
function qrPage({ badge, badgeTone, title, body, reloadMs, displayMinutes = 0 }) {
  return `<!doctype html>
<html lang="id"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>DIWAN WA Gateway</title>
<style>
  :root {
    --primer: #0f96b0; --primer-gelap: #07546a; --primer-terang: #2cbcd2;
    --bg: #f4f9fb; --surface: #fff; --border: #dbe8ec; --text: #17323b; --muted: #557480;
    --ok: #145c37; --ok-bg: #e5f4ec; --ok-line: #b4dcc5;
    --warn: #7a5712; --warn-bg: #fdf4e3; --warn-line: #ecd9a4;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
    padding: 24px; background: linear-gradient(155deg, #34c4d9 0%, var(--primer) 48%, var(--primer-gelap) 100%);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: var(--text);
  }
  .card {
    width: 100%; max-width: 380px; background: var(--surface); border-radius: 18px;
    padding: 28px 24px 24px; box-shadow: 0 20px 50px rgba(7, 84, 106, .35); text-align: center;
  }
  .wordmark { font-size: 13px; font-weight: 700; letter-spacing: .12em; color: var(--primer-gelap); text-transform: uppercase; }
  .badge {
    display: inline-flex; align-items: center; gap: 6px; margin: 12px 0 4px; padding: 5px 12px;
    border-radius: 999px; font-size: 12px; font-weight: 600;
    background: var(--badge-bg); color: var(--badge-fg); border: 1px solid var(--badge-line);
  }
  .dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
  h1 { font-size: 18px; margin: 10px 0 6px; color: var(--primer-gelap); }
  p { font-size: 14px; line-height: 1.5; color: var(--muted); margin: 0 0 4px; }
  .qr-wrap {
    margin: 18px auto; padding: 14px; width: fit-content; background: #fff;
    border: 1px solid var(--border); border-radius: 14px;
  }
  .qr-wrap img { display: block; width: 240px; height: 240px; }
  ol { text-align: left; margin: 16px 0 4px; padding-left: 20px; font-size: 13.5px; color: var(--text); }
  ol li { margin-bottom: 6px; }
  ol li b { color: var(--primer-gelap); }
  .spinner {
    width: 36px; height: 36px; margin: 20px auto; border-radius: 50%;
    border: 3px solid var(--border); border-top-color: var(--primer); animation: spin 1s linear infinite;
  }
  @keyframes spin { to { transform: rotate(360deg); } }
  .check {
    width: 52px; height: 52px; margin: 16px auto 4px; border-radius: 50%; background: var(--ok-bg);
    display: flex; align-items: center; justify-content: center; color: var(--ok); font-size: 26px;
  }
  .foot { margin-top: 16px; font-size: 12px; color: var(--muted); opacity: .8; }
</style>
</head><body>
  <div class="card">
    <div class="wordmark">DIWAN</div>
    <span class="badge" style="--badge-bg:${badgeTone.bg}; --badge-fg:${badgeTone.fg}; --badge-line:${badgeTone.line}">
      <span class="dot"></span>${badge}
    </span>
    <h1>${title}</h1>
    ${body}
  </div>
  ${reloadMs ? `<script>
    (function () {
      var interval = ${reloadMs};
      var duration = ${Math.max(0, displayMinutes) * 60000};
      if (!duration) { setTimeout(function () { location.reload(); }, interval); return; }
      var key = 'diwan-wa-qr-display-start';
      var started = Number(sessionStorage.getItem(key) || 0);
      if (!started) { started = Date.now(); sessionStorage.setItem(key, String(started)); }
      var remaining = duration - (Date.now() - started);
      if (remaining <= 0) {
        var qr = document.querySelector('.qr-wrap');
        if (qr) qr.style.display = 'none';
        var instructions = document.querySelector('.qr-instructions');
        if (instructions) instructions.style.display = 'none';
        var expired = document.querySelector('.qr-expired');
        if (expired) expired.style.display = 'block';
        return;
      }
      setTimeout(function () { location.reload(); }, Math.min(interval, remaining));
    })();
  </script>` : ''}
</body></html>`;
}

const TONE_OK = { bg: '#e5f4ec', fg: '#145c37', line: '#b4dcc5' };
const TONE_WAIT = { bg: '#fdf4e3', fg: '#7a5712', line: '#ecd9a4' };

// QR code untuk scan (halaman HTML)
app.get('/qr', (req, res) => {
  if (connectionStatus === 'open') {
    return res.send(qrPage({
      badge: 'Terhubung', badgeTone: TONE_OK,
      title: 'WhatsApp Sudah Tersambung',
      body: `
        <div class="check">✓</div>
        <p>WA Gateway sudah terhubung dan siap mengirim pesan.<br>Tidak perlu scan QR lagi.</p>
        <p class="foot">Halaman ini tidak akan diperbarui otomatis.</p>
      `,
    }));
  }

  if (!qrCode) {
    return res.send(qrPage({
      badge: 'Menyiapkan', badgeTone: TONE_WAIT,
      title: 'Menyiapkan Kode QR…',
      body: `
        <div class="spinner"></div>
        <p>Mohon tunggu sebentar, kode QR sedang dibuat.</p>
      `,
      reloadMs: 3000,
    }));
  }

  const displayMinutes = Math.min(10, Math.max(1, parseInt(req.query.minutes, 10) || 5));
  QRCode.toDataURL(qrCode, { width: 480, margin: 1 }).then(dataUrl => {
    res.send(qrPage({
      badge: 'Menunggu scan', badgeTone: TONE_WAIT,
      title: 'Hubungkan WhatsApp',
      body: `
        <div class="qr-wrap"><img src="${dataUrl}" alt="Kode QR WhatsApp"></div>
        <ol class="qr-instructions">
          <li>Buka <b>WhatsApp</b> di HP yang jadi perangkat pengirim</li>
          <li>Ketuk <b>Titik tiga (⋮) → Perangkat tertaut</b></li>
          <li>Ketuk <b>Tautkan perangkat</b>, lalu arahkan kamera ke kode di atas</li>
        </ol>
        <p class="foot">QR berlaku hingga sekitar 5 menit dan diperbarui otomatis. Halaman pemindaian tersedia ${displayMinutes} menit.</p>
        <div class="qr-expired" style="display:none;margin-top:14px;padding:12px;border-radius:10px;background:var(--warn-bg);color:var(--warn)">
          Waktu pemindaian ${displayMinutes} menit selesai. Tekan tombol untuk memulai sesi tampilan baru dengan QR terbaru.
          <button type="button" onclick="sessionStorage.removeItem('diwan-wa-qr-display-start');location.reload()" style="display:block;margin:12px auto 0;padding:9px 14px;border:0;border-radius:8px;background:#0f96b0;color:#fff;font-weight:700">Mulai lagi</button>
        </div>
      `,
      reloadMs: 20000,
      displayMinutes,
    }));
  });
});

function formatPhone(raw) {
  let phone = raw.replace(/\D/g, '');
  if (phone.startsWith('0')) phone = '62' + phone.slice(1);
  if (!phone.startsWith('62')) phone = '62' + phone;
  return phone;
}

async function doSend(phone, message, simulate = false) {
  const jid = phone + '@s.whatsapp.net';

  if (simulate) {
    await sock.presenceSubscribe(jid);
    await sock.sendPresenceUpdate('composing', jid);
    await new Promise(r => setTimeout(r, 2000 + Math.random() * 2000));
    await sock.sendPresenceUpdate('paused', jid);
  }

  const result = await sock.sendMessage(jid, { text: message });

  if (result?.key?.id) {
    if (sentMessages.size >= SENT_CACHE_MAX) {
      const oldest = sentMessages.keys().next().value;
      sentMessages.delete(oldest);
    }
    sentMessages.set(result.key.id, { conversation: message });
  }

  return result;
}

// Kirim pesan langsung (OTP, notifikasi) — tanpa antrian
app.post('/send', authMiddleware, async (req, res) => {
  if (connectionStatus !== 'open' || !sock) {
    return res.status(503).json({ ok: false, error: 'WhatsApp belum terhubung' });
  }

  const target = (req.body.target || '').toString().trim();
  const message = (req.body.message || '').toString().trim();

  if (!target || !message) {
    return res.status(400).json({ ok: false, error: 'target dan message wajib diisi' });
  }

  const phone = formatPhone(target);

  try {
    const result = await doSend(phone, message);
    res.json({ ok: true, id: result?.key?.id || null, status: 'sent' });
  } catch (err) {
    console.error('[WA] Gagal kirim:', err.message);
    res.status(500).json({ ok: false, error: err.message });
  }
});

// Kirim pesan via antrian (broadcast) — jeda acak 8-15 detik antar pesan
app.post('/send-queued', authMiddleware, async (req, res) => {
  if (connectionStatus !== 'open' || !sock) {
    return res.status(503).json({ ok: false, error: 'WhatsApp belum terhubung' });
  }

  const target = (req.body.target || '').toString().trim();
  const message = (req.body.message || '').toString().trim();

  if (!target || !message) {
    return res.status(400).json({ ok: false, error: 'target dan message wajib diisi' });
  }

  const phone = formatPhone(target);
  const delayMin = parseInt(req.body.delay_min) || DEFAULT_DELAY_MIN;
  const delayMax = parseInt(req.body.delay_max) || DEFAULT_DELAY_MAX;

  try {
    let sendResult;
    await enqueue(async () => {
      sendResult = await doSend(phone, message, true);
    }, delayMin, delayMax);
    res.json({ ok: true, id: sendResult?.key?.id || null, status: 'sent' });
  } catch (err) {
    console.error('[WA] Gagal kirim (queued):', err.message);
    res.status(500).json({ ok: false, error: err.message });
  }
});

app.post('/reset', authMiddleware, async (req, res) => {
  console.log('[WA] Reset: memindahkan auth_state ke cadangan dan memulai ulang koneksi...');
  try {
    if (sock) {
      sock.ev.removeAllListeners();
      await sock.logout().catch(() => {});
      sock = null;
    }
  } catch (_) {}

  connectionStatus = 'disconnected';
  qrCode = null;
  pairingRequested = false;
  if (pairingTimeout) clearTimeout(pairingTimeout);
  pairingTimeout = null;
  sentMessages.clear();

  if (existsSync(AUTH_DIR)) {
    renameSync(AUTH_DIR, `${AUTH_DIR}.lama.${Date.now()}`);
  }

  startWhatsApp();
  res.json({ ok: true, message: 'auth_state dipindah ke cadangan. Buka /qr untuk scan ulang.' });
});

app.listen(PORT, () => {
  console.log(`[WA Gateway] Berjalan di http://localhost:${PORT}`);
  console.log(`[WA Gateway] API Key: ${API_KEY}`);
  startWhatsApp();
});
