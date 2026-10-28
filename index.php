<?php
require __DIR__ . '/app/bootstrap.php';

$p = (string) get('p', '');
if (!preg_match('/^[a-z_]{0,40}$/', $p)) $p = '';

if ($p === 'logout') { logout(); redirect('index.php'); }

// Volver del modo "entrar como empresa"
if ($p === 'super_volver' && !empty($_SESSION['sid_back'])) {
    $sid = (int) $_SESSION['sid_back'];
    $_SESSION = ['sid' => $sid];
    redirect(url('super_empresas'));
}

$u = user();
$s = super_user();

if (!$u && !$s) {
    require APP_ROOT . '/pages/login.php';
    exit;
}

if ($s && !$u) {
    if ($p === '' || $p === 'dashboard') $p = 'super';
    if (strpos($p, 'super') !== 0) $p = 'super';
} else {
    if ($p === '') $p = 'dashboard';
    if (strpos($p, 'super') === 0) $p = 'dashboard';
    // Membresía
    $mem = membresia_estado();
    if (!$mem['ok'] && empty($_SESSION['sid_back']) && $p !== 'ticket') {
        require APP_ROOT . '/pages/bloqueado.php';
        exit;
    }
}

$file = APP_ROOT . '/pages/' . $p . '.php';
if (!is_file($file)) {
    http_response_code(404);
    layout_header('No encontrado');
    echo '<div class="card p-10 text-center"><h2 class="text-xl font-semibold">Página no encontrada</h2><a class="btn btn-primary mt-4" href="index.php">Ir al inicio</a></div>';
    layout_footer();
    exit;
}

try {
    require $file;
} catch (Throwable $ex) {
    if (defined('APP_DEBUG') && APP_DEBUG) throw $ex;
    error_log('[MANA] ' . $ex->getMessage() . ' en ' . $ex->getFile() . ':' . $ex->getLine());
    if (is_ajax()) json_out(['ok' => false, 'error' => 'Ocurrió un error inesperado'], 500);
    http_response_code(500);
    echo '<div style="font-family:sans-serif;padding:40px;text-align:center"><h2>Ocurrió un error</h2><p>Intenta de nuevo. Si persiste, contacta al administrador.</p><a href="index.php">Volver</a></div>';
}
