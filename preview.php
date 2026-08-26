<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$id = (int) ($_GET['id'] ?? 0);

$statement = db()->prepare("
    SELECT
        r.*,
        md.dokter_nama,
        COALESCE(kd.no_hp, '') AS no_whatsapp
    FROM reminders r
    LEFT JOIN rsi_byl.master_dokter md
        ON md.dokter_kd = r.doctor_id
    LEFT JOIN rsi_byl.kontak_dokter kd
        ON kd.kd_dr = r.doctor_id
    WHERE r.id = ?
    LIMIT 1
");

$statement->execute([$id]);
$reminder = $statement->fetch();

if (!$reminder) {
    http_response_code(404);
    exit('Reminder tidak ditemukan');
}
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
    <main class="container py-4 py-lg-5 page-narrow">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <span class="badge text-bg-success-subtle text-success mb-2">PREVIEW PESAN</span>
                <h1 class="h3 mb-1"><?= e($reminder['dokter_nama'] ?? '') ?></h1>
                <p class="text-secondary mb-0">
                    <?= e($reminder['no_whatsapp'] ?: '-') ?>
                    ·
                    <?= e($reminder['tanggal']) ?>
                </p>
            </div>

            <a class="btn btn-light" href="index.php">
                Kembali
            </a>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <div class="message-preview mb-4">
                    <?= nl2br(e($reminder['message'])) ?>
                </div>

                <div class="d-flex justify-content-end">
                    <a
                        class="btn btn-success"
                        target="_blank"
                        href="https://wa.me/<?= e(normalizePhone($reminder['no_whatsapp'])) ?>?text=<?= rawurlencode($reminder['message']) ?>"
                    >
                        Buka WhatsApp
                    </a>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
