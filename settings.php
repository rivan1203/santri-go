<?php
require __DIR__ . '/config.php';
require_role('admin');

$pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(80) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

function save_app_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->execute([$key, $value]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $schoolName = trim((string)($_POST['school_name'] ?? ''));
    if ($schoolName === '' || mb_strlen($schoolName) > 150) {
        flash('Nama pesantren wajib diisi dan maksimal 150 karakter.', 'error');
        redirect('settings.php');
    }

    $logoName = app_setting('school_logo', '');
    if (!empty($_POST['remove_logo'])) {
        if ($logoName !== '' && basename($logoName) === $logoName) {
            $oldPath = __DIR__ . '/uploads/' . $logoName;
            if (is_file($oldPath)) @unlink($oldPath);
        }
        $logoName = '';
    }

    if (isset($_FILES['school_logo']) && $_FILES['school_logo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['school_logo'];
        if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 2 * 1024 * 1024) {
            flash('Logo gagal diunggah. Ukuran maksimal 2 MB.', 'error');
            redirect('settings.php');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
        $imageInfo = @getimagesize($file['tmp_name']);
        if (!isset($allowed[$mime]) || !$imageInfo || !in_array($imageInfo[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            flash('Format logo harus PNG, JPG/JPEG, atau WebP.', 'error');
            redirect('settings.php');
        }
        $uploadDir = __DIR__ . '/uploads';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            flash('Folder upload tidak dapat dibuat.', 'error');
            redirect('settings.php');
        }
        $newLogoName = 'school-logo-' . bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $newLogoName)) {
            flash('Logo gagal disimpan. Periksa izin folder uploads.', 'error');
            redirect('settings.php');
        }
        if ($logoName !== '' && basename($logoName) === $logoName) {
            $oldPath = $uploadDir . '/' . $logoName;
            if (is_file($oldPath) && str_starts_with($logoName, 'school-logo-')) @unlink($oldPath);
        }
        $logoName = $newLogoName;
    }

    save_app_setting($pdo, 'bank_bri_account', preg_replace('/[^0-9]/', '', (string)($_POST['bank_bri_account'] ?? '')));
    save_app_setting($pdo, 'bank_bca_account', preg_replace('/[^0-9]/', '', (string)($_POST['bank_bca_account'] ?? '')));
    save_app_setting($pdo, 'school_name', $schoolName);
    save_app_setting($pdo, 'school_logo', $logoName);
    flash('Identitas pesantren berhasil diperbarui dan akan ditampilkan pada layout aplikasi.', 'success');
    redirect('settings.php');
}

$pageTitle = 'Pengaturan Pesantren';
$currentName = app_setting('school_name', APP_NAME) ?: APP_NAME;
$currentLogo = app_school_logo_url();
$currentBri = app_setting('bank_bri_account', '');
$currentBca = app_setting('bank_bca_account', '');
require __DIR__ . '/includes/header.php';
?>
<section class="settings-hero">
    <div class="settings-hero-icon" aria-hidden="true">✦</div>
    <div><span class="settings-eyebrow">KONFIGURASI APLIKASI</span>
        <h2>Identitas Pesantren</h2>
        <p>Kelola informasi pondok yang tampil pada portal, bukti pembayaran, dan komunikasi administrasi.</p>
    </div>
</section>
<section class="card settings-card">
    <form method="post" enctype="multipart/form-data" class="settings-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <div class="settings-section-head"><span class="settings-section-icon">⌂</span>
            <div>
                <h3>Profil Pesantren</h3>
                <p>Nama dan identitas visual lembaga</p>
            </div>
        </div>
        <div class="form-group">
            <label for="school_name">Nama pesantren</label>
            <input class="input" id="school_name" name="school_name" maxlength="150" required value="<?= e($currentName) ?>" placeholder="Contoh: Pondok Pesantren Mambaul Huda">
        </div>
        <div class="form-group">
            <label for="school_logo">Logo pesantren</label>
            <input class="input settings-file-input" type="file" id="school_logo" name="school_logo" accept="image/png,image/jpeg,image/webp">
            <small class="help">Format PNG, JPG/JPEG, atau WebP. Maksimal 2 MB. Logo lama tetap digunakan jika tidak memilih file baru.</small>
        </div>
        <?php if ($currentLogo): ?>
            <div class="settings-logo-panel">
                <div class="settings-logo-preview"><img src="<?= e($currentLogo) ?>" alt="Logo pesantren saat ini">
                    <div><strong><?= e($currentName) ?></strong><small>Logo yang sedang digunakan</small></div>
                </div>
                <label class="settings-remove-logo"><input type="checkbox" name="remove_logo" value="1"> Hapus logo saat ini</label>
            </div>
        <?php endif; ?>
        <div class="settings-divider"></div>
        <div class="settings-section-head"><span class="settings-section-icon bank-icon">▤</span>
            <div>
                <h3>Rekening Pembayaran</h3>
                <p>Informasi rekening tujuan transfer santri</p>
            </div>
        </div>
        <div class="settings-bank-grid">
            <div class="form-group"><label>Nomor rekening BRI</label><input class="input" name="bank_bri_account" inputmode="numeric" value="<?= e($currentBri) ?>" placeholder="Masukkan nomor rekening BRI"><small class="help">Rekening tujuan untuk pembayaran melalui BRI.</small></div>
            <div class="form-group"><label>Nomor rekening BCA</label><input class="input" name="bank_bca_account" inputmode="numeric" value="<?= e($currentBca) ?>" placeholder="Masukkan nomor rekening BCA"><small class="help">Rekening tujuan untuk pembayaran melalui BCA.</small></div>
        </div>
        <div class="settings-actions"><span>Pastikan data sudah benar sebelum menyimpan.</span><button class="btn" type="submit">Simpan pengaturan <span aria-hidden="true">→</span></button></div>
    </form>
</section>
<style>
    .settings-hero {
        max-width: 920px;
        display: flex;
        align-items: center;
        gap: 18px;
        margin: 2px 0 20px;
        padding: 23px 25px;
        border: 1px solid #e4eaf2;
        border-radius: 18px;
        background: linear-gradient(115deg, #fff 0%, #f5f8fd 100%);
        box-shadow: 0 8px 24px rgba(35, 60, 95, .035)
    }

    .settings-hero-icon {
        display: grid;
        place-items: center;
        flex: 0 0 52px;
        width: 52px;
        height: 52px;
        border-radius: 16px;
        background: #eaf0fb;
        color: #4569a7;
        font-size: 25px
    }

    .settings-eyebrow {
        display: block;
        margin-bottom: 5px;
        color: #6680ad;
        font-size: 9px;
        font-weight: 800;
        letter-spacing: 1.6px
    }

    .settings-hero h2 {
        margin: 0;
        color: #203451;
        font: 800 19px Manrope
    }

    .settings-hero p {
        margin: 6px 0 0;
        color: #74839a;
        font-size: 12px;
        line-height: 1.6;
        max-width: 650px
    }

    .settings-card {
        max-width: 920px;
        padding: 25px;
        border: 1px solid #e7ebf2;
        border-radius: 18px;
        box-shadow: 0 10px 30px rgba(30, 55, 90, .045)
    }

    .settings-form {
        display: grid;
        gap: 17px
    }

    .settings-section-head {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 1px
    }

    .settings-section-icon {
        display: grid;
        place-items: center;
        width: 38px;
        height: 38px;
        border-radius: 12px;
        background: #edf2fb;
        color: #5273aa;
        font-size: 20px
    }

    .settings-section-head h3 {
        margin: 0;
        color: #263b59;
        font: 800 14px Manrope
    }

    .settings-section-head p {
        margin: 4px 0 0;
        color: #8995a7;
        font-size: 11px
    }

    .settings-form .form-group {
        gap: 8px
    }

    .settings-form label {
        color: #485a73
    }

    .settings-form .input {
        min-height: 45px;
        border-color: #e0e6ef;
        border-radius: 10px
    }

    .settings-form .input:focus {
        border-color: #8da8d3;
        box-shadow: 0 0 0 3px rgba(82, 115, 170, .1)
    }

    .settings-file-input {
        padding: 9px;
        background: #fafbfd
    }

    .settings-logo-panel {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
        padding: 14px 16px;
        border: 1px solid #e8edf4;
        border-radius: 13px;
        background: #fafbfd
    }

    .settings-logo-preview {
        display: flex;
        align-items: center;
        gap: 13px
    }

    .settings-logo-preview img {
        width: 62px;
        height: 62px;
        object-fit: contain;
        border: 1px solid #edf0f5;
        border-radius: 12px;
        background: #fff;
        padding: 5px
    }

    .settings-logo-preview strong,
    .settings-logo-preview small {
        display: block
    }

    .settings-logo-preview strong {
        font-size: 12px;
        color: #344762
    }

    .settings-logo-preview small {
        margin-top: 4px;
        color: #8995a7;
        font-size: 10px
    }

    .settings-remove-logo {
        display: flex;
        align-items: center;
        gap: 7px;
        color: #a34b4b !important;
        font-size: 11px !important
    }

    .settings-remove-logo input {
        accent-color: #c43c3c
    }

    .settings-divider {
        height: 1px;
        margin: 3px 0;
        background: #edf0f5
    }

    .bank-icon {
        background: #f0f3fa
    }

    .settings-bank-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px
    }

    .settings-actions {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
        margin-top: 5px;
        padding-top: 18px;
        border-top: 1px solid #edf0f5
    }

    .settings-actions>span {
        color: #8a96a8;
        font-size: 10px
    }

    .settings-actions .btn {
        min-height: 43px;
        padding: 0 18px;
        border-radius: 10px;
        background: #365d96;
        box-shadow: 0 5px 13px rgba(54, 93, 150, .14)
    }

    .settings-actions .btn:hover {
        background: #294b7d
    }

    .brand-mark img {
        width: 100%;
        height: 100%;
        object-fit: contain;
        border-radius: inherit;
        background: #fff;
        padding: 3px
    }

    .receipt-logo img {
        width: 100%;
        height: 100%;
        object-fit: contain;
        border-radius: inherit;
        background: #fff;
        padding: 1mm
    }

    .brand-login .brand-mark img {
        width: 100%;
        height: 100%;
        object-fit: contain;
        border-radius: inherit;
        background: #fff;
        padding: 3px
    }

    @media(max-width:640px) {
        .settings-hero {
            padding: 18px;
            align-items: flex-start
        }

        .settings-hero-icon {
            width: 44px;
            height: 44px;
            flex-basis: 44px
        }

        .settings-hero h2 {
            font-size: 17px
        }

        .settings-card {
            padding: 17px
        }

        .settings-bank-grid {
            grid-template-columns: 1fr
        }

        .settings-logo-panel,
        .settings-actions {
            align-items: stretch;
            flex-direction: column
        }

        .settings-actions .btn {
            width: 100%
        }
    }
</style>
<?php require __DIR__ . '/includes/footer.php'; ?>