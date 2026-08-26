<?php

declare(strict_types=1);
require_once __DIR__ . '/functions.php';
$pdo = get_db('rsi_byl');
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'] ?? '';
    try {
        if ($type === 'doctor') {
            $q = $pdo->prepare('INSERT INTO master_dokter(dokter_kd,dokter_nama,spesialis,dokter_no_wa,status) VALUES(?,?,?,?,\'AKTIF\')');
            $q->execute([trim($_POST['kode']), trim($_POST['nama']), trim($_POST['spesialis']), trim($_POST['wa'])]);
        } elseif ($type === 'schedule') {
            $q = $pdo->prepare('INSERT INTO dokter_jadwal(dokter_kd,hari,tanggal,jam_mulai,jam_selesai,lokasi,poli_nama,status) VALUES(?,?,?,?,?,?,?,\'AKTIF\')');
            $q->execute([$_POST['dokter_kd'], $_POST['hari'], $_POST['tanggal'], $_POST['mulai'], $_POST['selesai'], trim($_POST['lokasi']), $_POST['poli_nama']]);
        }
        $notice = 'Data berhasil disimpan.';
    } catch (Throwable $e) {
        $notice = 'Gagal menyimpan: ' . $e->getMessage();
    }
}
$doctors = $pdo->query("SELECT * FROM master_dokter WHERE status='AKTIF' ORDER BY dokter_nama")->fetchAll();
$schedules = $pdo->query('SELECT dj.*, md.dokter_nama FROM dokter_jadwal dj JOIN master_dokter md ON md.dokter_kd = dj.dokter_kd WHERE dj.status=\'AKTIF\' ORDER BY dj.tanggal DESC, dj.jam_mulai DESC LIMIT 30')->fetchAll(); ?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Master Data</title>
    <link rel="stylesheet" href="assets/style.css">
</head>

<body>
    <header>
        <div><span class="eyebrow">ADMINISTRASI</span>
            <h1>Master Data</h1>
            <p>Kelola dokter, poli, dan jadwal praktik.</p>
        </div>
        <nav><a href="index.php">Dashboard</a><a class="active" href="master.php">Master Data</a><a href="settings.php">Template</a></nav>
    </header>
    <main><a class="back" href="index.php">← Kembali ke dashboard</a><?php if ($notice): ?><div class="notice"><?= e($notice) ?></div><?php endif; ?><section class="forms">
            <form method="post" class="panel"><input type="hidden" name="type" value="doctor">
                <h2>Tambah dokter</h2><input required name="kode" placeholder="Kode dokter"><input required name="nama" placeholder="Nama dokter"><input name="spesialis" placeholder="Spesialis"><input required name="wa" placeholder="Nomor WhatsApp"><button class="button">Simpan dokter</button>
            </form>
            <form method="post" class="panel"><input type="hidden" name="type" value="schedule">
                <h2>Tambah jadwal</h2><select required name="dokter_kd">
                    <option value="">Pilih dokter</option><?php foreach ($doctors as $d): ?><option value="<?= $d['dokter_kd'] ?>"><?= e($d['dokter_nama']) ?></option><?php endforeach; ?>
                </select><input required name="hari" placeholder="Hari"><input required type="date" name="tanggal" value="<?= date('Y-m-d') ?>">
                <div class="two"><input required type="time" name="mulai"><input required type="time" name="selesai"></div><input required name="poli_nama" placeholder="Poli"><input name="lokasi" placeholder="Lokasi"><button class="button">Simpan jadwal</button>
            </form>
        </section>
        <div class="section-title">
            <div><span class="eyebrow">DATA TERBARU</span>
                <h2>Jadwal praktik</h2>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <tr>
                    <th>Tanggal</th>
                    <th>Dokter</th>
                    <th>Poli</th>
                    <th>Jam</th>
                    <th>Lokasi</th>
                </tr><?php foreach ($schedules as $s): ?><tr>
                        <td><?= e($s['tanggal']) ?></td>
                        <td><?= e($s['dokter_nama']) ?></td>
                        <td><?= e($s['poli_nama']) ?></td>
                        <td><?= e($s['jam_mulai']) ?>–<?= e($s['jam_selesai']) ?></td>
                        <td><?= e($s['lokasi']) ?></td>
                    </tr><?php endforeach; ?>
            </table>
        </div>
    </main>
</body>

</html>