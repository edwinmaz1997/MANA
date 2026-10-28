<?php
require_perm('admin');
$eid = eid();
if (is_post()) {
    csrf_check();
    $id = (int) post('id');
    if (post('accion') === 'eliminar') {
        update('proveedores', ['activo' => 0], 'id=? AND empresa_id=?', [$id, $eid]);
        flash('success', 'Proveedor eliminado');
    } else {
        $data = ['nombre' => post('nombre'), 'apellido' => post('apellido', ''), 'telefono' => nullable(post('telefono')), 'nit' => nullable(post('nit')), 'direccion' => nullable(post('direccion'))];
        if ($data['nombre'] === '') { flash('error', 'El nombre es obligatorio'); redirect('proveedores'); }
        if ($id) update('proveedores', $data, 'id=? AND empresa_id=?', [$id, $eid]);
        else { $data['empresa_id'] = $eid; insert('proveedores', $data); }
        flash('success', 'Proveedor guardado');
    }
    redirect('proveedores');
}
$qstr = get('q', '');
$params = [$eid];
$w = '';
if ($qstr !== '') { $w = ' AND (p.nombre LIKE ? OR p.apellido LIKE ? OR p.nit LIKE ? OR p.telefono LIKE ?)'; $l = "%$qstr%"; array_push($params, $l, $l, $l, $l); }
list($list, $nTot, $pg, $per) = paginate("SELECT p.*, (SELECT COALESCE(SUM(total),0) FROM compras c WHERE c.proveedor_id=p.id) comprado, (SELECT COUNT(*) FROM compras c WHERE c.proveedor_id=p.id) compras
  FROM proveedores p WHERE p.empresa_id=? AND p.activo=1 $w ORDER BY p.nombre", $params);

layout_header('Proveedores', ['actions' => '<button onclick="openForm(\'tpl\')" class="btn btn-primary"><i data-lucide="plus"></i>Nuevo proveedor</button>']);
?>
<form class="mb-4 flex gap-2"><input type="hidden" name="p" value="proveedores"><input type="search" name="q" value="<?= e($qstr) ?>" class="input" placeholder="Buscar proveedor, NIT o teléfono"></form>
<div class="card overflow-hidden"><div class="overflow-x-auto"><table class="table">
  <thead><tr><th>Proveedor</th><th>Teléfono</th><th>NIT</th><th>Dirección</th><th class="num">Compras</th><th class="num">Total comprado</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($list as $p): ?>
    <tr><td class="font-semibold"><?= e(nombre_completo($p)) ?></td><td><?= e($p['telefono']) ?></td><td><?= e($p['nit']) ?></td><td class="max-w-xs truncate"><?= e($p['direccion']) ?></td><td class="num"><?= (int) $p['compras'] ?></td><td class="num font-semibold"><?= money($p['comprado']) ?></td>
      <td class="text-right"><button class="btn btn-light btn-sm" onclick='openForm("tpl", <?= e(json_encode($p)) ?>)'>Editar</button></td></tr>
  <?php endforeach; ?>
  </tbody></table><?php if (!$list) echo empty_state('Sin proveedores', 'factory'); ?></div><?= pager($nTot, $per, $pg) ?></div>

<div id="tpl" class="hidden">
  <form method="post" class="p-6 space-y-4">
    <?= csrf_field() ?><input type="hidden" name="id">
    <h3 class="text-lg font-semibold" data-title="Nuevo proveedor" data-edit="Editar proveedor"></h3>
    <div class="grid grid-cols-2 gap-3">
      <div><label class="label">Nombre *</label><input name="nombre" required class="input" autofocus></div>
      <div><label class="label">Apellido</label><input name="apellido" class="input"></div>
      <div><label class="label">Número de teléfono</label><input name="telefono" class="input"></div>
      <div><label class="label">NIT</label><input name="nit" class="input"></div>
    </div>
    <div><label class="label">Dirección</label><input name="direccion" class="input"></div>
    <div class="flex gap-2 pt-2"><button class="btn btn-primary flex-1">Guardar</button><button type="button" class="btn btn-light" onclick="closeModal()">Cancelar</button></div>
    <button type="submit" name="accion" value="eliminar" data-onlyedit class="btn btn-ghost text-rose-600 w-full" onclick="return confirm('¿Eliminar proveedor?')"><i data-lucide="trash-2"></i> Eliminar</button>
  </form>
</div>
<?php layout_footer();
