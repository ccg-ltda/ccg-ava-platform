/*
 * Ava web widget. Installed with:
 *   <script src="https://<ava>/widget/ava-widget.js" data-chatbot="<public key>" defer></script>
 * It asks Ava for the public configuration of that channel (the chatbot's identity and the look the client chose in
 * Ava) every time the page loads, so changing the look never needs a new script. Depending on the channel it draws:
 *   - a floating button and a chat panel (web), whose messages go to /api/widget/<key>/messages (the same endpoint
 *     returns, on GET, what a human agent wrote to this visitor while the panel is open), or
 *   - a floating button that opens a WhatsApp conversation (whatsapp).
 * Everything is drawn inside a shadow root (the host page's CSS cannot reach it) and everything that comes from the
 * network is set as plain text, never parsed as markup. The same code draws the live preview in Ava's interface
 * (`window.AvaWidget.mount`, preview mode), so the preview is the real widget.
 */
(function () {
    'use strict';

    var MAX = 2000;
    var DEFAULT_COLOR = '#2563eb';
    var CHAT_ICON = 'M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-5 4V6a2 2 0 0 1 2-2z';
    var WHATSAPP_ICON = 'M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.16-.17.2-.35.22-.64.07-.3-.15-1.26-.46-2.39-1.47-.88-.79-1.48-1.76-1.65-2.06-.17-.3-.02-.46.13-.6.13-.14.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.61-.92-2.2-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.21 3.07c.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.7.63.71.23 1.36.2 1.87.12.57-.08 1.76-.72 2-1.41.25-.7.25-1.29.17-1.41-.07-.13-.27-.2-.57-.35M12.05 21.8h-.01a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.74.98 1-3.65-.24-.37a9.86 9.86 0 0 1-1.51-5.26c0-5.45 4.44-9.88 9.89-9.88 2.64 0 5.12 1.03 6.99 2.9a9.83 9.83 0 0 1 2.89 6.99c0 5.45-4.44 9.88-9.88 9.88M20.46 3.49A11.82 11.82 0 0 0 12.05 0C5.5 0 .16 5.34.16 11.89c0 2.1.55 4.14 1.59 5.95L.06 24l6.3-1.65a11.88 11.88 0 0 0 5.68 1.45h.01c6.55 0 11.89-5.34 11.89-11.89 0-3.18-1.24-6.17-3.48-8.42';

    /*
     * What each number the client can choose looks like (limits mirror config/chatbots.php `appearance`; a value outside
     * them is pulled back in, so a stale or hand-edited configuration never breaks the page).
     */
    var LIMITS = { size: [48, 72, 60], widgetSize: [320, 420, 370], shape: [0, 50, 50], shadow: [0, 4, 2], radius: [0, 28, 16] };
    /* Opacity of the button shadow per level; blur and offset grow with the level. */
    var SHADOW_ALPHA = [0, 0.15, 0.25, 0.45, 0.55];
    var AUTO_OPEN_MS = 5000;
    var POLL_MS = 5000;
    /* What the visitor is told when the person answering changes (the server decides it; the text is the widget's own). */
    var NOTES = {
        pending: 'Un agente te atenderá en breve.',
        human: 'Un agente se unió a la conversación.',
        resolved: 'La conversación fue marcada como resuelta.',
        ai: 'El asistente vuelve a atenderte.'
    };

    function number(limits, value) {
        var parsed = parseInt(value, 10);

        return isNaN(parsed) ? limits[2] : Math.max(limits[0], Math.min(limits[1], parsed));
    }

    function hexOr(hex, fallback) {
        return /^#[0-9a-f]{6}$/i.test(hex) ? hex : fallback;
    }

    function readableOn(hex) {
        var r = parseInt(hex.slice(1, 3), 16);
        var g = parseInt(hex.slice(3, 5), 16);
        var b = parseInt(hex.slice(5, 7), 16);

        return (0.299 * r + 0.587 * g + 0.114 * b) > 160 ? '#0f172a' : '#ffffff';
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text) node.textContent = text;
        return node;
    }

    function icon(path, size, filled) {
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('width', String(size));
        svg.setAttribute('height', String(size));
        svg.setAttribute('aria-hidden', 'true');
        if (filled) {
            svg.setAttribute('fill', 'currentColor');
        } else {
            svg.setAttribute('fill', 'none');
            svg.setAttribute('stroke', 'currentColor');
            svg.setAttribute('stroke-width', '2');
            svg.setAttribute('stroke-linecap', 'round');
            svg.setAttribute('stroke-linejoin', 'round');
        }
        var p = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        p.setAttribute('d', path);
        svg.appendChild(p);
        return svg;
    }

    /** Corner radius of a square of `side` px whose rounding is `percent` of the side (50 = circle). */
    function corner(side, percent) {
        return percent >= 50 ? '999px' : Math.round(side * percent / 100) + 'px';
    }

    /** The look of a configuration as plain numbers and safe CSS values. */
    function look(config) {
        var style = config.style || {};
        var color = hexOr(style.primaryColor || config.primaryColor, DEFAULT_COLOR);
        var button = number(LIMITS.size, style.size);
        var width = number(LIMITS.widgetSize, style.widgetSize);
        var shape = number(LIMITS.shape, style.shape);
        var level = number(LIMITS.shadow, style.shadow);

        return {
            color: color,
            text: hexOr(style.textColor, readableOn(color)),
            size: { button: button, icon: Math.round(22 + (button - 48) / 3), font: button < 56 ? 13 : button < 68 ? 14 : 16 },
            panel: { width: width, height: 2 * width - 200 },
            shape: shape,
            buttonRadius: corner(button, shape),
            shadow: level === 0 ? 'none' : '0 ' + level * 4 + 'px ' + level * 12 + 'px rgba(0,0,0,' + SHADOW_ALPHA[level] + ')',
            side: style.position === 'bottom-left' ? 'left' : 'right',
            panelRadius: number(LIMITS.radius, style.radius) + 'px',
            label: typeof style.buttonText === 'string' ? style.buttonText : '',
            useAvatar: style.icon !== 'channel' && !!config.avatarUrl
        };
    }

    function styles(l, dark, preview) {
        var bg = dark ? '#111827' : '#ffffff';
        var surface = dark ? '#1f2937' : '#f1f5f9';
        var ink = dark ? '#f1f5f9' : '#0f172a';
        var line = dark ? '#374151' : '#e2e8f0';
        var pos = preview ? 'absolute' : 'fixed';
        var gap = l.side + ':20px;';
        var withText = l.label !== '';
        var inner = withText ? l.size.button - 16 : l.size.button;

        return [
            ':host{all:initial}',
            '*{box-sizing:border-box;font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}',
            '.fab{position:' + pos + ';' + gap + 'bottom:20px;z-index:2147483000;height:' + l.size.button + 'px;min-width:' + l.size.button + 'px;max-width:calc(100% - 40px);border:0;border-radius:' + l.buttonRadius + ';cursor:pointer;background:' + l.color + ';color:' + l.text + ';display:flex;align-items:center;justify-content:center;gap:8px;box-shadow:' + l.shadow + ';overflow:hidden;padding:' + (withText ? '0 18px 0 8px' : '0') + ';text-decoration:none;font-size:' + l.size.font + 'px;font-weight:700;line-height:1}',
            (preview ? '.fab,.panel{pointer-events:auto}' : '') + '.fab:focus-visible,.send:focus-visible,.close:focus-visible{outline:3px solid ' + l.color + ';outline-offset:3px}',
            '.fab .label{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
            '.mark{width:' + inner + 'px;height:' + inner + 'px;display:grid;place-items:center;flex:none;overflow:hidden;border-radius:' + corner(inner, l.shape) + '}',
            '.mark img,.badge img{width:100%;height:100%;object-fit:cover}',
            '.panel{position:' + pos + ';' + gap + 'bottom:' + (20 + l.size.button + 12) + 'px;z-index:2147483000;width:' + l.panel.width + 'px;max-width:calc(100% - 24px);height:' + l.panel.height + 'px;max-height:calc(100% - ' + (20 + l.size.button + 12 + 12) + 'px);display:none;flex-direction:column;background:' + bg + ';color:' + ink + ';border:1px solid ' + line + ';border-radius:' + l.panelRadius + ';box-shadow:0 16px 48px rgba(0,0,0,.3);overflow:hidden}',
            '.panel.open{display:flex}',
            '.head{display:flex;align-items:center;gap:10px;padding:12px 14px;background:' + l.color + ';color:' + l.text + '}',
            '.badge{width:36px;height:36px;border-radius:50%;overflow:hidden;display:grid;place-items:center;background:rgba(255,255,255,.2);flex:none}',
            '.title{flex:1;min-width:0;font-weight:700;font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
            '.close{border:0;background:transparent;color:inherit;cursor:pointer;font-size:22px;line-height:1;padding:4px 8px;border-radius:8px}',
            '.log{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:8px;background:' + bg + '}',
            '.msg{max-width:85%;padding:9px 12px;border-radius:14px;font-size:14px;line-height:1.45;white-space:pre-wrap;overflow-wrap:anywhere}',
            '.msg.bot{align-self:flex-start;background:' + surface + ';color:' + ink + ';border-bottom-left-radius:4px}',
            '.msg.me{align-self:flex-end;background:' + l.color + ';color:' + l.text + ';border-bottom-right-radius:4px}',
            '.msg.agent{align-self:flex-start;background:' + surface + ';color:' + ink + ';border-left:3px solid ' + l.color + ';border-bottom-left-radius:4px}',
            '.msg.note{align-self:center;max-width:100%;padding:2px 8px;background:transparent;color:' + (dark ? '#94a3b8' : '#64748b') + ';font-size:12px;text-align:center}',
            '.msg.err{align-self:flex-start;background:transparent;color:#dc2626;border:1px solid #dc2626;font-size:13px}',
            '.msg.typing{color:' + (dark ? '#94a3b8' : '#64748b') + ';font-style:italic}',
            'form{display:flex;gap:8px;padding:10px;border-top:1px solid ' + line + ';background:' + bg + '}',
            'textarea{flex:1;resize:none;border:1px solid ' + line + ';border-radius:12px;padding:9px 11px;font-size:14px;line-height:1.4;background:' + surface + ';color:' + ink + ';max-height:96px}',
            'textarea:focus{outline:2px solid ' + l.color + ';outline-offset:0}',
            '.send{border:0;border-radius:12px;padding:0 14px;font-weight:700;font-size:14px;cursor:pointer;background:' + l.color + ';color:' + l.text + '}',
            '.send:disabled,textarea:disabled{opacity:.5;cursor:not-allowed}',
            preview ? '' : '@media (max-width:480px){.panel{left:0;right:0;bottom:0;width:100vw;max-width:100vw;height:100vh;max-height:100vh;border-radius:0}.fab{' + l.side + ':14px;bottom:14px}.panel.open~.fab{display:none}}',
            '@media (prefers-reduced-motion:no-preference){.panel.open{animation:rise .18s ease-out}@keyframes rise{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}}'
        ].join('');
    }

    function mark(config, l, channelIconPath, filled) {
        var holder = el('span', 'mark');

        if (l.useAvatar) {
            var img = el('img');
            img.src = config.avatarUrl;
            img.alt = '';
            holder.appendChild(img);
        } else {
            holder.appendChild(icon(channelIconPath, l.size.icon, filled));
        }

        return holder;
    }

    function isDark(config) {
        return config.appearance === 'dark' || (config.appearance === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    }

    function sessionId(key) {
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

    /**
     * Draws the widget for `config` and returns { update(config), setOpen(open), destroy() }.
     * options: key (public key), api (base URL of the public endpoints), container (preview: where to draw), preview.
     */
    function mount(config, options) {
        options = options || {};
        var preview = !!options.preview;
        var host = document.createElement('div');
        host.setAttribute('data-ava-widget', options.key || 'preview');
        if (preview) host.style.cssText = 'position:absolute;inset:0;pointer-events:none';
        var root = host.attachShadow({ mode: 'open' });
        (preview ? options.container : document.body).appendChild(host);

        var current = config;
        var open = !!options.open;
        var session = preview ? null : sessionId(options.key);
        var busy = false;
        var log = null;
        var panel = null;
        var fab = null;
        var autoTimer = null;
        var pollTimer = null;
        var handling = null;

        function add(kind, text) {
            var node = el('div', 'msg ' + kind, text);
            log.appendChild(node);
            log.scrollTop = log.scrollHeight;
            return node;
        }

        /* Tells the visitor once when the person answering changes; a conversation the AI keeps says nothing. */
        function noteHandling(next) {
            if (typeof next !== 'string' || next === handling) return;
            var first = handling === null;
            handling = next;
            if (!(first && next === 'ai') && Object.prototype.hasOwnProperty.call(NOTES, next)) add('note', NOTES[next]);
        }

        /* Asks Ava for what a human agent wrote to this visitor and who is answering now. */
        function poll() {
            if (preview || !session) return;

            fetch(options.api + '/messages?session_id=' + encodeURIComponent(session), { headers: { 'Accept': 'application/json' } })
                .then(function (response) { return response.ok ? response.json() : null; })
                .then(function (body) {
                    if (!body) return;
                    noteHandling(body.handling);
                    (Array.isArray(body.messages) ? body.messages : []).forEach(function (item) {
                        if (item && typeof item.text === 'string') add('agent', item.text);
                    });
                })
                .catch(function () { /* the next poll tries again */ });
        }

        function startPolling() {
            if (preview || pollTimer || !session) return;
            poll();
            pollTimer = window.setInterval(function () {
                if (open && document.visibilityState === 'visible') poll();
            }, POLL_MS);
        }

        function setOpen(next) {
            open = next;
            if (!panel) return;
            panel.classList.toggle('open', open);
            fab.setAttribute('aria-expanded', String(open));
            if (open && log) startPolling();
        }

        function whatsapp(l) {
            var href = typeof current.href === 'string' && current.href.indexOf('https://wa.me/') === 0 ? current.href : null;
            fab = href ? el('a', 'fab') : el('button', 'fab');
            if (href) {
                fab.href = href;
                fab.target = '_blank';
                fab.rel = 'noopener noreferrer';
            } else {
                fab.type = 'button';
            }
            fab.setAttribute('aria-label', (l.label || 'Escríbenos por WhatsApp'));
            fab.appendChild(mark(current, l, WHATSAPP_ICON, true));
            if (l.label) fab.appendChild(el('span', 'label', l.label));
            root.appendChild(fab);
        }

        function chat(l) {
            var style = current.style || {};
            var title = (typeof style.headerTitle === 'string' && style.headerTitle) || current.name;

            panel = el('section', 'panel');
            panel.setAttribute('role', 'dialog');
            panel.setAttribute('aria-label', 'Chat con ' + title);

            var head = el('div', 'head');
            var badge = el('span', 'badge');
            if (current.avatarUrl) {
                var img = el('img');
                img.src = current.avatarUrl;
                img.alt = '';
                badge.appendChild(img);
            } else {
                badge.appendChild(icon(CHAT_ICON, 20, false));
            }
            head.appendChild(badge);
            head.appendChild(el('div', 'title', title));
            var close = el('button', 'close', '×');
            close.type = 'button';
            close.setAttribute('aria-label', 'Cerrar chat');
            head.appendChild(close);

            log = el('div', 'log');
            log.setAttribute('role', 'log');
            log.setAttribute('aria-live', 'polite');
            if (typeof style.welcomeMessage === 'string' && style.welcomeMessage) add('bot', style.welcomeMessage);

            var form = el('form');
            var input = el('textarea');
            input.rows = 1;
            input.maxLength = MAX;
            input.placeholder = preview ? 'Vista previa' : 'Escribe tu mensaje';
            input.setAttribute('aria-label', 'Mensaje');
            input.disabled = preview;
            var send = el('button', 'send', 'Enviar');
            send.type = 'submit';
            send.disabled = preview;
            form.appendChild(input);
            form.appendChild(send);

            panel.appendChild(head);
            panel.appendChild(log);
            panel.appendChild(form);
            root.appendChild(panel);

            fab = el('button', 'fab');
            fab.type = 'button';
            fab.setAttribute('aria-label', 'Abrir chat con ' + current.name);
            fab.setAttribute('aria-expanded', String(open));
            fab.appendChild(mark(current, l, CHAT_ICON, false));
            if (l.label) fab.appendChild(el('span', 'label', l.label));
            root.appendChild(fab);

            fab.addEventListener('click', function () { setOpen(!open); if (open && !preview) input.focus(); });
            close.addEventListener('click', function () { setOpen(false); if (!preview) fab.focus(); });
            panel.addEventListener('keydown', function (event) { if (event.key === 'Escape') { setOpen(false); if (!preview) fab.focus(); } });
            input.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); form.requestSubmit(); }
            });

            form.addEventListener('submit', function (event) {
                event.preventDefault();
                var text = input.value.trim();
                if (preview || !text || busy) return;

                busy = true;
                send.disabled = true;
                input.value = '';
                add('me', text);
                var typing = add('bot typing', 'Escribiendo…');

                fetch(options.api + '/messages', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ session_id: session, message: text })
                }).then(function (response) {
                    return response.json().catch(function () { return {}; }).then(function (body) {
                        typing.remove();
                        if (response.ok) noteHandling(body.handling);
                        if (response.ok && typeof body.reply === 'string') add('bot', body.reply);
                        else if (response.ok) { /* a person is answering: their reply arrives through the poll */ }
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

            setOpen(open);
        }

        function render() {
            var l = look(current);
            while (root.firstChild) root.removeChild(root.firstChild);
            panel = null;
            root.appendChild(el('style')).textContent = styles(l, isDark(current), preview);

            if (current.type === 'button') whatsapp(l); else chat(l);
        }

        function scheduleAutoOpen() {
            var style = current.style || {};
            if (preview || style.openBehavior !== 'auto' || window.innerWidth <= 480) return;

            var flag = 'ava-widget-opened-' + options.key;
            try { if (window.sessionStorage.getItem(flag)) return; } catch (e) { /* storage blocked: open every time */ }

            autoTimer = window.setTimeout(function () {
                setOpen(true);
                try { window.sessionStorage.setItem(flag, '1'); } catch (e) { /* see above */ }
            }, AUTO_OPEN_MS);
        }

        render();
        scheduleAutoOpen();

        return {
            update: function (next) { current = next; render(); },
            setOpen: setOpen,
            destroy: function () {
                if (autoTimer) window.clearTimeout(autoTimer);
                if (pollTimer) window.clearInterval(pollTimer);
                host.remove();
            }
        };
    }

    window.AvaWidget = window.AvaWidget || { mount: mount };

    var script = document.currentScript;
    var key = script && script.getAttribute('data-chatbot');

    if (!script || !key || window['__avaWidget_' + key]) return;
    window['__avaWidget_' + key] = true;

    var api = new URL(script.src, window.location.href).origin + '/api/widget/' + encodeURIComponent(key);

    function start() {
        fetch(api + '/config', { headers: { 'Accept': 'application/json' } })
            .then(function (response) { if (!response.ok) throw new Error(String(response.status)); return response.json(); })
            .then(function (config) { mount(config, { key: key, api: api }); })
            .catch(function (error) { if (window.console) console.warn('[Ava widget] no disponible (' + error.message + ')'); });
    }

    if (document.body) start(); else document.addEventListener('DOMContentLoaded', start);
})();
