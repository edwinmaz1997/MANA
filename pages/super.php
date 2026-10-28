<?php
if (!super_user()) redirect('index.php');
$st = row("SELECT COUNT(*) total, SUM(estado='activa' AND (fecha_vencimiento IS NULL OR fecha_vencimiento>=CURDATE())) activas,
   SUM(estado='activa' AND fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)) por_vencer,
   SUM(estado='suspendida' OR fecha_vencimiento < CURDATE()) inactivas FROM empresas");
$ing = row("SELECT COALESCE(SUM(CASE WHEN fecha_pago >= DATE_FORMAT(CURDATE(),'%Y-%m-01') THEN monto END),0) mes, COALESCE(SUM(CASE WHEN YEAR(fecha_pago)=YEAR(CURDATE()) THEN monto END),0) anio FROM membresia_pagos");
$porVencer = rows("SELECT e.*, p.nombre plan FROM empresas e LEFT JOIN planes p ON p.id=e.plan_id WHERE e.estado='activa' AND e.fecha_vencimiento IS NOT NULL AND e.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 10 DAY) ORDER BY e.fecha_vencimiento LIMIT 15");
$ult = rows("SELECT m.*, e.nombre empresa FROM membresia_pagos m JOIN empresas e ON e.id=m.empresa_id ORDER BY m.id DESC LIMIT 8");
$uso = rows("SELECT e.id, e.nombre, (SELECT COUNT(*) FROM usuarios u WHERE u.empresa_id=e.id AND u.activo=1) usuarios, (SELECT COUNT(*) FROM clientes c WHERE c.empresa_id=e.id AND c.activo=1) clientes, (SELECT COUNT(*) FROM ventas v WHERE v.empresa_id=e.id AND v.fecha >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) ventas30 FROM empresas e ORDER BY ventas30 DESC LIMIT 10");

layout_header('Panel de empresas', ['actions' => '<a href="' . url('super_empresas', ['nueva' => 1]) . '" class="btn btn-primary"><i data-lucide="plus"></i>Nueva empresa</a>']);
?>
<div class="grid grid-cols-2 xl:grid-cols-5 gap-3">
  <?php stat_card('Empresas', (int) $st['total'], 'building-2', 'brand'); ?>
  <?php stat_card('Activas', (int) $st['activas'], 'check-circle', 'green'); ?>
  <?php stat_card('Por vencer (7 días)', (int) $st['por_vencer'], 'clock', 'yellow'); ?>
  <?php stat_card('Vencidas / suspendidas', (int) $st['inactivas'], 'ban', 'red'); ?>
  <?php stat_card('Ingresos del mes', money($ing['mes']), 'banknote', 'violet', 'Año: ' . money($ing['anio'])); ?>
</div>
<div class="grid lg:grid-cols-2 gap-4 mt-4">
  <div class="card overflow-hidden"><div class="card-h"><i data-lucide="clock" class="w-4 h-4 text-amber-500"></i> Membresías por vencer</div>
    <div class="divide-y divide-slate-100"><?php foreach ($porVencer as $e): $d = dias_entre(today(), $e['fecha_vencimiento']); ?>
      <a href="<?= url('super_empresas', ['editar' => $e['id']]) ?>" class="flex items-center px-5 py-3 hover:bg-slate-50"><div><div class="font-medium"><?= e($e['nombre']) ?></div><div class="text-xs text-slate-500"><?= e($e['plan']) ?> · vence <?= fdate($e['fecha_vencimiento']) ?></div></div><span class="ml-auto badge <?= $d < 0 ? 'badge-red' : ($d <= 3 ? 'badge-yellow' : 'badge-gray') ?>"><?= $d < 0 ? 'Vencida hace ' . abs($d) . 'd' : $d . ' días' ?></span></a>
    <?php endforeach; if (!$porVencer) echo empty_state('Nada por vencer', 'check'); ?></div></div>
  <div class="card overflow-hidden"><div class="card-h"><i data-lucide="activity" class="w-4 h-4 text-brand-600"></i> Uso (últimos 30 días)</div>
    <table class="table"><thead><tr><th>Empresa</th><th class="num">Usuarios</th><th class="num">Clientes</th><th class="num">Ventas</th></tr></thead>
    <tbody><?php foreach ($uso as $u): ?><tr><td><?= e($u['nombre']) ?></td><td class="num"><?= (int) $u['usuarios'] ?></td><td class="num"><?= (int) $u['clientes'] ?></td><td class="num"><?= (int) $u['ventas30'] ?></td></tr><?php endforeach; ?></tbody></table></div>
</div>
<div class="card mt-4 overflow-hidden"><div class="card-h"><i data-lucide="receipt" class="w-4 h-4 text-brand-600"></i> Últimos pagos de membresía</div>
  <table class="table"><thead><tr><th>Fecha</th><th>Empresa</th><th>Periodo</th><th class="num">Monto</th></tr></thead>
  <tbody><?php foreach ($ult as $p): ?><tr><td><?= fdate($p['fecha_pago']) ?></td><td><?= e($p['empresa']) ?></td><td><?= fdate($p['desde']) ?> – <?= fdate($p['hasta']) ?></td><td class="num font-semibold"><?= money($p['monto']) ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php layout_footer();
