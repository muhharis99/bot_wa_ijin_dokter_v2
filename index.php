<?php

declare(strict_types=1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/functions.php';

$date = $_GET['tanggal'] ?? date('Y-m-d');

ensureReminders($date);

$pdo = db();
$pdoRsi = get_db('rsi_byl');

/*
|--------------------------------------------------------------------------
| Handle reminder action
|--------------------------------------------------------------------------
*/

if (isset($_GET['action'], $_GET['id'])) {
    $id = (int) $_GET['id'];
    $action = $_GET['action'];

    if (in_array($action, ['opened', 'sent', 'failed'], true)) {
        $status = strtoupper($action);

        if ($action === 'opened') {
            $sql = "
                UPDATE reminders
                SET status = ?, opened_at = ?
                WHERE id = ?
            ";

            $params = [
                $status,
                date('Y-m-d H:i:s'),
                $id
            ];
        } elseif ($action === 'sent') {
            $sql = "
                UPDATE reminders
                SET status = ?, sent_at = ?
                WHERE id = ?
            ";

            $params = [
                $status,
                date('Y-m-d H:i:s'),
                $id
            ];
        } else {
            $sql = "
                UPDATE reminders
                SET status = ?
                WHERE id = ?
            ";

            $params = [
                $status,
                $id
            ];
        }

        $pdo->prepare($sql)->execute($params);

        logAction($id, $status);
    }

    header(
        'Location: index.php?tanggal=' . urlencode($date)
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Get schedules
|--------------------------------------------------------------------------
*/

$rows = schedulesFor($date);

$byDoctor = [];

foreach ($rows as $row) {
    $doctorId = $row['doctor_id'];

    if (!isset($byDoctor[$doctorId])) {
        $byDoctor[$doctorId] = [
            'schedules' => [],
            'reminder'  => null
        ];
    }

    $byDoctor[$doctorId]['schedules'][] = $row;
}


/*
|--------------------------------------------------------------------------
| Get reminder for each doctor
|--------------------------------------------------------------------------
*/

$reminderStmt = $pdo->prepare("
    SELECT *
    FROM reminders
    WHERE doctor_id = ?
      AND tanggal = ?
      AND reminder_type = ?
    LIMIT 1
");

foreach ($byDoctor as $doctorId => &$doctorGroup) {
    $reminderStmt->execute([
        $doctorId,
        $date,
        'HARI_INI'
    ]);

    $doctorGroup['reminder'] = $reminderStmt->fetch() ?: null;
}

unset($doctorGroup);


/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$totalDoctors   = count($byDoctor);
$totalSchedules = count($rows);

$counts = [
    'SENT'   => 0,
    'FAILED' => 0,
    'READY'  => 0,
    'OPENED' => 0
];

foreach ($byDoctor as $group) {
    $status = $group['reminder']['status'] ?? 'READY';

    if (!isset($counts[$status])) {
        $counts[$status] = 0;
    }

    $counts[$status]++;
}


/*
|--------------------------------------------------------------------------
| Next schedule
|--------------------------------------------------------------------------
*/

$nextStmt = $pdoRsi->prepare("
    SELECT
        dj.*,
        mp.poli_nama AS lokasi,
        md.dokter_nama,
        mp.poli_nama,
        djk.tanggal

    FROM dokter_jadwal dj

    JOIN master_dokter md
        ON md.dokter_kd = dj.dokter_kd

    JOIN dokter_jadwal_kuota djk
        ON djk.dokter_jadwal_id = dj.id

    LEFT JOIN master_poli mp
        ON mp.poli_kd = dj.poli_kd

    WHERE djk.tanggal > ?
      AND djk.aktif = '1'

    ORDER BY
        djk.tanggal ASC,
        dj.jam_mulai ASC

    LIMIT 1
");

$nextStmt->execute([$date]);

$next = $nextStmt->fetch() ?: null;


/*
|--------------------------------------------------------------------------
| Helper values
|--------------------------------------------------------------------------
*/

$encodedDate = urlencode($date);

?>
<!doctype html>

<html lang="id">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title><?= e(APP_NAME) ?></title>

    <link
        rel="stylesheet"
        href="assets/style.css"
    >

</head>
<script>
document.addEventListener('DOMContentLoaded', function () {

    document.querySelectorAll('.js-whatsapp').forEach(function (button) {

        button.addEventListener('click', function (event) {

            event.preventDefault();

            const phone = this.dataset.phone;
            const message = this.dataset.message;

            if (!phone) {
                alert('Nomor WhatsApp tidak tersedia.');
                return;
            }

            const url =
                'https://web.whatsapp.com/send?phone=' +
                encodeURIComponent(phone) +
                '&text=' +
                encodeURIComponent(message);

            // Gunakan nama window yang sama
            window.open(url, 'whatsapp_window');

        });

    });

});
</script>

<body>


<header>

    <div>

        <span class="eyebrow">
            DAILY OPERATIONS
        </span>

        <h1>
            Reminder Jadwal Dokter
        </h1>

        <p>
            <?= e(indoDate($date)) ?>
        </p>

    </div>


    <nav>

        <a
            class="active"
            href="index.php"
        >
            Dashboard
        </a>

        <a href="master.php">
            Master Data
        </a>

        <a href="settings.php">
            Template
        </a>

    </nav>

</header>


<main>


    <!-- =========================================================
         HERO
    ========================================================== -->

    <section class="hero">

        <div>

            <span class="eyebrow">
                RINGKASAN HARI INI
            </span>

            <h2>
                Siapa yang praktik hari ini?
            </h2>

            <p>
                Pastikan setiap dokter menerima pengingat melalui WhatsApp.
            </p>

        </div>


        <form method="get">

            <input
                type="date"
                name="tanggal"
                value="<?= e($date) ?>"
            >

            <button
                class="button"
                type="submit"
            >
                Tampilkan
            </button>

        </form>

    </section>


    <!-- =========================================================
         STATISTICS
    ========================================================== -->

    <section class="stats">

        <div>

            <small>
                DOKTER PRAKTIK
            </small>

            <strong>
                <?= $totalDoctors ?>
            </strong>

        </div>


        <div>

            <small>
                JADWAL PRAKTIK
            </small>

            <strong>
                <?= $totalSchedules ?>
            </strong>

        </div>


        <div class="green">

            <small>
                SUDAH DIKIRIM
            </small>

            <strong>
                <?= $counts['SENT'] ?>
            </strong>

        </div>


        <div class="amber">

            <small>
                BELUM DIKIRIM
            </small>

            <strong>
                <?= ($counts['READY'] ?? 0) + ($counts['OPENED'] ?? 0) ?>
            </strong>

        </div>


        <div class="red">

            <small>
                GAGAL
            </small>

            <strong>
                <?= $counts['FAILED'] ?>
            </strong>

        </div>

    </section>


    <!-- =========================================================
         NEXT SCHEDULE
    ========================================================== -->

    <?php if ($next): ?>

        <div class="next">

            Jadwal berikutnya:

            <b>
                <?= e($next['dokter_nama']) ?>
            </b>

            ·

            <?= e($next['poli_nama']) ?>

            ·

            <?= e(indoDate($next['tanggal'])) ?>

            <?= e($next['jam_mulai']) ?>

        </div>

    <?php endif; ?>


    <!-- =========================================================
         SECTION TITLE
    ========================================================== -->

    <div class="section-title">

        <div>

            <span class="eyebrow">
                DAFTAR JADWAL
            </span>

            <h2>
                Jadwal dokter
            </h2>

        </div>


        <span class="muted">

            <?= $totalDoctors ?>
            dokter ·
            <?= $totalSchedules ?>
            sesi

        </span>

    </div>


    <!-- =========================================================
         DOCTOR GRID
    ========================================================== -->

    <section class="doctor-grid">


        <?php foreach ($byDoctor as $group): ?>

            <?php

            $schedules = $group['schedules'];
            $reminder  = $group['reminder'];

            if (empty($schedules)) {
                continue;
            }

            $doctor = $schedules[0];

            $status = $reminder['status'] ?? 'READY';

            $phoneRaw = $doctor['no_whatsapp'] ?? '';

            $phone = normalizePhone($phoneRaw);

            $message = $reminder['message'] ?? '';

            // $waUrl = '';

            // if ($phone !== '' && $message !== '') {

            //     $waUrl =
            //         'https://wa.me/' .
            //         $phone .
            //         '?text=' .
            //         rawurlencode($message);
            // }

            ?>


            <article class="card">


                <!-- CARD HEADER -->

                <div class="card-top">

                    <div>

                        <h3>
                            <?= e($doctor['nama_dokter']) ?>
                        </h3>

                        <p>

                            <?= e($doctor['spesialis'] ?? '') ?>

                            ·

                            <?= e($phoneRaw ?: '-') ?>

                        </p>

                    </div>


                    <span
                        class="status <?= e(strtolower($status)) ?>"
                    >
                        <?= e($status) ?>
                    </span>

                </div>


                <!-- SCHEDULE LIST -->

                <div class="schedule-list">


                    <?php foreach ($schedules as $schedule): ?>

                        <div>

                            <b>

                                <?= e($schedule['jam_mulai']) ?>

                                –

                                <?= e($schedule['jam_selesai']) ?>

                            </b>


                            <span>

                                <?= e($schedule['nama_poli']) ?>

                            </span>


                            <small>

                                <?= e($schedule['lokasi']) ?>

                            </small>

                        </div>

                    <?php endforeach; ?>


                </div>


                <!-- ACTIONS -->

                <?php if ($reminder): ?>

                    <div class="actions">


                        <!-- PREVIEW -->

                        <a
                            class="button outline"
                            target="_blank"
                            href="preview.php?id=<?= (int) $reminder['id'] ?>"
                        >
                            Preview
                        </a>


                        <!-- WHATSAPP -->

                      <?php
                            $phone = normalizePhone($doctor['no_whatsapp'] ?? '');
                            $message = $reminder['message'] ?? '';
                            ?>

                            <?php if ($phone !== '' && $message !== ''): ?>

                                <a
                                    href="#"
                                    class="button whatsapp js-whatsapp"
                                    data-phone="<?= e($phone) ?>"
                                    data-message="<?= e($message) ?>"
                                >
                                    WhatsApp
                                </a>

                            <?php else: ?>

                                <span class="muted">
                                    Nomor WhatsApp tidak tersedia
                                </span>

                            <?php endif; ?>


                        <!-- MARK SENT -->

                        <a
                            class="text-button"
                            href="index.php?tanggal=<?= $encodedDate ?>&action=sent&id=<?= (int) $reminder['id'] ?>"
                        >
                            Tandai terkirim
                        </a>


                    </div>

                <?php endif; ?>


            </article>


        <?php endforeach; ?>


    </section>


</main>


<footer>

    <?= e(APP_NAME) ?>

    · PHP Native + SQLite

</footer>


<script>

function markOpened(id) {

    setTimeout(function () {

        window.location.href =
            'index.php?tanggal=<?= $encodedDate ?>' +
            '&action=opened&id=' +
            encodeURIComponent(id);

    }, 500);

}

</script>


</body>

</html>