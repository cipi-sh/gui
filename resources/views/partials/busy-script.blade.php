<script data-cipi-busy-script>
(function () {
    // Busy state for the element that fires a Livewire action — the clicked
    // button, or the submit button of the form — until the server answers.
    // From the moment the request leaves, further clicks on it are cancelled;
    // after a short delay its label gives way to a spinner (see .busy-visible
    // in cipi-gui.css). One action interceptor for the whole panel instead of
    // wire:loading on every button. Left alone: buttons that bring their own
    // wire:loading, polls, model updates, and rows or cards that happen to
    // carry a wire:click. Livewire 4 (interceptAction); nothing on Livewire 3.
    var SHOW_AFTER_MS = 150;
    var GIVE_UP_MS = 90000;

    function hasOwnLoadingState(el) {
        for (var i = 0; i < el.attributes.length; i++) {
            if (el.attributes[i].name.indexOf('wire:loading') === 0) return true;
        }
        return !!el.querySelector('[wire\\:target]');
    }

    function targetFor(el, type) {
        if (type === 'submit') {
            var active = document.activeElement;
            if (active && el.contains(active) && active.matches('button, input[type="submit"]')) return active;
            return el.querySelector('button[type="submit"], input[type="submit"], button:not([type])');
        }
        return el.matches('button, a, [role="button"], .btn') ? el : null;
    }

    function show(el) {
        if (!el.hasAttribute('data-busy') || el.classList.contains('busy-visible')) return;
        el.classList.add('busy-visible');
        el.setAttribute('aria-busy', 'true');
        var spinner = document.createElement('span');
        spinner.className = 'busy-spinner';
        spinner.setAttribute('aria-hidden', 'true');
        el.appendChild(spinner);
    }

    function setBusy(el) {
        el.setAttribute('data-busy', '');
        el.__cipiBusyShow = setTimeout(function () { show(el); }, SHOW_AFTER_MS);
        el.__cipiBusyGiveUp = setTimeout(function () { clearBusy(el); }, GIVE_UP_MS);
    }

    // Idempotent: Livewire's morph may already have dropped the attributes and
    // the spinner when it re-rendered the button from the server's HTML.
    function clearBusy(el) {
        clearTimeout(el.__cipiBusyShow);
        clearTimeout(el.__cipiBusyGiveUp);
        el.removeAttribute('data-busy');
        el.removeAttribute('aria-busy');
        el.classList.remove('busy-visible');
        var spinner = el.querySelector(':scope > .busy-spinner');
        if (spinner) spinner.remove();
    }

    document.addEventListener('livewire:init', function () {
        if (!window.Livewire || typeof Livewire.interceptAction !== 'function') return;
        Livewire.interceptAction(function (ctx) {
            var origin = ctx.action && ctx.action.origin;
            if (!origin || !origin.el || !origin.directive) return;
            var type = origin.directive.value || origin.directive.type || '';
            if (type !== 'click' && type !== 'submit') return;
            var el = targetFor(origin.el, type);
            if (!el || hasOwnLoadingState(el)) return;
            if (el.hasAttribute('data-busy')) {
                if (typeof ctx.action.cancel === 'function') ctx.action.cancel();
                return;
            }
            ctx.onSend(function () { setBusy(el); });
            ctx.onFinish(function () { clearBusy(el); });
        });
    });
})();
</script>
