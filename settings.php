<?php

declare(strict_types=1);
require_once __DIR__ . '/functions.php';
$pdo = db();
$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['nama_rs', 'template'] as $key) {
        $q = $pdo->prepare('INSERT INTO settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');
        $q->execute([$key, $_POST[$key] ?? '']);
    }
    $saved = true;
}
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Template Pesan</title>
    <link rel="stylesheet" href="assets/style.css">
</head>

<body>
    <header>
        <div><span class="eyebrow">KONFIGURASI</span>
            <h1>Template WhatsApp</h1>
            <p>Pesan yang dipakai saat membuat reminder baru.</p>
        </div>
        <nav><a href="index.php">Dashboard</a><a href="master.php">Master Data</a><a class="active" href="settings.php">Template</a></nav>
    </header>
    <main class="narrow"><a class="back" href="index.php">← Kembali ke dashboard</a><?php if ($saved): ?><div class="notice">Pengaturan berhasil disimpan. Reminder yang sudah dibuat tidak berubah.</div><?php endif; ?><form method="post" class="panel"><label>Nama rumah sakit<input name="nama_rs" value="<?= e(setting('nama_rs', 'RS Sehat Sentosa')) ?>"></label><label>Template pesan<textarea name="template" rows="16"><?= e(setting('template', DEFAULT_TEMPLATE)) ?></textarea></label>
            <p class="muted">Variabel: {{nama_dokter}}, {{spesialis}}, {{tanggal}}, {{hari}}, {{nama_poli}}, {{jam_mulai}}, {{jam_selesai}}, {{lokasi}}, {{nama_rs}}</p><button class="button">Simpan template</button>
        </form>
    </main>
</body>

</html>