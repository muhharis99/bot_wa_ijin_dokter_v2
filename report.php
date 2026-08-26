<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$startDate = trim($_GET['start_date'] ?? date('Y-m-01'));
$endDate = trim($_GET['end_date'] ?? date('Y-m-d'));
$status = trim($_GET['status'] ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    $startDate = date('Y-m-01');
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    $endDate = date('Y-m-d');
}

$allowedStatuses = ['', 'PENDING', 'READY', 'OPENED', 'SENT', 'FAILED'];

if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

$sql = "
    SELECT
        id,
        doctor_id,
        tanggal,
        reminder_type,
        status,
        opened_at,
        sent_at,
        created_at
    FROM reminders
    WHERE tanggal BETWEEN ? AND ?
";

$params = [
    $startDate,
    $endDate
];

if ($status !== '') {
    $sql .= " AND status = ?";
    $params[] = $status;
}

$sql .= " ORDER BY tanggal DESC, created_at DESC";

$statement = db()->prepare($sql);
$statement->execute($params);
$rows = $statement->fetchAll();

function reportDoctorName($doctorId): string
{
    static $cache = [];

    $key = (string) $doctorId;

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    try {
        $external = get_db('rsi_byl')->prepare("
            SELECT dokter_nama
            FROM master_dokter
            WHERE dokter_kd = ?
            LIMIT 1
        ");
        $external->execute([$key]);
        $name = $external->fetchColumn();

        if ($name) {
            $cache[$key] = (string) $name;
            return $cache[$key];
        }
    } catch (Throwable $e) {
    }

    try {
        $local = db()->prepare("
            SELECT nama_dokter
            FROM doctors
            WHERE id = ? OR kode_dokter = ?
            LIMIT 1
        ");
        $local->execute([$key, $key]);
        $name = $local->fetchColumn();

        if ($name) {
            $cache[$key] = (string) $name;
            return $cache[$key];
        }
    } catch (Throwable $e) {
    }

    $cache[$key] = $key;
    return $cache[$key];
}

$summary = [
    'TOTAL' => count($rows),
    'SENT' => 0,
    'READY' => 0,
    'FAILED' => 0
];

foreach ($rows as $row) {
    if (isset($summary[$row['status']])) {
        $summary[$row['status']]++;
    }
}

$pdfQuery = http_build_query([
    'start_date' => $startDate,
    'end_date' => $endDate,
    'status' => $status
]);
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Report Reminder</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.datatables.net/v/bs5/dt-3.0.2/r-4.0.2/datatables.min.css"
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
                    <a class="nav-link" href="index.php">Dashboard</a>
                    <a class="nav-link" href="master.php">Master Data</a>
                    <a class="nav-link" href="settings.php">Template</a>
                    <a class="nav-link active fw-semibold" href="report.php">Report</a>
                </div>
            </div>
        </div>
    </nav>

    <main class="container py-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
            <div>
                <span class="badge text-bg-success-subtle text-success mb-2">LAPORAN</span>
                <h1 class="h3 mb-1">Report Reminder WhatsApp</h1>
                <p class="text-secondary mb-0">
                    Rekap reminder dokter berdasarkan periode dan status pengiriman.
                </p>
            </div>

            <a
                class="btn btn-success"
                id="printPdfButton"
                href="report_pdf.php?<?= e($pdfQuery) ?>"
                target="_blank"
            >
                Cetak PDF
            </a>
        </div>

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-end" id="reportFilterForm">
                    <div class="col-md-4">
                        <label class="form-label" for="startDate">Tanggal Awal</label>
                        <input
                            type="date"
                            class="form-control"
                            id="startDate"
                            name="start_date"
                            value="<?= e($startDate) ?>"
                        >
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="endDate">Tanggal Akhir</label>
                        <input
                            type="date"
                            class="form-control"
                            id="endDate"
                            name="end_date"
                            value="<?= e($endDate) ?>"
                        >
                    </div>

                    <div class="col-md-2">
                        <label class="form-label" for="status">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">Semua Status</option>
                            <?php foreach (['PENDING', 'READY', 'OPENED', 'SENT', 'FAILED'] as $item): ?>
                                <option
                                    value="<?= e($item) ?>"
                                    <?= $status === $item ? 'selected' : '' ?>
                                >
                                    <?= e($item) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2 d-grid">
                        <button class="btn btn-success" type="submit" id="showReportButton">
                            Tampilkan
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-3">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body py-3">
                        <div class="text-secondary small">TOTAL</div>
                        <div class="h3 fw-bold mb-0"><?= $summary['TOTAL'] ?></div>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body py-3">
                        <div class="text-secondary small">SENT</div>
                        <div class="h3 fw-bold mb-0"><?= $summary['SENT'] ?></div>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body py-3">
                        <div class="text-secondary small">READY</div>
                        <div class="h3 fw-bold mb-0"><?= $summary['READY'] ?></div>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body py-3">
                        <div class="text-secondary small">FAILED</div>
                        <div class="h3 fw-bold mb-0"><?= $summary['FAILED'] ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-body">
                <div class="table-responsive">
                    <table id="reportTable" class="table table-striped table-hover align-middle w-100">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Tanggal</th>
                                <th>Dokter</th>
                                <th>Jenis</th>
                                <th>Status</th>
                                <th>Dibuat</th>
                                <th>Terkirim</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $index => $row): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td data-order="<?= e($row['tanggal']) ?>">
                                        <?= e(date('d-m-Y', strtotime($row['tanggal']))) ?>
                                    </td>
                                    <td><?= e(reportDoctorName($row['doctor_id'])) ?></td>
                                    <td><?= e($row['reminder_type']) ?></td>
                                    <td>
                                        <span class="badge <?= $row['status'] === 'SENT' ? 'text-bg-success' : ($row['status'] === 'FAILED' ? 'text-bg-danger' : 'text-bg-secondary') ?>">
                                            <?= e($row['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= e($row['created_at'] ? date('d-m-Y H:i:s', strtotime($row['created_at'])) : '-') ?></td>
                                    <td><?= e($row['sent_at'] ? date('d-m-Y H:i:s', strtotime($row['sent_at'])) : '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/v/bs5/dt-3.0.2/r-4.0.2/datatables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            new DataTable('#reportTable', {
                responsive: true,
                pageLength: 25,
                order: [[1, 'desc']],
                language: {
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
                }
            });

            const reportFilterForm = document.getElementById('reportFilterForm');
            const showReportButton = document.getElementById('showReportButton');
            const printPdfButton = document.getElementById('printPdfButton');

            reportFilterForm.addEventListener('submit', function () {
                showReportButton.disabled = true;
                showReportButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Memuat...';

                Swal.fire({
                    title: 'Memuat Report',
                    text: 'Mohon tunggu...',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showConfirmButton: false,
                    didOpen: function () {
                        Swal.showLoading();
                    }
                });
            });

            printPdfButton.addEventListener('click', function () {
                const button = this;
                const originalHtml = button.innerHTML;

                button.classList.add('disabled');
                button.setAttribute('aria-disabled', 'true');
                button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Membuat PDF...';

                Swal.fire({
                    title: 'Membuat PDF',
                    text: 'Report sedang diproses...',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showConfirmButton: false,
                    didOpen: function () {
                        Swal.showLoading();
                    }
                });

                window.setTimeout(function () {
                    Swal.close();
                    button.classList.remove('disabled');
                    button.removeAttribute('aria-disabled');
                    button.innerHTML = originalHtml;
                }, 1800);
            });
        });
    </script>
</body>
</html>
