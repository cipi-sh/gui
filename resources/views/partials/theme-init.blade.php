<script>
(function () {
    var key = 'cipi-gui-theme';

    function stored() {
        try { return localStorage.getItem(key); } catch (e) { return null; }
    }

    function resolveTheme() {
        var value = stored();
        if (value === 'light' || value === 'dark') {
            return value;
        }
        return window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
    }

    function apply(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        try { localStorage.setItem(key, theme); } catch (e) {}
    }

    apply(resolveTheme());

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-theme-toggle]');
        if (!btn) return;
        apply(document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light');
    });

    document.addEventListener('livewire:navigated', function () { apply(resolveTheme()); });

    // Clipboard helper shared by the copy buttons, terminals and secret rows.
    window.cipiCopy = function (text) {
        var done = function () {
            window.dispatchEvent(new CustomEvent('notify', { detail: { type: 'success', message: 'Copied to clipboard' } }));
        };
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).then(done);
        }
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
        done();
        return Promise.resolve();
    };
})();
</script>
