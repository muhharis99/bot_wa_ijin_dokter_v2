<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/chat_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    chatJson([
        'success' => false,
        'message' => 'Method tidak diizinkan.'
    ], 405);
}

if (!chatIncomingRequestAllowed()) {
    chatJson([
        'success' => false,
        'message' => 'Akses webhook ditolak.'
    ], 403);
}

$payload = json_decode((string) file_get_contents('php://input'), true);

if (!is_array($payload)) {
    chatJson([
        'success' => false,
        'message' => 'Payload tidak valid.'
    ], 422);
}

$messageId = trim((string) ($payload['message_id'] ?? ''));
$phone = chatNormalizePhone((string) ($payload['phone'] ?? ''));
$messageType = trim((string) ($payload['message_type'] ?? 'text'));
$message = trim((string) ($payload['message'] ?? ''));
$receivedAt = trim((string) ($payload['received_at'] ?? ''));

if ($messageId === '' || $phone === '') {
    chatJson([
        'success' => false,
        'message' => 'message_id dan phone wajib diisi.'
    ], 422);
}

if ($message === '') {
    $message = '[' . strtoupper($messageType !== '' ? $messageType : 'MESSAGE') . ']';
}

if ($messageType === '') {
    $messageType = 'text';
}

$doctor = chatDoctorByPhone($phone);
$doctorId = $doctor['doctor_id'] ?? null;

if ($receivedAt !== '') {
    $timestamp = strtotime($receivedAt);
    $receivedAt = $timestamp !== false
        ? date('Y-m-d H:i:s', $timestamp)
        : date('Y-m-d H:i:s');
} else {
    $receivedAt = date('Y-m-d H:i:s');
}

try {
    $statement = db()->prepare("
        INSERT IGNORE INTO whatsapp_messages (
            message_id,
            doctor_id,
            phone,
            direction,
            message_type,
            message,
            status,
            received_at,
            created_at
        ) VALUES (?, ?, ?, 'IN', ?, ?, 'RECEIVED', ?, ?)
    ");

    $statement->execute([
        $messageId,
        $doctorId,
        $phone,
        $messageType,
        $message,
        $receivedAt,
        date('Y-m-d H:i:s')
    ]);

    chatJson([
        'success' => true,
        'saved' => $statement->rowCount() > 0,
        'doctor_id' => $doctorId
    ]);
} catch (Throwable $e) {
    error_log('Gagal menyimpan chat WhatsApp masuk: ' . $e->getMessage());

    chatJson([
        'success' => false,
        'message' => 'Pesan masuk gagal disimpan.'
    ], 500);
}
