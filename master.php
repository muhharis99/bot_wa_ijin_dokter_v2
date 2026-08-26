<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$pdo = get_db('rsi_byl');
$notice = '';
$noticeType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_contact') {
            $doctorCode = trim($_POST['dokter_kd'] ?? '');
            $phone = trim($_POST['no_hp'] ?? '');

            if ($doctorCode === '') {
                throw new RuntimeException('Dokter wajib dipilih.');
            }

            if ($phone === '') {
                throw new RuntimeException('Nomor WhatsApp wajib diisi.');
            }

            $check = $pdo->prepare("
                SELECT kd_dr
                FROM kontak_dokter
                WHERE kd_dr = ?
                LIMIT 1
            ");

            $check->execute([$doctorCode]);

            if ($check->fetchColumn()) {
                $statement = $pdo->prepare("
                    UPDATE kontak_dokter
                    SET no_hp = ?
                    WHERE kd_dr = ?
                ");

                $statement->execute([
                    $phone,
                    $doctorCode
                ]);
            } else {
                $statement = $pdo->prepare("
                    INSERT INTO kontak_dokter (
                        kd_dr,
                        no_hp
                    ) VALUES (?, ?)
                ");

                $statement->execute([
                    $doctorCode,
                    $phone
                ]);
            }

            $notice = 'Nomor WhatsApp dokter berhasil disimpan.';
        }

        if ($action === 'disable_contact') {
            $doctorCode = trim($_POST['dokter_kd'] ?? '');

            if ($doctorCode === '') {
                throw new RuntimeException('Kode dokter tidak valid.');
            }

            $statement = $pdo->prepare("
                UPDATE kontak_dokter
                SET no_hp = '0'
                WHERE kd_dr = ?
            ");

            $statement->execute([$doctorCode]);
            $notice = 'Nomor WhatsApp dokter dinonaktifkan.';
        }
    } catch (Throwable $e) {
        $notice = $e->getMessage();
        $noticeType = 'danger';
    }
}

$doctors = $pdo->query("
    SELECT
        md.dokter_kd,
        md.dokter_nama,
        COALESCE(kd.no_hp, '') AS no_hp
    FROM master_dokter md
    LEFT JOIN kontak_dokter kd
        ON kd.kd_dr = md.dokter_kd
    ORDER BY md.dokter_nama ASC
")->fetchAll();

$policies = $pdo->query("
    SELECT
        poli_kd,
        poli_nama
    FROM master_poli
    ORDER BY poli_nama ASC
")->fetchAll();

$schedules = $pdo->query("
    SELECT
        djk.tanggal,
        djk.hari,
        dj.dokter_kd,
        md.dokter_nama,
        dj.poli_kd,
        mp.poli_nama,
        dj.jam_mulai,
        dj.jam_selesai,
        djk.kuota_all,
        djk.aktif,
        COALESCE(kd.no_hp, '') AS no_hp
    FROM dokter_jadwal_kuota djk
    LEFT JOIN dokter_jadwal dj
        ON dj.id = djk.dokter_jadwal_id
    LEFT JOIN master_dokter md
        ON md.dokter_kd = dj.dokter_kd
    LEFT JOIN master_poli mp
        ON mp.poli_kd = dj.poli_kd
    LEFT JOIN kontak_dokter kd
        ON kd.kd_dr = dj.dokter_kd
    WHERE djk.tanggal >= CURDATE()
    ORDER BY
        djk.tanggal ASC,
        dj.jam_mulai ASC
    LIMIT 500
")->fetchAll();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Master Data</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.datatables.net/v/bs5/dt-3.0.2/r-4.0.2/datatables.min.css"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="assets/style.css">

    <style>
        body {
            background: #f4f6f9 !important;
        }

        .admin-wrapper {
            min-height: 100vh;
            display: flex;
        }

        .admin-sidebar {
            width: 240px;
            min-height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1035;
            background: #343a40;
            color: #ffffff;
        }

        .admin-brand {
            height: 64px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0 18px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            color: #ffffff;
            text-decoration: none;
            font-weight: 600;
        }

        .admin-brand:hover {
            color: #ffffff;
        }

        .admin-brand-icon {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: #198754;
        }

        .admin-menu {
            padding: 14px 10px;
        }

        .admin-menu-title {
            padding: 10px 12px 6px;
            color: #adb5bd;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }

        .admin-menu .nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 4px;
            padding: 10px 12px;
            border-radius: 4px;
            color: #c2c7d0;
            font-size: 14px;
        }

        .admin-menu .nav-link:hover {
            background: rgba(255, 255, 255, 0.08);
            color: #ffffff;
        }

        .admin-menu .nav-link.active {
            background: #198754;
            color: #ffffff;
        }

        .admin-content {
            width: calc(100% - 240px);
            margin-left: 240px;
        }

        .admin-topbar {
            min-height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
            border-bottom: 1px solid #dee2e6;
            background: #ffffff;
        }

        .content-header {
            padding: 22px 24px 14px;
        }

        .content-body {
            padding: 0 24px 30px;
        }

        .small-box {
            position: relative;
            overflow: hidden;
            min-height: 118px;
            border-radius: 4px;
            background: #ffffff;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
        }

        .small-box-inner {
            padding: 18px;
        }

        .small-box h3 {
            margin: 0 0 4px;
            font-size: 32px;
            font-weight: 700;
        }

        .small-box p {
            margin: 0;
            color: #6c757d;
            font-size: 14px;
        }

        .small-box-icon {
            position: absolute;
            top: 18px;
            right: 18px;
            color: rgba(0, 0, 0, 0.12);
            font-size: 48px;
        }

        .admin-card {
            margin-bottom: 20px;
            border: 0;
            border-top: 3px solid #198754;
            border-radius: 4px;
            background: #ffffff;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
        }

        .admin-card .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px !important;
            border-bottom: 1px solid #dee2e6 !important;
            background: #ffffff !important;
            color: #212529 !important;
        }

        .admin-card .card-title {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
        }

        .admin-card .card-body {
            padding: 16px;
        }

        .table thead th {
            background: #f8f9fa !important;
            color: #495057 !important;
            font-size: 12px;
            text-transform: none;
        }

        .table tbody td {
            font-size: 13px;
        }

        .btn {
            border-radius: 4px;
        }

        .form-control,
        .form-select {
            border-radius: 4px !important;
        }

        .modal-content {
            border-radius: 4px !important;
        }

        .modal-header {
            background: #198754 !important;
        }

        @media (max-width: 991.98px) {
            .admin-sidebar {
                position: static;
                width: 100%;
                min-height: auto;
            }

            .admin-wrapper {
                display: block;
            }

            .admin-content {
                width: 100%;
                margin-left: 0;
            }

            .admin-menu {
                display: flex;
                gap: 6px;
                overflow-x: auto;
            }

            .admin-menu-title {
                display: none;
            }

            .admin-menu .nav-link {
                flex: 0 0 auto;
            }
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <aside class="admin-sidebar">
            <a class="admin-brand" href="index.php">
                <span class="admin-brand-icon">
                    <i class="bi bi-whatsapp"></i>
                </span>
                <span>Dokter Reminder</span>
            </a>

            <nav class="admin-menu nav flex-column">
                <div class="admin-menu-title">Menu Utama</div>

                <a class="nav-link" href="index.php">
                    <i class="bi bi-speedometer2"></i>
                    <span>Dashboard</span>
                </a>

                <a class="nav-link active" href="master.php">
                    <i class="bi bi-database"></i>
                    <span>Master Data</span>
                </a>

                <a class="nav-link" href="settings.php">
                    <i class="bi bi-chat-left-text"></i>
                    <span>Template</span>
                </a>
            </nav>
        </aside>

        <div class="admin-content">
            <header class="admin-topbar">
                <div>
                    <span class="text-secondary small">Administrasi</span>
                    <div class="fw-semibold">Master Data</div>
                </div>

                <a class="btn btn-sm btn-outline-secondary" href="index.php">
                    <i class="bi bi-arrow-left"></i>
                    Dashboard
                </a>
            </header>

            <div class="content-header d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div>
                    <h1 class="h4 mb-1">Master Data</h1>
                    <div class="text-secondary small">
                        Kelola nomor WhatsApp dokter, jadwal, dan referensi poli.
                    </div>
                </div>

                <button
                    class="btn btn-success btn-sm"
                    type="button"
                    data-bs-toggle="modal"
                    data-bs-target="#contactModal"
                >
                    <i class="bi bi-plus-lg"></i>
                    Nomor WhatsApp
                </button>
            </div>

            <main class="content-body">
                <?php if ($notice !== ''): ?>
                    <div class="alert alert-<?= e($noticeType) ?> alert-dismissible fade show" role="alert">
                        <?= e($notice) ?>
                        <button
                            type="button"
                            class="btn-close"
                            data-bs-dismiss="alert"
                        ></button>
                    </div>
                <?php endif; ?>

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="small-box">
                            <div class="small-box-inner">
                                <h3><?= count($doctors) ?></h3>
                                <p>Total Dokter</p>
                            </div>
                            <div class="small-box-icon">
                                <i class="bi bi-person-badge"></i>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="small-box">
                            <div class="small-box-inner">
                                <h3><?= count($policies) ?></h3>
                                <p>Total Poli</p>
                            </div>
                            <div class="small-box-icon">
                                <i class="bi bi-hospital"></i>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="small-box">
                            <div class="small-box-inner">
                                <h3><?= count($schedules) ?></h3>
                                <p>Jadwal Mendatang</p>
                            </div>
                            <div class="small-box-icon">
                                <i class="bi bi-calendar3"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card admin-card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <i class="bi bi-person-lines-fill me-1"></i>
                            Dokter & Nomor WhatsApp
                        </h2>

                        <button
                            class="btn btn-success btn-sm"
                            type="button"
                            data-bs-toggle="modal"
                            data-bs-target="#contactModal"
                        >
                            Tambah / Ubah
                        </button>
                    </div>

                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="doctorTable" class="table table-bordered table-hover align-middle w-100">
                                <thead>
                                    <tr>
                                        <th>Kode</th>
                                        <th>Nama Dokter</th>
                                        <th>Nomor WhatsApp</th>
                                        <th>Status</th>
                                        <th style="width: 150px;">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($doctors as $doctor): ?>
                                        <?php
                                        $phone = trim((string) $doctor['no_hp']);
                                        $isActive = $phone !== '' && $phone !== '0';
                                        ?>
                                        <tr>
                                            <td><?= e($doctor['dokter_kd']) ?></td>
                                            <td><?= e($doctor['dokter_nama']) ?></td>
                                            <td><?= e($isActive ? $phone : '-') ?></td>
                                            <td>
                                                <?php if ($isActive): ?>
                                                    <span class="badge text-bg-success">Aktif</span>
                                                <?php else: ?>
                                                    <span class="badge text-bg-secondary">Belum Ada</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="d-flex gap-1">
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-primary js-edit-contact"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#contactModal"
                                                        data-doctor-code="<?= e($doctor['dokter_kd']) ?>"
                                                        data-phone="<?= e($isActive ? $phone : '') ?>"
                                                    >
                                                        Edit
                                                    </button>

                                                    <?php if ($isActive): ?>
                                                        <form method="post">
                                                            <input type="hidden" name="action" value="disable_contact">
                                                            <input
                                                                type="hidden"
                                                                name="dokter_kd"
                                                                value="<?= e($doctor['dokter_kd']) ?>"
                                                            >
                                                            <button
                                                                type="submit"
                                                                class="btn btn-sm btn-danger"
                                                                onclick="return confirm('Nonaktifkan nomor WhatsApp dokter ini?')"
                                                            >
                                                                Nonaktifkan
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card admin-card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <i class="bi bi-calendar-week me-1"></i>
                            Jadwal Praktik
                        </h2>
                    </div>

                    <div class="card-body">
                        <div class="row g-2 align-items-end mb-3">
                            <div class="col-md-4">
                                <label class="form-label" for="filterDoctor">Dokter</label>
                                <select id="filterDoctor" class="form-select form-select-sm">
                                    <option value="">Semua Dokter</option>
                                    <?php foreach ($doctors as $doctor): ?>
                                        <option value="<?= e($doctor['dokter_nama']) ?>">
                                            <?= e($doctor['dokter_nama']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label" for="filterPoli">Poli</label>
                                <select id="filterPoli" class="form-select form-select-sm">
                                    <option value="">Semua Poli</option>
                                    <?php foreach ($policies as $policy): ?>
                                        <option value="<?= e($policy['poli_nama']) ?>">
                                            <?= e($policy['poli_nama']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label" for="filterDate">Tanggal</label>
                                <input
                                    type="text"
                                    id="filterDate"
                                    class="form-control form-control-sm"
                                    placeholder="DD-MM-YYYY"
                                    inputmode="numeric"
                                    maxlength="10"
                                >
                            </div>

                            <div class="col-md-1 d-grid">
                                <button
                                    type="button"
                                    id="resetScheduleFilter"
                                    class="btn btn-outline-secondary btn-sm"
                                >
                                    Reset
                                </button>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table id="scheduleTable" class="table table-bordered table-hover align-middle w-100">
                                <thead>
                                    <tr>
                                        <th>Tanggal</th>
                                        <th>Hari</th>
                                        <th>Dokter</th>
                                        <th>Poli</th>
                                        <th>Jam</th>
                                        <th>Kuota</th>
                                        <th>WhatsApp</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($schedules as $schedule): ?>
                                        <?php
                                        $scheduleDate = date('d-m-Y', strtotime($schedule['tanggal']));
                                        ?>
                                        <tr>
                                            <td data-order="<?= e($schedule['tanggal']) ?>">
                                                <?= e($scheduleDate) ?>
                                            </td>
                                            <td><?= e($schedule['hari']) ?></td>
                                            <td><?= e($schedule['dokter_nama']) ?></td>
                                            <td><?= e($schedule['poli_nama']) ?></td>
                                            <td>
                                                <?= e($schedule['jam_mulai']) ?>
                                                -
                                                <?= e($schedule['jam_selesai']) ?>
                                            </td>
                                            <td><?= e((string) $schedule['kuota_all']) ?></td>
                                            <td><?= e($schedule['no_hp'] ?: '-') ?></td>
                                            <td>
                                                <?php if ((string) $schedule['aktif'] === '1'): ?>
                                                    <span class="badge text-bg-success">Aktif</span>
                                                <?php else: ?>
                                                    <span class="badge text-bg-secondary">Nonaktif</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card admin-card mb-0">
                    <div class="card-header">
                        <h2 class="card-title">
                            <i class="bi bi-building me-1"></i>
                            Master Poli
                        </h2>
                    </div>

                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="poliTable" class="table table-bordered table-hover align-middle w-100">
                                <thead>
                                    <tr>
                                        <th>Kode Poli</th>
                                        <th>Nama Poli</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($policies as $policy): ?>
                                        <tr>
                                            <td><?= e($policy['poli_kd']) ?></td>
                                            <td><?= e($policy['poli_nama']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <div class="modal fade" id="contactModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post">
                    <input type="hidden" name="action" value="save_contact">

                    <div class="modal-header">
                        <h2 class="modal-title fs-6">Nomor WhatsApp Dokter</h2>
                        <button
                            type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"
                        ></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="doctorSelect">Dokter</label>
                            <select
                                class="form-select"
                                id="doctorSelect"
                                name="dokter_kd"
                                required
                            >
                                <option value="">Pilih dokter</option>
                                <?php foreach ($doctors as $doctor): ?>
                                    <option value="<?= e($doctor['dokter_kd']) ?>">
                                        <?= e($doctor['dokter_kd']) ?> - <?= e($doctor['dokter_nama']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="form-label" for="phoneInput">Nomor WhatsApp</label>
                            <input
                                class="form-control"
                                id="phoneInput"
                                name="no_hp"
                                placeholder="Contoh: 081234567890"
                                required
                            >
                            <div class="form-text">
                                Nomor 08xx akan dinormalisasi menjadi 62xx ketika pesan dikirim.
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button
                            type="button"
                            class="btn btn-secondary"
                            data-bs-dismiss="modal"
                        >
                            Batal
                        </button>
                        <button type="submit" class="btn btn-success">
                            Simpan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/v/bs5/dt-3.0.2/r-4.0.2/datatables.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const language = {
                search: 'Cari:',
                lengthMenu: 'Tampilkan _MENU_ data',
                info: 'Menampilkan _START_ sampai _END_ dari _TOTAL_ data',
                infoEmpty: 'Tidak ada data',
                zeroRecords: 'Data tidak ditemukan',
                emptyTable: 'Belum ada data',
                paginate: {
                    first: 'Awal',
                    last: 'Akhir',
                    next: 'Berikutnya',
                    previous: 'Sebelumnya'
                }
            };

            new DataTable('#doctorTable', {
                responsive: true,
                pageLength: 25,
                order: [[1, 'asc']],
                language
            });

            const scheduleTable = new DataTable('#scheduleTable', {
                responsive: true,
                pageLength: 25,
                order: [[0, 'asc'], [4, 'asc']],
                language
            });

            new DataTable('#poliTable', {
                responsive: true,
                pageLength: 25,
                order: [[1, 'asc']],
                language
            });

            const filterDoctor = document.getElementById('filterDoctor');
            const filterPoli = document.getElementById('filterPoli');
            const filterDate = document.getElementById('filterDate');
            const resetScheduleFilter = document.getElementById('resetScheduleFilter');

            function escapeRegex(value) {
                return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            }

            function applyScheduleFilters() {
                const doctor = filterDoctor.value;
                const poli = filterPoli.value;
                const date = filterDate.value.trim();

                scheduleTable
                    .column(2)
                    .search(doctor ? '^' + escapeRegex(doctor) + '$' : '', true, false);

                scheduleTable
                    .column(3)
                    .search(poli ? '^' + escapeRegex(poli) + '$' : '', true, false);

                scheduleTable
                    .column(0)
                    .search(date ? '^' + escapeRegex(date) + '$' : '', true, false);

                scheduleTable.draw();
            }

            filterDoctor.addEventListener('change', applyScheduleFilters);
            filterPoli.addEventListener('change', applyScheduleFilters);

            filterDate.addEventListener('input', function () {
                let value = this.value.replace(/\D/g, '').slice(0, 8);

                if (value.length > 4) {
                    value = value.slice(0, 2) + '-' + value.slice(2, 4) + '-' + value.slice(4);
                } else if (value.length > 2) {
                    value = value.slice(0, 2) + '-' + value.slice(2);
                }

                this.value = value;
                applyScheduleFilters();
            });

            resetScheduleFilter.addEventListener('click', function () {
                filterDoctor.value = '';
                filterPoli.value = '';
                filterDate.value = '';

                scheduleTable.columns().search('');
                scheduleTable.search('');
                scheduleTable.draw();
            });

            const doctorSelect = document.getElementById('doctorSelect');
            const phoneInput = document.getElementById('phoneInput');
            const contactModal = document.getElementById('contactModal');

            contactModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;

                if (!button || !button.classList.contains('js-edit-contact')) {
                    doctorSelect.value = '';
                    phoneInput.value = '';
                    return;
                }

                doctorSelect.value = button.dataset.doctorCode || '';
                phoneInput.value = button.dataset.phone || '';
            });
        });
    </script>
</body>
</html>
