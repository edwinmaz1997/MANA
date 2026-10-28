<?php
require_perm('cobros');
$eid = eid();
$rng = rango_fechas();
if (!get('r')) $rng = ['r' => 'hoy', 'desde' => today(), 'hasta' => today()];
$usr = is_admin() ? (int) get('usuario', 0) : uid();

$w = 'a.empresa_id=? AND a.anulado=0 AND DATE(a.fecha) BETWEEN ? AND ?'; $params = [$eid, $rng['desde'], $rng['hasta']];
if ($usr) { $w .= ' AND a.usuario_id=?'; $params[] = $usr; }
list($list, $nTot, $pg, $per) = paginate("SELECT a.*, v.codigo, c.nombre, c.apellido, u.nombre usuario FROM abonos a JOIN ventas v ON v.id=a.venta_id LEFT JOIN clientes c ON c.id=a.cliente_id LEFT JOIN usuarios u ON u.id=a.usuario_id WHERE $w ORDER BY a.fecha DESC", $params);
$sum = row("SELECT COALESCE(SUM(a.monto),0) t, COALESCE(SUM(a.comision_monto),0) c FROM abonos a WHERE $w", $params);
$tot = (float) $sum['t']; $com = (float) $sum['c'];
$usuarios = is_admin() ? rows('SELECT id, nombre, apellido FROM usuarios WHERE empresa_id=? ORDER BY nombre', [$eid]) : [];

layout_header('Cobros');
?>
<div class="card p-4 mb-4 relative">
  <label class="label">Registrar cobro rápido</label>
  <div class="relative"><i data-lucide="search" class="w-4 h-4 absolute left-3 top-3.5 text-slate-400"></i>
    <input id="cq" class="input input-lg pl-9" placeholder="Buscar crédito por cliente, código o teléfono" autocomplete="off">
    <div id="csug" class="suggest hidden"></div></div>
</div>

<?php filtro_fechas($rng, $usr && is_admin() ? ['usuario' => $usr] : []); ?>
<?php if ($usuarios): ?>
<form class="mb-4"><input type="hidden" name="p" value="cobros"><input type="hidden" name="r" value="<?= e($rng['r']) ?>"><input type="hidden" name="desde" value="<?= e($rng['desde']) ?>"><input type="hidden" name="hasta" value="<?= e($rng['hasta']) ?>">
  <select name="usuario" class="select sm:w-64" onchange="this.form.submit()"><?= select_options($usuarios, $usr, 'id', 'nombre_completo', 'Todos los usuarios') ?></select></form>
<?php endif; ?>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
  <?php stat_card('Total cobrado', money($tot), 'hand-coins', 'green', num($nTot) . ' pago(s)'); ?>
  <?php stat_card('Comisiones', money($com), 'percent', 'violet'); ?>
  <?php stat_card('Neto a entregar', money($tot - $com), 'banknote', 'brand'); ?>
</div>

<div class="card overflow-hidden"><div class="overflow-x-auto"><table class="table">
  <thead><tr><th>Fecha</th><th>Cliente</th><th>Venta</th><th>Tipo</th><th>Recibió</th><th class="num">Monto</th><th class="num">Comisión</th><th>Estado</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($list as $a): ?>
    <tr><td><?= fdate($a['fecha'], true) ?></td><td><?= e(nombre_completo($a)) ?></td><td><a class="text-brand-600 font-medium" href="<?= url('venta', ['id' => $a['venta_id']]) ?>"><?= e($a['codigo']) ?></a></td><td><?= e(ucfirst($a['tipo'])) ?></td><td><?= e($a['usuario']) ?></td>
      <td class="num font-semibold"><?= money($a['monto']) ?></td><td class="num text-slate-500"><?= money($a['comision_monto']) ?></td>
      <td><?= $a['liquidacion_id'] ? '<span class="badge badge-green">Entregado</span>' : '<span class="badge badge-yellow">Por entregar</span>' ?></td>
      <td class="whitespace-nowrap"><a href="ticket.php?tipo=abono&id=<?= $a['id'] ?>" target="_blank" class="btn btn-ghost btn-icon"><i data-lucide="printer"></i></a><?php if (puede_editar_abono($a) && $a['tipo'] !== 'contado'): ?><a href="<?= url('venta', ['id' => $a['venta_id'], 'editar_abono' => $a['id']]) ?>" class="btn btn-ghost btn-icon text-brand-600" title="Corregir pago"><i data-lucide="pencil"></i></a><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table><?php if (!$list) echo empty_state('Sin cobros en este periodo', 'coins'); ?></div><?= pager($nTot, $per, $pg) ?></div>

<script>
(function () {
  var inp = document.getElementById('cq'), box = document.getElementById('csug'), t;
  inp.addEventListener('input', function () {
    clearTimeout(t); var q = inp.value.trim(); if (!q) { box.classList.add('hidden'); return; }
    t = setTimeout(async function () {
      var r = await api('index.php?p=api&a=creditos&q=' + encodeURIComponent(q));
      box.innerHTML = (r.data || []).map(function (c) {
        return '<div onclick="location.href=\'index.php?p=venta&id=' + c.id + '&cobrar=1\'"><div class="flex justify-between gap-2"><div><div class="font-medium">' + escapeHtml(c.nombre + ' ' + c.apellido) + '</div><div class="text-xs text-slate-500">' + escapeHtml(c.codigo) + ' · ' + escapeHtml(c.sem[1]) + '</div></div><div class="text-right"><div class="font-semibold">' + money(c.saldo) + '</div><div class="text-xs text-slate-500">Cuota ' + money(c.cuota_monto) + '</div></div></div></div>';
      }).join('') || '<div class="text-slate-400">Sin créditos activos</div>';
      box.classList.remove('hidden');
    }, 220);
  });
  inp.addEventListener('blur', function () { setTimeout(function () { box.classList.add('hidden'); }, 200); });
})();
</script>
<?php layout_footer();
