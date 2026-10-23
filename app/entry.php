<?php
declare(strict_types=1);

if (!defined('STUDIO_RELEASE')) {
    http_response_code(404);
    exit;
}

if (isset($_GET['health'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'ok',
        'app' => 'studio-ia',
        'release' => STUDIO_RELEASE,
    ], JSON_THROW_ON_ERROR);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
header('X-Frame-Options: DENY');
$version = htmlspecialchars(substr(STUDIO_RELEASE, 0, 12), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Studio IA · Eskalamos</title>
    <style>
        :root { color-scheme: dark; font-family: system-ui, -apple-system, sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: #0a101b; color: #e9f0f8; }
        main { width: min(900px, 100%); margin: auto; padding: 52px 24px 70px; }
        header { display: flex; justify-content: space-between; align-items: center; gap: 20px; }
        .brand { font-size: 15px; font-weight: 750; letter-spacing: .1em; }
        .status { font-size: 12px; color: #7ee3b2; background: #132c27; border: 1px solid #285442; padding: 8px 12px; border-radius: 30px; }
        .eyebrow { margin: 92px 0 16px; color: #8299b5; font-size: 12px; letter-spacing: .16em; }
        h1 { font-size: clamp(40px, 7vw, 68px); line-height: 1.05; letter-spacing: -.055em; margin: 0 0 24px; max-width: 650px; }
        .intro { color: #a7b7ca; font-size: 18px; line-height: 1.6; max-width: 590px; }
        .cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-top: 46px; }
        article { background: #101a28; border: 1px solid #233247; border-radius: 16px; padding: 24px; }
        article span { color: #8299b5; font-size: 12px; }
        h2 { margin: 16px 0 8px; font-size: 18px; }
        article p { color: #a7b7ca; font-size: 14px; line-height: 1.55; margin: 0; }
        footer { margin-top: 44px; color: #8299b5; font-size: 12px; }
        @media (max-width: 650px) { .cards { grid-template-columns: 1fr; } .eyebrow { margin-top: 62px; } }
    </style>
</head>
<body>
<main>
    <header><div class="brand">ESKALAMOS / STUDIO IA</div><div class="status">Base instalada</div></header>
    <p class="eyebrow">ESPACIO DE PRODUCCIÓN</p>
    <h1>La base de tu Studio<br>ya está lista.</h1>
    <p class="intro">El entorno está preparado para incorporar generación de imágenes, vídeo y voz. Esta es la página inicial de instalación.</p>
    <section class="cards" aria-label="Estado de los módulos">
        <article><span>INFRAESTRUCTURA</span><h2>Despliegue activo</h2><p>Código organizado por versiones y comprobación web disponible.</p></article>
        <article><span>IMAGEN Y VÍDEO</span><h2>Higgsfield</h2><p>Integración pendiente de configurar.</p></article>
        <article><span>LOCUCIONES</span><h2>ElevenLabs</h2><p>Integración pendiente de configurar.</p></article>
    </section>
    <footer>Versión <?= $version ?> · Los módulos de producción todavía no están habilitados.</footer>
</main>
</body>
</html>
