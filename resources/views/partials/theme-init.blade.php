<script>
(function () {
    var key = 'cipi-gui-theme';

    function resolveTheme() {
        var stored = localStorage.getItem(key);
        if (stored === 'light' || stored === 'dark') {
            return stored;
        }
        return window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
    }

    function currentTheme() {
        return document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
    }

    function apply(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem(key, theme);
    }

    function sync() {
        apply(resolveTheme());
    }

    sync();

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-theme-toggle]');
        if (!btn) return;
        apply(currentTheme() === 'dark' ? 'light' : 'dark');
    });

    document.addEventListener('livewire:navigated', sync);
})();
</script>
