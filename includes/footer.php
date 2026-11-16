</div>
  <footer class="footer">© <?= date('Y') ?> Pondok Pesantren <?= e(app_school_name()) ?> · Sistem Administrasi Keuangan</footer>
</main>
</div>

<div class="premium-modal-backdrop" id="premiumConfirmModal" aria-hidden="true" role="presentation">
  <section class="premium-modal" role="dialog" aria-modal="true" aria-labelledby="premiumConfirmTitle" aria-describedby="premiumConfirmMessage">
    <button class="premium-modal-close" type="button" data-confirm-cancel aria-label="Tutup">×</button>
    <div class="premium-modal-icon warning" aria-hidden="true">!</div>
    <div class="premium-modal-kicker">KONFIRMASI TINDAKAN</div>
    <h2 id="premiumConfirmTitle">Apakah Anda yakin?</h2>
    <p id="premiumConfirmMessage">Tindakan ini akan diproses.</p>
    <div class="premium-modal-actions"><button class="btn btn-light" type="button" data-confirm-cancel>Batal</button><button class="btn btn-danger" type="button" id="premiumConfirmProceed">Ya, lanjutkan</button></div>
  </section>
</div>
<script>
// Dialog aplikasi terpadu: seluruh notifikasi dan konfirmasi memakai modal premium, bukan dialog browser.
(function () {
    let activeDialog = null;
    function buildDialog() {
        let root = document.getElementById('appDialogModal');
        if (root) return root;
        root = document.createElement('div');
        root.id = 'appDialogModal';
        root.className = 'premium-modal-backdrop';
        root.setAttribute('aria-hidden', 'true');
        root.innerHTML = '<section class="premium-modal app-dialog-card" role="dialog" aria-modal="true" aria-labelledby="appDialogTitle" aria-describedby="appDialogMessage"><div class="premium-modal-icon warning" id="appDialogIcon">!</div><div class="premium-modal-kicker" id="appDialogKicker">SANTRI GO</div><h2 id="appDialogTitle">Informasi</h2><p id="appDialogMessage"></p><div class="premium-modal-actions" id="appDialogActions"></div></section>';
        document.body.appendChild(root);
        root.addEventListener('click', e => { if (e.target === root && activeDialog) activeDialog.finish(false); });
        return root;
    }
    function openDialog(options) {
        const root = buildDialog();
        const title = root.querySelector('#appDialogTitle');
        const message = root.querySelector('#appDialogMessage');
        const icon = root.querySelector('#appDialogIcon');
        const kicker = root.querySelector('#appDialogKicker');
        const actions = root.querySelector('#appDialogActions');
        title.textContent = options.title || 'Informasi';
        message.textContent = options.message || '';
        icon.textContent = options.type === 'success' ? '✓' : options.type === 'error' ? '×' : options.type === 'confirm' ? '?' : '!';
        icon.className = 'premium-modal-icon ' + (options.type === 'error' ? 'error' : options.type === 'confirm' ? 'warning' : '');
        kicker.textContent = options.kicker || 'SANTRI GO';
        actions.replaceChildren();
        return new Promise(resolve => {
            let settled = false;
            function finish(value) {
                if (settled) return;
                settled = true;
                root.classList.remove('is-open'); root.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('premium-modal-open');
                document.removeEventListener('keydown', onKey);
                activeDialog = null; resolve(value);
            }
            function onKey(e) { if (e.key === 'Escape' && options.type === 'confirm') finish(false); }
            activeDialog = { finish };
            if (options.type === 'confirm') {
                const cancel = document.createElement('button'); cancel.type='button'; cancel.className='btn btn-light'; cancel.textContent=options.cancelText || 'Batal'; cancel.addEventListener('click',()=>finish(false)); actions.appendChild(cancel);
            }
            const ok = document.createElement('button'); ok.type='button'; ok.className='btn ' + (options.type === 'confirm' ? 'btn-primary' : 'btn-primary'); ok.textContent=options.okText || 'Mengerti'; ok.addEventListener('click',()=>finish(options.type === 'confirm' ? true : true)); actions.appendChild(ok);
            root.classList.add('is-open'); root.setAttribute('aria-hidden','false'); document.body.classList.add('premium-modal-open');
            document.addEventListener('keydown', onKey); ok.focus();
        });
    }
    window.showAppNotice = function(message, type) { return openDialog({message:String(message), type:type || 'info', title:type==='error'?'Terjadi Kesalahan':type==='success'?'Berhasil':'Informasi'}); };
    window.showAppConfirm = function(message, options) { return openDialog(Object.assign({message:String(message), type:'confirm', title:'Konfirmasi Tindakan', okText:'Ya, lanjutkan'}, options || {})); };
})();

(function () {
    var loader = document.getElementById('schoolLoadingScreen');
    if (!loader) return;

    function navigationIsReload() {
        try {
            var nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
            return !!((nav && nav.type === 'reload') ||
                (performance.navigation && performance.navigation.type === 1));
        } catch (e) { return false; }
    }
    var afterLogin = false;
    try {
        afterLogin = sessionStorage.getItem('santriGoShowLoaderAfterLogin') === '1';
        if (afterLogin) sessionStorage.removeItem('santriGoShowLoaderAfterLogin');
    } catch (e) {}

    var shouldShow = navigationIsReload() || afterLogin;
    var startedAt = Date.now();
    var hideTimer = null;
    function hideSchoolLoader() {
        if (hideTimer) window.clearTimeout(hideTimer);
        var elapsed = Date.now() - startedAt;
        hideTimer = window.setTimeout(function () {
            loader.classList.add('is-hidden');
        }, Math.max(650, 1000 - elapsed));
    }
    if (shouldShow) {
        loader.classList.remove('is-hidden');
        if (document.readyState === 'complete') hideSchoolLoader();
        else {
            window.addEventListener('load', hideSchoolLoader, { once: true });
            window.setTimeout(hideSchoolLoader, 5000);
        }
    } else {
        loader.classList.add('is-hidden');
    }

    // Browser dapat memulihkan halaman dari back-forward cache tanpa reload penuh.
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) loader.classList.add('is-hidden');
    });
})();

(function () {
    const flashModal = document.getElementById('premiumFlashModal');
    if (flashModal) {
        document.body.classList.add('premium-modal-open');
        flashModal.querySelectorAll('[data-popup-close]').forEach(function (button) {
            button.addEventListener('click', function () {
                flashModal.classList.remove('is-open');
                document.body.classList.remove('premium-modal-open');
            });
        });
        flashModal.addEventListener('click', function (event) {
            if (event.target === flashModal) {
                flashModal.classList.remove('is-open');
                document.body.classList.remove('premium-modal-open');
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && flashModal.classList.contains('is-open')) {
                flashModal.classList.remove('is-open');
                document.body.classList.remove('premium-modal-open');
            }
        });
        const closeFlash = flashModal.querySelector('[data-popup-close]');
        if (closeFlash) closeFlash.focus();
    }

    const confirmModal = document.getElementById('premiumConfirmModal');
    const proceedButton = document.getElementById('premiumConfirmProceed');
    let pendingForm = null;
    function closeConfirm() {
        if (!confirmModal) return;
        confirmModal.classList.remove('is-open');
        confirmModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('premium-modal-open');
        pendingForm = null;
    }
    document.querySelectorAll('form[onsubmit*="confirm("], form[data-confirm]').forEach(function (form) {
        const inline = form.getAttribute('onsubmit') || '';
        const match = inline.match(/confirm\(['"]([\s\S]*?)['"]\)/);
        const message = form.dataset.confirm || (match ? match[1] : 'Lanjutkan tindakan ini?');
        form.removeAttribute('onsubmit');
        form.dataset.confirm = message;
        form.addEventListener('submit', function (event) {
            if (form.dataset.confirmed === 'true') { form.dataset.confirmed = 'false'; return; }
            event.preventDefault();
            pendingForm = form;
            const messageNode = document.getElementById('premiumConfirmMessage');
            if (messageNode) messageNode.textContent = message;
            confirmModal.classList.add('is-open');
            confirmModal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('premium-modal-open');
            if (proceedButton) proceedButton.focus();
        });
    });
    if (proceedButton) proceedButton.addEventListener('click', function () {
        if (!pendingForm) return;
        const form = pendingForm;
        form.dataset.confirmed = 'true';
        closeConfirm();
        if (typeof form.requestSubmit === 'function') form.requestSubmit(); else form.submit();
    });
    if (confirmModal) {
        confirmModal.querySelectorAll('[data-confirm-cancel]').forEach(function (button) { button.addEventListener('click', closeConfirm); });
        confirmModal.addEventListener('click', function (event) { if (event.target === confirmModal) closeConfirm(); });
        document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && confirmModal.classList.contains('is-open')) closeConfirm(); });
    }
})();
(function () {
    const modal = document.getElementById('notificationModal');
    const openButton = document.getElementById('notificationOpen');
    if (!modal || !openButton) return;
    function openNotifications() {
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('premium-modal-open');
        const closeButton = modal.querySelector('[data-notification-close]');
        if (closeButton) closeButton.focus();
    }
    function closeNotifications() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('premium-modal-open');
        openButton.focus();
    }
    openButton.addEventListener('click', openNotifications);
    modal.querySelectorAll('[data-notification-close]').forEach(function (button) { button.addEventListener('click', closeNotifications); });
    modal.addEventListener('click', function (event) { if (event.target === modal) closeNotifications(); });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && modal.classList.contains('is-open')) closeNotifications(); });
})();
(function () {
    const sidebar = document.getElementById('appSidebar');
    const overlay = document.querySelector('[data-sidebar-overlay]');
    const openButton = document.querySelector('[data-sidebar-open]');
    const closeButton = document.querySelector('[data-sidebar-close]');
    if (!sidebar || !overlay || !openButton) return;

    function openSidebar() {
        sidebar.classList.add('is-open');
        overlay.classList.add('is-visible');
        document.body.classList.add('sidebar-open');
        openButton.setAttribute('aria-expanded', 'true');
        overlay.setAttribute('aria-hidden', 'false');
        if (closeButton) closeButton.focus();
    }

    function closeSidebar() {
        sidebar.classList.remove('is-open');
        overlay.classList.remove('is-visible');
        document.body.classList.remove('sidebar-open');
        openButton.setAttribute('aria-expanded', 'false');
        overlay.setAttribute('aria-hidden', 'true');
    }

    openButton.addEventListener('click', openSidebar);
    if (closeButton) closeButton.addEventListener('click', closeSidebar);
    overlay.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeSidebar();
    });
    sidebar.querySelectorAll('a.nav-link').forEach(function (link) {
        link.addEventListener('click', closeSidebar);
    });
    window.addEventListener('resize', function () {
        if (window.innerWidth > 760) closeSidebar();
    });
})();

(function () {
    const timeoutMs = 5 * 60 * 1000;
    let timer = null, locked = false, lastPing = 0;
    const markup = `<div id="sessionIdleLock" class="premium-modal-backdrop is-open" aria-hidden="false" style="z-index:100000"><section class="premium-modal" role="dialog" aria-modal="true" aria-labelledby="sessionIdleTitle"><div class="premium-modal-icon warning">🔒</div><div class="premium-modal-kicker">SANTRI GO · KEAMANAN</div><h2 id="sessionIdleTitle">Sesi Dikunci</h2><p>Tidak ada aktivitas selama 5 menit. Masukkan kembali password akun untuk melanjutkan.</p><form id="sessionIdleForm"><label for="sessionIdlePassword">Password akun</label><input id="sessionIdlePassword" name="password" type="password" autocomplete="current-password" required style="width:100%;height:46px;padding:0 12px;border:1px solid #d7e1ee;border-radius:10px"><button class="btn btn-primary" type="submit" style="width:100%;margin-top:14px">Buka Kunci</button><div id="sessionIdleError" role="alert" style="color:#bd3434;font-size:13px;margin-top:10px;min-height:18px"></div></form></section></div>`;
    function showLock() {
        if (locked) return;
        locked = true;
        document.body.insertAdjacentHTML('beforeend', markup);
        document.body.classList.add('premium-modal-open');
        const form = document.getElementById('sessionIdleForm');
        const input = document.getElementById('sessionIdlePassword');
        const error = document.getElementById('sessionIdleError');
        const button = form.querySelector('button');
        input.focus();
        form.addEventListener('submit', async function (event) {
            event.preventDefault(); error.textContent = ''; button.disabled = true; button.textContent = 'Memeriksa...';
            try {
                const unlockData = new FormData(form); unlockData.append('csrf', window.SANTRI_GO_CSRF || '');
                const response = await fetch((window.SANTRI_GO_BASE || '') + '/session_lock.php?action=unlock', {method:'POST', body:unlockData, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}});
                const result = await response.json();
                if (result.success) { document.getElementById('sessionIdleLock').remove(); document.body.classList.remove('premium-modal-open'); locked = false; resetTimer(); return; }
                error.textContent = result.message || 'Password tidak sesuai.'; input.value = ''; input.focus();
            } catch (e) { error.textContent = 'Tidak dapat menghubungi server. Coba lagi.'; }
            finally { button.disabled = false; button.textContent = 'Buka Kunci'; }
        });
    }
    async function pingActivity() {
        if (locked || Date.now() - lastPing < 15000) return;
        lastPing = Date.now();
        try { await fetch((window.SANTRI_GO_BASE || '') + '/session_lock.php?action=activity', {method:'POST', credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}}); } catch (e) {}
    }
    function resetTimer() {
        if (locked) return;
        clearTimeout(timer);
        timer = setTimeout(async function () {
            try { await fetch((window.SANTRI_GO_BASE || '') + '/session_lock.php?action=lock', {method:'POST', credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}}); } catch (e) {}
            showLock();
        }, timeoutMs);
    }
    ['mousemove','mousedown','keydown','touchstart','scroll','click'].forEach(function (name) {
        document.addEventListener(name, function () { if (!locked) { resetTimer(); pingActivity(); } }, {passive:true});
    });
    document.addEventListener('visibilitychange', function () { if (!document.hidden && !locked) { resetTimer(); pingActivity(); } });
    resetTimer();
})();
</script>
</body>
</html>
