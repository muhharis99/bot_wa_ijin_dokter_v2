<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$id = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    exit('ID reminder tidak valid.');
}

$statement = db()->prepare("
    SELECT
        id,
        doctor_id,
        tanggal,
        reminder_type,
        message,
        status,
        created_at,
        opened_at,
        sent_at
    FROM reminders
    WHERE id = ?
    LIMIT 1
");

$statement->execute([$id]);
$reminder = $statement->fetch();

if (!$reminder) {
    http_response_code(404);
    exit('Reminder tidak ditemukan.');
}

$doctorStatement = get_db('rsi_byl')->prepare("
    SELECT
        md.dokter_kd,
        md.dokter_nama,
        COALESCE(kd.no_hp, '') AS no_whatsapp
    FROM master_dokter md
    LEFT JOIN kontak_dokter kd
        ON kd.kd_dr = md.dokter_kd
    WHERE md.dokter_kd = ?
    LIMIT 1
");

$doctorStatement->execute([
    $reminder['doctor_id']
]);

$doctor = $doctorStatement->fetch() ?: [];
$doctorName = trim((string) ($doctor['dokter_nama'] ?? ''));
$phoneRaw = trim((string) ($doctor['no_whatsapp'] ?? ''));
$phone = normalizePhone($phoneRaw);
$status = (string) ($reminder['status'] ?? 'READY');

$statusClass = match ($status) {
    'SENT' => 'success',
    'FAILED' => 'danger',
    'OPENED' => 'primary',
    default => 'secondary'
};
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Preview Reminder</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="bg-body-tertiary">
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container py-2">
            <a class="navbar-brand fw-bold" href="index.php">
                <?= e(APP_NAME) ?>
            </a>
        </div>
    </nav>

    <main class="container py-4 py-lg-5 page-narrow">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div>
                <span class="badge text-bg-success-subtle text-success mb-2">
                    PREVIEW PESAN
                </span>

                <h1 class="h3 mb-1">
                    <?= e($doctorName !== '' ? $doctorName : (string) $reminder['doctor_id']) ?>
                </h1>

                <div class="d-flex flex-wrap align-items-center gap-2 text-secondary">
                    <span><?= e($phoneRaw !== '' && $phoneRaw !== '0' ? $phoneRaw : '-') ?></span>
                    <span>·</span>
                    <span><?= e(indoDate((string) $reminder['tanggal'])) ?></span>
                    <span class="badge text-bg-<?= e($statusClass) ?>">
                        <?= e($status) ?>
                    </span>
                </div>
            </div>

            <button
                class="btn btn-outline-secondary"
                type="button"
                onclick="window.close(); history.back();"
            >
                Kembali
            </button>
        </div>

        <div class="card-modern shadow-sm">
            <div class="card-header-modern">
                Preview Pesan WhatsApp
            </div>

            <div class="card-body-modern">
                <div class="message-preview mb-4">
                    <?= nl2br(e((string) $reminder['message'])) ?>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="small text-secondary mb-1">Nomor Tujuan</div>
                        <div class="fw-semibold">
                            <?= e($phoneRaw !== '' && $phoneRaw !== '0' ? $phoneRaw : 'Tidak tersedia') ?>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="small text-secondary mb-1">Tanggal Jadwal</div>
                        <div class="fw-semibold">
                            <?= e(date('d-m-Y', strtotime((string) $reminder['tanggal']))) ?>
                        </div>
                    </div>
                </div>

                <?php if ($phone !== '' && $phoneRaw !== '0'): ?>
                    <div class="d-flex justify-content-end">
                        <a
                            class="btn btn-success"
                            target="_blank"
                            rel="noopener noreferrer"
                            href="https://wa.me/<?= e($phone) ?>?text=<?= rawurlencode((string) $reminder['message']) ?>"
                        >
                            Buka WhatsApp
                        </a>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning mb-0">
                        Nomor WhatsApp dokter belum tersedia.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
