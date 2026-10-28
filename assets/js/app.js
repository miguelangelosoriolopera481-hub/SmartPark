/* ============================================================================
   SmartPark Pro V3 — app.js limpio (sin duplicados)
   ============================================================================ */

/* ============ 1. PARTÍCULAS ============ */
(function() {
    'use strict';
    function initParticles() {
        const canvas = document.getElementById('particles-canvas');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        const particles = [];
        const COUNT = window.innerWidth < 768 ? 30 : 60;
        const MAX_D = 140;
        const COLORS = ['16, 185, 129', '6, 182, 212'];
        function resize() {
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;
        }
        resize();
        window.addEventListener('resize', resize);
        for (let i = 0; i < COUNT; i++) {
            particles.push({
                x: Math.random() * canvas.width,
                y: Math.random() * canvas.height,
                vx: (Math.random() - 0.5) * 0.3,
                vy: (Math.random() - 0.5) * 0.3,
                r: Math.random() * 1.8 + 0.6,
                c: COLORS[Math.floor(Math.random() * COLORS.length)],
            });
        }
        function draw() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            for (let i = 0; i < particles.length; i++) {
                const p = particles[i];
                ctx.beginPath();
                ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
                ctx.fillStyle = 'rgba(' + p.c + ', 0.6)';
                ctx.fill();
                p.x += p.vx;
                p.y += p.vy;
                if (p.x < 0 || p.x > canvas.width) p.vx *= -1;
                if (p.y < 0 || p.y > canvas.height) p.vy *= -1;
                for (let j = i + 1; j < particles.length; j++) {
                    const p2 = particles[j];
                    const dx = p.x - p2.x, dy = p.y - p2.y;
                    const d = Math.sqrt(dx * dx + dy * dy);
                    if (d < MAX_D) {
                        ctx.beginPath();
                        ctx.moveTo(p.x, p.y);
                        ctx.lineTo(p2.x, p2.y);
                        ctx.strokeStyle = 'rgba(' + p.c + ', ' + (1 - d / MAX_D) * 0.12 + ')';
                        ctx.lineWidth = 0.6;
                        ctx.stroke();
                    }
                }
            }
            requestAnimationFrame(draw);
        }
        draw();
    }

    /* ============ 2. NAVBAR SCROLL ============ */
    function initNavbarScroll() {
        const nav = document.querySelector('.cyber-navbar');
        if (!nav) return;
        window.addEventListener('scroll', function() {
            if (window.pageYOffset > 30) nav.classList.add('scrolled');
            else nav.classList.remove('scrolled');
        }, { passive: true });
    }

    /* ============ 3. RIPPLE EN BOTONES ============ */
    function initRipple() {
        document.querySelectorAll('.cyber-btn-primary').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                const rect = this.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;
                const ripple = document.createElement('span');
                ripple.style.cssText = 'position:absolute;left:' + x + 'px;top:' + y + 'px;width:0;height:0;border-radius:50%;background:rgba(255,255,255,0.35);transform:translate(-50%,-50%);pointer-events:none;animation:rippleExpand 0.6s ease-out;';
                this.appendChild(ripple);
                setTimeout(function() { ripple.remove(); }, 600);
            });
        });
        if (!document.getElementById('ripple-kf')) {
            const s = document.createElement('style');
            s.id = 'ripple-kf';
            s.textContent = '@keyframes rippleExpand { to { width: 300px; height: 300px; opacity: 0; } }';
            document.head.appendChild(s);
        }
    }

    /* ============ 4. CONTADORES ANIMADOS ============ */
    function initCounters() {
        const counters = document.querySelectorAll('[data-counter]');
        if (!counters.length) return;
        const obs = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting && !entry.target.dataset.animated) {
                    entry.target.dataset.animated = 'true';
                    const target = parseFloat(entry.target.dataset.counter);
                    const duration = 1400;
                    const start = performance.now();
                    const el = entry.target;
                    function update(now) {
                        const progress = Math.min((now - start) / duration, 1);
                        const eased = 1 - Math.pow(1 - progress, 3);
                        el.textContent = Math.floor(target * eased).toLocaleString('es-CO');
                        if (progress < 1) requestAnimationFrame(update);
                        else el.textContent = target.toLocaleString('es-CO');
                    }
                    requestAnimationFrame(update);
                }
            });
        }, { threshold: 0.4 });
        counters.forEach(function(c) { obs.observe(c); });
    }

    /* ============ 5. SCROLL REVEAL ============ */
    function initReveal() {
        const els = document.querySelectorAll('[data-reveal], [data-reveal-card], [data-reveal-feature]');
        if (!els.length) return;
        const obs = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    obs.unobserve(entry.target);
                }
            });
        }, { threshold: 0.15 });
        els.forEach(function(el, i) {
            setTimeout(function() { el.classList.add('visible'); }, i * 80);
            obs.observe(el);
        });
    }

    /* ============ 6. AUTO-DISMISS ALERTS ============ */
    function initAutoDismiss() {
        document.querySelectorAll('.cyber-success, .cyber-info').forEach(function(a) {
            setTimeout(function() {
                a.style.transition = 'all 0.5s ease';
                a.style.opacity = '0';
                setTimeout(function() { a.remove(); }, 500);
            }, 6000);
        });
    }

    /* ============ 7. SMOOTH SCROLL ============ */
    function initSmoothScroll() {
        document.querySelectorAll('a[href^="#"]').forEach(function(link) {
            link.addEventListener('click', function(e) {
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });
    }

    /* ============ 8. BUILD BLOCKS (registro) ============ */
    function initBuildBlocks() {
        const blocks = document.querySelectorAll('.build-block');
        if (!blocks.length) return;
        blocks.forEach(function(block, i) {
            setTimeout(function() {
                block.classList.add('visible');
            }, 200 + (i * 350));
        });
    }

    /* ============ 9. PAGE TRANSITIONS ============ */
    function initPageTransitions() {
        if (!document.querySelector('.page-transition-overlay')) {
            const overlay = document.createElement('div');
            overlay.className = 'page-transition-overlay';
            overlay.innerHTML = '<div class="page-transition-content">' +
                '<img src="IMG/SMARTPARK_TRANSPARENTE.png" alt="SmartPark" class="page-transition-logo">' +
                '<div class="page-transition-text">Cargando...</div>' +
                '<div class="page-transition-bar"></div></div>';
            document.body.appendChild(overlay);
        }
        document.querySelectorAll('a[href]').forEach(function(link) {
            const href = link.getAttribute('href');
            if (!href || href.startsWith('#') || href.startsWith('http') || href.startsWith('mailto:') || href.startsWith('tel:') || link.target === '_blank' || link.hasAttribute('data-no-transition')) return;
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const overlay = document.querySelector('.page-transition-overlay');
                if (overlay) overlay.classList.add('active');
                document.body.style.transition = 'opacity 0.35s ease';
                document.body.style.opacity = '0';
                setTimeout(function() { window.location.href = href; }, 400);
            });
        });
    }

    /* ============ 10. NAV FLOATING (versión única y correcta) ============ */
    function injectTopNav() {
        const path = window.location.pathname.toLowerCase();
        const esIndex = path.endsWith('/index.php') || path.endsWith('/') || path.endsWith('/sistema2') || path.endsWith('/sistema2/');

        document.querySelectorAll('.top-floating-nav').forEach(function(n) { n.remove(); });

        if (esIndex) return;

        // ⚡ ORDEN: específicos primero
        let currentPage = '';
        if (path.includes('login_empresa_admin')) currentPage = 'admin';
        else if (path.includes('login_developer')) currentPage = 'developer';
        else if (path.includes('registro')) currentPage = 'registro';
        else if (path.includes('login_empresa')) currentPage = 'empresas';

        const nav = document.createElement('nav');
        nav.className = 'top-floating-nav';
        nav.innerHTML =
            '<button class="top-nav-back" type="button" id="top-nav-back-btn">' +
                '<span class="arrow-icon">←</span> <span class="back-text">Volver</span>' +
            '</button>' +
            '<span class="top-nav-divider"></span>' +
            '<div class="top-nav-links">' +
                '<a href="index.php" class="' + (currentPage === '' ? 'active' : '') + '"><span class="link-icon">⌂</span> <span class="link-text">Inicio</span></a>' +
                '<a href="login_cliente.php" class="' + (currentPage === 'cliente' ? 'active' : '') + '"><span class="link-icon">◉</span> <span class="link-text">Cliente</span></a>' +
                '<a href="login_empresa.php" class="' + (currentPage === 'empresas' ? 'active' : '') + '"><span class="link-icon">◈</span> <span class="link-text">Empresas</span></a>' +
                '<a href="login_developer.php" class="' + (currentPage === 'developer' ? 'active' : '') + '"><span class="link-icon">◇</span> <span class="link-text">Developer</span></a>' +
                '<a href="registro_empresa.php" class="' + (currentPage === 'registro' ? 'active' : '') + '"><span class="link-icon">+</span> <span class="link-text">Registro</span></a>' +
            '</div>';
        document.body.appendChild(nav);

        const backBtn = document.getElementById('top-nav-back-btn');
        if (backBtn) {
            backBtn.addEventListener('click', function() {
                if (window.history.length > 1) window.history.back();
                else window.location.href = 'index.php';
            });
        }
    }

    /* ============ INIT ============ */
    function initAll() {
        initParticles();
        initNavbarScroll();
        initRipple();
        initCounters();
        initReveal();
        initAutoDismiss();
        initSmoothScroll();
        initBuildBlocks();
        initPageTransitions();
        injectTopNav();
        console.log('%c SmartPark Pro V3', 'color: #10b981; font-weight: bold;');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }

    window.addEventListener('pageshow', function() {
        document.body.style.opacity = '1';
        injectTopNav();
        initBuildBlocks();
    });
})();

/* ============ NAV DEVELOPER ESPECÍFICA ============ */
(function() {
    'use strict';

    function injectTopNav() {
        const path = window.location.pathname.toLowerCase();
        const esIndex = path.endsWith('/index.php') || path.endsWith('/') || path.endsWith('/sistema2') || path.endsWith('/sistema2/');

        document.querySelectorAll('.top-floating-nav').forEach(function(n) { n.remove(); });
        if (esIndex) return;

        const navType = document.body.dataset.nav || '';
        let navHTML = '';

        // NAV DEVELOPER
        if (navType === 'developer') {
            navHTML =
                '<button class="top-nav-back" type="button" id="top-nav-back-btn">' +
                    '<span class="arrow-icon">←</span> <span class="back-text">Volver</span>' +
                '</button>' +
                '<span class="top-nav-divider"></span>' +
                '<div class="top-nav-links">' +
                    '<a href="dashboard_developer.php"><span class="link-icon">⌂</span> <span class="link-text">Panel</span></a>' +
                    '<a href="#empresas"><span class="link-icon">◉</span> <span class="link-text">Empresas</span></a>' +
                    '<a href="#usuarios"><span class="link-icon">◇</span> <span class="link-text">Usuarios</span></a>' +
                    '<a href="#logs"><span class="link-icon">▤</span> <span class="link-text">Logs</span></a>' +
                    '<a href="#sistema"><span class="link-icon">◈</span> <span class="link-text">Sistema</span></a>' +
                    '<a href="#config"><span class="link-icon">⚙</span> <span class="link-text">Config</span></a>' +
                    '<a href="logout.php" style="color: #ef4444;"><span class="link-icon">✕</span> <span class="link-text">Salir</span></a>' +
                '</div>';
        } else {
            // NAV NORMAL (empresas, admin, developer, registro)
            let currentPage = '';
            if (path.includes('login_empresa_admin')) currentPage = 'admin';
            else if (path.includes('login_developer')) currentPage = 'developer';
            else if (path.includes('registro')) currentPage = 'registro';
            else if (path.includes('login_empresa')) currentPage = 'empresas';

            navHTML =
                '<button class="top-nav-back" type="button" id="top-nav-back-btn">' +
                    '<span class="arrow-icon">←</span> <span class="back-text">Volver</span>' +
                '</button>' +
                '<span class="top-nav-divider"></span>' +
                '<div class="top-nav-links">' +
                    '<a href="index.php" class="' + (currentPage === '' ? 'active' : '') + '"><span class="link-icon">⌂</span> <span class="link-text">Inicio</span></a>' +
                    '<a href="login_cliente.php" class="' + (currentPage === 'cliente' ? 'active' : '') + '"><span class="link-icon">◉</span> <span class="link-text">Cliente</span></a>' +
                '<a href="login_empresa.php" class="' + (currentPage === 'empresas' ? 'active' : '') + '"><span class="link-icon">◈</span> <span class="link-text">Empresas</span></a>' +
                    '<a href="login_developer.php" class="' + (currentPage === 'developer' ? 'active' : '') + '"><span class="link-icon">◇</span> <span class="link-text">Developer</span></a>' +
                    '<a href="registro_empresa.php" class="' + (currentPage === 'registro' ? 'active' : '') + '"><span class="link-icon">+</span> <span class="link-text">Registro</span></a>' +
                '</div>';
        }

        const nav = document.createElement('nav');
        nav.className = 'top-floating-nav';
        nav.innerHTML = navHTML;
        document.body.appendChild(nav);

        const backBtn = document.getElementById('top-nav-back-btn');
        if (backBtn) {
            backBtn.addEventListener('click', function() {
                if (window.history.length > 1) window.history.back();
                else window.location.href = 'index.php';
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', injectTopNav);
    } else {
        injectTopNav();
    }

    window.addEventListener('pageshow', injectTopNav);
})();

/* ============ SMOOTH SCROLL PARA NAV INTERNA ============ */
(function() {
    'use strict';
    document.addEventListener('DOMContentLoaded', function() {
        // Interceptar clicks en anchors internos del nav developer
        document.querySelectorAll('.top-nav-links a[href^="#"]').forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const targetId = this.getAttribute('href');
                const target = document.querySelector(targetId);
                if (target) {
                    // Scroll suave
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    
                    // Highlight temporal de la sección
                    target.style.transition = 'all 0.5s ease';
                    const originalBorder = target.style.borderColor;
                    target.style.borderColor = '#10b981';
                    target.style.boxShadow = '0 0 40px rgba(16, 185, 129, 0.3)';
                    setTimeout(function() {
                        target.style.borderColor = originalBorder;
                        target.style.boxShadow = '';
                    }, 2000);
                    
                    // Marcar activo el link
                    document.querySelectorAll('.top-nav-links a').forEach(a => a.classList.remove('active'));
                    this.classList.add('active');
                }
            });
        });
    });
})();

/* ============ NAV DEVELOPER CON PARQUEADEROS Y REGISTROS ============ */
(function() {
    'use strict';

    function injectTopNav() {
        const path = window.location.pathname.toLowerCase();
        const esIndex = path.endsWith('/index.php') || path.endsWith('/') || path.endsWith('/sistema2') || path.endsWith('/sistema2/');

        document.querySelectorAll('.top-floating-nav').forEach(function(n) { n.remove(); });
        if (esIndex) return;

        const navType = document.body.dataset.nav || '';
        let navHTML = '';

        if (navType === 'developer') {
            navHTML =
                '<button class="top-nav-back" type="button" id="top-nav-back-btn">' +
                    '<span class="arrow-icon">←</span> <span class="back-text">Volver</span>' +
                '</button>' +
                '<span class="top-nav-divider"></span>' +
                '<div class="top-nav-links">' +
                    '<a href="#top"><span class="link-icon">⌂</span> <span class="link-text">Panel</span></a>' +
                    '<a href="#empresas"><span class="link-icon">◉</span> <span class="link-text">Empresas</span></a>' +
                    '<a href="#parqueaderos"><span class="link-icon">⬚</span> <span class="link-text">Parqueaderos</span></a>' +
                    '<a href="#registros"><span class="link-icon">▤</span> <span class="link-text">Registros</span></a>' +
                    '<a href="#usuarios"><span class="link-icon">◇</span> <span class="link-text">Usuarios</span></a>' +
                    '<a href="#logs"><span class="link-icon">◎</span> <span class="link-text">Logs</span></a>' +
                    '<a href="#sistema"><span class="link-icon">◈</span> <span class="link-text">Sistema</span></a>' +
                    '<a href="#config"><span class="link-icon">⚙</span> <span class="link-text">Config</span></a>' +
                    '<a href="logout.php" style="color: #ef4444;"><span class="link-icon">✕</span> <span class="link-text">Salir</span></a>' +
                '</div>';
        } else {
            let currentPage = '';
            if (path.includes('login_empresa_admin')) currentPage = 'admin';
            else if (path.includes('login_developer')) currentPage = 'developer';
            else if (path.includes('registro')) currentPage = 'registro';
            else if (path.includes('login_empresa')) currentPage = 'empresas';

            navHTML =
                '<button class="top-nav-back" type="button" id="top-nav-back-btn">' +
                    '<span class="arrow-icon">←</span> <span class="back-text">Volver</span>' +
                '</button>' +
                '<span class="top-nav-divider"></span>' +
                '<div class="top-nav-links">' +
                    '<a href="index.php" class="' + (currentPage === '' ? 'active' : '') + '"><span class="link-icon">⌂</span> <span class="link-text">Inicio</span></a>' +
                    '<a href="login_cliente.php" class="' + (currentPage === 'cliente' ? 'active' : '') + '"><span class="link-icon">◉</span> <span class="link-text">Cliente</span></a>' +
                '<a href="login_empresa.php" class="' + (currentPage === 'empresas' ? 'active' : '') + '"><span class="link-icon">◈</span> <span class="link-text">Empresas</span></a>' +
                    '<a href="login_developer.php" class="' + (currentPage === 'developer' ? 'active' : '') + '"><span class="link-icon">◇</span> <span class="link-text">Developer</span></a>' +
                    '<a href="registro_empresa.php" class="' + (currentPage === 'registro' ? 'active' : '') + '"><span class="link-icon">+</span> <span class="link-text">Registro</span></a>' +
                '</div>';
        }

        const nav = document.createElement('nav');
        nav.className = 'top-floating-nav';
        nav.innerHTML = navHTML;
        document.body.appendChild(nav);

        const backBtn = document.getElementById('top-nav-back-btn');
        if (backBtn) {
            backBtn.addEventListener('click', function() {
                if (window.history.length > 1) window.history.back();
                else window.location.href = 'index.php';
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', injectTopNav);
    } else {
        injectTopNav();
    }

    window.addEventListener('pageshow', injectTopNav);
})();
