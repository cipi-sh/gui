<script>
(function () {
    var key = 'cipi-gui-theme';
    var stored = localStorage.getItem(key);
    var theme = stored === 'light' || stored === 'dark'
        ? stored
        : (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
    document.documentElement.setAttribute('data-theme', theme);
})();
</script>
