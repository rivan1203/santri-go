<?php
require __DIR__ . '/config.php';
require_role('admin');

$pageTitle = 'Log Aktivitas Pengunjung';
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$isDate = static fn(string $v): bool => $v === '' || (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
if (!$isDate($from)) $from = '';
if (!$isDate($to)) $to = '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$where = [];
$params = [];
if ($from !== '') { $where[] = 'DATE(visited_at) >= ?'; $params[] = $from; }
if ($to !== '') { $where[] = 'DATE(visited_at) <= ?'; $params[] = $to; }
if ($q !== '') {
    $where[] = '(COALESCE(visitor_name, "") LIKE ? OR ip_address LIKE ? OR page_path LIKE ? OR login_status LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM visitor_logs' . $whereSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$listStmt = $pdo->prepare('SELECT id, visitor_name, login_status, page_path, ip_address, visited_at FROM visitor_logs' . $whereSql . ' ORDER BY visited_at DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
$listStmt->execute($params);
$logs = $listStmt->fetchAll();
$filteredVisitors = $pdo->prepare('SELECT COUNT(DISTINCT visitor_key) FROM visitor_logs' . $whereSql);
$filteredVisitors->execute($params);
$uniqueVisitors = (int)$filteredVisitors->fetchColumn();
$queryString = http_build_query(['from'=>$from, 'to'=>$to, 'q'=>$q]);
require __DIR__ . '/includes/header.php';
?>
<style>
.visitor-filter{display:grid;grid-template-columns:repeat(3,minmax(0,1fr)) auto;gap:12px;align-items:end}
.visitor-filter label{display:block;font-size:12px;font-weight:700;color:#52647b;margin-bottom:6px}
.visitor-filter input{width:100%;min-height:42px;padding:9px 11px;border:1px solid #dce5ef;border-radius:9px;font:inherit;color:#24364b;background:#fff}
.visitor-summary{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:16px 0;color:#63748a;font-size:13px}
.visitor-table{min-width:850px}
.visitor-table td{vertical-align:middle}
.visitor-table .muted{display:block;color:#78889b;font-size:11px;margin-top:4px}
.visitor-badge{display:inline-flex;padding:5px 9px;border-radius:30px;font-size:11px;font-weight:700}
.visitor-badge.login{background:#e8f5ed;color:#26734d}.visitor-badge.anonymous{background:#eef2f7;color:#5b6878}
.visitor-pagination{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding-top:16px}
.visitor-pagination a,.visitor-pagination span{padding:8px 12px;border:1px solid #dce5ef;border-radius:8px;text-decoration:none;color:#30445c;font-size:12px}
@media(max-width:760px){.visitor-filter{grid-template-columns:1fr 1fr}.visitor-filter .search-field{grid-column:1/-1}}
</style>
<section class="card">
  <div class="section-head"><div><h2>Riwayat akses website</h2><p>Informasi kunjungan yang tercatat oleh sistem. Hanya administrator yang dapat melihat halaman ini.</p></div></div>
  <form method="get" class="visitor-filter">
    <div><label for="from">Tanggal mulai</label><input id="from" type="date" name="from" value="<?= e($from) ?>"></div>
    <div><label for="to">Tanggal akhir</label><input id="to" type="date" name="to" value="<?= e($to) ?>"></div>
    <div class="search-field"><label for="q">Cari nama, IP, halaman, status</label><input id="q" type="search" name="q" value="<?= e($q) ?>" placeholder="Ketik kata kunci..."></div>
    <div style="display:flex;gap:7px"><button class="btn btn-primary" type="submit">Terapkan</button><a class="btn btn-light" href="<?= APP_BASE ?>/visitor_logs.php">Reset</a></div>
  </form>
  <div class="visitor-summary"><strong><?= number_format($totalRows) ?></strong> catatan ditemukan <span>·</span> <strong><?= number_format($uniqueVisitors) ?></strong> sesi pengunjung unik pada hasil filter</div>
  <div class="table-wrap">
    <table class="visitor-table">
      <thead><tr><th>Waktu kunjungan</th><th>Pengunjung</th><th>Status</th><th>Halaman dibuka</th><th>Alamat IP</th></tr></thead>
      <tbody>
      <?php foreach ($logs as $log): ?>
        <tr>
          <td><?= e(date('d/m/Y H:i:s', strtotime($log['visited_at']))) ?></td>
          <td><strong><?= e($log['visitor_name'] ?: 'Pengunjung anonim') ?></strong><?php if (!$log['visitor_name']): ?><small class="muted">Identitas asli tidak diketahui</small><?php endif; ?></td>
          <td><span class="visitor-badge <?= $log['login_status'] === 'login' ? 'login' : 'anonymous' ?>"><?= $log['login_status'] === 'login' ? 'Sudah login' : 'Anonim' ?></span></td>
          <td><?= e($log['page_path']) ?></td>
          <td><?= e($log['ip_address']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$logs): ?><tr><td colspan="5" class="empty">Tidak ada aktivitas yang cocok dengan filter.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="visitor-pagination"><span>Halaman <?= number_format($page) ?> dari <?= number_format($totalPages) ?></span><div style="display:flex;gap:7px">
    <?php if ($page > 1): ?><a href="?<?= e($queryString) ?>&page=<?= $page-1 ?>">← Sebelumnya</a><?php endif; ?>
    <?php if ($page < $totalPages): ?><a href="?<?= e($queryString) ?>&page=<?= $page+1 ?>">Berikutnya →</a><?php endif; ?>
  </div></div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
