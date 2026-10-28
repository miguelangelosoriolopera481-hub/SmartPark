/* ============================================================
   ia_widget.js — Chat flotante con IA
   Maneja: apertura, envío, recepción, reintentos, reportes
   ============================================================ */
(function() {
    'use strict';

    if (window.__iaWidgetLoaded) return;
    window.__iaWidgetLoaded = true;

    // Config inyectada desde PHP vía data attributes del <body>
    const CONFIG = {
        apiUrl: window.IA_WIDGET_CONFIG?.apiUrl || 'ia_chat_api.php',
        reportUrl: window.IA_WIDGET_CONFIG?.reportUrl || 'ia_reportar.php',
        csrfToken: window.IA_WIDGET_CONFIG?.csrfToken || '',
        role: window.IA_WIDGET_CONFIG?.role || 'cliente',
        userName: window.IA_WIDGET_CONFIG?.userName || 'Usuario',
        empresaNombre: window.IA_WIDGET_CONFIG?.empresaNombre || 'SmartPark',
        suggestions: window.IA_WIDGET_CONFIG?.suggestions || [],
    };

    let state = {
        open: false,
        sending: false,
        messages: [],
        lastQuestion: '',
        lastResponse: '',
    };

    /* ---------- DOM refs ---------- */
    const $fab = document.querySelector('.ia-fab');
    const $panel = document.querySelector('.ia-panel');
    const $close = document.querySelector('.ia-close');
    const $messages = document.querySelector('.ia-messages');
    const $input = document.querySelector('.ia-input');
    const $send = document.querySelector('.ia-send');
    const $suggestions = document.querySelector('.ia-suggestions');

    if (!$fab || !$panel) return;

    /* ---------- Render de mensajes ---------- */
    function addMessage(texto, tipo) {
        const div = document.createElement('div');
        div.className = 'ia-msg ' + tipo;

        if (tipo === 'bot') {
            // Escapar HTML + formateo markdown simple
            const escapeHtml = (s) => s.replace(/[&<>"']/g, (c) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            }[c]));
            let html = escapeHtml(texto);
            html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
            html = html.replace(/`(.+?)`/g, '<code>$1</code>');
            div.innerHTML = html;
        } else {
            div.textContent = texto;
        }

        $messages.appendChild(div);
        $messages.scrollTop = $messages.scrollHeight;
        return div;
    }

    function showTyping() {
        const div = document.createElement('div');
        div.className = 'ia-typing';
        div.id = 'ia-typing-indicator';
        div.innerHTML = '<span></span><span></span><span></span>';
        $messages.appendChild(div);
        $messages.scrollTop = $messages.scrollHeight;
    }

    function hideTyping() {
        const el = document.getElementById('ia-typing-indicator');
        if (el) el.remove();
    }

    function addReportButton(pregunta, respuesta, motivo) {
        const wrap = document.createElement('div');
        wrap.className = 'ia-msg bot';
        wrap.style.background = 'transparent';
        wrap.style.border = 'none';
        wrap.style.padding = '0';
        wrap.style.marginTop = '0.3rem';

        const btn = document.createElement('button');
        btn.className = 'ia-report-btn';
        btn.innerHTML = '⚠️ Reportar problema';
        btn.addEventListener('click', () => {
            enviarReporte(pregunta, respuesta, motivo);
            btn.disabled = true;
            btn.textContent = '✓ Reportado';
            btn.style.opacity = '0.6';
        });

        wrap.appendChild(btn);
        $messages.appendChild(wrap);
    }

    /* ---------- Enviar pregunta ---------- */
    async function enviarPregunta(pregunta) {
        if (state.sending || !pregunta.trim()) return;

        state.sending = true;
        state.lastQuestion = pregunta;
        $send.disabled = true;
        $input.value = '';
        $input.style.height = 'auto';

        addMessage(pregunta, 'user');
        showTyping();

        try {
            const resp = await fetch(CONFIG.apiUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    pregunta: pregunta,
                    csrf_token: CONFIG.csrfToken,
                }),
            });

            // Limpiar BOM + espacios invisibles antes de parsear
            let textoResp = await resp.text();
            textoResp = textoResp.replace(/^\uFEFF/, '').replace(/^\s+/, '').trim();

            let data;
            try {
                data = JSON.parse(textoResp);
            } catch (e) {
                console.error('[IA Widget] JSON parse error:', e.message);
                console.error('[IA Widget] Raw response:', textoResp.substring(0, 500));
                hideTyping();
                addMessage('❌ Error de formato: ' + e.message + ' | Ver consola F12', 'error');
                state.sending = false;
                $send.disabled = false;
                return;
            }
            hideTyping();

            if (data.ok) {
                state.lastResponse = data.respuesta;
                addMessage(data.respuesta, 'bot');
            } else {
                // Manejo de errores
                addMessage('⚠️ ' + (data.error || 'Error desconocido'), 'error');

                if (data.sugerir_reporte) {
                    addReportButton(pregunta, '', data.tipo || 'error');
                }
            }
        } catch (err) {
            hideTyping();
            addMessage('❌ No se pudo contactar al servidor. Verifica tu conexión.', 'error');
            addReportButton(pregunta, '', 'error_red');
        } finally {
            state.sending = false;
            $send.disabled = false;
            $input.focus();
        }
    }

    /* ---------- Enviar reporte ---------- */
    async function enviarReporte(pregunta, respuesta, motivo) {
        try {
            const resp = await fetch(CONFIG.reportUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    pregunta: pregunta,
                    respuesta: respuesta,
                    motivo: motivo || 'ia_caida',
                    csrf_token: CONFIG.csrfToken,
                }),
            });
            const data = await resp.json();
            if (data.ok) {
                addMessage('✓ ' + data.mensaje, 'bot');
            }
        } catch (err) {
            console.error('Reporte falló:', err);
        }
    }

    /* ---------- Toggle panel ---------- */
    function abrir() {
        state.open = true;
        $panel.classList.add('active');
        $fab.style.display = 'none';

        // Bienvenida si es la primera vez
        if (state.messages.length === 0) {
            const bienvenida = `¡Hola ${CONFIG.userName}! 👋\n\nSoy **SmartPark AI**, tu asistente. Puedo ayudarte con información sobre **${CONFIG.empresaNombre}**.\n\n¿En qué te ayudo hoy?`;
            addMessage(bienvenida, 'bot');
        }

        setTimeout(() => $input.focus(), 400);
    }

    function cerrar() {
        state.open = false;
        $panel.classList.remove('active');
        setTimeout(() => { $fab.style.display = 'flex'; }, 300);
    }

    /* ---------- Event listeners ---------- */
    $fab.addEventListener('click', abrir);
    $close.addEventListener('click', cerrar);

    $send.addEventListener('click', () => enviarPregunta($input.value));

    $input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            enviarPregunta($input.value);
        }
    });

    // Auto-resize del textarea
    $input.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 100) + 'px';
    });

    // Sugerencias rápidas
    if ($suggestions && CONFIG.suggestions.length > 0) {
        CONFIG.suggestions.forEach(txt => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'ia-suggestion';
            btn.textContent = txt;
            btn.addEventListener('click', () => {
                $input.value = txt;
                enviarPregunta(txt);
            });
            $suggestions.appendChild(btn);
        });
    }

    // Escape para cerrar
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && state.open) cerrar();
    });

    console.log('%c SmartPark AI Widget listo', 'color: #10b981; font-weight: bold;');
})();