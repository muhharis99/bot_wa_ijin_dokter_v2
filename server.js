const express = require('express');
const cors = require('cors');
const QRCode = require('qrcode');
const { Client, LocalAuth } = require('whatsapp-web.js');

const app = express();
const PORT = Number(process.env.WA_PORT || 3000);
const HOST = process.env.WA_HOST || '0.0.0.0';

app.use(cors());
app.use(express.json({ limit: '1mb' }));

let waState = 'STARTING';
let qrDataUrl = null;
let lastError = null;

const client = new Client({
    authStrategy: new LocalAuth({
        clientId: 'dokter-reminder',
        dataPath: './.wwebjs_auth'
    }),
    puppeteer: {
        headless: true,
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage'
        ]
    }
});

function normalizePhone(value) {
    let phone = String(value || '').replace(/\D+/g, '');

    if (phone.startsWith('0')) {
        phone = '62' + phone.slice(1);
    }

    return phone;
}

client.on('qr', async (qr) => {
    try {
        qrDataUrl = await QRCode.toDataURL(qr, {
            width: 320,
            margin: 2
        });
        waState = 'QR_READY';
        lastError = null;
        console.log('QR WhatsApp siap. Buka browser ke http://localhost:' + PORT);
    } catch (error) {
        lastError = error.message;
        waState = 'ERROR';
        console.error('Gagal membuat QR:', error);
    }
});

client.on('authenticated', () => {
    waState = 'AUTHENTICATED';
    qrDataUrl = null;
    lastError = null;
    console.log('WhatsApp berhasil diautentikasi.');
});

client.on('ready', () => {
    waState = 'READY';
    qrDataUrl = null;
    lastError = null;
    console.log('WhatsApp gateway READY.');
});

client.on('auth_failure', (message) => {
    waState = 'AUTH_FAILURE';
    lastError = String(message || 'Authentication failure');
    console.error('WhatsApp auth failure:', message);
});

client.on('disconnected', (reason) => {
    waState = 'DISCONNECTED';
    qrDataUrl = null;
    lastError = String(reason || 'Disconnected');
    console.warn('WhatsApp disconnected:', reason);
});

app.get('/', (req, res) => {
    const statusLabel = waState === 'READY'
        ? 'WhatsApp Terhubung'
        : waState === 'QR_READY'
            ? 'Scan QR WhatsApp'
            : 'Menyiapkan WhatsApp';

    res.send(`<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>WhatsApp Gateway</title>
<style>
body{font-family:Arial,sans-serif;background:#f4f6f8;margin:0;padding:32px;color:#1f2937}.card{max-width:520px;margin:40px auto;background:#fff;border-radius:16px;padding:28px;box-shadow:0 8px 30px rgba(0,0,0,.08);text-align:center}.status{display:inline-block;padding:8px 12px;border-radius:999px;background:#eef2f7;font-weight:700;margin-bottom:18px}img{width:320px;max-width:100%;height:auto}.muted{color:#6b7280;font-size:14px;line-height:1.6}.ok{font-size:56px;margin:16px 0}button{border:0;border-radius:8px;padding:10px 14px;cursor:pointer}code{background:#f3f4f6;padding:2px 6px;border-radius:4px}</style>
</head>
<body>
<div class="card">
<div class="status">${statusLabel}</div>
${waState === 'QR_READY' && qrDataUrl ? `<div><img src="${qrDataUrl}" alt="QR WhatsApp"></div><p class="muted">Buka WhatsApp di HP → Perangkat tertaut → Tautkan perangkat, lalu scan QR ini.</p>` : ''}
${waState === 'READY' ? '<div class="ok">✓</div><h2>WhatsApp siap digunakan</h2><p class="muted">Dashboard PHP sekarang dapat mengirim pesan langsung melalui gateway ini.</p>' : ''}
${waState !== 'QR_READY' && waState !== 'READY' ? `<h2>${statusLabel}</h2><p class="muted">Status: <code>${waState}</code>. Halaman akan memperbarui otomatis.</p>` : ''}
${lastError ? `<p class="muted">Error: ${String(lastError).replace(/</g, '&lt;')}</p>` : ''}
<p><button onclick="location.reload()">Refresh</button></p>
</div>
<script>if (${JSON.stringify(waState)} !== 'READY') setTimeout(() => location.reload(), 3000);</script>
</body>
</html>`);
});

app.get('/status', (req, res) => {
    res.json({
        success: true,
        state: waState,
        ready: waState === 'READY',
        hasQr: Boolean(qrDataUrl),
        error: lastError
    });
});

app.post('/send', async (req, res) => {
    try {
        if (waState !== 'READY') {
            return res.status(503).json({
                success: false,
                message: 'WhatsApp belum terhubung. Scan QR terlebih dahulu.',
                state: waState
            });
        }

        const phone = normalizePhone(req.body.phone);
        const message = String(req.body.message || '').trim();

        if (!phone) {
            return res.status(422).json({ success: false, message: 'Nomor WhatsApp kosong.' });
        }

        if (!/^62\d{8,15}$/.test(phone)) {
            return res.status(422).json({ success: false, message: 'Format nomor WhatsApp tidak valid.' });
        }

        if (!message) {
            return res.status(422).json({ success: false, message: 'Pesan WhatsApp kosong.' });
        }

        const numberId = await client.getNumberId(phone);

        if (!numberId) {
            return res.status(404).json({ success: false, message: 'Nomor tidak terdaftar di WhatsApp.' });
        }

        const sentMessage = await client.sendMessage(numberId._serialized, message);

        return res.json({
            success: true,
            message: 'Pesan WhatsApp berhasil dikirim.',
            phone,
            messageId: sentMessage?.id?._serialized || null
        });
    } catch (error) {
        console.error('Gagal mengirim WhatsApp:', error);
        return res.status(500).json({
            success: false,
            message: error.message || 'Gagal mengirim WhatsApp.'
        });
    }
});

app.listen(PORT, HOST, () => {
    console.log(`WhatsApp gateway berjalan di http://localhost:${PORT}`);
});

client.initialize().catch((error) => {
    waState = 'ERROR';
    lastError = error.message;
    console.error('Gagal menginisialisasi WhatsApp:', error);
});
