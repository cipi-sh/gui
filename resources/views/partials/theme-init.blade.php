<script>
(function () {
    var key = 'cipi-gui-theme';

    function currentTheme() {
        return document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
    }

    function apply(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem(key, theme);
    }

    document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            apply(currentTheme() === 'dark' ? 'light' : 'dark');
        });
    });
})();
</script>
