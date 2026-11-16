<?php

declare(strict_types=1);
date_default_timezone_set('Asia/Jakarta');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// Penguncian server-side: status ini tetap tersimpan meskipun halaman di-refresh.
$isLockEndpoint = basename($_SERVER['SCRIPT_NAME'] ?? '') === 'session_lock.php';
if (!$isLockEndpoint && !empty($_SESSION['user'])) {
    $now = time();
    $lastActivity = (int)($_SESSION['last_activity'] ?? $now);
    if (!empty($_SESSION['session_locked']) || ($now - $lastActivity) >= 300) {
        $_SESSION['session_locked'] = true;
        require __DIR__ . '/session_locked_screen.php';
        exit;
    }
    $_SESSION['last_activity'] = $now;
}


const DB_HOST = '127.0.0.1';
const DB_NAME = 'santri_go';
const DB_USER = 'root';
const DB_PASS = '';

const APP_NAME = 'Mambaul Huda';
const APP_BASE = '/santri-go';
const APP_URL = '';


try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
    // Samakan zona waktu sesi MySQL dengan WIB (Asia/Jakarta), agar NOW(),
    // CURDATE(), dan pencatatan waktu statistik menggunakan jam Indonesia.
    $pdo->exec("SET time_zone = '+07:00'");
} catch (PDOException $e) {
    http_response_code(500);
    exit('Koneksi database gagal. Periksa pengaturan config.php dan pastikan database sudah diimpor.');
}


// Catat setiap permintaan halaman PHP. IP berasal dari REMOTE_ADDR (bukan header
// proxy yang dapat dipalsukan); pengunjung anonim tetap anonim.
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS visitor_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            visitor_key CHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            visitor_name VARCHAR(150) NULL,
            login_status ENUM('login','anonymous') NOT NULL DEFAULT 'anonymous',
            page_path VARCHAR(500) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            user_agent VARCHAR(500) NULL,
            visited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_visitor_visited (visited_at),
            INDEX idx_visitor_key (visitor_key),
            INDEX idx_visitor_user (user_id),
            CONSTRAINT fk_visitor_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $currentUser = $_SESSION['user'] ?? null;
    $sessionKey = hash('sha256', session_id());
    $pagePath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $pagePath = substr($pagePath, 0, 500);
    $remoteIp = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: '0.0.0.0';
    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $log = $pdo->prepare("
        INSERT INTO visitor_logs
            (visitor_key, user_id, visitor_name, login_status, page_path, ip_address, user_agent, visited_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $log->execute([
        $sessionKey,
        $currentUser ? (int)$currentUser['id'] : null,
        $currentUser ? (string)$currentUser['full_name'] : null,
        $currentUser ? 'login' : 'anonymous',
        $pagePath,
        $remoteIp,
        $userAgent
    ]);
} catch (Throwable $e) {
    // Pencatatan pengunjung tidak boleh membuat website utama gagal dibuka.
}


function app_setting(string $key, string $default = ''): string
{
    static $settings = null;
    global $pdo;
    if ($settings === null) {
        $settings = [];
        try {
            $rows = $pdo->query('SELECT setting_key, setting_value FROM app_settings')->fetchAll();
            foreach ($rows as $row) $settings[$row['setting_key']] = (string)$row['setting_value'];
        } catch (Throwable $e) {
            // Settings table is created when an administrator opens Pengaturan Pesantren.
        }
    }
    return (string)($settings[$key] ?? $default);
}
function app_school_name(): string
{
    return app_setting('school_name', APP_NAME) ?: APP_NAME;
}
function app_school_logo_url(): string
{
    $logo = app_setting('school_logo', '');
    if ($logo === '' || basename($logo) !== $logo) return '';
    $path = __DIR__ . '/uploads/' . $logo;
    return is_file($path) ? APP_BASE . '/uploads/' . rawurlencode($logo) : '';
}
function app_school_mark(): string
{
    $name = trim(app_school_name());
    $words = preg_split('/\s+/', $name) ?: [];
    $mark = '';
    foreach (array_slice($words, 0, 2) as $word) $mark .= strtoupper(substr($word, 0, 1));
    return $mark !== '' ? $mark : 'PP';
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function rupiah($amount): string
{
    return 'Rp ' . number_format((float)$amount, 0, ',', '.');
}
function redirect(string $path): never
{
    header('Location: ' . APP_BASE . '/' . ltrim($path, '/'));
    exit;
}
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_check(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419);
        exit('Sesi formulir kedaluwarsa. Silakan kembali dan coba lagi.');
    }
}
function require_login(): void
{
    if (empty($_SESSION['user'])) redirect('login.php');
}
function require_role(string $role): void
{
    require_login();
    if (($_SESSION['user']['role'] ?? '') !== $role) {
        http_response_code(403);
        exit('Akses tidak diizinkan.');
    }
}
function current_student_id(PDO $pdo): int
{
    $q = $pdo->prepare('SELECT id FROM students WHERE user_id = ? AND active = 1');
    $q->execute([$_SESSION['user']['id']]);
    $id = $q->fetchColumn();
    if (!$id) {
        http_response_code(403);
        exit('Data santri belum terhubung ke akun.');
    }
    return (int)$id;
}
function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}
function month_label(string $date): string
{
    $months = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $ts = strtotime($date);
    return $months[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}
