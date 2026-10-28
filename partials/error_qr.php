<?php
$errorQr = $errorQr ?? 'Ha ocurrido un error.';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Error - SmartPark</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
    body {
        margin: 0;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #030712;
        background-image: radial-gradient(circle at 50% 50%, rgba(239, 68, 68, 0.1) 0%, transparent 60%);
        font-family: 'Inter', sans-serif;
        color: #f1f5f9;
        padding: 1.5rem;
    }
    .card {
        background: rgba(15, 23, 42, 0.8);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(239, 68, 68, 0.3);
        border-radius: 24px;
        padding: 2.5rem 2rem;
        max-width: 400px;
        text-align: center;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.6);
        animation: fadeInUp 0.6s ease;
    }
    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(20px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .icon {
        width: 80px; height: 80px;
        border-radius: 50%;
        background: rgba(239, 68, 68, 0.15);
        border: 2px solid #ef4444;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1.25rem;
        font-size: 2rem;
        color: #ef4444;
    }
    h1 { font-size: 1.3rem; font-weight: 800; margin: 0 0 0.75rem; color: #f1f5f9; }
    p { color: #94a3b8; font-size: 0.88rem; line-height: 1.5; margin: 0 0 1.5rem; }
    a {
        display: inline-block;
        padding: 0.85rem 1.75rem;
        background: linear-gradient(135deg, #10b981, #06b6d4);
        color: #030712;
        text-decoration: none;
        border-radius: 12px;
        font-weight: 800;
        font-size: 0.9rem;
        transition: all 0.3s ease;
    }
    a:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(16, 185, 129, 0.4); }
</style>
</head>
<body>
    <div class="card">
        <div class="icon">✕</div>
        <h1>No se puede acceder</h1>
        <p><?= htmlspecialchars($errorQr) ?></p>
        <a href="index.php">← Volver al inicio</a>
    </div>
</body>
</html>