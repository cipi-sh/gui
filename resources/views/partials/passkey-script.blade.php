{{-- Passkeys (4.8.0): the browser half of the ceremonies. The panel's routes give the
     options and check the answers (CipiGui\Services\WebAuthn); here they only travel
     between them and navigator.credentials, as base64url. --}}
<script>
(() => {
    if (window.cipiPasskey) return;
    const b64 = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    const unb64 = (s) => { s = s.replace(/-/g, '+').replace(/_/g, '/'); s += '='.repeat((4 - s.length % 4) % 4); return Uint8Array.from(atob(s), c => c.charCodeAt(0)).buffer; };
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const post = async (url, body = {}) => {
        const res = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(body) });
        let data = {};
        try { data = await res.json(); } catch (e) { data = {}; }
        if (!res.ok) throw new Error(data.message || ('The panel answered ' + res.status + '.'));
        return data;
    };
    const asJson = (cred) => {
        const r = cred.response;
        const out = { id: cred.id, rawId: b64(cred.rawId), type: cred.type, response: { clientDataJSON: b64(r.clientDataJSON) } };
        if (r.attestationObject) { out.response.attestationObject = b64(r.attestationObject); out.response.transports = typeof r.getTransports === 'function' ? r.getTransports() : []; }
        if (r.authenticatorData) { out.response.authenticatorData = b64(r.authenticatorData); out.response.signature = b64(r.signature); out.response.userHandle = r.userHandle ? b64(r.userHandle) : null; }
        return out;
    };
    const why = (e) => {
        if (e && (e.name === 'NotAllowedError' || e.name === 'AbortError')) return 'Cancelled, or no passkey of this panel on this device.';
        if (e && e.name === 'InvalidStateError') return 'This device already has a passkey for your account.';
        if (e && e.name === 'SecurityError') return 'Passkeys need https and the panel\'s own address.';
        return (e && e.message) || 'The passkey did not go through.';
    };
    let conditional = null;
    window.cipiPasskey = {
        supported: () => !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.get),
        why,
        // Sign in, or the second step: options from optionsUrl, the answer to submitUrl.
        async use(optionsUrl, submitUrl, extra = {}, mediation = null) {
            if (conditional && mediation !== 'conditional') { conditional.abort(); conditional = null; }
            const o = await post(optionsUrl);
            o.challenge = unb64(o.challenge);
            o.allowCredentials = (o.allowCredentials || []).map(c => ({ ...c, id: unb64(c.id) }));
            const args = { publicKey: o };
            if (mediation === 'conditional') { conditional = new AbortController(); args.mediation = 'conditional'; args.signal = conditional.signal; }
            const cred = await navigator.credentials.get(args);
            const res = await post(submitUrl, { credential: asJson(cred), ...extra });
            if (res.redirect) window.location.assign(res.redirect);
            return res;
        },
        async add(optionsUrl, submitUrl, extra = {}) {
            const o = await post(optionsUrl);
            o.challenge = unb64(o.challenge);
            o.user.id = unb64(o.user.id);
            o.excludeCredentials = (o.excludeCredentials || []).map(c => ({ ...c, id: unb64(c.id) }));
            const cred = await navigator.credentials.create({ publicKey: o });
            const res = await post(submitUrl, { credential: asJson(cred), ...extra });
            if (res.redirect) window.location.assign(res.redirect);
            return res;
        },
        // Autofill: the email field offers this panel's passkeys as the person types.
        async autofill(optionsUrl, submitUrl, onError) {
            try {
                if (!window.PublicKeyCredential || !PublicKeyCredential.isConditionalMediationAvailable || !(await PublicKeyCredential.isConditionalMediationAvailable())) return;
                await this.use(optionsUrl, submitUrl, {}, 'conditional');
            } catch (e) {
                if (e && e.name !== 'AbortError' && e.name !== 'NotAllowedError' && onError) onError(why(e));
            }
        },
    };
})();
</script>
