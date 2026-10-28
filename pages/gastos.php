<?php
require_perm('admin');
$eid = eid();
if (is_post()) {
    csrf_check();
    $id = (int) post('id');
    if (post('accion') === 'eliminar') {
        q('DELETE FROM gastos WHERE id=? AND empresa_id=?', [$id, $eid]);
        flash('success', 'Gasto eliminado');
    } else {
        $data = ['categoria' => post('categoria') ?: 'General', 'descripcion' => post('descripcion'), 'monto' => dec(post('monto')), 'fecha' => post('fecha') ?: today()];
        if ($data['descripcion'] === '' || $data['monto'] <= 0) { flash('error', 'Descripción y monto son obligatorios'); redirect('gastos'); }
        if ($id) update('gastos', $data, 'id=? AND empresa_id=?', [$id, $eid]);
        else { $data['empresa_id'] = $eid; $data['usuario_id'] = uid(); insert('gastos', $data); }
        flash('success', 'Gasto guardado');
    }
    redirect(url('gastos', ['r' => get('r', 'mes')]));
}
$rng = rango_fechas();
$list = rows('SELECT g.*, u.nombre usuario FROM gastos g LEFT JOIN usuarios u ON u.id=g.usuario_id WHERE g.empresa_id=? AND g.fecha BETWEEN ? AND ? ORDER BY g.fecha DESC, g.id DESC', [$eid, $rng['desde'], $rng['hasta']]);
$porCat = rows('SELECT categoria, SUM(monto) t FROM gastos WHERE empresa_id=? AND fecha BETWEEN ? AND ? GROUP BY categoria ORDER BY t DESC', [$eid, $rng['desde'], $rng['hasta']]);
$total = array_sum(array_column($list, 'monto'));
$cats = array_column(rows('SELECT DISTINCT categoria FROM gastos WHERE empresa_id=? ORDER BY categoria', [$eid]), 'categoria');
$cats = array_unique(array_merge(['General', 'Combustible', 'Alquiler', 'Servicios', 'Sueldos', 'Mantenimiento', 'Publicidad'], $cats));

layout_header('Gastos', ['actions' => '<button onclick="openForm(\'tpl\', {fecha:\'' . today() . '\'})" class="btn btn-primary"><i data-lucide="plus"></i>Registrar gasto</button>']);
filtro_fechas($rng);
?>
<div class="grid lg:grid-cols-4 gap-4">
  <div class="space-y-4">
    <?php stat_card('Total gastos', money($total), 'wallet', 'red', count($list) . ' registro(s)'); ?>
    <div class="card"><div class="card-h">Por categoría</div><div class="p-4 space-y-2 text-sm">
      <?php foreach ($porCat as $c): $pct = $total > 0 ? $c['t'] / $total * 100 : 0; ?>
        <div><div class="flex justify-between"><span><?= e($c['categoria']) ?></span><span class="font-semibold"><?= money($c['t']) ?></span></div><div class="h-1.5 bg-slate-100 rounded mt-1"><div class="h-1.5 bg-rose-400 rounded" style="width:<?= round($pct) ?>%"></div></div></div>
      <?php endforeach; ?>
    </div></div>
  </div>
  <div class="card lg:col-span-3 overflow-hidden"><div class="overflow-x-auto"><table class="table">
    <thead><tr><th>Fecha</th><th>Categoría</th><th>Descripción</th><th>Registró</th><th class="num">Monto</th><th></th></tr></thead>
    <tbody><?php foreach ($list as $g): ?>
      <tr><td><?= fdate($g['fecha']) ?></td><td><span class="badge badge-gray"><?= e($g['categoria']) ?></span></td><td><?= e($g['descripcion']) ?></td><td><?= e($g['usuario']) ?></td><td class="num font-semibold"><?= money($g['monto']) ?></td>
      <td class="text-right"><button class="btn btn-light btn-sm" onclick='openForm("tpl", <?= e(json_encode($g)) ?>)'>Editar</button></td></tr>
    <?php endforeach; ?></tbody></table><?php if (!$list) echo empty_state('Sin gastos en este periodo', 'wallet'); ?></div></div>
</div>

<div id="tpl" class="hidden">
  <form method="post" class="p-6 space-y-4">
    <?= csrf_field() ?><input type="hidden" name="id">
    <h3 class="text-lg font-semibold" data-title="Registrar gasto" data-edit="Editar gasto"></h3>
    <div class="grid grid-cols-2 gap-3">
      <div><label class="label">Fecha</label><input type="date" name="fecha" class="input"></div>
      <div><label class="label">Monto *</label><input type="number" step="0.01" min="0.01" name="monto" required class="input" autofocus></div>
    </div>
    <div><label class="label">Categoría</label><input name="categoria" list="cats" class="input" placeholder="General"><datalist id="cats"><?php foreach ($cats as $c) echo '<option value="' . e($c) . '">'; ?></datalist></div>
    <div><label class="label">Descripción *</label><input name="descripcion" required class="input"></div>
    <div class="flex gap-2 pt-2"><button class="btn btn-primary flex-1">Guardar</button><button type="button" class="btn btn-light" onclick="closeModal()">Cancelar</button></div>
    <button type="submit" name="accion" value="eliminar" data-onlyedit class="btn btn-ghost text-rose-600 w-full" onclick="return confirm('¿Eliminar gasto?')"><i data-lucide="trash-2"></i> Eliminar</button>
  </form>
</div>
<?php layout_footer();
