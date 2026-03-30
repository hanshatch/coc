
</main><!-- /.ct-main -->

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ── Mobile sidebar toggle ─────────────────────────────────────
(function() {
    const toggle   = document.getElementById('sidebarToggle');
    const sidebar  = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');

    function openSidebar() {
        sidebar.classList.add('show');
        backdrop.classList.add('show');
    }

    function closeSidebar() {
        sidebar.classList.remove('show');
        backdrop.classList.remove('show');
    }

    if (toggle) toggle.addEventListener('click', openSidebar);
    if (backdrop) backdrop.addEventListener('click', closeSidebar);
})();

// ── Auto-dismiss alerts after 4s ──────────────────────────────
document.querySelectorAll('.alert-dismissible').forEach(function(el) {
    setTimeout(function() {
        var bsAlert = bootstrap.Alert.getOrCreateInstance(el);
        bsAlert.close();
    }, 4000);
});

// ── Confirmación de eliminación ───────────────────────────────
document.querySelectorAll('[data-confirm]').forEach(function(el) {
    el.addEventListener('click', function(e) {
        if (!confirm(el.getAttribute('data-confirm'))) {
            e.preventDefault();
        }
    });
});
</script>
</body>
</html>
