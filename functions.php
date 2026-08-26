<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function indoDate(string $date): string
{
    $days = [
        'Minggu',
        'Senin',
        'Selasa',
        'Rabu',
        'Kamis',
        'Jumat',
        'Sabtu'
    ];

    $months = [
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
    $statement = db()->prepare(
        'SELECT `value` FROM settings WHERE `key` = ?'
    );

    $statement->execute([$key]);

    return (string) ($statement->fetchColumn() ?: $fallback);
}

function schedulesFor(string $date): array
{
    $statement = get_db('rsi_byl')->prepare("
        SELECT
            djk.hari,
            djk.tanggal,
            dj.jam_mulai,
            dj.jam_selesai,
            mp.poli_nama AS lokasi,
            dj.dokter_kd AS kode_dokter,
            md.dokter_nama AS nama_dokter,
            '' AS no_whatsapp,
            '' AS spesialis,
            mp.poli_nama AS nama_poli,
            md.dokter_kd AS doctor_id,
            IF(
                kd.no_hp = '' OR kd.no_hp IS NULL,
                '0',
                kd.no_hp
            ) AS no_whatsapp
        FROM dokter_jadwal_kuota djk
        LEFT JOIN dokter_jadwal dj
            ON dj.id = djk.dokter_jadwal_id
        LEFT JOIN master_dokter md
            ON md.dokter_kd = dj.dokter_kd
        LEFT JOIN master_poli mp
            ON mp.poli_kd = dj.poli_kd
        LEFT JOIN kontak_dokter kd
            ON kd.kd_dr = dj.dokter_kd
        WHERE djk.tanggal = ?
            AND djk.aktif = '1'
            AND kd.no_hp != '0'
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

    return $statement->fetchAll();
}

function createReminder(array $doctor, array $items, string $date): int
{
    $pdo = db();

    $doctorStatement = $pdo->prepare("
        SELECT
            dokter_kd AS id,
            dokter_kd AS doctor_id
        FROM rsi_byl.master_dokter
        WHERE dokter_kd = ?
    ");

    $doctorStatement->execute([
        $doctor['doctor_id']
    ]);

    $localDoctorId = $doctorStatement->fetchColumn();

    $existsStatement = $pdo->prepare("
        SELECT id
        FROM reminders
        WHERE tanggal = ?
            AND doctor_id = ?
            AND reminder_type = ?
    ");

    $existsStatement->execute([
        $date,
        $localDoctorId,
        'HARI_INI'
    ]);

    $existingId = $existsStatement->fetchColumn();

    if ($existingId) {
        return (int) $existingId;
    }

    $rows = '';

    foreach ($items as $item) {
        $rows .= "\n🕐 {$item['jam_mulai']} - {$item['jam_selesai']}\n";
        $rows .= "   Poli {$item['nama_poli']} — {$item['lokasi']}\n";
    }

    $variables = [
        '{{nama_dokter}}' => $doctor['nama_dokter'],
        '{{gelar}}' => '',
        '{{spesialis}}' => $doctor['spesialis'],
        '{{tanggal}}' => indoDate($date),
        '{{hari}}' => indoDate($date),
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
        setting('template', DEFAULT_TEMPLATE),
        $variables
    );

    if (count($items) > 1) {
        $message .= "\n\nJadwal lainnya hari ini:" . $rows;
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

function ensureReminders(string $date): void
{
    $groups = [];

    foreach (schedulesFor($date) as $row) {
        $groups[$row['doctor_id']][] = $row;
    }

    foreach ($groups as $items) {
        createReminder(
            $items[0],
            $items,
            $date
        );
    }
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
