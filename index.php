<?php

declare(strict_types=1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/functions.php';

$date = $_GET['tanggal'] ?? date('Y-m-d');

ensureReminders($date);

$pdo = db();
$pdoRsi = get_db('rsi_byl');

/*
|--------------------------------------------------------------------------
| Handle reminder action
|--------------------------------------------------------------------------
*/
if (isset($_GET['action'], $_GET['id'])) {
    $id = (int) $_GET['id'];
    $action = $_GET['action'];

    if (in_array($action, ['opened', 'sent', 'failed'], true)) {
        $status = strtoupper($action);

        if ($action === 'opened') {
            $sql = 'UPDATE reminders SET status = ?, opened_at = ? WHERE id = ?';
            $params = [$status, date('Y-m-d H:i:s'), $id];
        } elseif ($action === 'sent') {
            $sql = 'UPDATE reminders SET status = ?, sent_at = ? WHERE id = ?';
            $params = [$status, date('Y-m-d H:i:s'), $id];
        } else {
            $sql = 'UPDATE reminders SET status = ? WHERE id = ?';
            $params = [$status, $id];
        }

        $pdo->prepare($sql)->execute($params);
        logAction($id, $status);
    }

    header('Location: index.php?tanggal=' . urlencode($date));
    exit;
}

/*
|--------------------------------------------------------------------------
| Get schedules
|--------------------------------------------------------------------------
*/
$rows = schedulesFor($date);
$byDoctor = [];

foreach ($rows as $row) {
    $doctorId = $row['doctor_id'];

    if (!isset($byDoctor[$doctorId])) {
        $byDoctor[$doctorId] = [
            'schedules' => [],
            'reminder' => null
        ];
    }

    $byDoctor[$doctorId]['schedules'][] = $row;
}

/*
|--------------------------------------------------------------------------
| Get reminder for each doctor
|--------------------------------------------------------------------------
*/
$reminderStmt = $pdo->prepare("SELECT * FROM reminders WHERE doctor_id = ? AND tanggal = ? AND reminder_type = ? LIMIT 1");

foreach ($byDoctor as $doctorId => &$doctorGroup) {
    $reminderStmt->execute([$doctorId, $date, 'HARI_INI']);
    $doctorGroup['reminder'] = $reminderStmt->fetch() ?: null;
}

unset($doctorGroup);

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/
$totalDoctors = count($byDoctor);
$totalSchedules = count($rows);
$counts = ['SENT' => 0, 'FAILED' => 0, 'READY' => 0, 'OPENED' => 0];

foreach ($byDoctor as $group) {
    $status = $group['reminder']['status'] ?? 'READY';

    if (!isset($counts[$status])) {
        $counts[$status] = 0;
    }

    $counts[$status]++;
}

/*
|--------------------------------------------------------------------------
| Next schedule
|--------------------------------------------------------------------------
*/
$nextStmt = $pdoRsi->prepare("
    SELECT dj.*, mp.poli_nama AS lokasi, md.dokter_nama, mp.poli_nama, djk.tanggal
    FROM dokter_jadwal dj
    JOIN master_dokter md ON md.dokter_kd = dj.dokter_kd
    JOIN dokter_jadwal_kuota djk ON djk.dokter_jadwal_id = dj.id
    LEFT JOIN master_poli mp ON mp.poli_kd = dj.poli_kd
    WHERE djk.tanggal > ? AND djk.aktif = '1'
    ORDER BY djk.tanggal ASC, dj.jam_mulai ASC
    LIMIT 1
");
$nextStmt->execute([$date]);
$next = $nextStmt->fetch() ?: null;
$encodedDate = urlencode($date);
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .gateway-bar{display:flex;align-items:center;gap:10px;margin-bottom:18px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:10px;background:#fff}.gateway-dot{width:10px;height:10px;border-radius:50%;background:#f59e0b}.gateway-dot.ready{background:#16a34a}.gateway-dot.error{background:#dc2626}.gateway-bar a{margin-left:auto}.js-whatsapp.is-loading{pointer-events:none;opacity:.65}
    </style>
</head>
<body>
<header>
    <div>
        <span class="eyebrow">DAILY OPERATIONS</span>
        <h1>Reminder Jadwal Dokter</h1>
        <p><?= e(indoDate($date)) ?></p>
    </div>
    <nav>
        <a class="active" href="index.php">Dashboard</a>
        <a href="master.php">Master Data</a>
        <a href="settings.php">Template</a>
    </nav>
</header>

<main>
    <div class="gateway-bar">
        <span id="gatewayDot" class="gateway-dot"></span>
        <span id="gatewayStatus">Memeriksa WhatsApp Gateway...</span>
        <a id="gatewayLink" class="text-button" href="#" target="_blank">Buka QR / Status</a>
    </div>

    <section class="hero">
        <div>
            <span class="eyebrow">RINGKASAN HARI INI</span>
            <h2>Siapa yang praktik hari ini?</h2>
            <p>Pesan reminder dikirim langsung melalui WhatsApp Gateway setelah tombol WhatsApp ditekan.</p>
        </div>
        <form method="get">
            <input type="date" name="tanggal" value="<?= e($date) ?>">
            <button class="button" type="submit">Tampilkan</button>
        </form>
    </section>

    <section class="stats">
        <div><small>DOKTER PRAKTIK</small><strong><?= $totalDoctors ?></strong></div>
        <div><small>JADWAL PRAKTIK</small><strong><?= $totalSchedules ?></strong></div>
        <div class="green"><small>SUDAH DIKIRIM</small><strong><?= $counts['SENT'] ?></strong></div>
        <div class="amber"><small>BELUM DIKIRIM</small><strong><?= ($counts['READY'] ?? 0) + ($counts['OPENED'] ?? 0) ?></strong></div>
        <div class="red"><small>GAGAL</small><strong><?= $counts['FAILED'] ?></strong></div>
    </section>

    <?php if ($next): ?>
        <div class="next">
            Jadwal berikutnya: <b><?= e($next['dokter_nama']) ?></b> · <?= e($next['poli_nama']) ?> · <?= e(indoDate($next['tanggal'])) ?> <?= e($next['jam_mulai']) ?>
        </div>
    <?php endif; ?>

    <div class="section-title">
        <div>
            <span class="eyebrow">DAFTAR JADWAL</span>
            <h2>Jadwal dokter</h2>
        </div>
        <span class="muted"><?= $totalDoctors ?> dokter · <?= $totalSchedules ?> sesi</span>
    </div>

    <section class="doctor-grid">
        <?php foreach ($byDoctor as $group): ?>
            <?php
            $schedules = $group['schedules'];
            $reminder = $group['reminder'];

            if (empty($schedules)) {
                continue;
            }

            $doctor = $schedules[0];
            $status = $reminder['status'] ?? 'READY';
            $phoneRaw = $doctor['no_whatsapp'] ?? '';
            $phone = normalizePhone($phoneRaw);
            $message = $reminder['message'] ?? '';
            ?>

            <article class="card">
                <div class="card-top">
                    <div>
                        <h3><?= e($doctor['nama_dokter']) ?></h3>
                        <p><?= e($doctor['spesialis'] ?? '') ?> · <?= e($phoneRaw ?: '-') ?></p>
                    </div>
                    <span class="status <?= e(strtolower($status)) ?>"><?= e($status) ?></span>
                </div>

                <div class="schedule-list">
                    <?php foreach ($schedules as $schedule): ?>
                        <div>
                            <b><?= e($schedule['jam_mulai']) ?> – <?= e($schedule['jam_selesai']) ?></b>
                            <span><?= e($schedule['nama_poli']) ?></span>
                            <small><?= e($schedule['lokasi']) ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($reminder): ?>
                    <div class="actions">
                        <a class="button outline" target="_blank" href="preview.php?id=<?= (int) $reminder['id'] ?>">Preview</a>

                        <?php if ($phone !== '' && $message !== ''): ?>
                            <a
                                href="#"
                                class="button whatsapp js-whatsapp"
                                data-phone="<?= e($phone) ?>"
                                data-message="<?= e($message) ?>"
                                data-reminder-id="<?= (int) $reminder['id'] ?>"
                            >
                                <?= $status === 'SENT' ? 'Kirim Ulang WhatsApp' : 'Kirim WhatsApp' ?>
                            </a>
                        <?php else: ?>
                            <span class="muted">Nomor WhatsApp tidak tersedia</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
</main>

<footer><?= e(APP_NAME) ?> · PHP Native + MySQL + whatsapp-web.js</footer>

<script>
const gatewayBaseUrl = window.location.protocol === 'https:'
    ? 'http://' + window.location.hostname + ':3000'
    : 'http://' + window.location.hostname + ':3000';

const gatewayLink = document.getElementById('gatewayLink');
const gatewayStatus = document.getElementById('gatewayStatus');
const gatewayDot = document.getElementById('gatewayDot');
gatewayLink.href = gatewayBaseUrl + '/';

async function refreshGatewayStatus() {
    try {
        const response = await fetch(gatewayBaseUrl + '/status', { cache: 'no-store' });
        const data = await response.json();

        if (data.ready) {
            gatewayStatus.textContent = 'WhatsApp Gateway READY';
            gatewayDot.className = 'gateway-dot ready';
        } else if (data.hasQr) {
            gatewayStatus.textContent = 'QR tersedia - scan WhatsApp terlebih dahulu';
            gatewayDot.className = 'gateway-dot';
        } else {
            gatewayStatus.textContent = 'WhatsApp Gateway: ' + (data.state || 'belum siap');
            gatewayDot.className = 'gateway-dot';
        }
    } catch (error) {
        gatewayStatus.textContent = 'WhatsApp Gateway tidak aktif. Jalankan: node server.js';
        gatewayDot.className = 'gateway-dot error';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    refreshGatewayStatus();
    setInterval(refreshGatewayStatus, 5000);

    document.querySelectorAll('.js-whatsapp').forEach(function (button) {
        button.addEventListener('click', async function (event) {
            event.preventDefault();

            const phone = this.dataset.phone;
            const message = this.dataset.message;
            const reminderId = this.dataset.reminderId;
            const originalText = this.textContent;

            if (!phone || !message || !reminderId) {
                alert('Data WhatsApp belum lengkap.');
                return;
            }

            this.classList.add('is-loading');
            this.textContent = 'Mengirim...';

            try {
                const response = await fetch(gatewayBaseUrl + '/send', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ phone, message })
                });

                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Gagal mengirim WhatsApp.');
                }

                alert('WhatsApp berhasil dikirim ke ' + phone + '.');
                window.location.href = 'index.php?tanggal=<?= $encodedDate ?>&action=sent&id=' + encodeURIComponent(reminderId);
            } catch (error) {
                alert(error.message || 'Gagal menghubungi WhatsApp Gateway.');
                window.location.href = 'index.php?tanggal=<?= $encodedDate ?>&action=failed&id=' + encodeURIComponent(reminderId);
            } finally {
                this.classList.remove('is-loading');
                this.textContent = originalText;
            }
        });
    });
});
</script>
</body>
</html>
