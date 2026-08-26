<?php

declare(strict_types=1);
require_once __DIR__ . '/functions.php';
$id = (int)($_GET['id'] ?? 0);
$q = db()->prepare('SELECT r.*,d.nama_dokter,d.no_whatsapp FROM reminders r JOIN doctors d ON d.id=r.doctor_id WHERE r.id=?');
$q->execute([$id]);
$r = $q->fetch();
if (!$r) {
    http_response_code(404);
    exit('Reminder tidak ditemukan');
} ?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Preview Reminder</title>
    <link rel="stylesheet" href="assets/style.css">
</head>

<body>
    <main class="narrow"><a class="back" href="index.php">← Kembali ke dashboard</a>
        <div class="preview-head"><span class="eyebrow">PREVIEW PESAN</span>
            <h1><?= e($r['nama_dokter']) ?></h1>
            <p><?= e($r['no_whatsapp']) ?> · <?= e($r['tanggal']) ?></p>
        </div>
        <div class="message"><?= nl2br(e($r['message'])) ?></div><a class="button whatsapp full" target="_blank" href="https://wa.me/<?= e(normalizePhone($r['no_whatsapp'])) ?>?text=<?= rawurlencode($r['message']) ?>">Buka WhatsApp</a>
    </main>
</body>

</html>