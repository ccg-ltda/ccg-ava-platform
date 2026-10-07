/*
 * Ava web chat widget. Installed with:
 *   <script src="https://<ava>/widget/ava-widget.js" data-chatbot="<public key>" defer></script>
 * It asks Ava for the chatbot's identity (name, avatar) and the Workspace's appearance, draws a floating button and
 * a chat panel inside a shadow root (the host page's CSS cannot reach it), and sends each message to
 * /api/widget/<key>/messages. Everything that comes from the network is set as plain text, never parsed as markup.
 */
(function () {
    'use strict';

    var script = document.currentScript;
    var key = script && script.getAttribute('data-chatbot');

    if (!script || !key || window['__avaWidget_' + key]) return;
    window['__avaWidget_' + key] = true;

    var base = new URL(script.src, window.location.href).origin;
    var api = base + '/api/widget/' + encodeURIComponent(key);
    var MAX = 2000;

    function sessionId() {
        var storageKey = 'ava-widget-session-' + key;

        try {
            var saved = window.localStorage.getItem(storageKey);
            if (saved && /^[A-Za-z0-9_-]{8,64}$/.test(saved)) return saved;
        } catch (e) { /* storage blocked: the session lives as long as the page */ }

        var bytes = new Uint8Array(18);
        (window.crypto || window.msCrypto).getRandomValues(bytes);
        var id = Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');

        try { window.localStorage.setItem(storageKey, id); } catch (e) { /* see above */ }

        return id;
    }

    function readableOn(hex) {
        var value = /^#[0-9a-f]{6}$/i.test(hex) ? hex : '#2563eb';
        var r = parseInt(value.slice(1, 3), 16);
        var g = parseInt(value.slice(3, 5), 16);
        var b = parseInt(value.slice(5, 7), 16);

        return { color: value, text: (0.299 * r + 0.587 * g + 0.114 * b) > 160 ? '#0f172a' : '#ffffff' };
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text) node.textContent = text;
        return node;
    }

    var ICON = 'M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-5 4V6a2 2 0 0 1 2-2z';

    function icon(path) {
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('width', '26');
        svg.setAttribute('height', '26');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('aria-hidden', 'true');
        var p = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        p.setAttribute('d', path);
        svg.appendChild(p);
        return svg;
    }

    function avatar(config, className) {
        var holder = el('span', className);

        if (config.avatarUrl) {
            var img = el('img');
            img.src = config.avatarUrl;
            img.alt = '';
            holder.appendChild(img);
        } else {
            holder.appendChild(icon(ICON));
        }

        return holder;
    }

    function styles(brand, dark) {
        var bg = dark ? '#111827' : '#ffffff';
        var surface = dark ? '#1f2937' : '#f1f5f9';
        var ink = dark ? '#f1f5f9' : '#0f172a';
        var line = dark ? '#374151' : '#e2e8f0';

        return [
            ':host{all:initial}',
            '*{box-sizing:border-box;font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}',
            '.fab{position:fixed;right:20px;bottom:20px;z-index:2147483000;width:60px;height:60px;border:0;border-radius:50%;cursor:pointer;background:' + brand.color + ';color:' + brand.text + ';display:grid;place-items:center;box-shadow:0 8px 24px rgba(0,0,0,.25);overflow:hidden;padding:0}',
            '.fab:focus-visible,.send:focus-visible,.close:focus-visible{outline:3px solid ' + brand.color + ';outline-offset:3px}',
            '.fab img,.badge img{width:100%;height:100%;object-fit:cover}',
            '.panel{position:fixed;right:20px;bottom:92px;z-index:2147483000;width:370px;max-width:calc(100vw - 24px);height:540px;max-height:calc(100vh - 112px);display:none;flex-direction:column;background:' + bg + ';color:' + ink + ';border:1px solid ' + line + ';border-radius:16px;box-shadow:0 16px 48px rgba(0,0,0,.3);overflow:hidden}',
            '.panel.open{display:flex}',
            '.head{display:flex;align-items:center;gap:10px;padding:12px 14px;background:' + brand.color + ';color:' + brand.text + '}',
            '.badge{width:36px;height:36px;border-radius:50%;overflow:hidden;display:grid;place-items:center;background:rgba(255,255,255,.2);flex:none}',
            '.badge svg{width:20px;height:20px}',
            '.title{flex:1;min-width:0;font-weight:700;font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
            '.close{border:0;background:transparent;color:inherit;cursor:pointer;font-size:22px;line-height:1;padding:4px 8px;border-radius:8px}',
            '.log{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:8px;background:' + bg + '}',
            '.msg{max-width:85%;padding:9px 12px;border-radius:14px;font-size:14px;line-height:1.45;white-space:pre-wrap;overflow-wrap:anywhere}',
            '.msg.bot{align-self:flex-start;background:' + surface + ';color:' + ink + ';border-bottom-left-radius:4px}',
            '.msg.me{align-self:flex-end;background:' + brand.color + ';color:' + brand.text + ';border-bottom-right-radius:4px}',
            '.msg.err{align-self:flex-start;background:transparent;color:#dc2626;border:1px solid #dc2626;font-size:13px}',
            '.msg.typing{color:' + (dark ? '#94a3b8' : '#64748b') + ';font-style:italic}',
            'form{display:flex;gap:8px;padding:10px;border-top:1px solid ' + line + ';background:' + bg + '}',
            'textarea{flex:1;resize:none;border:1px solid ' + line + ';border-radius:12px;padding:9px 11px;font-size:14px;line-height:1.4;background:' + surface + ';color:' + ink + ';max-height:96px}',
            'textarea:focus{outline:2px solid ' + brand.color + ';outline-offset:0}',
            '.send{border:0;border-radius:12px;padding:0 14px;font-weight:700;font-size:14px;cursor:pointer;background:' + brand.color + ';color:' + brand.text + '}',
            '.send:disabled{opacity:.5;cursor:not-allowed}',
            '@media (max-width:480px){.panel{right:0;bottom:0;width:100vw;max-width:100vw;height:100vh;max-height:100vh;border-radius:0}.fab{right:14px;bottom:14px}.panel.open~.fab{display:none}}',
            '@media (prefers-reduced-motion:no-preference){.panel.open{animation:rise .18s ease-out}@keyframes rise{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}}'
        ].join('');
    }

    function mount(config) {
        var brand = readableOn(config.primaryColor);
        var dark = config.appearance === 'dark' || (config.appearance === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
        var host = document.createElement('div');
        host.setAttribute('data-ava-widget', key);
        var root = host.attachShadow({ mode: 'open' });
        root.appendChild(el('style')).textContent = styles(brand, dark);

        var fab = el('button', 'fab');
        fab.type = 'button';
        fab.setAttribute('aria-label', 'Abrir chat con ' + config.name);
        fab.setAttribute('aria-expanded', 'false');
        fab.appendChild(config.avatarUrl ? avatar(config, 'badge') : icon(ICON));

        var panel = el('section', 'panel');
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', 'Chat con ' + config.name);

        var head = el('div', 'head');
        head.appendChild(avatar(config, 'badge'));
        head.appendChild(el('div', 'title', config.name));
        var close = el('button', 'close', '×');
        close.type = 'button';
        close.setAttribute('aria-label', 'Cerrar chat');
        head.appendChild(close);

        var log = el('div', 'log');
        log.setAttribute('role', 'log');
        log.setAttribute('aria-live', 'polite');

        var form = el('form');
        var input = el('textarea');
        input.rows = 1;
        input.maxLength = MAX;
        input.placeholder = 'Escribe tu mensaje';
        input.setAttribute('aria-label', 'Mensaje');
        var send = el('button', 'send', 'Enviar');
        send.type = 'submit';
        form.appendChild(input);
        form.appendChild(send);

        panel.appendChild(head);
        panel.appendChild(log);
        panel.appendChild(form);
        root.appendChild(panel);
        root.appendChild(fab);
        document.body.appendChild(host);

        var session = sessionId();
        var busy = false;

        function add(kind, text) {
            var node = el('div', 'msg ' + kind, text);
            log.appendChild(node);
            log.scrollTop = log.scrollHeight;
            return node;
        }

        function setOpen(open) {
            panel.classList.toggle('open', open);
            fab.setAttribute('aria-expanded', String(open));
            if (open) input.focus(); else fab.focus();
        }

        fab.addEventListener('click', function () { setOpen(!panel.classList.contains('open')); });
        close.addEventListener('click', function () { setOpen(false); });
        panel.addEventListener('keydown', function (event) { if (event.key === 'Escape') setOpen(false); });
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); form.requestSubmit(); }
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var text = input.value.trim();
            if (!text || busy) return;

            busy = true;
            send.disabled = true;
            input.value = '';
            add('me', text);
            var typing = add('bot typing', 'Escribiendo…');

            fetch(api + '/messages', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ session_id: session, message: text })
            }).then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (body) {
                    typing.remove();
                    if (response.ok && typeof body.reply === 'string') add('bot', body.reply);
                    else add('err', typeof body.message === 'string' ? body.message : 'No se pudo enviar el mensaje. Inténtalo de nuevo.');
                });
            }).catch(function () {
                typing.remove();
                add('err', 'No hay conexión con el asistente. Inténtalo de nuevo.');
            }).then(function () {
                busy = false;
                send.disabled = false;
                input.focus();
            });
        });
    }

    function start() {
        fetch(api + '/config', { headers: { 'Accept': 'application/json' } })
            .then(function (response) { if (!response.ok) throw new Error(String(response.status)); return response.json(); })
            .then(mount)
            .catch(function (error) { if (window.console) console.warn('[Ava widget] no disponible (' + error.message + ')'); });
    }

    if (document.body) start(); else document.addEventListener('DOMContentLoaded', start);
})();
