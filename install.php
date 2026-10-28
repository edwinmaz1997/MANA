<?php
/**
 * Instalador de Rutero
 * Crea config.php, las tablas y el usuario superadmin.
 */
define('APP_ROOT', __DIR__);
session_start();
$cfgFile = __DIR__ . '/config.php';
$msg = null; $ok = false;

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

if (file_exists($cfgFile)) {
    require $cfgFile;
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $has = $pdo->query("SHOW TABLES LIKE 'superadmins'")->fetchColumn();
        if ($has && $pdo->query('SELECT COUNT(*) FROM superadmins')->fetchColumn() > 0) {
            // Ya instalado: solo aplicar actualizaciones de esquema (idempotente)
            $pdo->exec(file_get_contents(__DIR__ . '/sql/schema.sql'));
            require_once __DIR__ . '/app/helpers.php';
            require_once __DIR__ . '/app/migrate.php';
            migrar();
            die('<div style="font-family:sans-serif;padding:40px;text-align:center"><h2>Rutero ya está instalado</h2><p>Esquema verificado. Por seguridad puedes eliminar <b>install.php</b>.</p><a href="index.php">Ir al sistema</a></div>');
        }
    } catch (Throwable $e) { /* continuar con el formulario */ }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim($_POST['db_host'] ?? 'localhost');
    $name = trim($_POST['db_name'] ?? '');
    $user = trim($_POST['db_user'] ?? '');
    $pass = (string) ($_POST['db_pass'] ?? '');
    $sname = trim($_POST['s_nombre'] ?? 'Administrador');
    $suser = trim($_POST['s_usuario'] ?? '');
    $spass = (string) ($_POST['s_password'] ?? '');
    try {
        if ($name === '' || $user === '' || $suser === '' || strlen($spass) < 6) throw new Exception('Completa todos los campos (contraseña mínimo 6 caracteres).');
        $pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec(file_get_contents(__DIR__ . '/sql/schema.sql'));
        $st = $pdo->prepare('INSERT INTO superadmins (nombre, usuario, password) VALUES (?,?,?)');
        $st->execute([$sname, $suser, password_hash($spass, PASSWORD_DEFAULT)]);
        $cfg = "<?php\n"
            . "define('DB_HOST', " . var_export($host, true) . ");\n"
            . "define('DB_NAME', " . var_export($name, true) . ");\n"
            . "define('DB_USER', " . var_export($user, true) . ");\n"
            . "define('DB_PASS', " . var_export($pass, true) . ");\n"
            . "define('APP_TZ', 'America/Guatemala');\n"
            . "define('APP_DEBUG', false);\n";
        if (!file_exists($cfgFile) && @file_put_contents($cfgFile, $cfg) === false) {
            throw new Exception('No se pudo escribir config.php. Créalo manualmente con:<pre>' . h($cfg) . '</pre>');
        }
        foreach (['uploads', 'uploads/clientes', 'uploads/logos', 'uploads/productos'] as $d) if (!is_dir(__DIR__ . '/' . $d)) @mkdir(__DIR__ . '/' . $d, 0755, true);
        $ok = true;
    } catch (Throwable $e) {
        $msg = $e->getMessage();
    }
}
?><!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, user-scalable=no, viewport-fit=cover">
<title>Instalar Rutero</title><link rel="stylesheet" href="assets/styles.css"></head>
<body class="min-h-screen bg-slate-100 grid place-items-center p-6">
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-8 w-full max-w-lg">
  <h1 class="text-2xl font-bold">Instalar Rutero</h1>
  <?php if ($ok): ?>
    <div class="mt-5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 p-4">Instalación completa. Ingresa con tu usuario superadmin y crea la primera empresa. <b>Elimina install.php</b> del servidor.</div>
    <a href="index.php" class="inline-block mt-5 px-5 py-2.5 rounded-xl bg-indigo-600 text-white font-semibold">Ir al sistema</a>
  <?php else: ?>
    <p class="text-slate-500 mt-1">Datos de la base de datos MySQL creada en cPanel.</p>
    <?php if ($msg): ?><div class="mt-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 p-3 text-sm"><?= $msg ?></div><?php endif; ?>
    <form method="post" class="mt-5 space-y-3">
      <div class="grid grid-cols-2 gap-3">
        <label class="text-sm">Servidor<input name="db_host" value="<?= h($_POST['db_host'] ?? 'localhost') ?>" class="mt-1 w-full border rounded-lg px-3 py-2"></label>
        <label class="text-sm">Base de datos<input name="db_name" value="<?= h($_POST['db_name'] ?? '') ?>" class="mt-1 w-full border rounded-lg px-3 py-2"></label>
        <label class="text-sm">Usuario BD<input name="db_user" value="<?= h($_POST['db_user'] ?? '') ?>" class="mt-1 w-full border rounded-lg px-3 py-2"></label>
        <label class="text-sm">Contraseña BD<input name="db_pass" type="password" class="mt-1 w-full border rounded-lg px-3 py-2"></label>
      </div>
      <hr class="my-4">
      <p class="font-semibold">Superadmin (administrador de empresas)</p>
      <label class="block text-sm">Nombre<input name="s_nombre" value="<?= h($_POST['s_nombre'] ?? 'Administrador') ?>" class="mt-1 w-full border rounded-lg px-3 py-2"></label>
      <div class="grid grid-cols-2 gap-3">
        <label class="text-sm">Usuario<input name="s_usuario" value="<?= h($_POST['s_usuario'] ?? 'superadmin') ?>" class="mt-1 w-full border rounded-lg px-3 py-2"></label>
        <label class="text-sm">Contraseña<input name="s_password" type="password" class="mt-1 w-full border rounded-lg px-3 py-2"></label>
      </div>
      <button class="w-full mt-3 py-3 rounded-xl bg-indigo-600 text-white font-semibold">Instalar</button>
    </form>
  <?php endif; ?>
</div></body></html>
