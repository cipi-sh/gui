{{-- The browser side of a dump upload (Restore dialog of the Databases page): the file is
     cut into chunks and posted one at a time to DbUploadController, which hands each on
     to the server. Used as x-data="cipiDumpUpload({...})". Nothing here trusts the file
     name or the size: the server checks both, and the content against the name. --}}
<script data-cipi-dump-upload>
window.cipiDumpUpload = function (opts) {
    return {
        file: null,
        error: '',
        state: 'idle',          // idle | uploading | restoring
        sent: 0,
        uploadId: null,
        controller: null,
        run: 0,                 // the upload under way; a stop, or a newer upload, makes the earlier one stale

        get busy() { return this.state === 'uploading' || this.state === 'restoring'; },
        get percent() { return this.file && this.file.size > 0 ? Math.min(100, Math.round(this.sent / this.file.size * 100)) : 0; },
        get tooLarge() { return !!this.file && opts.maxSize > 0 && this.file.size > opts.maxSize; },
        get wrongType() { return !!this.file && !/\.sql(\.gz)?$/i.test(this.file.name); },
        get ready() { return !!this.file && !this.tooLarge && !this.wrongType && !this.busy; },

        human(bytes) {
            if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(bytes >= 10737418240 ? 0 : 1) + ' GB';
            if (bytes >= 1048576) return (bytes / 1048576).toFixed(bytes >= 104857600 ? 0 : 1) + ' MB';
            if (bytes >= 1024) return Math.round(bytes / 1024) + ' KB';
            return bytes + ' B';
        },

        pick(event) {
            this.file = event.target.files && event.target.files[0] ? event.target.files[0] : null;
            this.error = '';
            this.sent = 0;
            this.state = 'idle';
        },

        async post(url, body, isForm) {
            var headers = { 'Accept': 'application/json', 'X-CSRF-TOKEN': opts.csrf, 'X-Requested-With': 'XMLHttpRequest' };
            if (!isForm) headers['Content-Type'] = 'application/json';
            var response = await fetch(url, {
                method: 'POST', headers: headers, credentials: 'same-origin',
                body: isForm ? body : JSON.stringify(body), signal: this.controller ? this.controller.signal : undefined,
            });
            var json = null;
            try { json = await response.json(); } catch (e) { json = null; }
            if (!response.ok) {
                var message = response.status === 419 ? 'The page expired. Reload it and try again.'
                    : json && (json.error || json.message) ? (json.error || json.message)
                    : (response.status === 413 ? 'The panel refused the chunk as too large (HTTP 413). Lower CIPI_GUI_DB_UPLOAD_CHUNK_MB.'
                    : 'The upload failed (HTTP ' + response.status + ').');
                var failure = new Error(message);
                failure.status = response.status;
                throw failure;
            }
            return json && json.data ? json.data : {};
        },

        async send() {
            if (!this.ready) return;
            this.error = '';
            this.sent = 0;
            this.state = 'uploading';
            this.controller = new AbortController();
            var file = this.file;
            // A stopped upload is not over at once: a chunk already answered still
            // resolves, and the abort of the one in flight arrives a moment later.
            // Whatever a stale run finds after an await, it leaves alone — the
            // progress, the error, and above all the restore.
            var run = ++this.run;
            var self = this;
            var stale = function () { return run !== self.run; };
            try {
                var started = await this.post(opts.startUrl, { server: opts.server, filename: file.name, size: file.size }, false);
                if (stale()) { this.forget(started.upload_id); return; }
                this.uploadId = started.upload_id;
                var chunkSize = Math.max(1, Math.min(started.chunk_size || opts.chunkSize, opts.chunkSize));
                var url = opts.chunkUrl.replace('__ID__', encodeURIComponent(this.uploadId));
                var index = 0;
                for (var offset = 0; offset < file.size; offset += chunkSize, index++) {
                    await this.sendChunk(url, file.slice(offset, Math.min(file.size, offset + chunkSize)), index);
                    if (stale()) return;
                    this.sent = Math.min(file.size, offset + chunkSize);
                }
                this.state = 'restoring';
                var id = this.uploadId;
                this.uploadId = null;       // the server's from here on: the restore job deletes it
                await this.$wire.restoreFromUpload(id);
                if (stale()) return;
                this.state = 'idle';
            } catch (failure) {
                if (stale()) return;
                this.state = 'idle';
                this.error = failure && failure.name === 'AbortError' ? '' : (failure && failure.message ? failure.message : 'The upload failed.');
                this.discard();
            }
        },

        // One chunk, tried up to three times. A 409 that says the server already
        // expects the next one means an earlier try did arrive: carry on.
        async sendChunk(url, blob, index) {
            for (var attempt = 1; ; attempt++) {
                var form = new FormData();
                form.append('server', opts.server);
                form.append('index', index);
                form.append('chunk', blob, 'chunk.bin');
                try {
                    await this.post(url, form, true);
                    return;
                } catch (failure) {
                    if (failure.name === 'AbortError') throw failure;
                    if (failure.status === 409 && new RegExp('next one is ' + (index + 1) + '\\b').test(failure.message || '')) return;
                    var retry = !failure.status || failure.status >= 500;
                    if (!retry || attempt >= 3) throw failure;
                    await new Promise(function (resolve) { setTimeout(resolve, 800 * attempt); });
                }
            }
        },

        // The dialog was closed (Escape, the X) while a dump was on its way: stop it.
        destroy() { this.cancel(); },

        cancel() {
            if (this.state !== 'uploading') return;
            this.run++;             // the run under way is stale from here on
            if (this.controller) this.controller.abort();
            this.discard();
            this.sent = 0;
            this.state = 'idle';
        },

        discard() {
            if (!this.uploadId) return;
            var id = this.uploadId;
            this.uploadId = null;
            this.forget(id);
        },

        // Tell the server to delete an upload nobody will finish.
        forget(id) {
            if (!id) return;
            fetch(opts.cancelUrl.replace('__ID__', encodeURIComponent(id)) + '?server=' + encodeURIComponent(opts.server), {
                method: 'DELETE', credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': opts.csrf, 'X-Requested-With': 'XMLHttpRequest' },
            }).catch(function () {});
        },
    };
};
</script>
