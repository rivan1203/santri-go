<?php
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
function lock_json(bool $success, string $message = ''): never {
    echo json_encode(['success'=>$success, 'message'=>$message], JSON_UNESCAPED_UNICODE);
    exit;
}
if (empty($_SESSION['user'])) lock_json(false, 'Sesi login sudah berakhir. Silakan login kembali.');
$action = (string)($_GET['action'] ?? '');
if ($action === 'activity') {
    if (empty($_SESSION['session_locked'])) $_SESSION['last_activity'] = time();
    lock_json(true);
}
if ($action === 'lock') {
    $_SESSION['session_locked'] = true;
    lock_json(true);
}
if ($action === 'unlock') {
    if (empty($_SESSION['session_locked'])) lock_json(true);
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) lock_json(false, 'Permintaan tidak valid. Silakan muat ulang halaman.');
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$_SESSION['user']['id']]);
    $hash = $stmt->fetchColumn();
    if (!$hash || !password_verify((string)($_POST['password'] ?? ''), (string)$hash)) lock_json(false, 'Password tidak sesuai. Silakan coba lagi.');
    $_SESSION['session_locked'] = false;
    $_SESSION['last_activity'] = time();
    lock_json(true);
}
http_response_code(400);
lock_json(false, 'Permintaan tidak dikenali.');
