<?php
$err = null;
if (is_post()) {
    csrf_check();
    $k = 'login_fail_' . md5($_SERVER['REMOTE_ADDR'] ?? '');
    $fails = $_SESSION[$k] ?? ['n' => 0, 't' => 0];
    if ($fails['n'] >= 6 && time() - $fails['t'] < 300) {
        $err = 'Demasiados intentos. Espera 5 minutos.';
    } else {
        $r = login_attempt(post('usuario', ''), post('password', ''));
        if ($r) redirect('index.php');
        $_SESSION[$k] = ['n' => $fails['n'] + 1, 't' => time()];
        $err = 'Usuario o contraseña incorrectos';
    }
}
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="theme-color" content="#0f172a">
<title>Ingresar · <?= APP_NAME ?></title>
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/styles.css?v=<?= APP_VERSION ?>">
<style>body{font-family:Inter,system-ui,sans-serif}</style>
</head>
<body class="min-h-screen bg-slate-950 text-white grid lg:grid-cols-2">
  <div class="hidden lg:flex flex-col justify-between p-12 bg-gradient-to-br from-indigo-600 via-indigo-700 to-slate-900 relative overflow-hidden">
    <div class="absolute -right-32 -top-32 w-96 h-96 rounded-full bg-emerald-400/20 blur-3xl"></div>
    <div class="absolute -left-20 bottom-0 w-80 h-80 rounded-full bg-indigo-300/20 blur-3xl"></div>
    <div class="flex items-center gap-3 relative">
      <div class="w-11 h-11 rounded-2xl bg-white text-indigo-700 grid place-items-center font-extrabold text-xl">M</div>
      <span class="text-xl font-bold tracking-tight"><?= APP_NAME ?></span>
    </div>
    <div class="relative">
      <h1 class="text-4xl font-extrabold leading-tight">Ventas al crédito,<br>rutas y cobros<br>en un solo lugar.</h1>
      <p class="mt-4 text-indigo-100 max-w-md">Controla tus clientes, cobradores y cartera desde cualquier teléfono.</p>
    </div>
    <div class="text-sm text-indigo-200 relative">© <?= date('Y') ?> <?= APP_NAME ?></div>
  </div>
  <div class="flex items-center justify-center p-6">
    <form method="post" class="w-full max-w-sm">
      <?= csrf_field() ?>
      <div class="lg:hidden flex items-center gap-3 mb-10">
        <div class="w-11 h-11 rounded-2xl bg-indigo-600 grid place-items-center font-extrabold text-xl">M</div>
        <span class="text-xl font-bold"><?= APP_NAME ?></span>
      </div>
      <h2 class="text-2xl font-bold">Bienvenido</h2>
      <p class="text-slate-400 mt-1 mb-8">Ingresa con tu usuario para continuar</p>
      <?php if ($err): ?><div class="mb-5 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 px-4 py-3 text-sm"><?= e($err) ?></div><?php endif; ?>
      <label class="block text-sm text-slate-300 mb-1.5">Usuario</label>
      <input name="usuario" required autofocus autocomplete="username" value="<?= e(post('usuario', '')) ?>" class="w-full rounded-xl bg-white/5 border border-white/10 px-4 py-3 outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/20">
      <label class="block text-sm text-slate-300 mb-1.5 mt-4">Contraseña</label>
      <input name="password" type="password" required autocomplete="current-password" class="w-full rounded-xl bg-white/5 border border-white/10 px-4 py-3 outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/20">
      <button class="w-full mt-7 rounded-xl bg-indigo-600 hover:bg-indigo-500 py-3 font-semibold transition">Ingresar</button>
    </form>
  </div>
</body>
</html>
