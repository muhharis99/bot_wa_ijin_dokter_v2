<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

function preview_escape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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
} catch (Throwable $e) {
    http_response_code(500);
    exit('Preview gagal dimuat: ' . preview_escape($e->getMessage()));
}

$doctorId = trim((string) ($reminder['doctor_id'] ?? ''));
$date = trim((string) ($reminder['tanggal'] ?? ''));
$status = (string) ($reminder['status'] ?? 'READY');
$message = (string) ($reminder['message'] ?? '');
$doctorName = $doctorId;
$isLeave = false;
$hasActiveSchedule = false;
$notice = '';

if ($doctorId !== '') {
    try {
        $doctorStatement = get_db('rsi_byl')->prepare("
            SELECT dokter_nama
            FROM master_dokter
            WHERE dokter_kd = ?
            LIMIT 1
        ");

        $doctorStatement->execute([$doctorId]);
        $doctorResult = $doctorStatement->fetch(PDO::FETCH_ASSOC);

        if ($doctorResult && !empty($doctorResult['dokter_nama'])) {
            $doctorName = (string) $doctorResult['dokter_nama'];
        }
    } catch (Throwable $e) {
        $doctorName = $doctorId;
    }
}

if ($doctorId !== '' && $date !== '') {
    $leaveCodes = doctorLeaveCodes($date);
    $isLeave = isset($leaveCodes[$doctorId]);

    if ($isLeave) {
        $notice = 'Dokter tercatat izin pada tanggal ini. Reminder tidak perlu dikirim.';
    } else {
        try {
            $scheduleRows = schedulesFor($date);
            $doctorSchedules = array_values(
                array_filter(
                    $scheduleRows,
                    static function (array $row) use ($doctorId): bool {
                        return (string) ($row['doctor_id'] ?? '') === $doctorId;
                    }
                )
            );

            if ($doctorSchedules) {
                $hasActiveSchedule = true;
                $message = buildReminderMessage(
                    $doctorSchedules[0],
                    $doctorSchedules,
                    $date
                );

                if ((string) ($reminder['message'] ?? '') !== $message) {
                    $updateStatement = db()->prepare("
                        UPDATE reminders
                        SET message = ?
                        WHERE id = ?
                    ");

                    $updateStatement->execute([
                        $message,
                        $id
                    ]);
                }
            } else {
                $notice = 'Jadwal dokter tidak lagi aktif pada tanggal ini. Reminder tidak perlu dikirim.';
            }
        } catch (Throwable $e) {
            $notice = 'Gagal memvalidasi ulang jadwal terbaru: ' . $e->getMessage();
        }
    }
}

$displayDate = $date !== '' ? date('d-m-Y', strtotime($date)) : '-';
$statusClass = 'secondary';

if ($isLeave) {
    $statusClass = 'warning';
    $status = 'IJIN';
} elseif (!$hasActiveSchedule && $notice !== '') {
    $statusClass = 'secondary';
    $status = 'TIDAK AKTIF';
} elseif ($status === 'SENT') {
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
                Dokter Reminder RSU Islam Klaten
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
                    <?= preview_escape($doctorName) ?>
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

        <?php if ($notice !== ''): ?>
            <div class="alert <?= $isLeave ? 'alert-warning' : 'alert-secondary' ?>">
                <?= preview_escape($notice) ?>
            </div>
        <?php endif; ?>

        <?php if (!$isLeave && $hasActiveSchedule): ?>
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
        <?php endif; ?>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
