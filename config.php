<?php

$databases = [
    'local' => [
        'host' => '192.168.0.14',
        'user' => 'admin3dp',
        'pass' => '4dm1n3dp',
        'name' => 'dokter_reminder'
    ],
    'rsiklaten' => [
        'host' => '192.168.0.67',
        'user' => 'admin',
        'pass' => 'admin3dp',
        'name' => 'db_67'
    ],
    'rsi_byl' => [
        'host' => '192.168.0.14',
        'user' => 'admin3dp',
        'pass' => '4dm1n3dp',
        'name' => 'rsi_byl'
    ],
    'rme' => [
        'host' => '192.168.0.33',
        'user' => 'admin',
        'pass' => 'admin3dp',
        'name' => 'rme'
    ],
];

const APP_NAME = 'DokterReminder';

const DEFAULT_TEMPLATE = "Selamat pagi {{nama_dokter}}.\n\nMengingatkan bahwa hari ini Anda memiliki jadwal praktik:\n\n📅 {{tanggal}}\n🏥 {{nama_rs}}\n🩺 Poli: {{nama_poli}}\n🕐 Jam: {{jam_mulai}} - {{jam_selesai}}\n📍 Lokasi: {{lokasi}}\n\nTerima kasih.";

date_default_timezone_set('Asia/Jakarta');

$protocol = isset($_SERVER['HTTPS']) ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base_url = $protocol . '://' . $host . dirname($_SERVER['PHP_SELF']);
