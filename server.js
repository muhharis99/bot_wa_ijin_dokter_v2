const express = require('express');
const cors = require('cors');
const QRCode = require('qrcode');
const { Client, LocalAuth } = require('whatsapp-web.js');

const app = express();
const PORT = Number(process.env.WA_PORT || 3210);
const HOST = process.env.WA_HOST || '0.0.0.0';
const CHAT_INCOMING_URL = process.env.CHAT_INCOMING_URL ||
    'http://127.0.0.1/dokter-reminder/api/chat/incoming.php';

app.disable('x-powered-by');
app.use(cors());
app.use(express.json({ limit: '128kb' }));

let waState = 'STARTING';
let qrDataUrl = null;
let lastError = null;
let incomingQueueProcessing = false;

const incomingQueue = new Map();
const completedIncoming = new Map();

const timeFormatter = new Intl.DateTimeFormat('id-ID', {
    timeZone: 'Asia/Jakarta',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false
});

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
            '--disable-dev-shm-usage',
            '--disable-gpu',
            '--disable-extensions',
            '--disable-background-networking',
            '--disable-background-timer-throttling',
            '--disable-backgrounding-occluded-windows',
            '--disable-renderer-backgrounding',
            '--disable-default-apps',
            '--disable-sync',
            '--disable-translate',
            '--disable-features=Translate,MediaRouter,OptimizationHints,AutofillServerCommunication',
            '--metrics-recording-only',
            '--mute-audio',
            '--no-first-run',
            '--no-default-browser-check'
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

function now() {
    return timeFormatter.format(new Date());
}

function terminalLog(status, data = {}) {
    const lines = [
        '',
        '============================================================',
        `[${now()}] ${status}`
    ];

    Object.entries(data).forEach(([key, value]) => {
        lines.push(`${key}: ${value ?? '-'}`);
    });

    lines.push('============================================================');

    console.log(lines.join('\n'));
}

function incomingMessageContent(message) {
    const type = String(message.type || 'chat').toLowerCase();
    const body = String(message.body || '').trim();

    if (body !== '') {
        return body;
    }

    if (type === 'image') {
        return '[IMAGE]';
    }

    if (type === 'document') {
        return '[DOCUMENT]';
    }

    if (type === 'audio' || type === 'ptt') {
        return '[AUDIO]';
    }

    if (type === 'video') {
        return '[VIDEO]';
    }

    return '[MESSAGE]';
}

function incomingMessageType(message) {
    const type = String(message.type || 'chat').toLowerCase();

    if (type === 'chat') {
        return 'text';
    }

    if (type === 'ptt') {
        return 'audio';
    }

    return type;
}

function incomingPayload(message) {
    if (!message || message.fromMe) {
        return null;
    }

    const source = String(message.from || '');

    if (source === '' ||
        source === 'status@broadcast' ||
        source.endsWith('@g.us') ||
        source.endsWith('@broadcast')) {
        return null;
    }

    const phone = normalizePhone(source.split('@')[0]);
    const messageId = message?.id?._serialized || '';

    if (!phone || !messageId) {
        return null;
    }

    const timestamp = Number(message.timestamp || 0);
    const receivedAt = timestamp > 0
        ? new Date(timestamp * 1000).toISOString()
        : new Date().toISOString();

    return {
        message_id: messageId,
        phone,
        message_type: incomingMessageType(message),
        message: incomingMessageContent(message),
        received_at: receivedAt
    };
}

function retryDelay(attempts) {
    const delays = [
        1000,
        2000,
        5000,
        10000,
        20000,
        30000,
        60000
    ];

    return delays[Math.min(attempts, delays.length - 1)];
}

function cleanupCompletedIncoming() {
    const cutoff = Date.now() - (30 * 60 * 1000);

    for (const [messageId, completedAt] of completedIncoming.entries()) {
        if (completedAt < cutoff) {
            completedIncoming.delete(messageId);
        }
    }
}

function enqueueIncomingMessage(message, eventName) {
    const payload = incomingPayload(message);

    if (!payload) {
        return;
    }

    const messageId = payload.message_id;

    cleanupCompletedIncoming();

    if (completedIncoming.has(messageId) || incomingQueue.has(messageId)) {
        return;
    }

    incomingQueue.set(messageId, {
        payload,
        attempts: 0,
        nextAttemptAt: Date.now(),
        lastError: null,
        eventName
    });

    terminalLog('CHAT DOKTER MASUK DITERIMA', {
        Status: 'MASUK ANTREAN',
        Pengirim: payload.phone,
        MessageId: messageId,
        Event: eventName,
        Antrean: incomingQueue.size
    });

    processIncomingQueue();
}

async function deliverIncomingItem(messageId, item) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);

    try {
        const response = await fetch(CHAT_INCOMING_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify(item.payload),
            signal: controller.signal
        });

        const responseText = await response.text();

        if (!response.ok) {
            throw new Error(
                `HTTP ${response.status}: ${responseText.slice(0, 250)}`
            );
        }

        let result = null;

        try {
            result = JSON.parse(responseText);
        } catch (error) {
            throw new Error('Respons webhook incoming bukan JSON yang valid.');
        }

        if (!result || result.success !== true) {
            throw new Error(
                result?.message || 'Webhook incoming tidak mengembalikan status sukses.'
            );
        }

        incomingQueue.delete(messageId);
        completedIncoming.set(messageId, Date.now());

        terminalLog('CHAT DOKTER MASUK DISIMPAN', {
            Status: 'BERHASIL',
            Pengirim: item.payload.phone,
            MessageId: messageId,
            Percobaan: item.attempts + 1,
            Antrean: incomingQueue.size
        });
    } catch (error) {
        item.attempts += 1;
        item.lastError = error.message || String(error);
        item.nextAttemptAt = Date.now() + retryDelay(item.attempts - 1);
        incomingQueue.set(messageId, item);

        terminalLog('CHAT DOKTER MASUK MENUNGGU RETRY', {
            Status: 'RETRY',
            Pengirim: item.payload.phone,
            MessageId: messageId,
            Percobaan: item.attempts,
            UlangDalamDetik: Math.round(
                (item.nextAttemptAt - Date.now()) / 1000
            ),
            Alasan: item.lastError,
            Antrean: incomingQueue.size
        });
    } finally {
        clearTimeout(timeout);
    }
}

async function processIncomingQueue() {
    if (incomingQueueProcessing) {
        return;
    }

    incomingQueueProcessing = true;

    try {
        const currentTime = Date.now();

        for (const [messageId, item] of incomingQueue.entries()) {
            if (item.nextAttemptAt > currentTime) {
                continue;
            }

            await deliverIncomingItem(messageId, item);
        }
    } finally {
        incomingQueueProcessing = false;
    }
}

setInterval(() => {
    processIncomingQueue();
}, 2000);

client.on('qr', async (qr) => {
    try {
        qrDataUrl = await QRCode.toDataURL(qr, {
            width: 240,
            margin: 1
        });

        waState = 'QR_READY';
        lastError = null;

        console.log(
            'QR WhatsApp siap. Buka browser ke http://localhost:' +
            PORT
        );
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

client.on('message', (message) => {
    enqueueIncomingMessage(message, 'message');
});

client.on('message_create', (message) => {
    enqueueIncomingMessage(message, 'message_create');
});

app.get('/', (req, res) => {
    const statusLabel = waState === 'READY'
        ? 'WhatsApp Terhubung'
        : waState === 'QR_READY'
            ? 'Scan QR WhatsApp'
            : 'Menyiapkan WhatsApp';

    const qrSection = waState === 'QR_READY' && qrDataUrl
        ? `
            <div class="mb-4">
                <img
                    class="img-fluid border rounded-3 p-2 bg-white"
                    src="${qrDataUrl}"
                    alt="QR WhatsApp"
                    width="240"
                    height="240"
                >
            </div>
            <p class="text-secondary mb-0">
                Buka WhatsApp di HP, pilih Perangkat tertaut, lalu scan QR ini.
            </p>
        `
        : '';

    const readySection = waState === 'READY'
        ? `
            <div class="display-3 text-success mb-3">✓</div>
            <h2 class="h4 mb-2">WhatsApp siap digunakan</h2>
            <p class="text-secondary mb-0">
                Dashboard PHP dapat mengirim pesan langsung melalui gateway ini.
            </p>
        `
        : '';

    const waitingSection = waState !== 'QR_READY' && waState !== 'READY'
        ? `
            <h2 class="h4 mb-3">${statusLabel}</h2>
            <p class="text-secondary mb-0">
                Status: <code>${waState}</code>. Halaman akan memperbarui otomatis.
            </p>
        `
        : '';

    const errorSection = lastError
        ? `
            <div class="alert alert-danger mt-4 mb-0">
                ${String(lastError).replace(/</g, '&lt;')}
            </div>
        `
        : '';

    res.send(`<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WhatsApp Gateway</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
</head>
<body class="bg-body-tertiary">
    <main class="container py-5">
        <div class="row justify-content-center">
            <div class="col-12 col-md-8 col-lg-6">
                <div class="card shadow-sm border-0">
                    <div class="card-body p-4 p-lg-5 text-center">
                        <span class="badge text-bg-success-subtle text-success mb-3">
                            ${statusLabel}
                        </span>

                        ${qrSection}
                        ${readySection}
                        ${waitingSection}
                        ${errorSection}

                        <div class="mt-4">
                            <button
                                class="btn btn-outline-secondary btn-sm"
                                type="button"
                                onclick="location.reload()"
                            >
                                Refresh
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        if (${JSON.stringify(waState)} !== 'READY') {
            setTimeout(function () {
                location.reload();
            }, 5000);
        }
    </script>
</body>
</html>`);
});

app.get('/status', (req, res) => {
    res.set('Cache-Control', 'no-store');
    res.json({
        success: true,
        state: waState,
        ready: waState === 'READY',
        hasQr: Boolean(qrDataUrl),
        error: lastError,
        incomingQueue: incomingQueue.size
    });
});

app.post('/send', async (req, res) => {
    const phone = normalizePhone(req.body.phone);
    const message = String(req.body.message || '').trim();

    terminalLog('PERMINTAAN KIRIM WHATSAPP', {
        Status: 'MEMPROSES',
        Tujuan: phone || '-',
        PanjangPesan: message.length
    });

    try {
        if (waState !== 'READY') {
            terminalLog('WHATSAPP GAGAL DIKIRIM', {
                Status: 'GAGAL',
                Tujuan: phone || '-',
                Alasan: 'WhatsApp gateway belum READY',
                Gateway: waState
            });

            return res.status(503).json({
                success: false,
                message: 'WhatsApp belum terhubung. Scan QR terlebih dahulu.',
                state: waState
            });
        }

        if (!phone) {
            return res.status(422).json({
                success: false,
                message: 'Nomor WhatsApp kosong.'
            });
        }

        if (!/^62\d{8,15}$/.test(phone)) {
            return res.status(422).json({
                success: false,
                message: 'Format nomor WhatsApp tidak valid.'
            });
        }

        if (!message) {
            return res.status(422).json({
                success: false,
                message: 'Pesan WhatsApp kosong.'
            });
        }

        const numberId = await client.getNumberId(phone);

        if (!numberId) {
            return res.status(404).json({
                success: false,
                message: 'Nomor tidak terdaftar di WhatsApp.'
            });
        }

        const sentMessage = await client.sendMessage(
            numberId._serialized,
            message
        );

        const messageId = sentMessage?.id?._serialized || null;

        terminalLog('WHATSAPP BERHASIL DIKIRIM', {
            Status: 'BERHASIL',
            Tujuan: phone,
            MessageId: messageId || '-',
            Ack: sentMessage?.ack ?? '-'
        });

        return res.json({
            success: true,
            message: 'Pesan WhatsApp berhasil dikirim.',
            phone,
            messageId
        });
    } catch (error) {
        terminalLog('WHATSAPP GAGAL DIKIRIM', {
            Status: 'GAGAL',
            Tujuan: phone || '-',
            Alasan: error.message || 'Gagal mengirim WhatsApp'
        });

        return res.status(500).json({
            success: false,
            message: error.message || 'Gagal mengirim WhatsApp.'
        });
    }
});

app.listen(PORT, HOST, () => {
    console.log(
        `WhatsApp gateway berjalan di http://localhost:${PORT}`
    );
});

client.initialize().catch((error) => {
    waState = 'ERROR';
    lastError = error.message;

    console.error('Gagal menginisialisasi WhatsApp:', error);
});
