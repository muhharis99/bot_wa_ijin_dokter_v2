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

function indoDay(string $date): string
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

    return $days[(int) date('w', strtotime($date))];
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

function sanitizeReminderMessage(string $message): string
{
    $patterns = [
        '/^[ \t]*Mohon hadir sesuai jadwal praktik[,.]?[ \t]*\r?\n?/mi',
        '/^[ \t]*Jika ada perubahan jam,\s*silahkan ketik\s*["“”]?Ubah\s*\[Nama Poli\]\s*:\s*\[jam_baru\]["“”]?[,.]?[ \t]*\r?\n?/mi',
        '/^[ \t]*Jika ada perubahan jam,\s*silakan ketik\s*["“”]?Ubah\s*\[Nama Poli\]\s*:\s*\[jam_baru\]["“”]?[,.]?[ \t]*\r?\n?/mi'
    ];

    $message = preg_replace($patterns, '', $message);
    $message = preg_replace("/\r\n|\r/", "\n", $message);
    $message = preg_replace("/\n{3,}/", "\n\n", $message);

    return trim($message);
}

function reminderTemplate(): string
{
    static $template = null;

    if ($template !== null) {
        return $template;
    }

    $template = sanitizeReminderMessage(
        setting('template', DEFAULT_TEMPLATE)
    );

    return $template;
}

function doctorLeaveCodes(string $date): array
{
    static $cache = [];

    if (isset($cache[$date])) {
        return $cache[$date];
    }

    try {
        $statement = get_db('rme')->prepare("
            SELECT DISTINCT dokter_id
            FROM surat_ijin
            WHERE praktek LIKE ?
                AND dokter_id IS NOT NULL
                AND dokter_id != ''
                AND deleted IS NULL
        ");

        $statement->execute(['%' . $date . '%']);
        $codes = [];

        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $doctorCode) {
            $doctorCode = trim((string) $doctorCode);

            if ($doctorCode !== '') {
                $codes[$doctorCode] = true;
            }
        }

        $cache[$date] = $codes;
    } catch (Throwable $e) {
        error_log(
            'Gagal membaca rme.surat_ijin untuk tanggal ' . $date . ': ' . $e->getMessage()
        );
        $cache[$date] = [];
    }

    return $cache[$date];
}

function normalizePracticeTime(?string $time): string
{
    $time = trim((string) $time);

    if ($time === '') {
        return '';
    }

    $timestamp = strtotime($time);

    if ($timestamp === false) {
        return $time;
    }

    return date('H:i:s', $timestamp);
}

function movedPracticeSchedules(string $date): array
{
    static $cache = [];

    if (isset($cache[$date])) {
        return $cache[$date];
    }

    $result = [];

    try {
        $statement = get_db('rme')->prepare("
            SELECT
                praktek.jam1,
                praktek.jam2,
                praktek.cjam1,
                praktek.cjam2,
                praktek.ctanggal,
                dokter_spesialis.dokter_id,
                dokter.nama2 AS nama
            FROM pendaftaran.praktek AS praktek
            INNER JOIN pendaftaran.dokter_spesialis AS dokter_spesialis
                ON praktek.dokter_spesialis_id = dokter_spesialis.id
            INNER JOIN rsiklaten.dokter AS dokter
                ON dokter_spesialis.dokter_id = dokter.no_dr
            WHERE praktek.ctanggal LIKE ?
        ");

        $statement->execute(['%' . $date . '%']);

        foreach ($statement->fetchAll() as $row) {
            $doctorCode = trim((string) ($row['dokter_id'] ?? ''));
            $newStart = normalizePracticeTime($row['cjam1'] ?? '');
            $newEnd = normalizePracticeTime($row['cjam2'] ?? '');

            if ($doctorCode === '') {
                continue;
            }

            $result[$doctorCode][] = [
                'nama' => (string) ($row['nama'] ?? ''),
                'jam1' => normalizePracticeTime($row['jam1'] ?? ''),
                'jam2' => normalizePracticeTime($row['jam2'] ?? ''),
                'cjam1' => $newStart,
                'cjam2' => $newEnd,
                'tanggal' => $date
            ];
        }
    } catch (Throwable $e) {
        error_log(
            'Gagal membaca pendaftaran.praktek untuk tanggal ' . $date . ': ' . $e->getMessage()
        );
    }

    $cache[$date] = $result;

    return $cache[$date];
}

function applyMovedPracticeSchedules(array $rows, string $date): array
{
    $movedSchedules = movedPracticeSchedules($date);

    if (!$movedSchedules || !$rows) {
        return $rows;
    }

    $doctorScheduleCount = [];

    foreach ($rows as $row) {
        $doctorCode = trim((string) ($row['kode_dokter'] ?? ''));

        if ($doctorCode !== '') {
            $doctorScheduleCount[$doctorCode] = ($doctorScheduleCount[$doctorCode] ?? 0) + 1;
        }
    }

    foreach ($rows as &$row) {
        $doctorCode = trim((string) ($row['kode_dokter'] ?? ''));

        if ($doctorCode === '' || empty($movedSchedules[$doctorCode])) {
            continue;
        }

        $currentStart = normalizePracticeTime($row['jam_mulai'] ?? '');
        $currentEnd = normalizePracticeTime($row['jam_selesai'] ?? '');
        $match = null;

        foreach ($movedSchedules[$doctorCode] as $candidate) {
            $oldStartMatches = $candidate['jam1'] === '' || $candidate['jam1'] === $currentStart;
            $oldEndMatches = $candidate['jam2'] === '' || $candidate['jam2'] === $currentEnd;

            if ($oldStartMatches && $oldEndMatches) {
                $match = $candidate;
                break;
            }
        }

        if ($match === null &&
            ($doctorScheduleCount[$doctorCode] ?? 0) === 1 &&
            count($movedSchedules[$doctorCode]) === 1) {
            $match = $movedSchedules[$doctorCode][0];
        }

        if ($match === null) {
            continue;
        }

        $row['jam_mulai_asli'] = (string) ($row['jam_mulai'] ?? '');
        $row['jam_selesai_asli'] = (string) ($row['jam_selesai'] ?? '');

        if ($match['cjam1'] !== '') {
            $row['jam_mulai'] = $match['cjam1'];
        }

        if ($match['cjam2'] !== '') {
            $row['jam_selesai'] = $match['cjam2'];
        }

        $row['jam_pindah'] = true;
    }

    unset($row);

    usort(
        $rows,
        static function (array $a, array $b): int {
            $keysA = [
                (string) ($a['jam_mulai'] ?? ''),
                (string) ($a['jam_selesai'] ?? ''),
                (string) ($a['poli_kd'] ?? ''),
                (string) ($a['jadwal_id'] ?? '')
            ];

            $keysB = [
                (string) ($b['jam_mulai'] ?? ''),
                (string) ($b['jam_selesai'] ?? ''),
                (string) ($b['poli_kd'] ?? ''),
                (string) ($b['jadwal_id'] ?? '')
            ];

            return $keysA <=> $keysB;
        }
    );

    return $rows;
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
            dj.id AS jadwal_id,
            dj.jam_mulai,
            dj.jam_selesai,
            dj.poli_kd,
            mp.poli_nama AS lokasi,
            dj.dokter_kd AS kode_dokter,
            md.dokter_nama AS nama_dokter,
            mp.poli_nama AS spesialis,
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
        ORDER BY
            dj.jam_mulai,
            dj.jam_selesai,
            dj.poli_kd,
            dj.id
    ");

    $statement->execute([$date]);
    $rows = $statement->fetchAll();
    $leaveCodes = doctorLeaveCodes($date);

    if ($leaveCodes) {
        $rows = array_values(
            array_filter(
                $rows,
                static function (array $row) use ($leaveCodes): bool {
                    $doctorCode = trim((string) ($row['kode_dokter'] ?? ''));

                    return $doctorCode === '' || !isset($leaveCodes[$doctorCode]);
                }
            )
        );
    }

    $rows = applyMovedPracticeSchedules($rows, $date);
    $cache[$date] = $rows;

    return $cache[$date];
}

function indenCount(string $date, string $doctorCode, array $items): int
{
    static $cache = [];
    $jadwalIds = [];

    foreach ($items as $item) {
        $jadwalId = (int) ($item['jadwal_id'] ?? 0);

        if ($jadwalId > 0) {
            $jadwalIds[$jadwalId] = $jadwalId;
        }
    }

    $jadwalIds = array_values($jadwalIds);
    sort($jadwalIds);

    if (!$jadwalIds) {
        return 0;
    }

    $cacheKey = $date . '|' . implode(',', $jadwalIds);

    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    try {
        $placeholders = implode(',', array_fill(0, count($jadwalIds), '?'));
        $params = $jadwalIds;
        $params[] = $date . ' 00:00:00';
        $params[] = date('Y-m-d', strtotime($date . ' +1 day')) . ' 00:00:00';

        $statement = get_db('rsi_byl')->prepare("
            SELECT COUNT(*)
            FROM inden_kunjung
            WHERE jadwal_id IN ({$placeholders})
                AND tgl_masuk >= ?
                AND tgl_masuk < ?
                AND (deleted IS NULL OR deleted = 0 OR deleted = '')
        ");

        $statement->execute($params);

        $cache[$cacheKey] = (int) $statement->fetchColumn();
    } catch (Throwable $e) {
        error_log('Gagal menghitung inden_kunjung: ' . $e->getMessage());
        $cache[$cacheKey] = 0;
    }

    return $cache[$cacheKey];
}

function patientCount(string $date, string $doctorCode, array $items): int
{
    static $cache = [];

    $doctorCode = trim($doctorCode);
    $poliCodes = [];

    foreach ($items as $item) {
        $poliCode = trim((string) ($item['poli_kd'] ?? ''));

        if ($poliCode !== '') {
            $poliCodes[$poliCode] = $poliCode;
        }
    }

    $poliCodes = array_values($poliCodes);
    sort($poliCodes);

    if ($date === '' || $doctorCode === '' || !$poliCodes) {
        return 0;
    }

    $cacheKey = $date . '|' . $doctorCode . '|' . implode(',', $poliCodes);

    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    try {
        $placeholders = implode(',', array_fill(0, count($poliCodes), '?'));
        $params = [
            $date,
            $doctorCode
        ];

        foreach ($poliCodes as $poliCode) {
            $params[] = $poliCode;
        }

        $statement = get_db('rme')->prepare("
            SELECT COUNT(DISTINCT no_reg)
            FROM rsiklaten.kunjung
            WHERE tanggal = ?
                AND kd_dr = ?
                AND kd_poli IN ({$placeholders})
                AND no_reg IS NOT NULL
                AND no_reg != ''
                AND deleted IS NULL
        ");

        $statement->execute($params);

        $cache[$cacheKey] = (int) $statement->fetchColumn();
    } catch (Throwable $e) {
        error_log('Gagal menghitung rsiklaten.kunjung: ' . $e->getMessage());
        $cache[$cacheKey] = 0;
    }

    return $cache[$cacheKey];
}

function buildReminderMessage(array $doctor, array $items, string $date): string
{
    $additionalItems = array_slice($items, 1);
    $rows = '';

    foreach ($additionalItems as $item) {
        $rows .= "\n🕐 {$item['jam_mulai']} - {$item['jam_selesai']}\n";
        $rows .= "   Poli {$item['nama_poli']} — {$item['lokasi']}\n";
    }

    $formattedDate = indoDate($date);
    $dayName = trim((string) ($doctor['hari'] ?? ''));

    if ($dayName === '') {
        $dayName = indoDay($date);
    }

    $doctorCode = (string) ($doctor['doctor_id'] ?? '');
    $isPracticeDay = $date === date('Y-m-d');
    $jumlahPasien = $isPracticeDay
        ? patientCount($date, $doctorCode, $items)
        : 0;
    $inden = indenCount($date, $doctorCode, $items);

    $variables = [
        '{{nama_dokter}}' => (string) ($doctor['nama_dokter'] ?? ''),
        '{{gelar}}' => '',
        '{{spesialis}}' => (string) ($doctor['spesialis'] ?? $items[0]['nama_poli'] ?? ''),
        '{{tanggal}}' => $formattedDate,
        '{{hari}}' => $dayName,
        '{{nama_poli}}' => (string) ($items[0]['nama_poli'] ?? ''),
        '{{jam_mulai}}' => (string) ($items[0]['jam_mulai'] ?? ''),
        '{{jam_selesai}}' => (string) ($items[0]['jam_selesai'] ?? ''),
        '{{lokasi}}' => (string) ($items[0]['lokasi'] ?? $items[0]['nama_poli'] ?? ''),
        '{{jumlah_pasien}}' => (string) $jumlahPasien,
        '{{inden}}' => (string) $inden,
        '{{nama_rs}}' => setting(
            'nama_rs',
            'RS Sehat Sentosa'
        )
    ];

    $template = reminderTemplate();
    $message = strtr($template, $variables);

    if ($isPracticeDay) {
        if (strpos($template, '{{jumlah_pasien}}') === false) {
            $patientBlock = "Jumlah Pasien : {$jumlahPasien}\nJumlah Inden Pasien : {$inden}\n\nApakah ada pembatasan untuk kuota pasien nggih dokter?\n\n";

            if (strpos($message, 'Terima kasih.') !== false) {
                $message = preg_replace(
                    '/Terima kasih\./',
                    $patientBlock . 'Terima kasih.',
                    $message,
                    1
                );
            } else {
                $message .= "\n\n" . trim($patientBlock);
            }
        }
    } else {
        $message = preg_replace(
            '/^[ \t]*Jumlah Pasien\s*:\s*.*(?:\r?\n)?/mi',
            '',
            $message
        );

        if (strpos($template, '{{inden}}') === false) {
            $indenBlock = "Jumlah Inden Pasien : {$inden}\n\nApakah ada pembatasan untuk kuota pasien nggih dokter?\n\n";

            if (strpos($message, 'Terima kasih.') !== false) {
                $message = preg_replace(
                    '/Terima kasih\./',
                    $indenBlock . 'Terima kasih.',
                    $message,
                    1
                );
            } else {
                $message .= "\n\n" . trim($indenBlock);
            }
        }
    }

    if ($rows !== '') {
        $message .= "\n\nJadwal lainnya :\n" . ltrim($rows, "\n");
    }

    return sanitizeReminderMessage($message);
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
        if ($existingReminder['message'] !== $message) {
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
