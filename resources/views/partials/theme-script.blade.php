<script>
(function () {
    var theme = null;
    try { theme = localStorage.getItem('cipi-gui-theme'); } catch (e) {}
    if (theme !== 'light' && theme !== 'dark') {
        theme = window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
    }
    document.documentElement.setAttribute('data-theme', theme);
})();
</script>
