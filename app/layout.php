<?php
/* ---------- Layout ---------- */

function nav_items()
{
    if (super_user() && !user()) {
        return [
            ['super', 'layout-dashboard', 'Panel'],
            ['super_empresas', 'building-2', 'Empresas'],
            ['super_planes', 'badge-check', 'Planes'],
            ['super_pagos', 'receipt', 'Pagos membresía'],
        ];
    }
    $items = [
        ['dashboard', 'layout-dashboard', 'Inicio', 'dashboard'],
        ['ventas_nueva', 'shopping-cart', 'Nueva venta', 'ventas.crear'],
        ['ruta', 'map', 'Mi ruta', 'rutas.mia'],
        ['ventas', 'receipt-text', 'Ventas y créditos', 'ventas'],
        ['clientes', 'users', 'Clientes', 'clientes'],
        ['cobros', 'hand-coins', 'Cobros', 'cobros'],
        ['_sep', '', 'Administración', 'admin'],
        ['rutas', 'route', 'Rutas', 'admin'],
        ['productos', 'package', 'Productos', 'admin'],
        ['compras', 'truck', 'Compras', 'admin'],
        ['proveedores', 'factory', 'Proveedores', 'admin'],
        ['gastos', 'wallet', 'Gastos', 'admin'],
        ['empleados', 'badge-dollar-sign', 'Empleados y comisiones', 'admin'],
        ['reportes', 'bar-chart-3', 'Reportes', 'admin'],
        ['_sep', '', 'Configuración', 'admin'],
        ['usuarios', 'user-cog', 'Usuarios', 'admin'],
        ['areas', 'map-pinned', 'Áreas', 'admin'],
        ['empresa', 'settings', 'Empresa y ticket', 'admin'],
    ];
    $out = [];
    foreach ($items as $it) {
        $perm = $it[3];
        if ($perm === 'admin' ? is_admin() : can($perm)) $out[] = $it;
    }
    return $out;
}

function layout_header($title = '', array $opt = [])
{
    $page = get('p', 'dashboard');
    $u = user();
    $s = super_user();
    $emp = empresa();
    $flash = flash();
    $mem = $u ? membresia_estado() : null;
    $displayName = $u ? nombre_completo($u) : ($s ? $s['nombre'] : '');
    $roleLabel = $u ? ucfirst($u['rol']) : 'Superadmin';
    ?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="theme-color" content="#0f172a">
<title><?= e($title ? $title . ' · ' : '') ?><?= e($emp ? $emp['nombre'] : APP_NAME) ?></title>
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= asset('assets/styles.css') ?>">
<script src="<?= asset('assets/vendor/lucide.min.js') ?>"></script>
<script src="<?= asset('assets/app.js') ?>"></script>
<?php if (!empty($opt['charts'])): ?><script src="assets/vendor/chart.umd.min.js"></script><?php endif; ?>
<script>window.CSRF = <?= json_encode(csrf_token()) ?>; window.MONEDA = <?= json_encode($u ? currency() : 'Q') ?>;</script>
</head>
<body class="bg-slate-100 text-slate-800 font-sans antialiased">
<div id="modal-root"></div>
<?php if (!empty($_SESSION['sid_back'])): ?>
<div class="bg-amber-400 text-amber-950 text-sm px-4 py-2 flex items-center justify-between gap-3 sticky top-0 z-50">
  <span><i data-lucide="eye" class="w-4 h-4 inline"></i> Estás viendo la empresa <b><?= e($emp['nombre'] ?? '') ?></b> como superadmin</span>
  <a href="<?= url('super_volver') ?>" class="font-semibold underline">Volver al panel</a>
</div>
<?php endif; ?>
<div class="min-h-screen lg:flex">
  <!-- Sidebar -->
  <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 w-72 -translate-x-full lg:translate-x-0 lg:sticky lg:top-0 lg:h-screen transition-transform duration-200 bg-slate-900 text-slate-300 flex flex-col">
    <div class="px-5 py-5 flex items-center gap-3 border-b border-white/5">
      <?php if ($emp && $emp['logo']): ?>
        <img src="<?= e($emp['logo']) ?>" class="w-10 h-10 rounded-xl object-contain bg-white p-1" alt="">
      <?php else: ?>
        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-brand-500 to-emerald-400 grid place-items-center text-white font-extrabold">M</div>
      <?php endif; ?>
      <div class="min-w-0">
        <div class="text-white font-semibold truncate"><?= e($emp ? $emp['nombre'] : APP_NAME . ' Admin') ?></div>
        <div class="text-xs text-slate-400"><?= e($u ? 'Ventas y cobros' : 'Gestión de empresas') ?></div>
      </div>
      <button class="lg:hidden ml-auto p-2 rounded-lg hover:bg-white/10" onclick="toggleSidebar(false)"><i data-lucide="x" class="w-5 h-5"></i></button>
    </div>
    <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-0.5 scrollbar-thin">
      <?php foreach (nav_items() as $it):
        if ($it[0] === '_sep') { echo '<div class="px-3 pt-5 pb-2 text-[11px] uppercase tracking-wider text-slate-500 font-semibold">' . e($it[2]) . '</div>'; continue; }
        $active = $page === $it[0] || (strpos($page, $it[0] . '_') === 0 && !in_array($it[0], ['ventas', 'super'], true));
        if ($it[0] === 'ventas' && in_array($page, ['ventas', 'venta'], true)) $active = true;
        if ($it[0] === 'clientes' && in_array($page, ['clientes', 'cliente', 'cliente_form'], true)) $active = true; ?>
        <a href="<?= url($it[0]) ?>" class="nav-link <?= $active ? 'active' : '' ?>">
          <i data-lucide="<?= e($it[1]) ?>" class="w-[18px] h-[18px]"></i><span><?= e($it[2]) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="p-3 border-t border-white/5">
      <div class="flex items-center gap-3 px-2 py-2">
        <div class="w-9 h-9 rounded-full bg-white/10 grid place-items-center text-white font-semibold"><?= e(mb_strtoupper(mb_substr($displayName, 0, 1))) ?></div>
        <div class="min-w-0 flex-1">
          <div class="text-sm text-white truncate"><?= e($displayName) ?></div>
          <div class="text-xs text-slate-400"><?= e($roleLabel) ?></div>
        </div>
        <?php if ($u): ?><a href="<?= url('perfil') ?>" class="p-2 rounded-lg hover:bg-white/10" title="Mi perfil"><i data-lucide="user" class="w-4 h-4"></i></a><?php endif; ?>
        <a href="<?= url('logout') ?>" class="p-2 rounded-lg hover:bg-white/10" title="Salir"><i data-lucide="log-out" class="w-4 h-4"></i></a>
      </div>
    </div>
  </aside>
  <div id="backdrop" class="fixed inset-0 bg-slate-900/50 z-30 hidden lg:hidden" onclick="toggleSidebar(false)"></div>

  <!-- Contenido -->
  <div class="flex-1 min-w-0 flex flex-col">
    <header class="sticky top-0 z-20 bg-white/80 backdrop-blur border-b border-slate-200">
      <div class="px-4 lg:px-8 h-16 flex items-center gap-3">
        <button class="lg:hidden p-2 -ml-2 rounded-lg hover:bg-slate-100" onclick="toggleSidebar(true)"><i data-lucide="menu" class="w-5 h-5"></i></button>
        <h1 class="text-base sm:text-lg font-semibold truncate min-w-0 flex-1"><?= e($title) ?></h1>
        <div class="ml-auto flex items-center gap-2 shrink-0"><?= isset($opt['actions']) ? $opt['actions'] : '' ?></div>
      </div>
    </header>
    <main class="flex-1 px-4 lg:px-8 py-6 pb-28 lg:pb-10 max-w-[1400px] w-full mx-auto">
      <?php if ($mem && $mem['ok'] && $mem['dias'] <= 5 && is_admin()): ?>
        <div class="mb-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 text-sm flex gap-2 items-center"><i data-lucide="alert-triangle" class="w-4 h-4"></i> Tu membresía vence en <?= (int) $mem['dias'] ?> día(s) (<?= fdate($emp['fecha_vencimiento']) ?>).</div>
      <?php endif; ?>
      <?php foreach ($flash as $f): $c = $f['type'] === 'error' ? 'bg-rose-50 border-rose-200 text-rose-800' : ($f['type'] === 'warning' ? 'bg-amber-50 border-amber-200 text-amber-800' : 'bg-emerald-50 border-emerald-200 text-emerald-800'); ?>
        <div class="mb-4 rounded-xl border px-4 py-3 text-sm <?= $c ?> flash"><?= e($f['msg']) ?></div>
      <?php endforeach; ?>
<?php
}

function layout_footer()
{
    $u = user();
    ?>
    </main>
  </div>
</div>
<?php if ($u && !is_admin()): ?>
<!-- Navegación inferior móvil -->
<nav class="lg:hidden fixed bottom-0 inset-x-0 z-30 bg-white border-t border-slate-200 pb-[env(safe-area-inset-bottom)]">
  <div class="grid grid-cols-4 text-[11px]">
    <a href="<?= url('dashboard') ?>" class="bnav"><i data-lucide="home" class="w-5 h-5"></i>Inicio</a>
    <?php if (can('rutas.mia')): ?><a href="<?= url('ruta') ?>" class="bnav"><i data-lucide="map" class="w-5 h-5"></i>Ruta</a><?php else: ?><a href="<?= url('ventas') ?>" class="bnav"><i data-lucide="receipt-text" class="w-5 h-5"></i>Ventas</a><?php endif; ?>
    <a href="<?= url('ventas_nueva') ?>" class="bnav"><i data-lucide="plus-circle" class="w-5 h-5"></i>Vender</a>
    <a href="<?= url('clientes') ?>" class="bnav"><i data-lucide="users" class="w-5 h-5"></i>Clientes</a>
  </div>
</nav>
<?php endif; ?>
</body>
</html>
<?php
}

/** Barra de filtros de fecha (hoy, mes, año, rango) */
function filtro_fechas($rng, array $extra = [])
{
    $p = get('p');
    $opts = ['hoy' => 'Hoy', 'semana' => 'Semana', 'mes' => 'Mes', 'anio' => 'Año', 'rango' => 'Rango'];
    echo '<form method="get" class="flex flex-wrap items-center gap-2 mb-5">';
    echo '<input type="hidden" name="p" value="' . e($p) . '">';
    foreach ($extra as $k => $v) echo '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    echo '<div class="flex w-full sm:w-auto overflow-x-auto rounded-xl bg-white border border-slate-200 p-1">';
    foreach ($opts as $k => $l) {
        $act = $rng['r'] === $k ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100';
        echo '<button name="r" value="' . $k . '" class="flex-1 sm:flex-none px-2.5 sm:px-3 py-1.5 text-sm rounded-lg whitespace-nowrap ' . $act . '">' . $l . '</button>';
    }
    echo '</div>';
    echo '<div class="flex flex-wrap items-center gap-2 w-full sm:w-auto ' . ($rng['r'] === 'rango' ? '' : 'hidden') . '" id="rango-box">';
    echo '<input type="date" name="desde" value="' . e($rng['desde']) . '" class="input flex-1 sm:!w-auto">';
    echo '<span class="text-slate-400">a</span><input type="date" name="hasta" value="' . e($rng['hasta']) . '" class="input flex-1 sm:!w-auto">';
    echo '</div>';
    echo '<span class="text-sm text-slate-500 ml-1">' . fdate($rng['desde']) . ' — ' . fdate($rng['hasta']) . '</span>';
    echo '</form>';
}

function stat_card($label, $value, $icon, $color = 'brand', $sub = '')
{
    $colors = [
        'brand' => 'bg-brand-50 text-brand-600', 'green' => 'bg-emerald-50 text-emerald-600', 'red' => 'bg-rose-50 text-rose-600',
        'yellow' => 'bg-amber-50 text-amber-600', 'blue' => 'bg-sky-50 text-sky-600', 'slate' => 'bg-slate-100 text-slate-600', 'violet' => 'bg-violet-50 text-violet-600',
    ];
    echo '<div class="card p-3.5 sm:p-4 lg:p-5 flex flex-col sm:flex-row items-start gap-2 sm:gap-4"><div class="w-9 h-9 sm:w-11 sm:h-11 rounded-xl grid place-items-center shrink-0 ' . ($colors[$color] ?? $colors['brand']) . '"><i data-lucide="' . e($icon) . '" class="w-4 h-4 sm:w-5 sm:h-5"></i></div>';
    echo '<div class="min-w-0 w-full"><div class="text-xs text-slate-500 font-medium">' . e($label) . '</div><div class="text-lg sm:text-xl xl:text-2xl font-bold tracking-tight leading-tight ' . (preg_match('/^[^a-z]*$/iu', strip_tags(str_replace(currency(), '', (string) $value))) ? 'fit' : 'break-anywhere') . '">' . $value . '</div>';
    if ($sub) echo '<div class="text-xs text-slate-500 mt-0.5 break-anywhere">' . $sub . '</div>';
    echo '</div></div>';
}

function empty_state($msg, $icon = 'inbox')
{
    return '<div class="py-14 text-center text-slate-400"><i data-lucide="' . e($icon) . '" class="w-10 h-10 mx-auto mb-2"></i><div>' . e($msg) . '</div></div>';
}

function pager($total, $per, $pageNum)
{
    $pages = (int) ceil($total / $per);
    if ($pages <= 1) return '';
    $q = $_GET;
    $h = '<div class="flex items-center justify-between gap-2 px-4 py-3 border-t border-slate-100 text-sm"><span class="text-slate-500">' . num($total) . ' registros</span><div class="flex gap-1">';
    for ($i = max(1, $pageNum - 2); $i <= min($pages, $pageNum + 2); $i++) {
        $q['pg'] = $i;
        $h .= '<a href="index.php?' . e(http_build_query($q)) . '" class="px-3 py-1.5 rounded-lg ' . ($i === $pageNum ? 'bg-slate-900 text-white' : 'bg-white border border-slate-200 hover:bg-slate-50') . '">' . $i . '</a>';
    }
    return $h . '</div></div>';
}

function select_options(array $rows, $selected, $valKey = 'id', $labelFn = null, $empty = null)
{
    $h = $empty !== null ? '<option value="">' . e($empty) . '</option>' : '';
    foreach ($rows as $r) {
        $v = $r[$valKey];
        $l = $labelFn ? $labelFn($r) : $r['nombre'];
        $h .= '<option value="' . e($v) . '"' . ((string) $v === (string) $selected ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    return $h;
}
