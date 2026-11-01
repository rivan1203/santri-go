<?php
require __DIR__ . '/config.php';
require_role('admin');
$pageTitle = 'Pembayaran & Laporan';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($action === 'cash_add') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $month = (string)($_POST['billing_month'] ?? '');
        if (preg_match('/^\d{4}-\d{2}$/', $month)) $month .= '-01';
        $type = (string)($_POST['payment_type'] ?? 'spp');
        $q = $pdo->prepare('SELECT s.id,s.class_level,fc.id category_id,fc.amount,fc.spp_amount FROM students s JOIN fee_categories fc ON fc.name=s.class_level AND fc.active=1 WHERE s.id=? AND s.active=1');
        $q->execute([$studentId]);
        $st = $q->fetch();
        $dt = DateTime::createFromFormat('!Y-m-d', $month);
        if (!$st || !$dt || $dt->format('Y-m-d') !== $month || $month > date('Y-m-01') || !in_array($type, ['spp', 'jenjang', 'combined'], true)) {
            flash('Data pembayaran tunai tidak valid.', 'error');
            redirect('payments.php');
        }
        $amtSpp = in_array($type, ['spp', 'combined'], true) ? (float)$st['spp_amount'] : 0;
        $amtJenjang = in_array($type, ['jenjang', 'combined'], true) ? (float)$st['amount'] : 0;
        $total = $amtSpp + $amtJenjang;
        $check = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE student_id=? AND billing_month=? AND status IN ('paid','pending') AND (payment_type=? OR payment_type='combined' OR ?='combined')");
        $check->execute([$studentId, $month, $type, $type]);
        if ($total <= 0 || (int)$check->fetchColumn() > 0) {
            flash('Nominal belum diatur atau tagihan periode tersebut sudah diproses.', 'error');
            redirect('payments.php');
        }
        $order = 'CASH-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
        $q = $pdo->prepare("INSERT INTO payments(order_id,student_id,fee_category_id,billing_month,payment_type,category_amount,spp_amount,total_amount,method,status,notes,verified_by,verified_at) VALUES(?,?,?,?,?,?,?,?, 'cash','paid',?,?,NOW())");
        $q->execute([$order, $studentId, $st['category_id'], $month, $type, $amtJenjang, $amtSpp, $total, trim($_POST['notes'] ?? 'Pembayaran tunai diterima admin'), $_SESSION['user']['id']]);
        flash('Pembayaran tunai berhasil dicatat dan langsung berstatus lunas.');
        redirect('payments.php');
    }
    if (in_array($action, ['approve', 'reject'], true)) {
        $rejectReason = trim((string)($_POST['notes'] ?? ''));
        if ($action === 'reject' && $rejectReason === '') { flash('Isi alasan penolakan sebelum menolak pembayaran.', 'error'); redirect('payments.php'); }
        $status = $action === 'approve' ? 'paid' : 'rejected';
        $q = $pdo->prepare("SELECT * FROM payments WHERE id=? AND method IN ('cash','bank_transfer') AND status='pending'");
        $q->execute([$id]);
        $p = $q->fetch();
        if (!$p) {
            flash('Transaksi tidak ditemukan atau sudah diproses.', 'error');
            redirect('payments.php');
        }
        if ($status === 'paid') {
            // Tolak hanya jika tagihan yang sama sudah lunas. SPP Pondok dan biaya jenjang
            // pada bulan yang sama adalah dua tagihan berbeda dan boleh diverifikasi terpisah.
            $q = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE student_id=? AND billing_month=? AND status='paid' AND (payment_type=? OR payment_type='combined' OR ?='combined')");
            $q->execute([$p['student_id'], $p['billing_month'], $p['payment_type'], $p['payment_type']]);
            if ((int)$q->fetchColumn() > 0) {
                flash('Jenis tagihan ini sudah lunas melalui transaksi lain. Periksa riwayat transaksi sebelum menyetujui.', 'error');
                redirect('payments.php');
            }
        }
        $q = $pdo->prepare('UPDATE payments SET status=?,verified_by=?,verified_at=NOW(),notes=? WHERE id=? AND status=\'pending\'');
        $verifierId = (int)($_SESSION['user']['id'] ?? 0);
        if ($verifierId <= 0) { flash('Sesi pengguna tidak valid. Silakan masuk kembali.', 'error'); redirect('login.php'); }
        $q->execute([$status, $verifierId, trim($_POST['notes'] ?? ''), $id]);
        flash($status === 'paid' ? 'Pembayaran berhasil diverifikasi lunas.' : 'Pembayaran ditolak.', 'success');
    }
    redirect('payments.php');
}
$statusFilter = (string)($_GET['status'] ?? 'all');
if (!in_array($statusFilter, ['all','pending','paid','rejected','failed','expire'], true)) $statusFilter = 'all';
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$validDate = static function ($value) { $d = DateTime::createFromFormat('!Y-m-d', (string)$value); return $d && $d->format('Y-m-d') === $value; };
if (($from !== '' && !$validDate($from)) || ($to !== '' && !$validDate($to)) || ($from !== '' && $to !== '' && $from > $to)) { $from = ''; $to = ''; }
$whereParts = []; $params = [];
if ($from !== '') { $whereParts[] = 'DATE(p.created_at) >= ?'; $params[] = $from; }
if ($to !== '') { $whereParts[] = 'DATE(p.created_at) <= ?'; $params[] = $to; }
if ($statusFilter !== 'all') { $whereParts[] = 'p.status = ?'; $params[] = $statusFilter; }
$where = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';
// Semua transaksi ditampilkan tanpa filter tanggal otomatis; pagination membatasi tampilan per halaman.
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM payments p $where");
$countStmt->execute($params); $totalRows = (int)$countStmt->fetchColumn();
$perPage = (int)($_GET['per_page'] ?? 10); if (!in_array($perPage, [10, 50, 100], true)) $perPage = 10;
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = max(1, (int)($_GET['page'] ?? 1)); $page = min($page, $totalPages); $offset = ($page - 1) * $perPage;
$sql = "SELECT p.*,u.full_name,s.student_no,fc.name category,vu.full_name AS verifier_name FROM payments p LEFT JOIN students s ON s.id=p.student_id LEFT JOIN users u ON u.id=s.user_id LEFT JOIN fee_categories fc ON fc.id=p.fee_category_id LEFT JOIN users vu ON vu.id=p.verified_by $where ORDER BY p.created_at DESC, p.id DESC LIMIT $perPage OFFSET $offset";
$q = $pdo->prepare($sql); $q->execute($params); $rows = $q->fetchAll();
$cashStudents = $pdo->query("SELECT s.id,s.student_no,s.class_level,u.full_name FROM students s JOIN users u ON u.id=s.user_id WHERE s.active=1 ORDER BY u.full_name")->fetchAll();
$cashLevels = $pdo->query('SELECT id,name,amount,spp_amount FROM fee_categories WHERE active=1 ORDER BY name')->fetchAll();
require __DIR__ . '/includes/header.php';
?>
<?php
$periodLabel = ($from || $to) ? (($from ? date('d/m/Y', strtotime($from)) : 'Awal') . ' — ' . ($to ? date('d/m/Y', strtotime($to)) : 'Sekarang')) : 'Semua tanggal';
$paidCount = 0; $paidAmount = 0; $totalAmount = 0;
foreach ($rows as $statRow) { $totalAmount += (float)$statRow['total_amount']; if ($statRow['status'] === 'paid') { $paidCount++; $paidAmount += (float)$statRow['total_amount']; } }
?>
<div class="report-print-head"><div class="report-brand"><div class="report-logo"><?php if (app_school_logo_url()): ?><img src="<?= e(app_school_logo_url()) ?>" alt="Logo <?= e(app_school_name()) ?>"><?php else: ?><?= e(app_school_mark()) ?><?php endif; ?></div><div><h2><?= e(strtoupper(app_school_name())) ?></h2><p>Administrasi Pembayaran Santri</p></div></div><div class="report-period"><small>PERIODE TRANSAKSI</small><strong><?= e($periodLabel) ?></strong><span><?= e($statusFilter === 'all' ? 'Semua status' : ucfirst($statusFilter)) ?></span></div></div>
<div class="report-stats"><div class="report-stat"><small>JUMLAH TRANSAKSI</small><strong><?= $totalRows ?></strong><span>Seluruh hasil filter</span></div><div class="report-stat"><small>NOMINAL DI HALAMAN</small><strong><?= rupiah($totalAmount) ?></strong><span>Transaksi yang ditampilkan</span></div><div class="report-stat"><small>TRANSAKSI LUNAS</small><strong><?= $paidCount ?></strong><span>Di halaman ini</span></div><div class="report-stat"><small>NOMINAL LUNAS</small><strong><?= rupiah($paidAmount) ?></strong><span>Di halaman ini</span></div></div>
<section class="card report-table-card">
    <div class="section-head">
        <div>
            <h2>Daftar Transaksi Pembayaran</h2>
            <p>Menampilkan <?= $totalRows ? $offset + 1 : 0 ?>–<?= min($offset + count($rows), $totalRows) ?> dari <?= $totalRows ?> transaksi · Terbaru lebih dahulu</p>
        </div><div class="payment-head-actions"><a class="btn btn-light" href="<?= APP_BASE ?>/export_excel.php?<?= e(http_build_query(['from'=>$from,'to'=>$to,'status'=>$statusFilter])) ?>">▦ Unduh Excel</a><a class="btn btn-light" target="_blank" rel="noopener" href="<?= APP_BASE ?>/export_pdf.php?<?= e(http_build_query(['from'=>$from,'to'=>$to,'status'=>$statusFilter])) ?>">↓ Cetak PDF</a><button type="button" class="btn" onclick="openCashModal()">＋ Input pembayaran</button></div>
    </div>
    <form method="get" id="paymentFilter" class="report-filter" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-bottom:16px">
        <div class="form-group"><label>Dari tanggal</label><input class="input" type="date" name="from" value="<?= e($from) ?>"></div>
        <div class="form-group"><label>Sampai tanggal</label><input class="input" type="date" name="to" value="<?= e($to) ?>"></div>
        <div class="form-group"><label>Status</label><select name="status" id="paymentStatus">
                <option value="all">Semua status</option><?php foreach (['pending' => 'Menunggu verifikasi', 'paid' => 'Lunas', 'rejected' => 'Ditolak', 'failed' => 'Gagal', 'expire' => 'Kedaluwarsa'] as $k => $v): ?><option value="<?= e($k) ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
            </select></div>
    </form>
    <div class="table-wrap">
        <table class="report-table payment-report-table">
            <thead>
                <tr>
                    <th>Santri / Order</th>
                    <th>Periode</th>
                    <th>Metode</th>
                    <th>Total</th>
                    <th>Bukti</th>
                    <th>Status</th>
                    <th>Verifikasi</th>
                    <th>Kwitansi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?><tr>
                        <td data-label="Santri / Order"><strong><?= e($r['full_name']) ?></strong>
                            <div class="help"><?= e($r['student_no']) ?> · <?= e($r['order_id']) ?></div>
                        </td>
                        <td data-label="Periode"><?= e(month_label($r['billing_month'])) ?><div class="help"><?= e($r['category']) ?></div><?php if (!empty($r['bank_destination'])): ?><div class="help">Rekening: <?= e($r['bank_destination']) ?></div><?php endif; ?></td>
                        <td data-label="Metode"><?= e(['cash' => 'Tiket tunai', 'bank_transfer' => 'Transfer bank'][$r['method']] ?? $r['method']) ?></td>
                        <td data-label="Total"><strong><?= rupiah($r['total_amount']) ?></strong></td>
                        <td data-label="Bukti"><?php if (!empty($r['proof_path'])): $proofUrl = preg_match('~^https?://~i', (string)$r['proof_path']) ? (string)$r['proof_path'] : APP_BASE . '/' . ltrim((string)$r['proof_path'], '/'); ?><button class="btn btn-light btn-small js-proof-open" type="button" data-proof-url="<?= e($proofUrl) ?>">Lihat bukti</button><?php else: ?><span class="help">Tidak ada</span><?php endif; ?></td>
                        <td data-label="Status"><span class="badge <?= e($r['status'] === 'paid' ? 'paid' : ($r['status'] === 'pending' ? 'pending' : $r['status'])) ?>"><?= e(['paid' => 'Success', 'rejected' => 'Ditolak', 'pending' => 'Menunggu verifikasi', 'failed' => 'Gagal', 'expire' => 'Kedaluwarsa'][$r['status']] ?? ucfirst($r['status'])) ?></span><?php if ($r['status'] === 'rejected' && !empty($r['notes'])): ?><br/><div class="payment-rejection-note"><?= e($r['notes']) ?></div><?php endif; ?></td>
                        <td data-label="Verifikasi"><?php if ($r['status'] === 'pending'): ?><form method="post" style="display:flex;gap:5px;align-items:center"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input class="input" name="notes" placeholder="Alasan jika ditolak" aria-label="Catatan atau alasan penolakan" style="width:150px;padding:7px"><button class="btn btn-small" name="action" value="approve">Verifikasi</button><button class="btn btn-danger btn-small" name="action" value="reject">Tolak</button></form><div class="help">Menunggu verifikasi</div><?php else: ?><strong><?= e(($r['verifier_name'] ?? '') ?: ((int)($r['verified_by'] ?? 0) > 0 ? 'Admin #' . (int)$r['verified_by'] : ($r['status'] === 'paid' ? 'Terverifikasi' : 'Diproses'))) ?></strong><?php if (!empty($r['verified_at'])): ?><div class="help"><?= e(date('d/m/Y H:i', strtotime($r['verified_at']))) ?></div><?php endif; ?><?php endif; ?></td>
                        <td data-label="Kwitansi"><a class="btn btn-light btn-small" target="_blank" href="<?= APP_BASE ?>/receipt.php?id=<?= (int)$r['id'] ?>">Cetak kwitansi</a></td>
                    </tr><?php endforeach; ?>
                <?php if (!$rows): ?><tr>
                        <td colspan="8" class="empty">Belum ada transaksi pada filter ini.</td>
                    </tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<div class="report-pagination no-print">
 <div class="page-size">Tampilkan <select id="paymentPerPage" aria-label="Jumlah transaksi per halaman"><option value="10" <?= $perPage===10?'selected':'' ?>>10</option><option value="50" <?= $perPage===50?'selected':'' ?>>50</option><option value="100" <?= $perPage===100?'selected':'' ?>>100</option></select> transaksi · <?= $totalRows ?> total</div>
 <div class="page-nav"><span>Halaman <?= $page ?> dari <?= $totalPages ?></span><?php $pageBase=['from'=>$from,'to'=>$to,'status'=>$statusFilter,'per_page'=>$perPage]; ?><a class="btn btn-light btn-small <?= $page<=1?'disabled':'' ?>" style="<?= $page<=1?'pointer-events:none;opacity:.45;':'' ?>" href="?<?= e(http_build_query($pageBase+['page'=>max(1,$page-1)])) ?>">‹ Sebelumnya</a><a class="btn btn-light btn-small <?= $page>=$totalPages?'disabled':'' ?>" style="<?= $page>=$totalPages?'pointer-events:none;opacity:.45;':'' ?>" href="?<?= e(http_build_query($pageBase+['page'=>min($totalPages,$page+1)])) ?>">Selanjutnya ›</a></div>
</div>
<div class="proof-modal-backdrop" id="adminProofModal" aria-hidden="true">
  <section class="proof-modal" role="dialog" aria-modal="true" aria-labelledby="adminProofTitle">
    <div class="proof-modal-head"><h2 id="adminProofTitle">Bukti pembayaran</h2><button type="button" class="proof-close" id="adminProofClose" aria-label="Tutup">×</button></div>
    <div class="proof-preview" id="adminProofPreview"><p>Pilih bukti untuk melihat pratinjau.</p></div>
    <div class="proof-modal-actions"><a class="btn" id="adminProofDownload" href="#" download>Unduh bukti</a><button class="btn btn-light" type="button" id="adminProofCloseBottom">Tutup</button></div>
  </section>
</div>
<script>
(function(){
 const modal=document.getElementById('adminProofModal');
 const preview=document.getElementById('adminProofPreview');
 const download=document.getElementById('adminProofDownload');
 function close(){modal.classList.remove('is-open');modal.setAttribute('aria-hidden','true');document.body.classList.remove('modal-open');preview.replaceChildren();}
 function open(url){
   if(!url){preview.textContent='Alamat bukti pembayaran tidak tersedia.';modal.classList.add('is-open');modal.setAttribute('aria-hidden','false');return;}
   preview.replaceChildren();
   const clean=url.split('?')[0].split('#')[0];
   const ext=(clean.split('.').pop()||'').toLowerCase();
   if(['jpg','jpeg','png','webp','gif','bmp'].includes(ext)){
     const img=document.createElement('img');img.src=url;img.alt='Bukti pembayaran';img.onerror=()=>{preview.replaceChildren();const msg=document.createElement('p');msg.textContent='Bukti tidak dapat dimuat. Periksa apakah file masih tersedia dan alamat file benar.';const a=document.createElement('a');a.href=url;a.target='_blank';a.rel='noopener';a.textContent='Coba buka bukti di tab baru';preview.append(msg,a);};preview.appendChild(img);
   }else if(ext==='pdf'){
     const frame=document.createElement('iframe');frame.src=url;frame.title='Bukti pembayaran PDF';frame.setAttribute('loading','eager');preview.appendChild(frame);
   }else{
     const msg=document.createElement('p');msg.textContent='Pratinjau format ini tidak tersedia.';const a=document.createElement('a');a.href=url;a.target='_blank';a.rel='noopener';a.textContent='Buka bukti di tab baru';preview.append(msg,a);
   }
   download.href=url;download.setAttribute('download',clean.split('/').pop()||'bukti-pembayaran');
   modal.classList.add('is-open');modal.setAttribute('aria-hidden','false');document.body.classList.add('modal-open');
 }
 document.addEventListener('click',function(e){const btn=e.target.closest('.js-proof-open');if(btn){e.preventDefault();open(btn.getAttribute('data-proof-url'));}});
 document.getElementById('adminProofClose').addEventListener('click',close);document.getElementById('adminProofCloseBottom').addEventListener('click',close);
 modal.addEventListener('click',e=>{if(e.target===modal)close();});document.addEventListener('keydown',e=>{if(e.key==='Escape')close();});
})();
</script>
<div class="premium-modal-backdrop" id="cashModal" role="presentation" aria-hidden="true">
    <section class="premium-modal" role="dialog" aria-modal="true" aria-labelledby="cashTitle"><button class="premium-modal-close" type="button" onclick="closeCashModal()">×</button>
        <div class="premium-modal-kicker">KASIR ADMIN</div>
        <h2 id="cashTitle">Input pembayaran tunai</h2>
        <p>Transaksi yang dicatat di sini langsung tersimpan sebagai lunas.</p>
        <form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="cash_add">
            <div class="form-group full admin-student-search"><label for="cashStudentSearch">Nama santri</label><input class="input" id="cashStudentSearch" type="text" autocomplete="off" placeholder="Ketik nama atau nomor induk santri..." aria-autocomplete="list" aria-controls="cashStudentSuggestions" aria-expanded="false" required><input type="hidden" name="student_id" id="cashStudentId" required><div id="cashStudentSuggestions" class="admin-student-suggestions" role="listbox" hidden></div><small class="help" id="cashStudentHint">Pilih nama santri dari daftar pencarian.</small></div>
            <div class="form-group"><label>Bulan tagihan</label><input class="input" type="month" name="billing_month" value="<?= date('Y-m') ?>" max="<?= date('Y-m') ?>" required></div>
            <div class="form-group"><label>Jenis pembayaran</label><select name="payment_type" id="cashPaymentType" required>
                    <option value="spp">SPP Pondok</option>
                    <option value="jenjang">SPP (pilih santri)</option>
                    <option value="combined">SPP Pondok + (pilih santri)</option>
                </select></div>
            <div class="form-group full"><label>Catatan (opsional)</label><input class="input" name="notes" placeholder="Contoh: diterima oleh bendahara"></div>
            <div class="form-group full"><button class="btn" type="submit">Simpan pembayaran tunai</button></div>
        </form>
    </section>
</div>
<style>
.report-export-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.btn-pdf{display:inline-flex;align-items:center;gap:11px;background:linear-gradient(135deg,#2f6fc4,#204b8b)!important;color:#fff!important;border:1px solid #245da8!important;font-weight:800;padding:10px 16px;border-radius:12px;box-shadow:0 7px 18px rgba(36,93,168,.2);text-decoration:none;transition:transform .15s ease,box-shadow .15s ease}.btn-pdf:hover{background:linear-gradient(135deg,#285fa8,#183d73)!important;color:#fff!important;transform:translateY(-1px);box-shadow:0 10px 22px rgba(36,93,168,.26)}.btn-pdf strong,.btn-pdf small{display:block;text-align:left}.btn-pdf strong{font-size:12px;line-height:1.3}.btn-pdf small{font-size:10px;font-weight:500;opacity:.82;margin-top:2px}.pdf-icon{width:34px;height:34px;border-radius:9px;background:rgba(255,255,255,.17);display:grid;place-items:center;font-size:22px;line-height:1}.report-filter{grid-template-columns:repeat(3,minmax(0,1fr));align-items:end}.report-filter .form-group{min-width:0}.report-filter input,.report-filter select{width:100%;min-height:42px;border-radius:10px}.report-bank-tag{display:inline-flex;align-items:center;margin-top:5px;padding:4px 9px;border-radius:7px;background:#eaf2ff;border:1px solid #c9dcfb;color:#315f9f;font-size:9px;font-weight:800;letter-spacing:.4px}.report-filter{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:13px}.report-action .btn{width:100%}.report-print-head{display:flex;justify-content:space-between;align-items:center;gap:20px;background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px;margin:18px 0}.report-brand{display:flex;align-items:center;gap:13px}.report-logo{width:48px;height:48px;border-radius:14px;background:linear-gradient(135deg,#8bb6f0,#4c79bd);color:white;display:grid;place-items:center;font:800 16px Manrope;overflow:hidden}.report-logo img{width:100%;height:100%;object-fit:contain;background:#fff;padding:2px;border-radius:inherit}.report-brand h2{font:800 16px Manrope;margin:0}.report-brand p{margin:5px 0 0;color:var(--muted);font-size:11px}.report-period{text-align:right}.report-period small,.report-stat small{display:block;font-size:9px;letter-spacing:1px;color:#7e8e83;font-weight:800}.report-period strong{display:block;font:800 13px Manrope;margin:7px 0}.report-period span{font-size:10px;color:#365f9b;font-weight:700}.report-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:13px;margin-bottom:18px}.report-stat{background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px;min-width:0}.report-stat strong{display:block;font:800 20px Manrope;margin:11px 0 6px;overflow-wrap:anywhere}.report-stat span{font-size:10px;color:var(--muted)}.report-table-card{padding:20px}.report-table{white-space:normal;min-width:850px}.report-table th{font-size:9px;padding:12px 10px}.report-table td{font-size:10px;padding:12px 10px}.table-sub{display:block;color:#87958c;font-size:9px;margin-top:4px;overflow-wrap:anywhere}.report-status{display:inline-block;padding:5px 8px;border-radius:20px;font-size:9px;font-weight:800;background:#edf1ee;color:#607067}.status-paid{background:#edf4ff;color:#365f9b}.status-pending{background:#fff5db;color:#8b6500}.status-rejected,.status-failed,.status-expire{background:#fff0f0;color:#b33b3b}.amount-cell{font-weight:800;white-space:nowrap}.report-table tfoot td{background:#edf4ff;font-weight:800;color:#365f9b}.report-signature{display:none;justify-content:space-between;margin:32px 35px 0;text-align:center;gap:60px}.report-signature>div{width:230px;display:flex;flex-direction:column;align-items:center;gap:5px}.report-signature span{font-size:10px}.report-signature strong{font-size:11px}.signature-space{height:52px;width:100%}.report-signature b{font-size:10px;font-weight:600}
.report-pagination{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin:14px 0 22px;padding:14px 18px;background:#fff;border:1px solid var(--line);border-radius:12px}.page-size,.page-nav{display:flex;align-items:center;gap:9px;font-size:12px;color:var(--muted)}.page-size select{padding:8px 12px;border:1px solid var(--line);border-radius:8px;background:#fff;color:var(--ink);font-weight:700}.page-btn{display:inline-flex;align-items:center;padding:8px 12px;border:1px solid var(--line);border-radius:8px;color:#244d7d;text-decoration:none;font-weight:700;background:#fff}.page-btn.disabled{opacity:.4;pointer-events:none}.report-filter{grid-template-columns:repeat(3,minmax(0,1fr))}.report-filter .form-group:last-child{grid-column:auto}@media(max-width:850px){.report-filter{grid-template-columns:repeat(2,minmax(0,1fr))}.report-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.report-print-head{align-items:flex-start;flex-direction:column}.report-period{text-align:left}}
@media print{@page{size:A4 landscape;margin:10mm}body{background:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;font-size:9px!important}.sidebar,.topbar,.footer,.no-print{display:none!important}.app-shell,.main-area,.content{display:block!important;margin:0!important;padding:0!important;max-width:none!important;background:#fff!important}.report-print-head{border:0;border-bottom:2px solid #527bb8;border-radius:0;padding:0 0 12px;margin:0 0 12px}.report-logo{width:38px;height:38px}.report-brand h2{font-size:13px}.report-stats{grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-bottom:12px}.report-stat{padding:10px;border-radius:7px}.report-stat strong{font-size:14px;margin:6px 0 3px}.report-stat small{font-size:7px}.report-stat span{font-size:8px}.report-table-card{padding:0;border:0;box-shadow:none}.report-table-card .section-head{margin-bottom:8px}.report-table-card .section-head h2{font-size:12px}.report-table{min-width:0;width:100%;table-layout:auto}.report-table th{font-size:7px;padding:7px 5px}.report-table td{font-size:7.5px;padding:7px 5px}.table-sub{font-size:7px}.report-status{font-size:7px;padding:3px 5px}.report-signature{display:flex}.report-period strong{font-size:11px}.report-period small{font-size:7px}.report-period span{font-size:8px}.report-table tr{break-inside:avoid}}
\n.payment-head-actions{display:flex;gap:9px;align-items:center;flex-wrap:wrap}.btn-pdf{background:linear-gradient(135deg,#2f6fc4,#204b8b)!important;color:#fff!important;border:1px solid #245da8!important;font-weight:800;box-shadow:0 6px 16px rgba(36,93,168,.18)}.btn-pdf:hover{background:linear-gradient(135deg,#285fa8,#183d73)!important;color:#fff!important}.admin-student-search{position:relative}.admin-student-search .input{width:100%;min-height:44px;border-radius:10px}.admin-student-suggestions{position:absolute;z-index:100;top:calc(100% - 22px);left:0;right:0;max-height:240px;overflow:auto;background:#fff;border:1px solid #dce6f5;border-radius:12px;box-shadow:0 14px 35px rgba(31,62,105,.16);padding:5px}.admin-student-option{display:flex;width:100%;flex-direction:column;gap:4px;text-align:left;border:0;background:#fff;border-radius:8px;padding:10px 12px;cursor:pointer;color:#203a5f}.admin-student-option:hover{background:#edf4ff}.admin-student-option strong{font-size:13px}.admin-student-option small{font-size:11px;color:#6b7f9d}
.payment-report-table{min-width:1100px}.payment-report-table td form{min-width:250px}.payment-report-table .btn{white-space:nowrap}.payment-report-table .help{font-size:11px;color:#718078;margin-top:4px}.payment-report-table .amount-cell{font-weight:800;color:#174b36}\n
/* Penyelarasan modal kasir admin */
#cashModal .premium-modal{text-align:left}
#cashModal .premium-modal-kicker{color:var(--green);text-align:left}
#cashModal #cashTitle,#cashModal>section>p{text-align:left}
#cashModal .form-grid,#cashModal .form-group{ text-align:left }
#cashModal .form-group label{display:block;width:100%;text-align:left;margin:0}
#cashModal .form-group .help{text-align:left}
#cashModal .form-group button{width:100%}


/* Tampilan responsif halaman pembayaran untuk ponsel */
@media (max-width:640px){
.main-area,.content{min-width:0!important;width:100%}.content{padding:12px!important}
.report-print-head{padding:15px!important;margin:10px 0 12px!important;gap:12px!important;border-radius:13px!important}
.report-brand{min-width:0;gap:10px}.report-logo{width:40px;height:40px;flex:0 0 40px}.report-brand h2{font-size:12px!important;overflow-wrap:anywhere}.report-brand p{font-size:10px!important}
.report-period{width:100%;text-align:left!important;padding-top:9px;border-top:1px solid var(--line)}
.report-stats{grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:8px!important;margin-bottom:12px!important}.report-stat{padding:12px!important;border-radius:11px!important}.report-stat small{font-size:8px!important;letter-spacing:.5px!important}.report-stat strong{font-size:15px!important;margin:7px 0 4px!important}.report-stat span{font-size:9px!important}
.report-table-card{padding:13px!important;border-radius:13px!important}.report-table-card .section-head{display:flex;flex-direction:column;align-items:stretch;gap:12px}.report-table-card .section-head h2{font-size:16px!important}.report-table-card .section-head p{font-size:11px!important}
.payment-head-actions{display:grid!important;grid-template-columns:1fr 1fr;gap:8px!important}.payment-head-actions .btn{width:100%;min-width:0;justify-content:center;padding:10px 7px!important;font-size:11px!important}.payment-head-actions .btn:last-child{grid-column:1/-1}
.report-filter{display:grid!important;grid-template-columns:1fr!important;gap:10px!important}.report-filter .form-group{width:100%;min-width:0}.report-filter input,.report-filter select{width:100%!important;min-height:42px}
.table-wrap{overflow:visible!important;width:100%}.payment-report-table{display:block!important;min-width:0!important;width:100%!important}.payment-report-table thead{display:none!important}.payment-report-table tbody{display:grid;gap:11px}.payment-report-table tbody tr{display:block;width:100%;padding:8px 11px;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 3px 12px rgba(20,45,35,.04)}
.payment-report-table tbody td{display:grid!important;grid-template-columns:94px minmax(0,1fr);align-items:start;gap:8px;width:100%;min-width:0;padding:9px 2px!important;border:0!important;border-bottom:1px solid #edf1ee!important;text-align:left!important;font-size:11px!important;overflow-wrap:anywhere}.payment-report-table tbody td:last-child{border-bottom:0!important}.payment-report-table tbody td:before{content:attr(data-label);color:var(--muted);font-size:10px;font-weight:800}.payment-report-table tbody td.empty{display:block!important;text-align:center!important;padding:20px!important}.payment-report-table tbody td.empty:before{content:none}
.payment-report-table td form{min-width:0!important;width:100%;display:flex!important;flex-wrap:wrap;gap:6px!important}.payment-report-table td form input{width:100%!important;flex:1 1 100%}.payment-report-table td form button{flex:1 1 auto}.payment-report-table .btn{white-space:normal!important;font-size:10px!important;padding:8px 9px!important}.payment-report-table .help{font-size:10px!important;overflow-wrap:anywhere}
.report-pagination{align-items:stretch!important;padding:12px!important;gap:12px!important}.page-size,.page-nav{width:100%;flex-wrap:wrap;justify-content:space-between;font-size:11px!important}.page-nav .btn{flex:1;justify-content:center;text-align:center}
#cashModal .premium-modal{width:calc(100% - 24px)!important;max-width:440px!important;max-height:calc(100dvh - 24px)!important;overflow-y:auto!important;padding:22px 18px!important;border-radius:18px!important}#cashModal .form-grid{grid-template-columns:1fr!important;gap:13px!important}#cashModal .form-group{grid-column:1/-1!important;min-width:0!important}
}
@media(max-width:380px){.content{padding:9px!important}.report-stats{gap:6px!important}.report-stat{padding:10px!important}.report-stat strong{font-size:13px!important}.payment-head-actions{grid-template-columns:1fr!important}.payment-head-actions .btn:last-child{grid-column:auto}.payment-report-table tbody td{grid-template-columns:82px minmax(0,1fr);gap:6px}}

</style>
<script>
    (()=>{const f=document.getElementById("paymentFilter"),s=document.getElementById("paymentStatus"),pp=document.getElementById("paymentPerPage");if(pp)pp.addEventListener("change",()=>{const u=new URL(location.href);u.searchParams.set("per_page",pp.value);u.searchParams.set("page","1");location.href=u.toString()});if(f){if(s)s.addEventListener("change",()=>f.requestSubmit());f.querySelectorAll('input[type="date"]').forEach(el=>el.addEventListener("change",()=>{if(f.checkValidity())f.requestSubmit()}));}const input=document.getElementById('cashStudentSearch'),hidden=document.getElementById('cashStudentId'),list=document.getElementById('cashStudentSuggestions'),hint=document.getElementById('cashStudentHint');if(!input)return;let timer;input.addEventListener('input',()=>{hidden.value='';hint.textContent='Pilih nama santri dari daftar pencarian.';const q=input.value.trim();clearTimeout(timer);if(q.length<2){list.hidden=true;input.setCustomValidity('Pilih nama santri dari daftar pencarian.');return}timer=setTimeout(async()=>{try{const r=await fetch('<?= APP_BASE ?>/login.php?lookup_santri='+encodeURIComponent(q),{headers:{Accept:'application/json'}});const items=await r.json();list.replaceChildren();if(!items.length){const empty=document.createElement('div');empty.className='admin-student-option';empty.textContent='Nama santri tidak ditemukan';list.append(empty)}else items.forEach(item=>{const b=document.createElement('button');b.type='button';b.className='admin-student-option';const strong=document.createElement('strong');strong.textContent=item.full_name;const small=document.createElement('small');small.textContent='No. induk: '+item.student_no;b.append(strong,small);b.addEventListener('click',()=>{input.value=item.full_name;hidden.value=item.id;input.setCustomValidity('');hint.textContent='Santri dipilih: '+item.full_name+' · '+item.student_no;const level=(item.class_level||'Jenjang').trim();const typeSelect=document.getElementById('cashPaymentType');typeSelect.querySelector('option[value="jenjang"]').textContent='SPP ('+level+')';typeSelect.querySelector('option[value="combined"]').textContent='SPP Pondok + ('+level+')';list.hidden=true});list.append(b)});list.hidden=false;input.setAttribute('aria-expanded','true')}catch(e){list.hidden=true}},180)});input.addEventListener('change',()=>{if(!hidden.value)input.setCustomValidity('Pilih nama santri dari daftar pencarian.')});document.addEventListener('click',e=>{if(!e.target.closest('.admin-student-search'))list.hidden=true});const modal=document.getElementById('cashModal');modal.querySelector('form').addEventListener('submit',e=>{if(!hidden.value){e.preventDefault();input.setCustomValidity('Pilih nama santri dari daftar pencarian.');input.reportValidity()}})})();
    function openCashModal() {
        let m = document.getElementById('cashModal');
        m.classList.add('is-open');
        m.setAttribute('aria-hidden', 'false');
        document.body.classList.add('premium-modal-open')
    }

    function closeCashModal() {
        let m = document.getElementById('cashModal');
        m.classList.remove('is-open');
        m.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('premium-modal-open')
    }
    document.querySelectorAll('form').forEach(form=>{const reject=form.querySelector('button[name="action"][value="reject"]');if(reject){reject.addEventListener('click',e=>{const notes=form.querySelector('[name="notes"]');if(notes&&!notes.value.trim()){e.preventDefault();notes.required=true;notes.setCustomValidity('Isi alasan penolakan terlebih dahulu.');notes.reportValidity();notes.addEventListener('input',()=>notes.setCustomValidity(''),{once:true});}});}});
    document.getElementById('cashModal').addEventListener('click', e => {
        if (e.target === e.currentTarget) closeCashModal()
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeCashModal()
    });
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
