{{-- One confirmation dialog for the whole panel, in place of the browser's confirm().
     A button asks with data-confirm="Question? Optional detail." next to its wire:click:

         <button wire:click="remove(3)" data-confirm="Remove the key deploy@ci?">Remove</button>

     The click is held (capture phase, before Livewire or Alpine see it), the dialog
     opens, and on confirm the same button is clicked again and goes through — so the
     action, its busy state and anything else bound to the click behave exactly as
     without the attribute. Optional: data-confirm-label="Remove" (the confirm button;
     default: the trigger's own text), data-confirm-danger (red confirm button; default
     when the trigger is styled as dangerous). The first sentence, up to the question
     mark, is the title; the rest is the body. --}}
<div id="cipi-confirm" class="modal-overlay confirm-overlay" hidden role="alertdialog" aria-modal="true" aria-labelledby="cipi-confirm-title" aria-describedby="cipi-confirm-text">
    <div class="modal-content confirm-dialog">
        <div class="modal-body">
            <h2 class="modal-title" id="cipi-confirm-title"></h2>
            <p class="confirm-text" id="cipi-confirm-text"></p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-confirm-cancel>Cancel</button>
            <button type="button" class="btn btn-primary" data-confirm-ok>Confirm</button>
        </div>
    </div>
</div>
<script data-cipi-confirm-script>
(function () {
    var overlay = document.getElementById('cipi-confirm');
    if (!overlay) return;
    var title = overlay.querySelector('#cipi-confirm-title');
    var text = overlay.querySelector('#cipi-confirm-text');
    var ok = overlay.querySelector('[data-confirm-ok]');
    var cancel = overlay.querySelector('[data-confirm-cancel]');
    var pending = null;      // the element whose click is being held
    var lastFocus = null;

    function split(message) {
        message = (message || '').trim();
        var cut = message.indexOf('?');
        if (cut === -1 || cut === message.length - 1) return [message, ''];
        return [message.slice(0, cut + 1), message.slice(cut + 1).trim()];
    }

    function labelOf(el) {
        var given = el.getAttribute('data-confirm-label');
        if (given) return given;
        var own = (el.textContent || '').replace(/\s+/g, ' ').trim();
        return own !== '' && own.length <= 28 ? own : 'Confirm';
    }

    function isDanger(el) {
        return el.hasAttribute('data-confirm-danger') || /(^|\s)(danger|btn-danger|btn-danger-solid|text-danger)(\s|$)/.test(el.className || '');
    }

    function open(el) {
        var parts = split(el.getAttribute('data-confirm'));
        pending = el;
        lastFocus = document.activeElement;
        title.textContent = parts[0] || 'Are you sure?';
        text.textContent = parts[1];
        text.hidden = parts[1] === '';
        ok.textContent = labelOf(el);
        ok.className = 'btn ' + (isDanger(el) ? 'btn-danger-solid' : 'btn-primary');
        overlay.hidden = false;
        // A destructive action does not get the focus: Enter must not confirm it by reflex.
        (isDanger(el) ? cancel : ok).focus();
    }

    function close(confirmed) {
        var el = pending;
        pending = null;
        overlay.hidden = true;
        if (confirmed && el && el.isConnected) {
            el.__cipiConfirmed = true;
            el.click();
        } else if (lastFocus && lastFocus.isConnected && typeof lastFocus.focus === 'function') {
            lastFocus.focus();
        }
        lastFocus = null;
    }

    // Capture phase on the document: this runs before any handler on the button itself.
    document.addEventListener('click', function (event) {
        var el = event.target && event.target.closest ? event.target.closest('[data-confirm]') : null;
        if (!el || overlay.contains(el)) return;
        if (el.__cipiConfirmed) { el.__cipiConfirmed = false; return; }
        if (el.disabled || el.getAttribute('aria-disabled') === 'true') return;
        event.preventDefault();
        event.stopImmediatePropagation();
        open(el);
    }, true);

    ok.addEventListener('click', function () { close(true); });
    cancel.addEventListener('click', function () { close(false); });
    overlay.addEventListener('mousedown', function (event) { if (event.target === overlay) close(false); });
    document.addEventListener('keydown', function (event) {
        if (overlay.hidden) return;
        if (event.key === 'Escape') { event.preventDefault(); event.stopImmediatePropagation(); close(false); return; }
        if (event.key === 'Tab') {
            // Two buttons: keep the focus on them.
            event.preventDefault();
            (document.activeElement === ok ? cancel : ok).focus();
        }
    }, true);
})();
</script>
