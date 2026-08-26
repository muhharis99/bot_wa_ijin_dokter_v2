<?php

declare(strict_types=1);
require_once __DIR__ . '/db.php';

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function indoDate(string $date): string
{
    $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $t = strtotime($date);
    return $days[(int)date('w', $t)] . ', ' . date('j', $t) . ' ' . $months[(int)date('n', $t) - 1] . ' ' . date('Y', $t);
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
    $s = db()->prepare('SELECT `value` FROM settings WHERE `key` = ?');
    $s->execute([$key]);
    return (string)($s->fetchColumn() ?: $fallback);
}

function reminderType(): string
{
    return 'H_MINUS_1';
}

function defaultReminderTargetDate(): string
{
    return date('Y-m-d', strtotime('+1 day'));
}

function reminderTemplate(): string
{
    $template = setting('template', DEFAULT_TEMPLATE);

    if (stripos($template, 'hari ini Anda memiliki jadwal praktik') !== false) {
        return DEFAULT_TEMPLATE;
    }

    return $template;
}

function schedulesFor(string $date): array
{
    $s = get_db('rsi_byl')->prepare("
        SELECT 
            djk.hari, 
            djk.tanggal, 
            dj.jam_mulai, 
            dj.jam_selesai, 
            mp.poli_nama as lokasi,
            dj.dokter_kd as kode_dokter,
            md.dokter_nama as nama_dokter, 
            '' as no_whatsapp, 
            '' as spesialis,
            mp.poli_nama as nama_poli,
            md.dokter_kd as doctor_id,
            if(kd.no_hp ='' or kd.no_hp is null,'0', kd.no_hp) as no_whatsapp
        FROM dokter_jadwal_kuota djk 
        LEFT JOIN dokter_jadwal dj ON dj.id = djk.dokter_jadwal_id 
        LEFT JOIN master_dokter md ON md.dokter_kd = dj.dokter_kd 
        LEFT JOIN master_poli mp ON mp.poli_kd = dj.poli_kd
        LEFT JOIN kontak_dokter kd ON kd.kd_dr = dj.dokter_kd
        WHERE djk.tanggal=? 
          AND djk.aktif='1' 
          AND kd.no_hp !='0' 
          AND djk.kuota_all>2
          AND dj.poli_kd NOT IN ('EEG','PDP','ODC','P084','P085','P086','GCU')
        GROUP BY dj.dokter_kd,dj.poli_kd
        ORDER BY dj.jam_mulai
    ");
    $s->execute([$date]);
    return $s->fetchAll();
}

function createReminder(array $doctor, array $items, string $date): int
{
    $pdo = db();
    $qDoctorId = $pdo->prepare('SELECT dokter_kd FROM rsi_byl.master_dokter WHERE dokter_kd = ?');
    $qDoctorId->execute([$doctor['doctor_id']]);
    $localDoctorId = $qDoctorId->fetchColumn();

    $exists = $pdo->prepare('SELECT id FROM reminders WHERE tanggal=? AND doctor_id=? AND reminder_type=?');
    $exists->execute([$date, $localDoctorId, reminderType()]);

    if ($id = $exists->fetchColumn()) {
        return (int)$id;
    }

    $rows = '';
    foreach ($items as $item) {
        $rows .= "\n🕐 {$item['jam_mulai']} - {$item['jam_selesai']}\n   Poli {$item['nama_poli']} — {$item['lokasi']}\n";
    }

    $vars = [
        '{{nama_dokter}}' => $doctor['nama_dokter'],
        '{{gelar}}' => '',
        '{{spesialis}}' => $doctor['spesialis'],
        '{{tanggal}}' => indoDate($date),
        '{{hari}}' => indoDate($date),
        '{{nama_poli}}' => $items[0]['nama_poli'],
        '{{jam_mulai}}' => $items[0]['jam_mulai'],
        '{{jam_selesai}}' => $items[0]['jam_selesai'],
        '{{lokasi}}' => $items[0]['lokasi'],
        '{{nama_rs}}' => setting('nama_rs', 'RS Sehat Sentosa')
    ];

    $message = strtr(reminderTemplate(), $vars);

    if (count($items) > 1) {
        $message .= "\n\nJadwal lainnya besok:" . $rows;
    }

    $q = $pdo->prepare('INSERT INTO reminders(doctor_id,tanggal,reminder_type,message,status,created_at) VALUES(?,?,?,?,?,?)');
    $q->execute([$localDoctorId, $date, reminderType(), $message, 'READY', date('Y-m-d H:i:s')]);

    return (int)$pdo->lastInsertId();
}

function ensureReminders(string $date): void
{
    $groups = [];
    foreach (schedulesFor($date) as $row) {
        $groups[$row['doctor_id']][] = $row;
    }
    foreach ($groups as $items) {
        createReminder($items[0], $items, $date);
    }
}

function logAction(int $reminderId, string $action): void
{
    $q = db()->prepare('INSERT INTO reminder_logs(reminder_id,action,created_at) VALUES(?,?,?)');
    $q->execute([$reminderId, $action, date('Y-m-d H:i:s')]);
}
