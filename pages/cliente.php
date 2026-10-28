<?php
require_perm('clientes');
$eid = eid();
$id = (int) get('id');
$c = row('SELECT c.*, a.nombre area_nombre, u.nombre creador FROM clientes c LEFT JOIN areas a ON a.id=c.area_id LEFT JOIN usuarios u ON u.id=c.created_by WHERE c.id=? AND c.empresa_id=?', [$id, $eid]);
if (!$c) redirect('clientes');

if (is_post() && post('accion') === 'eliminar' && is_admin()) {
    csrf_check();
    if (val("SELECT COUNT(*) FROM ventas WHERE cliente_id=? AND estado='activa' AND tipo='credito'", [$id])) {
        flash('error', 'El cliente tiene créditos activos, no se puede eliminar');
    } else {
        update('clientes', ['activo' => 0], 'id=?', [$id]);
        flash('success', 'Cliente eliminado');
        redirect('clientes');
    }
    redirect(url('cliente', ['id' => $id]));
}

$ventas = rows("SELECT v.*, u.nombre vendedor, " . sql_credito_cols() . " FROM ventas v LEFT JOIN usuarios u ON u.id=v.vendedor_id WHERE v.cliente_id=? AND v.empresa_id=? ORDER BY v.id DESC", [$id, $eid]);
$abonos = rows("SELECT a.*, v.codigo, u.nombre usuario FROM abonos a JOIN ventas v ON v.id=a.venta_id LEFT JOIN usuarios u ON u.id=a.usuario_id WHERE a.cliente_id=? AND a.anulado=0 ORDER BY a.fecha DESC LIMIT 30", [$id]);
$tot = row("SELECT COALESCE(SUM(total),0) comprado, COALESCE(SUM(CASE WHEN tipo='credito' AND estado='activa' THEN saldo ELSE 0 END),0) saldo FROM ventas WHERE cliente_id=? AND estado<>'anulada'", [$id]);

$canEdit = is_admin() || (int) $c['created_by'] === uid();
layout_header('Cliente', ['actions' =>
    ($canEdit ? '<a href="' . url('cliente_form', ['id' => $id]) . '" class="btn btn-light"><i data-lucide="pencil"></i><span class="hidden sm:inline">Editar</span></a>' : '') .
    (can('ventas.crear') ? '<a href="' . url('ventas_nueva', ['cliente' => $id]) . '" class="btn btn-primary"><i data-lucide="shopping-cart"></i><span class="hidden sm:inline">Vender</span></a>' : '')]);
?>
<div class="grid lg:grid-cols-3 gap-4">
  <div class="space-y-4">
    <div class="card overflow-hidden">
      <?php if ($c['foto_casa']): ?><a href="<?= e($c['foto_casa']) ?>" target="_blank"><img src="<?= e($c['foto_casa']) ?>" class="w-full aspect-video object-cover"></a><?php else: ?><div class="w-full aspect-video bg-slate-100 grid place-items-center text-slate-300"><i data-lucide="home" class="w-12 h-12"></i></div><?php endif; ?>
      <div class="p-5">
        <div class="text-xs font-semibold text-brand-600"><?= e($c['codigo']) ?></div>
        <h2 class="text-xl font-bold"><?= e(nombre_completo($c)) ?></h2>
        <dl class="mt-4 space-y-2.5 text-sm">
          <div class="flex gap-3"><i data-lucide="phone" class="w-4 h-4 text-slate-400 mt-0.5"></i><span><?= e($c['telefono'] ?: '—') ?></span></div>
          <div class="flex gap-3"><i data-lucide="map-pin" class="w-4 h-4 text-slate-400 mt-0.5"></i><span><?= nl2br(e($c['direccion'] ?: '—')) ?></span></div>
          <div class="flex gap-3"><i data-lucide="map" class="w-4 h-4 text-slate-400 mt-0.5"></i><span>Área: <?= e($c['area_nombre'] ?: '—') ?></span></div>
          <div class="flex gap-3"><i data-lucide="id-card" class="w-4 h-4 text-slate-400 mt-0.5"></i><span>DPI: <?= e($c['dpi'] ?: '—') ?></span></div>
          <?php if ($c['notas']): ?><div class="flex gap-3"><i data-lucide="sticky-note" class="w-4 h-4 text-slate-400 mt-0.5"></i><span><?= nl2br(e($c['notas'])) ?></span></div><?php endif; ?>
        </dl>
        <div class="grid grid-cols-3 gap-2 mt-5">
          <?php if ($c['telefono']): ?>
            <a href="tel:<?= e($c['telefono']) ?>" class="btn btn-light flex-col !gap-1 text-xs"><i data-lucide="phone-call"></i>Llamar</a>
            <a href="<?= e(wa_link($c['telefono'])) ?>" target="_blank" class="btn btn-light flex-col !gap-1 text-xs"><i data-lucide="message-circle"></i>WhatsApp</a>
          <?php endif; ?>
          <?php if ($m = maps_link($c)): ?><a href="<?= e($m) ?>" target="_blank" class="btn btn-light flex-col !gap-1 text-xs"><i data-lucide="navigation"></i>Cómo llegar</a><?php endif; ?>
        </div>
        <div class="text-xs text-slate-400 mt-4">Registrado <?= fdate($c['created_at']) ?><?= $c['creador'] ? ' por ' . e($c['creador']) : '' ?></div>
      </div>
    </div>
    <?php if (is_admin()): ?>
      <form method="post" data-confirm="¿Eliminar este cliente?"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><button class="btn btn-ghost text-rose-600 w-full"><i data-lucide="trash-2"></i> Eliminar cliente</button></form>
    <?php endif; ?>
  </div>

  <div class="lg:col-span-2 space-y-4">
    <div class="grid grid-cols-2 gap-3">
      <?php stat_card('Total comprado', money($tot['comprado']), 'shopping-bag', 'brand'); ?>
      <?php stat_card('Saldo pendiente', money($tot['saldo']), 'wallet', $tot['saldo'] > 0 ? 'yellow' : 'green'); ?>
    </div>
    <div class="card">
      <div class="card-h"><i data-lucide="receipt-text" class="w-4 h-4 text-brand-600"></i> Ventas y créditos</div>
      <div class="divide-y divide-slate-100">
        <?php foreach ($ventas as $v): $s = semaforo($v); ?>
          <a href="<?= url('venta', ['id' => $v['id']]) ?>" class="flex items-center gap-3 px-5 py-3.5 hover:bg-slate-50 sem-<?= $s[0] ?>">
            <div class="min-w-0 flex-1">
              <div class="font-semibold"><?= e($v['codigo']) ?> <span class="badge <?= $v['tipo'] === 'credito' ? 'badge-violet' : 'badge-gray' ?> ml-1"><?= e(tipo_label($v['tipo'])) ?></span></div>
              <div class="text-xs text-slate-500"><?= fdate($v['fecha']) ?> · <?= e($v['vendedor']) ?><?= $v['tipo'] === 'credito' ? ' · ' . (int) $v['plazo_meses'] . ' meses' : '' ?></div>
            </div>
            <div class="text-right">
              <div class="font-semibold"><?= money($v['total']) ?></div>
              <span class="badge <?= $s[2] ?>"><?= e($s[1]) ?></span>
              <?php if ($v['tipo'] === 'credito' && $v['saldo'] > 0): ?><div class="text-xs text-slate-500 mt-0.5">Saldo <?= money($v['saldo']) ?></div><?php endif; ?>
            </div>
          </a>
        <?php endforeach; if (!$ventas) echo empty_state('Sin ventas registradas', 'shopping-cart'); ?>
      </div>
    </div>
    <div class="card">
      <div class="card-h"><i data-lucide="history" class="w-4 h-4 text-brand-600"></i> Últimos pagos</div>
      <div class="overflow-x-auto"><table class="table">
        <thead><tr><th>Fecha</th><th>Venta</th><th>Tipo</th><th>Recibió</th><th class="num">Monto</th></tr></thead>
        <tbody><?php foreach ($abonos as $a): ?><tr><td><?= fdate($a['fecha'], true) ?></td><td><?= e($a['codigo']) ?></td><td><?= e(ucfirst($a['tipo'])) ?></td><td><?= e($a['usuario']) ?></td><td class="num font-semibold"><?= money($a['monto']) ?></td></tr><?php endforeach; ?></tbody>
      </table><?php if (!$abonos) echo empty_state('Sin pagos', 'coins'); ?></div>
    </div>
  </div>
</div>
<?php layout_footer();
