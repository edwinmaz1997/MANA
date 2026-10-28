<?php
require_perm('admin');
$eid = eid();
if (is_post()) {
    csrf_check();
    $id = (int) post('id');
    if (post('accion') === 'eliminar') {
        update('areas', ['activo' => 0], 'id=? AND empresa_id=?', [$id, $eid]);
        flash('success', 'Área eliminada');
    } else {
        $data = ['nombre' => post('nombre'), 'descripcion' => nullable(post('descripcion')), 'cobrador_id' => nullable(post('cobrador_id'))];
        if ($data['nombre'] === '') { flash('error', 'El nombre es obligatorio'); redirect('areas'); }
        if ($id) update('areas', $data, 'id=? AND empresa_id=?', [$id, $eid]);
        else { $data['empresa_id'] = $eid; insert('areas', $data); }
        flash('success', 'Área guardada');
    }
    redirect('areas');
}
$list = rows("SELECT a.*, u.nombre cob_nombre, u.apellido cob_apellido,
   (SELECT COUNT(*) FROM clientes c WHERE c.area_id=a.id AND c.activo=1) clientes,
   (SELECT COALESCE(SUM(v.saldo),0) FROM ventas v JOIN clientes c ON c.id=v.cliente_id WHERE c.area_id=a.id AND v.tipo='credito' AND v.estado='activa') cartera
   FROM areas a LEFT JOIN usuarios u ON u.id=a.cobrador_id WHERE a.empresa_id=? AND a.activo=1 ORDER BY a.nombre", [$eid]);
$cobradores = rows("SELECT id, nombre, apellido FROM usuarios WHERE empresa_id=? AND activo=1 AND rol IN ('cobrador','admin') ORDER BY nombre", [$eid]);

layout_header('Áreas', ['actions' => '<button onclick="openForm(\'tpl\')" class="btn btn-primary"><i data-lucide="plus"></i>Nueva área</button>']);
?>
<p class="text-sm text-slate-500 mb-4">Las áreas agrupan a los clientes por zona. Asigna un cobrador a cada área para generar sus rutas automáticamente.</p>
<div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-4">
  <?php foreach ($list as $a): ?>
    <div class="card p-5">
      <div class="flex items-start gap-3">
        <div class="w-11 h-11 rounded-xl bg-brand-50 text-brand-600 grid place-items-center"><i data-lucide="map-pinned" class="w-5 h-5"></i></div>
        <div class="min-w-0 flex-1"><div class="font-semibold text-lg"><?= e($a['nombre']) ?></div><div class="text-sm text-slate-500"><?= e($a['descripcion'] ?: '') ?></div></div>
        <button class="btn btn-ghost btn-icon" onclick='openForm("tpl", <?= e(json_encode($a)) ?>)'><i data-lucide="pencil"></i></button>
      </div>
      <div class="grid grid-cols-3 gap-2 mt-4 text-center">
        <div class="rounded-xl bg-slate-50 p-2"><div class="text-xs text-slate-500">Clientes</div><div class="font-bold"><?= (int) $a['clientes'] ?></div></div>
        <div class="rounded-xl bg-slate-50 p-2 col-span-2"><div class="text-xs text-slate-500">Cartera</div><div class="font-bold"><?= money($a['cartera']) ?></div></div>
      </div>
      <div class="mt-3 text-sm flex items-center gap-2"><i data-lucide="user" class="w-4 h-4 text-slate-400"></i> <?= $a['cobrador_id'] ? e(nombre_completo($a, 'cob_')) : '<span class="text-amber-600">Sin cobrador asignado</span>' ?></div>
    </div>
  <?php endforeach; ?>
</div>
<?php if (!$list) echo '<div class="card">' . empty_state('Crea tu primera área (ej. Centro, Aldea El Rosario)', 'map-pinned') . '</div>'; ?>

<div id="tpl" class="hidden">
  <form method="post" class="p-6 space-y-4">
    <?= csrf_field() ?><input type="hidden" name="id">
    <h3 class="text-lg font-semibold" data-title="Nueva área" data-edit="Editar área"></h3>
    <div><label class="label">Nombre *</label><input name="nombre" required class="input" autofocus></div>
    <div><label class="label">Descripción</label><input name="descripcion" class="input"></div>
    <div><label class="label">Cobrador asignado</label><select name="cobrador_id" class="select"><?= select_options($cobradores, '', 'id', 'nombre_completo', '— Ninguno —') ?></select></div>
    <div class="flex gap-2 pt-2"><button class="btn btn-primary flex-1">Guardar</button><button type="button" class="btn btn-light" onclick="closeModal()">Cancelar</button></div>
    <button type="submit" name="accion" value="eliminar" data-onlyedit class="btn btn-ghost text-rose-600 w-full" onclick="return confirm('¿Eliminar esta área?')"><i data-lucide="trash-2"></i> Eliminar</button>
  </form>
</div>
<?php layout_footer();
