<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function indoDate(string $date): string
{
    static $days = [
        'Minggu',
        'Senin',
        'Selasa',
        'Rabu',
        'Kamis',
        'Jumat',
        'Sabtu'
    ];

    static $months = [
        'Januari',
        'Februari',
        'Maret',
        'April',
        'Mei',
        'Juni',
        'Juli',
        'Agustus',
        'September',
        'Oktober',
        'November',
        'Desember'
    ];

    $timestamp = strtotime($date);

    return $days[(int) date('w', $timestamp)] . ', ' .
        date('j', $timestamp) . ' ' .
        $months[(int) date('n', $timestamp) - 1] . ' ' .
        date('Y', $timestamp);
}

function normalizePhone(string $phone): string
{
    $phone = preg_replace('/\D+/', '', $phone);

    if (strpos($phone, '0') === 0) {
        $phone = '62' . substr($phone, 1);
    }

    return $phone;
}

function setting(string $key, string $fallback = ''): string
{
    static $cache = [];

    if (array_key_exists($key, $cache)) {
        return $cache[$key] !== '' ? $cache[$key] : $fallback;
    }

    $statement = db()->prepare(
        'SELECT `value` FROM settings WHERE `key` = ? LIMIT 1'
    );

    $statement->execute([$key]);
    $value = (string) ($statement->fetchColumn() ?: '');
    $cache[$key] = $value;

    return $value !== '' ? $value : $fallback;
}

function reminderTemplate(): string
{
    static $template = null;

    if ($template !== null) {
        return $template;
    }

    $template = setting('template', DEFAULT_TEMPLATE);

    $patterns = [
        '/^[ \t]*Mohon hadir sesuai jadwal praktik[,.]?[ \t]*\r?\n?/mi',
        '/^[ \t]*Jika ada perubahan jam,\s*silahkan ketik\s*"Ubah \[Nama Poli\]: \[jam_baru\]"[ \t]*\r?\n?/mi',
        '/^[ \t]*Jika ada perubahan jam,\s*silakan ketik\s*"Ubah \[Nama Poli\]: \[jam_baru\]"[ \t]*\r?\n?/mi'
    ];

    $template = preg_replace($patterns, '', $template);
    $template = preg_replace("/\n{3,}/", "\n\n", $template);
    $template = trim($template);

    return $template;
}

function schedulesFor(string $date): array
{
    static $cache = [];

    if (isset($cache[$date])) {
        return $cache[$date];
    }

    $statement = get_db('rsi_byl')->prepare("
        SELECT
            djk.hari,
            djk.tanggal,
            dj.jam_mulai,
            dj.jam_selesai,
            mp.poli_nama AS lokasi,
            dj.dokter_kd AS kode_dokter,
            md.dokter_nama AS nama_dokter,
            '' AS spesialis,
            mp.poli_nama AS nama_poli,
            md.dokter_kd AS doctor_id,
            IF(
                kd.no_hp = '' OR kd.no_hp IS NULL,
                '0',
                kd.no_hp
            ) AS no_whatsapp
        FROM dokter_jadwal_kuota djk
        INNER JOIN dokter_jadwal dj
            ON dj.id = djk.dokter_jadwal_id
        INNER JOIN master_dokter md
            ON md.dokter_kd = dj.dokter_kd
        LEFT JOIN master_poli mp
            ON mp.poli_kd = dj.poli_kd
        INNER JOIN kontak_dokter kd
            ON kd.kd_dr = dj.dokter_kd
            AND kd.no_hp != '0'
        WHERE djk.tanggal = ?
            AND djk.aktif = '1'
            AND djk.kuota_all > 2
            AND dj.poli_kd NOT IN (
                'EEG',
                'PDP',
                'ODC',
                'P084',
                'P085',
                'P086',
                'GCU'
            )
        GROUP BY
            dj.dokter_kd,
            dj.poli_kd
        ORDER BY dj.jam_mulai
    ");

    $statement->execute([$date]);
    $cache[$date] = $statement->fetchAll();

    return $cache[$date];
}

function buildReminderMessage(array $doctor, array $items, string $date): string
{
    $rows = '';

    if (count($items) > 1) {
        foreach ($items as $item) {
            $rows .= "\n🕐 {$item['jam_mulai']} - {$item['jam_selesai']}\n";
            $rows .= "   Poli {$item['nama_poli']} — {$item['lokasi']}\n";
        }
    }

    $formattedDate = indoDate($date);

    $variables = [
        '{{nama_dokter}}' => $doctor['nama_dokter'],
        '{{gelar}}' => '',
        '{{spesialis}}' => $doctor['spesialis'],
        '{{tanggal}}' => $formattedDate,
        '{{hari}}' => $formattedDate,
        '{{nama_poli}}' => $items[0]['nama_poli'],
        '{{jam_mulai}}' => $items[0]['jam_mulai'],
        '{{jam_selesai}}' => $items[0]['jam_selesai'],
        '{{lokasi}}' => $items[0]['lokasi'],
        '{{nama_rs}}' => setting(
            'nama_rs',
            'RS Sehat Sentosa'
        )
    ];

    $message = strtr(
        reminderTemplate(),
        $variables
    );

    if ($rows !== '') {
        $message .= "\n\nJadwal lainnya hari ini:" . $rows;
    }

    return $message;
}

function createReminder(array $doctor, array $items, string $date): int
{
    $pdo = db();
    $localDoctorId = (string) $doctor['doctor_id'];
    $message = buildReminderMessage($doctor, $items, $date);

    $existsStatement = $pdo->prepare("
        SELECT
            id,
            status,
            message
        FROM reminders
        WHERE tanggal = ?
            AND doctor_id = ?
            AND reminder_type = ?
        LIMIT 1
    ");

    $existsStatement->execute([
        $date,
        $localDoctorId,
        'HARI_INI'
    ]);

    $existingReminder = $existsStatement->fetch();

    if ($existingReminder) {
        if (
            $existingReminder['status'] !== 'SENT' &&
            $existingReminder['message'] !== $message
        ) {
            $updateStatement = $pdo->prepare("
                UPDATE reminders
                SET message = ?
                WHERE id = ?
            ");

            $updateStatement->execute([
                $message,
                $existingReminder['id']
            ]);
        }

        return (int) $existingReminder['id'];
    }

    $insertStatement = $pdo->prepare("
        INSERT INTO reminders (
            doctor_id,
            tanggal,
            reminder_type,
            message,
            status,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?)
    ");

    $insertStatement->execute([
        $localDoctorId,
        $date,
        'HARI_INI',
        $message,
        'READY',
        date('Y-m-d H:i:s')
    ]);

    return (int) $pdo->lastInsertId();
}

function ensureReminders(string $date, ?array $scheduleRows = null): array
{
    $rows = $scheduleRows ?? schedulesFor($date);
    $groups = [];

    foreach ($rows as $row) {
        $groups[$row['doctor_id']][] = $row;
    }

    foreach ($groups as $items) {
        createReminder(
            $items[0],
            $items,
            $date
        );
    }

    return $rows;
}

function logAction(int $reminderId, string $action): void
{
    $statement = db()->prepare("
        INSERT INTO reminder_logs (
            reminder_id,
            action,
            created_at
        ) VALUES (?, ?, ?)
    ");

    $statement->execute([
        $reminderId,
        $action,
        date('Y-m-d H:i:s')
    ]);
}
