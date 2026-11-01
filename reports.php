<?php
// Halaman laporan digabung ke payments.php agar hanya ada satu halaman transaksi.
require __DIR__ . '/config.php';
require_role('admin');
$query = $_SERVER['QUERY_STRING'] ?? '';
$target = APP_BASE . '/payments.php' . ($query !== '' ? '?' . $query : '');
header('Location: ' . $target, true, 302);
exit;
