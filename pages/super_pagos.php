<?php
if (!super_user()) redirect('index.php');
if (is_post() && post('accion') === 'eliminar') {
    csrf_check();
    q('DELETE FROM membresia_pagos WHERE id=?', [(int) post('id')]);
    flash('success', 'Pago eliminado (el vencimiento de la empresa no se modificó)');
    redirect('super_pagos');
}
$rng = rango_fechas();
$list = rows('SELECT m.*, e.nombre empresa, p.nombre plan FROM membresia_pagos m JOIN empresas e ON e.id=m.empresa_id LEFT JOIN planes p ON p.id=m.plan_id WHERE m.fecha_pago BETWEEN ? AND ? ORDER BY m.fecha_pago DESC, m.id DESC', [$rng['desde'], $rng['hasta']]);
layout_header('Pagos de membresía');
filtro_fechas($rng);
?>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4"><?php stat_card('Total recibido', money(array_sum(array_column($list, 'monto'))), 'banknote', 'green', count($list) . ' pago(s)'); ?></div>
<div class="card overflow-hidden"><div class="overflow-x-auto"><table class="table">
  <thead><tr><th>Fecha</th><th>Empresa</th><th>Plan</th><th>Periodo</th><th>Método</th><th class="num">Monto</th><th></th></tr></thead>
  <tbody><?php foreach ($list as $p): ?>
    <tr><td><?= fdate($p['fecha_pago']) ?></td><td class="font-medium"><?= e($p['empresa']) ?></td><td><?= e($p['plan']) ?></td><td><?= fdate($p['desde']) ?> – <?= fdate($p['hasta']) ?></td><td><?= e($p['metodo']) ?></td><td class="num font-semibold"><?= money($p['monto']) ?></td>
      <td><form method="post" data-confirm="¿Eliminar este pago?"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn btn-ghost btn-icon text-rose-500"><i data-lucide="trash-2"></i></button></form></td></tr>
  <?php endforeach; ?></tbody></table><?php if (!$list) echo empty_state('Sin pagos en este periodo', 'receipt'); ?></div></div>
<?php layout_footer();
