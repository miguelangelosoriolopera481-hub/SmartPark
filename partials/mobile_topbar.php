<?php
/**
 * partials/mobile_topbar.php - Barra superior con botón Volver para páginas móviles
 * Incluir justo después de <body> en páginas destinadas a móvil.
 */
$_backUrl = $backUrl ?? 'javascript:history.back()';
$_showHome = $showHome ?? true;
?>
<div class="mobile-topbar">
    <a href="<?= htmlspecialchars($_backUrl) ?>" class="mobile-topbar-btn">
        <span class="mtb-icon">←</span>
        <span class="mtb-text">Volver</span>
    </a>
    <div class="mobile-topbar-title">
        <span class="mtb-logo">◈</span> SmartPark
    </div>
    <?php if ($_showHome): ?>
        <a href="index.php" class="mobile-topbar-btn">
            <span class="mtb-icon">⌂</span>
        </a>
    <?php else: ?>
        <div style="width: 60px;"></div>
    <?php endif; ?>
</div>

<style>
    .mobile-topbar {
        position: fixed;
        top: 0; left: 0; right: 0;
        height: 52px;
        background: rgba(2, 6, 23, 0.92);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border-bottom: 1px solid rgba(16, 185, 129, 0.25);
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 0.75rem;
        z-index: 99999;
        font-family: 'Inter', sans-serif;
    }
    .mobile-topbar-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.5rem 0.9rem;
        background: rgba(16, 185, 129, 0.12);
        border: 1px solid rgba(16, 185, 129, 0.35);
        border-radius: 10px;
        color: #10b981;
        font-size: 0.8rem;
        font-weight: 700;
        text-decoration: none;
        transition: all 0.25s ease;
        min-width: 44px;
        justify-content: center;
    }
    .mobile-topbar-btn:hover,
    .mobile-topbar-btn:active {
        background: #10b981;
        color: #030712;
    }
    .mobile-topbar-btn .mtb-icon {
        font-size: 1rem;
        line-height: 1;
    }
    .mobile-topbar-title {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.9rem;
        font-weight: 800;
        color: #f1f5f9;
        letter-spacing: -0.01em;
    }
    .mobile-topbar-title .mtb-logo {
        color: #10b981;
        font-size: 1.1rem;
    }

    /* Ajustar body para no quedar tapado */
    body.has-mobile-topbar {
        padding-top: 60px !important;
    }

    /* En desktop no se muestra */
    @media (min-width: 768px) {
        .mobile-topbar { display: none; }
        body.has-mobile-topbar { padding-top: 0 !important; }
    }
</style>