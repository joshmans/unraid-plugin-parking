<?php
/* JSON endpoint for the Parking tabs. Every request is a POST: Unraid checks csrf_token on POSTs
 * (local_prepend.php runs before this file), so nothing can be changed by a plain link. */
require_once __DIR__ . '/lib.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

@set_time_limit(600);
try {
    echo json_encode(pp_api((string)($_POST['action'] ?? ''), $_POST));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
