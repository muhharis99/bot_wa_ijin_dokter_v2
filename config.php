<?php

/**
 * FLEXIBLE MULTI-DATABASE SERVER CONFIGURATION
 * 
 * Template untuk menambah database server baru:
 * 'alias_name' => [
 *     'host' => 'ip_or_hostname',
 *     'port' => 3306,              // Opsional, default 3306
 *     'user' => 'username',
 *     'pass' => 'password',
 *     'name' => 'database_name'
 * ]
 */

$databases = [
    // LOCAL DATABASE
    'local' => [
        'host' => '192.168.0.14',
        // 'port' => 3306,
        'user' => 'admin3dp',
        'pass' => '4dm1n3dp',
        'name' => 'dokter_reminder'
    ],
    
    
    // EXTERNAL DATABASE - DB_67 (rsiklaten)
    'rsiklaten' => [
        'host' => '192.168.0.67',
        // 'port' => 3306,
        'user' => 'admin',
        'pass' => 'admin3dp',
        'name' => 'db_67'
    ],
    // RSI BYL DATABASE
    'rsi_byl' => [
        'host' => '192.168.0.14',
        // 'port' => 3306,
        'user' => 'admin3dp',
        'pass' => '4dm1n3dp',
        'name' => 'rsi_byl'
    ],
    
    // EXAMPLE SERVER 1 - Remote Server
    // 'server1' => [
    //     'host' => '192.168.1.100',      // Ganti dengan IP server sesungguhnya
    //     'port' => 3306,
    //     'user' => 'user_server1',
    //     'pass' => 'password_server1',
    //     'name' => 'database_server1'
    // ],
    
    // EXAMPLE SERVER 2 - Another Remote Server
    // 'server2' => [
    //     'host' => '192.168.1.101',      // Ganti dengan IP server sesungguhnya
    //     'port' => 3307,                 // Custom port jika diperlukan
    //     'user' => 'user_server2',
    //     'pass' => 'password_server2',
    //     'name' => 'database_server2'
    // ]
    
    // TAMBAHKAN DATABASE SERVER LAIN DI BAWAH INI
    // Contoh format di atas
];

// Legacy database configuration (for backward compatibility)
const APP_NAME = 'DokterReminder';
// const DB_HOST = '127.0.0.1';
// const DB_PORT = '3306';
// const DB_NAME = 'dokter_reminder';
// const DB_USER = 'root';
// const DB_PASS = '';
const DEFAULT_TEMPLATE = "Selamat pagi {{nama_dokter}}.\n\nMengingatkan bahwa hari ini Anda memiliki jadwal praktik:\n\n📅 {{tanggal}}\n🏥 {{nama_rs}}\n🩺 Poli: {{nama_poli}}\n🕐 Jam: {{jam_mulai}} - {{jam_selesai}}\n📍 Lokasi: {{lokasi}}\n\nMohon hadir sesuai jadwal praktik.\n\nTerima kasih.";
date_default_timezone_set('Asia/Jakarta');

// This is used for getting base URL
$protocol = isset($_SERVER['HTTPS']) ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base_url = $protocol . '://' . $host . dirname($_SERVER['PHP_SELF']);
