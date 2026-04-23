<?php
declare(strict_types=1);

$pin = preg_replace('/\D/', '', (string) ($_GET['pin'] ?? ''));
if (strlen($pin) !== 6) {
    http_response_code(400);
    exit('bad pin');
}

header('Location: https://api.qrserver.com/v1/create-qr-code/?size=400x400&margin=10&data=' . urlencode($pin), true, 302);
