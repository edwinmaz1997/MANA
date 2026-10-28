<?php
require_perm('admin');
$eid = eid();
$fecha = get('fecha', today());
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $fecha = today();

if (is_post() && post('accion') === 'generar_todas') {
    csrf_check();
    $tot = 0;
    foreach (rows("SELECT DISTINCT cobrador_id FROM areas WHERE empresa_id=? AND activo=1 AND cobrador_id IS NOT NULL", [$eid]) as $r) {
        list(, $n) = generar_ruta((int) $r['cobrador_id'], $fecha);
        $tot += $n;
    }
    flash('success', "Rutas generadas. $tot visita(s) agregadas.");
    redirect(url('rutas', ['fecha' => $fecha]));
}

// Mantener al día las rutas de hoy (agrega ventas nuevas y cuotas que vencen hoy)
if ($fecha === today()) {
    foreach (rows("SELECT DISTINCT cobrador_id FROM areas WHERE empresa_id=? AND activo=1 AND cobrador_id IS NOT NULL", [$eid]) as $r) generar_ruta((int) $r['cobrador_id'], $fecha);
}
$cobs = rows("SELECT u.id, u.nombre, u.apellido, u.rol,
    (SELECT GROUP_CONCAT(a.nombre SEPARATOR ', ') FROM areas a WHERE a.cobrador_id=u.id AND a.activo=1) areas,
    r.id ruta_id
    FROM usuarios u LEFT JOIN rutas r ON r.cobrador_id=u.id AND r.fecha=? AND r.empresa_id=u.empresa_id
    WHERE u.empresa_id=? AND u.activo=1 AND (u.rol='cobrador' OR EXISTS(SELECT 1 FROM areas a WHERE a.cobrador_id=u.id AND a.activo=1))
    ORDER BY u.nombre", [$fecha, $eid]);

$atrasadas = rows("SELECT rv.*, c.nombre, c.apellido, v.codigo, u.nombre cobrador, r.fecha ruta_fecha, a.nombre area
    FROM ruta_visitas rv JOIN rutas r ON r.id=rv.ruta_id JOIN ventas v ON v.id=rv.venta_id LEFT JOIN clientes c ON c.id=rv.cliente_id
    LEFT JOIN usuarios u ON u.id=r.cobrador_id LEFT JOIN areas a ON a.id=rv.area_id
    WHERE r.empresa_id=? AND r.fecha=? AND rv.atrasada=1 AND rv.estado='pendiente' ORDER BY rv.fecha_programada LIMIT 100", [$eid, $fecha]);
$sinArea = (int) val("SELECT COUNT(*) FROM ventas v JOIN clientes c ON c.id=v.cliente_id LEFT JOIN areas a ON a.id=c.area_id WHERE v.empresa_id=? AND v.tipo='credito' AND v.estado='activa' AND (c.area_id IS NULL OR a.cobrador_id IS NULL)", [$eid]);

layout_header('Rutas de cobro', ['actions' => '<form method="post">' . csrf_field() . '<input type="hidden" name="accion" value="generar_todas"><button class="btn btn-primary"><i data-lucide="wand-sparkles"></i>Generar rutas automáticas</button></form>']);
?>
<form class="flex items-center gap-2 mb-4"><input type="hidden" name="p" value="rutas"><input type="date" name="fecha" value="<?= e($fecha) ?>" class="input !w-auto" onchange="this.form.submit()"><span class="text-sm text-slate-500">Las rutas incluyen los créditos con cuota vencida a la fecha, enganches pendientes y visitas atrasadas.</span></form>
<?php if ($sinArea): ?><div class="mb-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 text-sm flex items-center gap-2"><i data-lucide="alert-triangle" class="w-4 h-4"></i> <?= $sinArea ?> crédito(s) activos son de clientes sin área o de áreas sin cobrador, por eso no entran en ninguna ruta. <a href="<?= url('areas') ?>" class="underline font-semibold">Revisar áreas</a></div><?php endif; ?>

<div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-4">
<?php foreach ($cobs as $c):
    $st = $c['ruta_id'] ? row("SELECT COUNT(*) t, SUM(estado<>'pendiente') hechas, SUM(estado='pago') pagos, SUM(atrasada=1 AND estado='pendiente') atr FROM ruta_visitas WHERE ruta_id=? AND estado<>'reprogramada'", [$c['ruta_id']]) : null;
    $res = resumen_cobrador($c['id'], $fecha, $fecha);
    $pct = $st && $st['t'] ? round($st['hechas'] / $st['t'] * 100) : 0; ?>
  <a href="<?= url('ruta', ['cobrador' => $c['id'], 'fecha' => $fecha]) ?>" class="card p-5 hover:border-brand-300 transition block">
    <div class="flex items-center gap-3">
      <div class="w-11 h-11 rounded-full bg-brand-600 text-white grid place-items-center font-semibold"><?= e(mb_strtoupper(mb_substr($c['nombre'], 0, 1))) ?></div>
      <div class="min-w-0"><div class="font-semibold"><?= e(nombre_completo($c)) ?></div><div class="text-xs text-slate-500 truncate"><?= e($c['areas'] ?: 'Sin áreas asignadas') ?></div></div>
    </div>
    <?php if ($st): ?>
      <div class="flex justify-between text-sm mt-4"><span>Visitas <?= (int) $st['hechas'] ?>/<?= (int) $st['t'] ?></span><span class="font-semibold"><?= $pct ?>%</span></div>
      <div class="h-2 rounded-full bg-slate-100 mt-1 overflow-hidden"><div class="h-full bg-emerald-500" style="width:<?= $pct ?>%"></div></div>
      <div class="grid grid-cols-3 gap-2 mt-4 text-center text-xs">
        <div class="rounded-lg bg-emerald-50 p-2"><div class="text-emerald-700">Cobrado</div><div class="font-bold text-sm"><?= money($res['cobrado']) ?></div></div>
        <div class="rounded-lg bg-slate-50 p-2"><div class="text-slate-500">Con pago</div><div class="font-bold text-sm"><?= (int) $st['pagos'] ?></div></div>
        <div class="rounded-lg <?= $st['atr'] ? 'bg-rose-50' : 'bg-slate-50' ?> p-2"><div class="<?= $st['atr'] ? 'text-rose-600' : 'text-slate-500' ?>">Atrasadas</div><div class="font-bold text-sm"><?= (int) $st['atr'] ?></div></div>
      </div>
    <?php else: ?><div class="mt-4 text-sm text-slate-400">Sin ruta generada para esta fecha</div><?php endif; ?>
  </a>
<?php endforeach; ?>
</div>
<?php if (!$cobs) echo '<div class="card">' . empty_state('No hay cobradores. Crea usuarios con rol Cobrador y asígnales áreas.', 'route') . '</div>'; ?>

<div class="card mt-6 overflow-hidden">
  <div class="card-h"><i data-lucide="clock-alert" class="w-4 h-4 text-rose-500"></i> Visitas atrasadas pendientes (<?= count($atrasadas) ?>)</div>
  <div class="overflow-x-auto"><table class="table">
    <thead><tr><th>Cliente</th><th>Área</th><th>Cobrador</th><th>Crédito</th><th>Debía visitarse</th><th class="num">Días</th></tr></thead>
    <tbody><?php foreach ($atrasadas as $a): ?><tr><td class="font-medium"><?= e(nombre_completo($a)) ?></td><td><?= e($a['area']) ?></td><td><?= e($a['cobrador']) ?></td><td><a class="text-brand-600" href="<?= url('venta', ['id' => $a['venta_id']]) ?>"><?= e($a['codigo']) ?></a></td><td><?= fdate($a['fecha_programada']) ?></td><td class="num text-rose-600 font-semibold"><?= max(0, dias_entre($a['fecha_programada'], $fecha)) ?></td></tr><?php endforeach; ?></tbody>
  </table><?php if (!$atrasadas) echo empty_state('Sin visitas atrasadas', 'check-circle'); ?></div>
</div>
<?php layout_footer();
