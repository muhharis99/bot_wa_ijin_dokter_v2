<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/functions.php';

$date = defaultReminderTargetDate();
ensureReminders($date);

echo "Reminder H-1 siap untuk jadwal {$date}\n";
