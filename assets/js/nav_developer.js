/* ============================================================
   nav_developer.js - Nav universal por rol
   SIN EMOJIS - Solo iconos unicode geometricos
   ============================================================ */
(function() {
    'use strict';

    if (window.__spNavLoaded) return;
    window.__spNavLoaded = true;

    function getContext() {
        const ctx = window.SP_CONTEXT || {};
        return {
            role: ctx.role || 'invitado',
            rolEmpresa: ctx.rol_empresa || null,
            nombre: ctx.nombre || '',
            logueado: ctx.logueado || false,
        };
    }

    function esDeveloper(ctx) { return ctx.role === 'developer'; }
    function esAdmin(ctx) {
        return ctx.role === 'admin' || ctx.role === 'superadmin'
            || ctx.rolEmpresa === 'admin' || ctx.rolEmpresa === 'superadmin';
    }
    function esVigilante(ctx) { return ctx.rolEmpresa === 'vigilante' || ctx.role === 'vigilante'; }
    function esFinanzas(ctx) { return ctx.rolEmpresa === 'finanzas' || ctx.role === 'finanzas'; }
    function esOperador(ctx) { return ctx.rolEmpresa === 'operador'; }
    function esCliente(ctx) {
        return ctx.role === 'cliente' || ctx.rolEmpresa === 'cliente';
    }

    function getLinks(ctx) {
        const path = window.location.pathname.toLowerCase();
        
        // FIX: Si estamos en páginas de cliente, forzar menú de cliente
        if (path.includes('dashboard_cliente') || path.includes('mi_cuenta') || path.includes('mi_parqueadero') || path.includes('registro_carro')) {
            return [
                { href: 'dashboard_cliente.php', icon: '⌂', text: 'Mi Cuenta' },
                { href: 'mi_parqueadero.php',    icon: '▢', text: 'Mi Parqueadero' },
                { href: 'registro_carro.php',    icon: '+', text: 'Registrar' },
            ];
        }
        // ICONOS UNICODE GEOMETRICOS (no emojis):
        //  ⌂ Inicio    ◉ Empresas    ◈ Admin    ◇ Developer    + Registrar
        //  ▢ Celdas    ▦ QR          ◐ Finanzas ⚙ Config      ◎ Logs
        //  ↓ Backups   ▤ Registros   → Salida   $ Cobrar       ← Volver
        //  ✕ Salir     ➕ (NO USAR - es emoji)

        if (esDeveloper(ctx)) {
            return [
                { href: 'dashboard_developer.php', icon: '⌂', text: 'Panel' },
                { href: 'dev_empresas.php',        icon: '◉', text: 'Empresas' },
                { href: 'dev_parqueaderos.php',    icon: '▢', text: 'Parqueaderos' },
                { href: 'dev_registros.php',       icon: '▤', text: 'Registros' },
                { href: 'dev_usuarios.php',        icon: '◇', text: 'Usuarios' },
                { href: 'dev_backups.php',         icon: '↓', text: 'Backups' },
                { href: 'dev_logs.php',            icon: '◎', text: 'Logs' },
                { href: 'dev_sistema.php',         icon: '◈', text: 'Sistema' },
                { href: 'dev_config.php',          icon: '⚙', text: 'Config' },
            ];
        }

        if (esAdmin(ctx)) {
            return [
                { href: 'dashboard_admin.php',   icon: '⌂', text: 'Panel' },
                { href: 'registro_entrada.php',  icon: '+', text: 'Registrar' },
                { href: 'modulo_pagos.php',      icon: '$', text: 'Cobrar' },
                { href: 'salida.php',            icon: '→', text: 'Salida' },
                { href: 'admin.php',             icon: '▢', text: 'Celdas' },
                { href: 'ver_qr.php',            icon: '▦', text: 'QR' },
                { href: 'dashboard_finanzas.php',icon: '◐', text: 'Finanzas' },
                { href: 'config.php',            icon: '⚙', text: 'Config' },
            ];
        }

        if (esOperador(ctx)) {
            return [
                { href: 'dashboard_admin.php',   icon: '⌂', text: 'Panel' },
                { href: 'registro_entrada.php',  icon: '+', text: 'Registrar' },
                { href: 'modulo_pagos.php',      icon: '$', text: 'Cobrar' },
                { href: 'salida.php',            icon: '→', text: 'Salida' },
                { href: 'admin.php',             icon: '▢', text: 'Celdas' },
            ];
        }

        if (esVigilante(ctx)) {
            return [
                { href: 'dashboard_vigilante.php', icon: '⌂', text: 'Panel' },
                { href: 'registro_entrada.php',    icon: '+', text: 'Registrar' },
                { href: 'salida.php',              icon: '→', text: 'Salida' },
                { href: 'mi_parqueadero.php',      icon: '▢', text: 'Mapa' },
            ];
        }

        if (esFinanzas(ctx)) {
            return [
                { href: 'dashboard_finanzas.php', icon: '⌂', text: 'Panel' },
                { href: 'exportar_finanzas.php?formato=csv', icon: '◫', text: 'CSV' },
                { href: 'exportar_finanzas.php?formato=pdf', icon: '▤', text: 'PDF' },
                { href: 'stats.php',              icon: '◐', text: 'Estadisticas' },
            ];
        }

        if (esCliente(ctx)) {
            return [
                { href: 'dashboard_cliente.php', icon: '⌂', text: 'Mi Cuenta' },
                { href: 'mi_parqueadero.php',    icon: '▢', text: 'Mi Parqueadero' },
                { href: 'registro_carro.php',    icon: '+', text: 'Registrar' },
            ];
        }

        // INVITADO
        return [
            { href: 'index.php',               icon: '⌂', text: 'Inicio' },
            { href: 'login_cliente.php',       icon: '◉', text: 'Cliente' },
                { href: 'login_empresa.php',       icon: '◈', text: 'Empresas' },
            { href: 'login_developer.php',     icon: '◇', text: 'Developer' },
            { href: 'registro_empresa.php',    icon: '+', text: 'Registro' },
        ];
    }

    function inyectarNav() {
        document.querySelectorAll('.top-floating-nav').forEach(n => n.remove());

        const ctx = getContext();
        const links = getLinks(ctx);
        const path = window.location.pathname.toLowerCase();

        let activeHref = '';
        for (const l of links) {
            const target = l.href.split('?')[0].toLowerCase();
            if (path.includes(target.replace('.php', ''))) {
                activeHref = l.href;
                break;
            }
        }
        if (!activeHref && links.length && links[0].href === 'index.php') {
            activeHref = 'index.php';
        }

        let linksHTML = '';
        for (const l of links) {
            const isActive = (l.href === activeHref) ? 'active' : '';
            linksHTML +=
                '<a href="' + l.href + '" class="' + isActive + '">' +
                    '<span class="link-icon">' + l.icon + '</span>' +
                    ' <span class="link-text">' + l.text + '</span>' +
                '</a>';
        }

        let salirHTML = '';
        if (ctx.logueado) {
            salirHTML =
                '<span class="top-nav-divider"></span>' +
                '<a href="logout.php" class="top-nav-exit">' +
                    '<span class="link-icon">✕</span>' +
                    ' <span class="link-text">Salir</span>' +
                '</a>';
        }

        const volverHTML =
            '<button type="button" class="top-nav-back" id="sp-nav-volver">' +
                '<span class="arrow-icon">←</span>' +
                ' <span class="back-text">Volver</span>' +
            '</button>' +
            '<span class="top-nav-divider"></span>' +
            '<a href="index.php" class="top-nav-back">' +
                '<span class="arrow-icon">⌂</span>' +
                ' <span class="back-text">Inicio</span>' +
            '</a>' +
            '<span class="top-nav-divider"></span>';

        const nav = document.createElement('nav');
        nav.className = 'top-floating-nav';
        nav.innerHTML =
            volverHTML +
            '<div class="top-nav-links">' + linksHTML + '</div>' +
            salirHTML;
        document.body.appendChild(nav);

        const btnVolver = document.getElementById('sp-nav-volver');
        if (btnVolver) {
            btnVolver.addEventListener('click', function() {
                if (window.history.length > 1) window.history.back();
                else window.location.href = 'index.php';
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inyectarNav);
    } else {
        inyectarNav();
    }
    window.addEventListener('pageshow', inyectarNav);

    console.log('%c SP Nav Universal cargada (sin emojis)', 'color:#10b981;font-weight:bold;');
})();