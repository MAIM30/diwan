// WA Gateway DIWAN — Baileys + Express
// Jalankan: node server.js
// Endpoint: POST /send, GET /status, GET /qr

import express from 'express';
import makeWASocket, { useMultiFileAuthState, DisconnectReason, fetchLatestBaileysVersion } from '@whiskeysockets/baileys';
import { Boom } from '@hapi/boom';
import QRCode from 'qrcode';
import { readFileSync, existsSync } from 'fs';

const PORT = process.env.PORT || 3001;
const AUTH_DIR = './auth_state';
const API_KEY = process.env.API_KEY || 'diwan-wa-secret';

// Anti-spam: jeda default antar pesan broadcast (detik), bisa di-override per request
const DEFAULT_DELAY_MIN = 8;
const DEFAULT_DELAY_MAX = 15;

let sock = null;
let qrCode = null;
let connectionStatus = 'disconnected'; // disconnected | connecting | open

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
    auth: state,
    browser: ['DIWAN WA Gateway', 'Chrome', '22.0'],
    generateHighQualityLinkPreview: false,
    defaultQueryTimeoutMs: 60000,
  });

  sock.ev.on('creds.update', saveCreds);

  sock.ev.on('connection.update', (update) => {
    const { connection, lastDisconnect, qr } = update;

    if (qr) {
      qrCode = qr;
      connectionStatus = 'connecting';
      console.log('[WA] QR code diperbarui — buka http://localhost:' + PORT + '/qr untuk scan');
    }

    if (connection === 'close') {
      connectionStatus = 'disconnected';
      qrCode = null;

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

// QR code untuk scan (halaman HTML)
app.get('/qr', (req, res) => {
  if (connectionStatus === 'open') {
    return res.send(`
      <html><body style="font-family:sans-serif;text-align:center;padding:40px">
        <h2>WhatsApp Sudah Terhubung</h2>
        <p>Tidak perlu scan QR lagi.</p>
      </body></html>
    `);
  }

  if (!qrCode) {
    return res.send(`
      <html><body style="font-family:sans-serif;text-align:center;padding:40px">
        <h2>Menunggu QR Code...</h2>
        <p>Muat ulang halaman ini dalam beberapa detik.</p>
        <script>setTimeout(function(){location.reload()},3000)</script>
      </body></html>
    `);
  }

  QRCode.toDataURL(qrCode, { width: 300 }).then(dataUrl => {
    res.send(`
      <html><body style="font-family:sans-serif;text-align:center;padding:40px">
        <h2>Scan QR dengan WhatsApp</h2>
        <p>Buka WhatsApp di HP → Linked Devices → Link a Device</p>
        <img src="${dataUrl}" style="margin:20px auto;display:block">
        <p style="color:#888">QR otomatis diperbarui setiap 20 detik</p>
        <script>setTimeout(function(){location.reload()},20000)</script>
      </body></html>
    `);
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

app.listen(PORT, () => {
  console.log(`[WA Gateway] Berjalan di http://localhost:${PORT}`);
  console.log(`[WA Gateway] API Key: ${API_KEY}`);
  startWhatsApp();
});
