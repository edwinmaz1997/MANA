<?php
require_perm('admin');
$eid = eid();
if (is_post()) {
    csrf_check();
    $id = (int) post('id');
    $data = [
        'nombre' => post('nombre'), 'apellido' => post('apellido', ''), 'telefono' => nullable(post('telefono')),
        'direccion' => nullable(post('direccion')), 'dpi' => nullable(post('dpi')), 'usuario' => strtolower(post('usuario', '')),
        'rol' => in_array(post('rol'), ['admin', 'vendedor', 'cobrador'], true) ? post('rol') : 'vendedor',
        'comision_cobro_pct' => dec(post('comision_cobro_pct')), 'comision_venta_pct' => dec(post('comision_venta_pct')),
        'salario_base' => dec(post('salario_base')), 'activo' => post('activo') ? 1 : 0,
    ];
    try {
        if ($data['nombre'] === '' || $data['usuario'] === '') throw new RuntimeException('Nombre y usuario son obligatorios');
        if (!preg_match('/^[a-z0-9._@-]{3,60}$/', $data['usuario'])) throw new RuntimeException('El usuario solo puede tener letras, números y . _ - @ (mínimo 3)');
        if (val('SELECT id FROM usuarios WHERE usuario=? AND id<>?', [$data['usuario'], $id]) || val('SELECT id FROM superadmins WHERE usuario=?', [$data['usuario']])) throw new RuntimeException('Ese nombre de usuario ya está en uso');
        $pass = (string) post('password', '');
        if ($pass !== '') {
            if (strlen($pass) < 6) throw new RuntimeException('La contraseña debe tener al menos 6 caracteres');
            $data['password'] = password_hash($pass, PASSWORD_DEFAULT);
        }
        if ($id) {
            if ($id === uid()) { $data['activo'] = 1; $data['rol'] = 'admin'; }
            update('usuarios', $data, 'id=? AND empresa_id=?', [$id, $eid]);
        } else {
            if ($pass === '') throw new RuntimeException('La contraseña es obligatoria');
            $emp = empresa();
            if (!empty($emp['max_usuarios']) && (int) val('SELECT COUNT(*) FROM usuarios WHERE empresa_id=? AND activo=1', [$eid]) >= (int) $emp['max_usuarios']) throw new RuntimeException('Tu plan permite un máximo de ' . $emp['max_usuarios'] . ' usuarios activos');
            $data['empresa_id'] = $eid;
            insert('usuarios', $data);
        }
        flash('success', 'Usuario guardado');
    } catch (RuntimeException $ex) { flash('error', $ex->getMessage()); }
    redirect('usuarios');
}
$list = rows('SELECT * FROM usuarios WHERE empresa_id=? ORDER BY activo DESC, rol, nombre', [$eid]);
$roles = ['admin' => ['Administrador', 'badge-violet', 'Ve y administra todo'], 'vendedor' => ['Vendedor', 'badge-blue', 'Solo vende (crédito y contado)'], 'cobrador' => ['Cobrador', 'badge-green', 'Cobra en ruta y también vende']];

layout_header('Usuarios', ['actions' => '<button onclick="openForm(\'tpl\', {activo:1, comision_cobro_pct:13, rol:\'cobrador\'})" class="btn btn-primary"><i data-lucide="user-plus"></i>Nuevo usuario</button>']);
?>
<div class="card overflow-hidden">
  <div class="overflow-x-auto"><table class="table">
    <thead><tr><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Teléfono</th><th class="num">Com. cobro</th><th class="num">Com. venta</th><th class="num">Base</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $u): $r = $roles[$u['rol']]; $j = $u; unset($j['password']); ?>
      <tr class="<?= $u['activo'] ? '' : 'opacity-50' ?>">
        <td><div class="font-semibold"><?= e(nombre_completo($u)) ?></div><div class="text-xs text-slate-500"><?= $u['dpi'] ? 'DPI ' . e($u['dpi']) : '' ?></div></td>
        <td class="font-mono text-sm"><?= e($u['usuario']) ?></td>
        <td><span class="badge <?= $r[1] ?>"><?= $r[0] ?></span></td>
        <td><?= e($u['telefono']) ?></td>
        <td class="num"><?= $u['rol'] === 'cobrador' ? num($u['comision_cobro_pct'], 2) . '%' : '—' ?></td>
        <td class="num"><?= $u['comision_venta_pct'] > 0 ? num($u['comision_venta_pct'], 2) . '%' : 'Por producto' ?></td>
        <td class="num"><?= money($u['salario_base']) ?></td>
        <td><?= $u['activo'] ? '<span class="badge badge-green">Activo</span>' : '<span class="badge badge-gray">Inactivo</span>' ?></td>
        <td class="text-right"><button class="btn btn-light btn-sm" onclick='openForm("tpl", <?= e(json_encode($j)) ?>)'>Editar</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div id="tpl" class="hidden">
  <form method="post" class="p-6 space-y-4" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="id">
    <h3 class="text-lg font-semibold" data-title="Nuevo usuario" data-edit="Editar usuario"></h3>
    <div class="grid grid-cols-2 gap-3">
      <div><label class="label">Nombre *</label><input name="nombre" required class="input"></div>
      <div><label class="label">Apellido</label><input name="apellido" class="input"></div>
      <div><label class="label">Teléfono</label><input name="telefono" class="input"></div>
      <div><label class="label">DPI</label><input name="dpi" class="input"></div>
    </div>
    <div><label class="label">Dirección</label><input name="direccion" class="input"></div>
    <div>
      <label class="label">Rol</label>
      <div class="seg">
        <?php foreach ($roles as $k => $r): ?><input type="radio" name="rol" id="rol_<?= $k ?>" value="<?= $k ?>"><label for="rol_<?= $k ?>"><?= $r[0] ?></label><?php endforeach; ?>
      </div>
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
      <div><label class="label">Comisión cobro %</label><input name="comision_cobro_pct" type="number" step="0.01" class="input"></div>
      <div><label class="label">Comisión venta %</label><input name="comision_venta_pct" type="number" step="0.01" class="input"></div>
      <div class="col-span-2 sm:col-span-1"><label class="label">Salario base</label><input name="salario_base" type="number" step="0.01" class="input"></div>
    </div>
    <p class="hint !mt-0">Cobro: % sobre lo recaudado (13% = Q130 por cada Q1,000). Venta: además de la comisión fija de cada producto.</p>
    <div class="grid grid-cols-2 gap-3">
      <div><label class="label">Usuario *</label><input name="usuario" required class="input" autocomplete="off"></div>
      <div><label class="label">Contraseña</label><input name="password" type="password" class="input" autocomplete="new-password" placeholder="Dejar vacío = no cambiar"></div>
    </div>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="activo" value="1" class="w-4 h-4"> Usuario activo</label>
    <div class="flex gap-2 pt-2"><button class="btn btn-primary flex-1">Guardar</button><button type="button" class="btn btn-light" onclick="closeModal()">Cancelar</button></div>
  </form>
</div>
<?php layout_footer();
