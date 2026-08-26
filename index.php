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

if (isset($_GET['action'], $_GET['id'])) {
    $id = (int) $_GET['id'];
    $action = $_GET['action'];

    if (in_array($action, ['opened', 'sent', 'failed'], true)) {
        $status = strtoupper($action);

        if ($action === 'opened') {
            $sql = "
                UPDATE reminders
                SET
                    status = ?,
                    opened_at = ?
                WHERE id = ?
            ";

            $params = [
                $status,
                date('Y-m-d H:i:s'),
                $id
            ];
        } elseif ($action === 'sent') {
            $sql = "
                UPDATE reminders
                SET
                    status = ?,
                    sent_at = ?
                WHERE id = ?
            ";

            $params = [
                $status,
                date('Y-m-d H:i:s'),
                $id
            ];
        } else {
            $sql = "
                UPDATE reminders
                SET status = ?
                WHERE id = ?
            ";

            $params = [
                $status,
                $id
            ];
        }

        $pdo->prepare($sql)->execute($params);
        logAction($id, $status);
    }

    header(
        'Location: index.php?tanggal=' . urlencode($date)
    );

    exit;
}

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

$reminderStmt = $pdo->prepare("
    SELECT *
    FROM reminders
    WHERE doctor_id = ?
        AND tanggal = ?
        AND reminder_type = ?
    LIMIT 1
");

foreach ($byDoctor as $doctorId => &$doctorGroup) {
    $reminderStmt->execute([
        $doctorId,
        $date,
        'HARI_INI'
    ]);

    $doctorGroup['reminder'] = $reminderStmt->fetch() ?: null;
}

unset($doctorGroup);

$totalDoctors = count($byDoctor);
$totalSchedules = count($rows);

$counts = [
    'SENT' => 0,
    'FAILED' => 0,
    'READY' => 0,
    'OPENED' => 0
];

foreach ($byDoctor as $group) {
    $status = $group['reminder']['status'] ?? 'READY';

    if (!isset($counts[$status])) {
        $counts[$status] = 0;
    }

    $counts[$status]++;
}

$nextStmt = $pdoRsi->prepare("
    SELECT
        dj.*,
        mp.poli_nama AS lokasi,
        md.dokter_nama,
        mp.poli_nama,
        djk.tanggal
    FROM dokter_jadwal dj
    JOIN master_dokter md
        ON md.dokter_kd = dj.dokter_kd
    JOIN dokter_jadwal_kuota djk
        ON djk.dokter_jadwal_id = dj.id
    LEFT JOIN master_poli mp
        ON mp.poli_kd = dj.poli_kd
    WHERE djk.tanggal > ?
        AND djk.aktif = '1'
    ORDER BY
        djk.tanggal ASC,
        dj.jam_mulai ASC
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

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="bg-body-tertiary">
    <nav class="navbar navbar-expand-lg bg-white border-bottom sticky-top">
        <div class="container py-2">
            <a class="navbar-brand fw-bold text-success" href="index.php">
                <?= e(APP_NAME) ?>
            </a>

            <button
                class="navbar-toggler"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#mainNavbar"
            >
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="mainNavbar">
                <div class="navbar-nav ms-auto">
                    <a class="nav-link active fw-semibold" href="index.php">Dashboard</a>
                    <a class="nav-link" href="master.php">Master Data</a>
                    <a class="nav-link" href="settings.php">Template</a>
                </div>
            </div>
        </div>
    </nav>

    <main class="container py-4 py-lg-5">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
            <div>
                <span class="badge text-bg-success-subtle text-success mb-2">DAILY OPERATIONS</span>
                <h1 class="h3 mb-1">Reminder Jadwal Dokter</h1>
                <p class="text-secondary mb-0"><?= e(indoDate($date)) ?></p>
            </div>

            <form class="d-flex gap-2" method="get">
                <input
                    class="form-control"
                    type="date"
                    name="tanggal"
                    value="<?= e($date) ?>"
                >
                <button class="btn btn-success" type="submit">
                    Tampilkan
                </button>
            </form>
        </div>

        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body d-flex flex-column flex-md-row align-items-md-center gap-3">
                <span id="gatewayDot" class="gateway-dot"></span>
                <div class="flex-grow-1">
                    <div class="fw-semibold" id="gatewayStatus">
                        Memeriksa WhatsApp Gateway...
                    </div>
                    <div class="text-secondary small">
                        Gateway digunakan untuk mengirim pesan langsung tanpa membuka WhatsApp Web.
                    </div>
                </div>
                <a
                    id="gatewayLink"
                    class="btn btn-outline-success btn-sm"
                    href="#"
                    target="_blank"
                >
                    Buka QR / Status
                </a>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-6 col-lg">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body">
                        <div class="text-secondary small">DOKTER PRAKTIK</div>
                        <div class="display-6 fw-bold"><?= $totalDoctors ?></div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body">
                        <div class="text-secondary small">JADWAL PRAKTIK</div>
                        <div class="display-6 fw-bold"><?= $totalSchedules ?></div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body">
                        <div class="text-secondary small">SUDAH DIKIRIM</div>
                        <div class="display-6 fw-bold text-success"><?= $counts['SENT'] ?></div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body">
                        <div class="text-secondary small">BELUM DIKIRIM</div>
                        <div class="display-6 fw-bold text-warning">
                            <?= ($counts['READY'] ?? 0) + ($counts['OPENED'] ?? 0) ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body">
                        <div class="text-secondary small">GAGAL</div>
                        <div class="display-6 fw-bold text-danger"><?= $counts['FAILED'] ?></div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($next): ?>
            <div class="alert alert-success-subtle border-success-subtle mb-4">
                Jadwal berikutnya:
                <strong><?= e($next['dokter_nama']) ?></strong>
                · <?= e($next['poli_nama']) ?>
                · <?= e(indoDate($next['tanggal'])) ?>
                <?= e($next['jam_mulai']) ?>
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="h5 mb-1">Jadwal Dokter</h2>
                <p class="text-secondary small mb-0">
                    <?= $totalDoctors ?> dokter · <?= $totalSchedules ?> sesi
                </p>
            </div>
        </div>

        <div class="row g-3">
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

                $statusClass = match ($status) {
                    'SENT' => 'text-bg-success',
                    'FAILED' => 'text-bg-danger',
                    'OPENED' => 'text-bg-primary',
                    default => 'text-bg-secondary'
                };
                ?>

                <div class="col-12 col-lg-6">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-body d-flex flex-column">
                            <div class="d-flex justify-content-between gap-3 mb-3">
                                <div>
                                    <h3 class="h5 mb-1"><?= e($doctor['nama_dokter']) ?></h3>
                                    <div class="text-secondary small">
                                        <?= e($phoneRaw ?: '-') ?>
                                    </div>
                                </div>
                                <span class="badge <?= e($statusClass) ?> align-self-start">
                                    <?= e($status) ?>
                                </span>
                            </div>

                            <div class="list-group list-group-flush mb-3">
                                <?php foreach ($schedules as $schedule): ?>
                                    <div class="list-group-item px-0">
                                        <div class="d-flex justify-content-between gap-3">
                                            <div>
                                                <div class="fw-semibold">
                                                    <?= e($schedule['nama_poli']) ?>
                                                </div>
                                                <div class="text-secondary small">
                                                    <?= e($schedule['lokasi']) ?>
                                                </div>
                                            </div>
                                            <div class="fw-semibold text-nowrap">
                                                <?= e($schedule['jam_mulai']) ?>
                                                -
                                                <?= e($schedule['jam_selesai']) ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <?php if ($reminder): ?>
                                <div class="d-flex flex-wrap gap-2 mt-auto">
                                    <a
                                        class="btn btn-outline-secondary btn-sm"
                                        target="_blank"
                                        href="preview.php?id=<?= (int) $reminder['id'] ?>"
                                    >
                                        Preview
                                    </a>

                                    <?php if ($phone !== '' && $message !== ''): ?>
                                        <button
                                            type="button"
                                            class="btn btn-success btn-sm js-whatsapp"
                                            data-phone="<?= e($phone) ?>"
                                            data-message="<?= e($message) ?>"
                                            data-reminder-id="<?= (int) $reminder['id'] ?>"
                                        >
                                            <?= $status === 'SENT' ? 'Kirim Ulang WhatsApp' : 'Kirim WhatsApp' ?>
                                        </button>
                                    <?php else: ?>
                                        <span class="text-danger small align-self-center">
                                            Nomor WhatsApp tidak tersedia
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </main>

    <footer class="border-top bg-white py-4 text-center text-secondary small">
        <?= e(APP_NAME) ?> · PHP Native + MySQL + whatsapp-web.js
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        const gatewayBaseUrl =
            'http://' + window.location.hostname + ':3000';

        const gatewayLink = document.getElementById('gatewayLink');
        const gatewayStatus = document.getElementById('gatewayStatus');
        const gatewayDot = document.getElementById('gatewayDot');

        gatewayLink.href = gatewayBaseUrl + '/';

        async function refreshGatewayStatus() {
            try {
                const response = await fetch(
                    gatewayBaseUrl + '/status',
                    {
                        cache: 'no-store'
                    }
                );

                const data = await response.json();

                if (data.ready) {
                    gatewayStatus.textContent = 'WhatsApp Gateway READY';
                    gatewayDot.className = 'gateway-dot ready';
                    return;
                }

                if (data.hasQr) {
                    gatewayStatus.textContent = 'QR tersedia - scan WhatsApp terlebih dahulu';
                    gatewayDot.className = 'gateway-dot';
                    return;
                }

                gatewayStatus.textContent =
                    'WhatsApp Gateway: ' +
                    (data.state || 'belum siap');

                gatewayDot.className = 'gateway-dot';
            } catch (error) {
                gatewayStatus.textContent =
                    'WhatsApp Gateway tidak aktif. Jalankan: node server.js';

                gatewayDot.className = 'gateway-dot error';
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            refreshGatewayStatus();
            setInterval(refreshGatewayStatus, 5000);

            document.querySelectorAll('.js-whatsapp').forEach(function (button) {
                button.addEventListener('click', async function () {
                    const phone = this.dataset.phone;
                    const message = this.dataset.message;
                    const reminderId = this.dataset.reminderId;
                    const originalText = this.textContent;

                    if (!phone || !message || !reminderId) {
                        alert('Data WhatsApp belum lengkap.');
                        return;
                    }

                    this.disabled = true;
                    this.textContent = 'Mengirim...';

                    try {
                        const response = await fetch(
                            gatewayBaseUrl + '/send',
                            {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify({
                                    phone,
                                    message
                                })
                            }
                        );

                        const result = await response.json();

                        if (!response.ok || !result.success) {
                            throw new Error(
                                result.message ||
                                'Gagal mengirim WhatsApp.'
                            );
                        }

                        alert(
                            'WhatsApp berhasil dikirim ke ' +
                            phone +
                            '.'
                        );

                        window.location.href =
                            'index.php?tanggal=<?= $encodedDate ?>' +
                            '&action=sent&id=' +
                            encodeURIComponent(reminderId);
                    } catch (error) {
                        alert(
                            error.message ||
                            'Gagal menghubungi WhatsApp Gateway.'
                        );

                        window.location.href =
                            'index.php?tanggal=<?= $encodedDate ?>' +
                            '&action=failed&id=' +
                            encodeURIComponent(reminderId);
                    } finally {
                        this.disabled = false;
                        this.textContent = originalText;
                    }
                });
            });
        });
    </script>
</body>
</html>
