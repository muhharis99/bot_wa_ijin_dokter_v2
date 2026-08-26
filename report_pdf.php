<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$autoload = __DIR__ . '/vendor/autoload.php';

if (!is_file($autoload)) {
    http_response_code(500);
    exit('mPDF belum terpasang. Jalankan: composer install');
}

require_once $autoload;

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

$sql .= " ORDER BY tanggal ASC, created_at ASC";

$statement = db()->prepare($sql);
$statement->execute($params);
$rows = $statement->fetchAll();

function pdfDoctorName($doctorId): string
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

$total = count($rows);
$sent = 0;
$ready = 0;
$failed = 0;

foreach ($rows as $row) {
    if ($row['status'] === 'SENT') {
        $sent++;
    }

    if ($row['status'] === 'READY') {
        $ready++;
    }

    if ($row['status'] === 'FAILED') {
        $failed++;
    }
}

$hospitalName = setting('nama_rs', 'RSU ISLAM KLATEN');
$statusLabel = $status === '' ? 'Semua Status' : $status;

$mpdf = new \Mpdf\Mpdf([
    'format' => 'A4-L',
    'margin_left' => 10,
    'margin_right' => 10,
    'margin_top' => 14,
    'margin_bottom' => 14
]);

$mpdf->SetTitle('Report Reminder WhatsApp');
$mpdf->SetAuthor($hospitalName);
$mpdf->SetFooter('{PAGENO} / {nbpg}');

$html = '
<style>
body {
    font-family: sans-serif;
    font-size: 10pt;
    color: #1f2937;
}
.header {
    text-align: center;
    margin-bottom: 14px;
}
.header h1 {
    margin: 0 0 4px 0;
    font-size: 16pt;
}
.header p {
    margin: 2px 0;
    color: #4b5563;
}
.summary {
    width: 100%;
    margin: 10px 0 14px 0;
    border-collapse: collapse;
}
.summary td {
    width: 25%;
    border: 1px solid #d1d5db;
    padding: 7px 9px;
    text-align: center;
}
.summary .label {
    font-size: 8pt;
    color: #6b7280;
}
.summary .value {
    margin-top: 3px;
    font-size: 15pt;
    font-weight: bold;
}
.data-table {
    width: 100%;
    border-collapse: collapse;
}
.data-table th {
    background: #0d6e4f;
    color: #ffffff;
    border: 1px solid #0d6e4f;
    padding: 6px;
    font-size: 8.5pt;
}
.data-table td {
    border: 1px solid #d1d5db;
    padding: 5px 6px;
    font-size: 8.5pt;
}
.data-table tr:nth-child(even) td {
    background: #f9fafb;
}
.text-center {
    text-align: center;
}
</style>

<div class="header">
    <h1>REPORT REMINDER WHATSAPP DOKTER</h1>
    <p>' . e($hospitalName) . '</p>
    <p>Periode ' . e(date('d-m-Y', strtotime($startDate))) . ' s/d ' . e(date('d-m-Y', strtotime($endDate))) . ' | Status: ' . e($statusLabel) . '</p>
</div>

<table class="summary">
    <tr>
        <td><div class="label">TOTAL</div><div class="value">' . $total . '</div></td>
        <td><div class="label">SENT</div><div class="value">' . $sent . '</div></td>
        <td><div class="label">READY</div><div class="value">' . $ready . '</div></td>
        <td><div class="label">FAILED</div><div class="value">' . $failed . '</div></td>
    </tr>
</table>

<table class="data-table">
    <thead>
        <tr>
            <th width="4%">No</th>
            <th width="10%">Tanggal</th>
            <th width="30%">Dokter</th>
            <th width="12%">Jenis</th>
            <th width="10%">Status</th>
            <th width="17%">Dibuat</th>
            <th width="17%">Terkirim</th>
        </tr>
    </thead>
    <tbody>';

if (!$rows) {
    $html .= '<tr><td colspan="7" class="text-center">Tidak ada data.</td></tr>';
} else {
    foreach ($rows as $index => $row) {
        $html .= '
        <tr>
            <td class="text-center">' . ($index + 1) . '</td>
            <td>' . e(date('d-m-Y', strtotime($row['tanggal']))) . '</td>
            <td>' . e(pdfDoctorName($row['doctor_id'])) . '</td>
            <td>' . e($row['reminder_type']) . '</td>
            <td class="text-center">' . e($row['status']) . '</td>
            <td>' . e($row['created_at'] ? date('d-m-Y H:i:s', strtotime($row['created_at'])) : '-') . '</td>
            <td>' . e($row['sent_at'] ? date('d-m-Y H:i:s', strtotime($row['sent_at'])) : '-') . '</td>
        </tr>';
    }
}

$html .= '
    </tbody>
</table>';

$mpdf->WriteHTML($html);

$fileName = 'report-reminder-' . $startDate . '-sampai-' . $endDate . '.pdf';
$mpdf->Output($fileName, \Mpdf\Output\Destination::INLINE);
