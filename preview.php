<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$id = (int) ($_GET['id'] ?? 0);

$statement = db()->prepare("
    SELECT
        r.*,
        d.nama_dokter,
        d.no_whatsapp
    FROM reminders r
    JOIN doctors d
        ON d.id = r.doctor_id
    WHERE r.id = ?
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
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <main class="narrow">
        <a class="back" href="index.php">
            ← Kembali ke dashboard
        </a>

        <div class="preview-head">
            <span class="eyebrow">PREVIEW PESAN</span>
            <h1><?= e($reminder['nama_dokter']) ?></h1>
            <p>
                <?= e($reminder['no_whatsapp']) ?> · <?= e($reminder['tanggal']) ?>
            </p>
        </div>

        <div class="message">
            <?= nl2br(e($reminder['message'])) ?>
        </div>

        <a
            class="button whatsapp full"
            target="_blank"
            href="https://wa.me/<?= e(normalizePhone($reminder['no_whatsapp'])) ?>?text=<?= rawurlencode($reminder['message']) ?>"
        >
            Buka WhatsApp
        </a>
    </main>
</body>
</html>
