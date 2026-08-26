<?php

require_once __DIR__ . '/db.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

function preview_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function preview_phone($phone)
{
    $phone = preg_replace('/\D+/', '', (string) $phone);

    if (strpos($phone, '0') === 0) {
        $phone = '62' . substr($phone, 1);
    }

    return $phone;
}

if ($id <= 0) {
    http_response_code(400);
    exit('ID reminder tidak valid.');
}

try {
    $statement = db()->prepare("
        SELECT *
        FROM reminders
        WHERE id = ?
        LIMIT 1
    ");

    $statement->execute([$id]);
    $reminder = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$reminder) {
        http_response_code(404);
        exit('Reminder tidak ditemukan.');
    }
} catch (Exception $e) {
    http_response_code(500);
    exit('Preview gagal dimuat: ' . preview_escape($e->getMessage()));
}

$doctorId = isset($reminder['doctor_id']) ? (string) $reminder['doctor_id'] : '-';
$date = isset($reminder['tanggal']) ? (string) $reminder['tanggal'] : '';
$message = isset($reminder['message']) ? (string) $reminder['message'] : '';
$status = isset($reminder['status']) ? (string) $reminder['status'] : 'READY';
$displayDate = $date !== '' ? date('d-m-Y', strtotime($date)) : '-';

$statusClass = 'secondary';

if ($status === 'SENT') {
    $statusClass = 'success';
} elseif ($status === 'FAILED') {
    $statusClass = 'danger';
} elseif ($status === 'OPENED') {
    $statusClass = 'primary';
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
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container py-2">
            <a class="navbar-brand fw-bold" href="index.php">
                DokterReminder
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
                    Reminder Dokter <?= preview_escape($doctorId) ?>
                </h1>

                <div class="d-flex flex-wrap align-items-center gap-2 text-secondary">
                    <span><?= preview_escape($displayDate) ?></span>
                    <span class="badge text-bg-<?= preview_escape($statusClass) ?>">
                        <?= preview_escape($status) ?>
                    </span>
                </div>
            </div>

            <a class="btn btn-outline-secondary" href="index.php">
                Kembali
            </a>
        </div>

        <div class="card-modern shadow-sm">
            <div class="card-header-modern">
                Preview Pesan WhatsApp
            </div>

            <div class="card-body-modern">
                <div class="message-preview mb-0">
                    <?= nl2br(preview_escape($message)) ?>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
