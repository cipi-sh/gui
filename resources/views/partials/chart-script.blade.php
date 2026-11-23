{{--
    Charts of the Metrics tab (4.3.0). No library and no build step, like the
    rest of the panel: an element with data-cipi-chart='{…}' (Support\Metrics::chart)
    becomes an SVG that fills its width, with a crosshair and a tooltip, deploy
    and incident markers, a threshold line. Colours are the theme's tokens, read
    at draw time: switching theme redraws. Elements that Livewire adds later (a
    poll, a range change) are found by a MutationObserver.
--}}
<script>
(function () {
    if (window.CipiChart) return;
    var NS = 'http://www.w3.org/2000/svg';
    var H = 200, PAD = { t: 12, r: 12, b: 24, l: 44 };
    var TOKENS = { accent: '--accent', info: '--info', env: '--env', danger: '--danger', warn: '--warn', success: '--success' };
    var MARK = { deploy: '--info', crit: '--danger', warn: '--warn', ok: '--success' };

    function css(el, name) { return getComputedStyle(el).getPropertyValue(name).trim() || '#888'; }
    function svg(tag, attrs, parent) {
        var n = document.createElementNS(NS, tag);
        for (var k in attrs) n.setAttribute(k, attrs[k]);
        if (parent) parent.appendChild(n);
        return n;
    }
    function nice(max) {
        if (!(max > 0)) return 1;
        var e = Math.pow(10, Math.floor(Math.log10(max))), f = max / e;
        return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * e;
    }
    function fmt(v, unit, short) {
        if (v === null || v === undefined || isNaN(v)) return '—';
        var a = Math.abs(v);
        if (unit === '%') return (a >= 10 || short ? Math.round(v) : v.toFixed(1)) + '%';
        if (unit === 'ms') return a >= 1000 ? (v / 1000).toFixed(a >= 10000 ? 0 : 1) + ' s' : Math.round(v) + ' ms';
        if (unit === 'mb') return a >= 1024 ? (v / 1024).toFixed(1) + ' GB' : Math.round(v) + ' MB';
        if (unit === 'bps') {
            var u = ['B/s', 'KB/s', 'MB/s', 'GB/s'], i = 0;
            while (a >= 1024 && i < 3) { a /= 1024; v /= 1024; i++; }
            return (i === 0 || a >= 100 ? Math.round(v) : v.toFixed(1)) + ' ' + u[i];
        }
        if (a >= 10000) return (v / 1000).toFixed(a >= 100000 ? 0 : 1) + 'k';
        if (a >= 100 || short) return String(Math.round(v * (a < 10 ? 10 : 1)) / (a < 10 ? 10 : 1));
        return String(Math.round(v * 100) / 100);
    }
    function pad2(n) { return (n < 10 ? '0' : '') + n; }
    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    function tlabel(t, span, full) {
        var d = new Date(t * 1000), hm = pad2(d.getHours()) + ':' + pad2(d.getMinutes()), day = d.getDate() + ' ' + MONTHS[d.getMonth()];
        if (full) return day + ', ' + hm;
        return span <= 36 * 3600 ? hm : day;
    }

    function draw(el) {
        var cfg;
        try { cfg = JSON.parse(el.getAttribute('data-cipi-chart') || '{}'); } catch (e) { return; }
        var W = Math.max(240, Math.floor(el.clientWidth || 600));
        var unit = cfg.unit || '', step = cfg.step || 60, series = cfg.series || [];
        var all = [];
        series.forEach(function (s) { (s.points || []).forEach(function (p) { all.push(p); }); });
        el.innerHTML = '';
        el.classList.add('chart-ready');
        if (!all.length) {
            var empty = document.createElement('div');
            empty.className = 'chart-empty';
            empty.textContent = el.getAttribute('data-empty') || 'No data in this range yet.';
            el.appendChild(empty);
            return;
        }

        var tMin = cfg.from || Math.min.apply(null, all.map(function (p) { return p[0]; }));
        var tMax = cfg.to || Math.max.apply(null, all.map(function (p) { return p[0]; }));
        if (tMax <= tMin) tMax = tMin + step;
        var vMax = 0;
        series.forEach(function (s) {
            (s.points || []).forEach(function (p) { vMax = Math.max(vMax, s.band ? p[2] : p[1]); });
        });
        if (cfg.threshold) vMax = Math.max(vMax, cfg.threshold.value);
        // Four gridlines on round values; whole numbers for counts; a percentage of a total stops at 100.
        var tick = nice(vMax * 1.08 / 4);
        if (unit === 'count' && tick < 1) tick = 1;
        var yMax = tick * 4;
        if (cfg.yMax && yMax > cfg.yMax) yMax = cfg.yMax;

        var iw = W - PAD.l - PAD.r, ih = H - PAD.t - PAD.b;
        var x = function (t) { return PAD.l + (t - tMin) / (tMax - tMin) * iw; };
        var y = function (v) { return PAD.t + ih - Math.min(v, yMax) / yMax * ih; };

        var root = svg('svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': el.getAttribute('aria-label') || 'chart', class: 'chart-svg' }, el);
        var defs = svg('defs', {}, root);
        var grid = css(el, '--border-subtle'), axis = css(el, '--text-subtle');

        // grid + y labels
        for (var i = 0; i <= 4; i++) {
            var gv = yMax / 4 * i, gy = y(gv);
            svg('line', { x1: PAD.l, x2: W - PAD.r, y1: gy, y2: gy, stroke: grid, 'stroke-width': 1, 'shape-rendering': 'crispEdges' }, root);
            var tl = svg('text', { x: PAD.l - 8, y: gy + 3.5, 'text-anchor': 'end', class: 'chart-axis', fill: axis }, root);
            tl.textContent = fmt(gv, unit, true);
        }
        // x labels on round local times: every 5 min … every week, as many as fit
        var span = tMax - tMin, fit = Math.max(2, Math.floor(iw / 90));
        var every = [300, 600, 900, 1800, 3600, 7200, 10800, 21600, 43200, 86400, 172800, 345600, 604800].filter(function (v) { return span / v <= fit; })[0] || 604800;
        var tz = -new Date(tMin * 1000).getTimezoneOffset() * 60;
        for (var tt = Math.ceil((tMin + tz) / every) * every - tz; tt <= tMax; tt += every) {
            var px = x(tt);
            if (px < PAD.l + 14 || px > W - PAD.r - 14) continue;
            var xl = svg('text', { x: px, y: H - 6, 'text-anchor': 'middle', class: 'chart-axis', fill: axis }, root);
            var midnight = new Date(tt * 1000).getHours() === 0 && new Date(tt * 1000).getMinutes() === 0;
            xl.textContent = every >= 86400 || midnight ? tlabel(tt, Infinity, false) : tlabel(tt, 0, false);
            if (midnight && every < 86400) xl.setAttribute('font-weight', 600);
        }

        // threshold
        if (cfg.threshold && cfg.threshold.value > 0) {
            var ty = y(cfg.threshold.value), tc = css(el, '--warn');
            svg('line', { x1: PAD.l, x2: W - PAD.r, y1: ty, y2: ty, stroke: tc, 'stroke-width': 1, 'stroke-dasharray': '4 4' }, root);
            var tlab = svg('text', { x: W - PAD.r - 2, y: ty - 4, 'text-anchor': 'end', class: 'chart-axis', fill: tc }, root);
            tlab.textContent = cfg.threshold.label || '';
        }

        // markers
        (cfg.markers || []).forEach(function (m) {
            if (m.t < tMin || m.t > tMax) return;
            var mc = css(el, MARK[m.kind] || '--info'), mx = x(m.t);
            var g = svg('g', { class: 'chart-marker' }, root);
            svg('line', { x1: mx, x2: mx, y1: PAD.t, y2: PAD.t + ih, stroke: mc, 'stroke-width': 1, 'stroke-dasharray': '2 3', opacity: 0.7 }, g);
            svg('circle', { cx: mx, cy: PAD.t + 1, r: 3, fill: mc }, g);
            var title = svg('title', {}, g);
            title.textContent = tlabel(m.t, 0, true) + ' — ' + m.label;
        });

        // series: split where minutes are missing (the server was down, the cron off)
        var gapAt = step * 2.5, barW = Math.max(1, Math.min(10, iw / ((tMax - tMin) / step) * 0.7));
        series.forEach(function (s, si) {
            var col = css(el, TOKENS[s.color] || '--accent'), pts = s.points || [];
            if (!pts.length) return;
            if (s.kind === 'bar') {
                pts.forEach(function (p) {
                    if (!(p[1] > 0)) return;
                    var bx = x(p[0]) - barW / 2, by = y(p[1]);
                    svg('rect', { x: bx, y: by, width: barW, height: PAD.t + ih - by, fill: col, opacity: 0.85, rx: 1 }, root);
                });
                return;
            }
            var segs = [[]];
            pts.forEach(function (p, k) {
                if (k > 0 && p[0] - pts[k - 1][0] > gapAt) segs.push([]);
                segs[segs.length - 1].push(p);
            });
            var gid = 'g' + Math.random().toString(36).slice(2, 8);
            var grad = svg('linearGradient', { id: gid, x1: 0, x2: 0, y1: 0, y2: 1 }, defs);
            svg('stop', { offset: '0%', 'stop-color': col, 'stop-opacity': s.kind === 'line' ? 0 : 0.28 }, grad);
            svg('stop', { offset: '100%', 'stop-color': col, 'stop-opacity': 0 }, grad);
            segs.forEach(function (seg) {
                if (!seg.length) return;
                var line = seg.map(function (p, k) { return (k ? 'L' : 'M') + x(p[0]).toFixed(1) + ',' + y(p[1]).toFixed(1); }).join('');
                if (s.band && seg.length > 1) {
                    var top = seg.map(function (p, k) { return (k ? 'L' : 'M') + x(p[0]).toFixed(1) + ',' + y(p[2]).toFixed(1); }).join('');
                    var bottom = seg.slice().reverse().map(function (p) { return 'L' + x(p[0]).toFixed(1) + ',' + y(p[1]).toFixed(1); }).join('');
                    svg('path', { d: top + bottom + 'Z', fill: col, opacity: 0.12 }, root);
                }
                if (s.kind !== 'line' && seg.length > 1) {
                    svg('path', { d: line + 'L' + x(seg[seg.length - 1][0]).toFixed(1) + ',' + (PAD.t + ih) + 'L' + x(seg[0][0]).toFixed(1) + ',' + (PAD.t + ih) + 'Z', fill: 'url(#' + gid + ')' }, root);
                }
                if (seg.length === 1) {
                    svg('circle', { cx: x(seg[0][0]), cy: y(seg[0][1]), r: 2, fill: col }, root);
                } else {
                    svg('path', { d: line, fill: 'none', stroke: col, 'stroke-width': 1.75, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }, root);
                }
            });
        });

        // crosshair + tooltip
        var cross = svg('line', { y1: PAD.t, y2: PAD.t + ih, stroke: axis, 'stroke-width': 1, opacity: 0, 'pointer-events': 'none' }, root);
        var dots = series.map(function (s) { return svg('circle', { r: 3.5, fill: css(el, TOKENS[s.color] || '--accent'), stroke: css(el, '--bg-elevated'), 'stroke-width': 2, opacity: 0, 'pointer-events': 'none' }, root); });
        var tip = document.createElement('div');
        tip.className = 'chart-tooltip';
        tip.hidden = true;
        el.appendChild(tip);
        var hit = svg('rect', { x: PAD.l, y: PAD.t, width: iw, height: ih, fill: 'transparent' }, root);

        function nearest(pts, t) {
            var best = null, bd = Infinity;
            for (var k = 0; k < pts.length; k++) { var d = Math.abs(pts[k][0] - t); if (d < bd) { bd = d; best = pts[k]; } }
            return bd <= gapAt ? best : null;
        }
        function move(clientX) {
            var r = root.getBoundingClientRect(), px = (clientX - r.left) * (W / r.width);
            var t = tMin + (px - PAD.l) / iw * (tMax - tMin);
            var html = '', anchor = null;
            series.forEach(function (s, si) {
                var p = nearest(s.points || [], t);
                if (!p) { dots[si].setAttribute('opacity', 0); return; }
                anchor = anchor || p[0];
                dots[si].setAttribute('cx', x(p[0])); dots[si].setAttribute('cy', y(p[1])); dots[si].setAttribute('opacity', s.kind === 'bar' ? 0 : 1);
                html += '<div class="chart-tip-row"><span class="chart-swatch" style="background:' + css(el, TOKENS[s.color] || '--accent') + '"></span>'
                    + '<span>' + s.name + '</span><b>' + fmt(p[1], unit) + '</b>'
                    + (s.band && p[2] > p[1] ? '<span class="chart-tip-max">max ' + fmt(p[2], unit) + '</span>' : '') + '</div>';
            });
            if (anchor === null) { leave(); return; }
            (cfg.markers || []).forEach(function (m) {
                if (Math.abs(m.t - anchor) <= Math.max(step, (tMax - tMin) / 120)) html += '<div class="chart-tip-mark is-' + m.kind + '">' + m.label.replace(/</g, '&lt;') + '</div>';
            });
            cross.setAttribute('x1', x(anchor)); cross.setAttribute('x2', x(anchor)); cross.setAttribute('opacity', 0.5);
            tip.innerHTML = '<div class="chart-tip-time">' + tlabel(anchor, 0, true) + '</div>' + html;
            tip.hidden = false;
            var left = x(anchor) / W * r.width, tw = tip.offsetWidth;
            tip.style.left = Math.max(0, Math.min(r.width - tw, left + 12 > r.width - tw ? left - tw - 12 : left + 12)) + 'px';
        }
        function leave() { cross.setAttribute('opacity', 0); dots.forEach(function (d) { d.setAttribute('opacity', 0); }); tip.hidden = true; }
        hit.addEventListener('mousemove', function (e) { move(e.clientX); });
        hit.addEventListener('mouseleave', leave);
        hit.addEventListener('touchstart', function (e) { if (e.touches[0]) move(e.touches[0].clientX); }, { passive: true });
        hit.addEventListener('touchmove', function (e) { if (e.touches[0]) move(e.touches[0].clientX); }, { passive: true });
        hit.addEventListener('touchend', leave);
    }

    var resize = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(function (entries) {
        entries.forEach(function (en) {
            var el = en.target, w = Math.floor(el.clientWidth);
            if (w && w !== +el.getAttribute('data-w')) { el.setAttribute('data-w', w); draw(el); }
        });
    }) : null;

    function mount(root) {
        var els = (root && root.querySelectorAll) ? root.querySelectorAll('[data-cipi-chart]') : [];
        if (root && root.hasAttribute && root.hasAttribute('data-cipi-chart')) els = [root].concat([].slice.call(els));
        [].forEach.call(els, function (el) {
            if (el.__cipiChart === el.getAttribute('data-cipi-chart')) return;
            el.__cipiChart = el.getAttribute('data-cipi-chart');
            el.setAttribute('data-w', Math.floor(el.clientWidth));
            draw(el);
            if (resize) resize.observe(el);
        });
    }
    function redrawAll() {
        [].forEach.call(document.querySelectorAll('[data-cipi-chart]'), function (el) { el.__cipiChart = null; });
        mount(document);
    }

    window.CipiChart = { mount: mount, redraw: redrawAll, format: fmt };

    function start() {
        mount(document);
        new MutationObserver(function (muts) {
            muts.forEach(function (m) {
                if (m.type === 'attributes') { mount(m.target); return; }
                [].forEach.call(m.addedNodes, function (n) { if (n.nodeType === 1) mount(n); });
            });
        }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-cipi-chart'] });
        // The theme toggle sets data-theme on <html>: the colours come from it.
        new MutationObserver(redrawAll).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
</script>
