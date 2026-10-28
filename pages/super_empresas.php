<?php
if (!super_user()) redirect('index.php');
$planes = rows('SELECT * FROM planes ORDER BY precio');

if (is_post()) {
    csrf_check();
    $acc = post('accion');
    $id = (int) post('id');
    try {
        if ($acc === 'guardar') {
            $data = [
                'nombre' => post('nombre'), 'nit' => nullable(post('nit')), 'telefono' => nullable(post('telefono')), 'email' => nullable(post('email')),
                'direccion' => nullable(post('direccion')), 'plan_id' => nullable(post('plan_id')), 'fecha_vencimiento' => nullable(post('fecha_vencimiento')),
                'estado' => post('estado') === 'suspendida' ? 'suspendida' : 'activa', 'notas' => nullable(post('notas')),
            ];
            if ($data['nombre'] === '') throw new RuntimeException('El nombre es obligatorio');
            db()->beginTransaction();
            if ($id) {
                update('empresas', $data, 'id=?', [$id]);
                flash('success', 'Empresa actualizada');
            } else {
                $au = strtolower(trim((string) post('admin_usuario')));
                $ap = (string) post('admin_password');
                if (!preg_match('/^[a-z0-9._@-]{3,60}$/', $au)) throw new RuntimeException('Usuario administrador inválido');
                if (strlen($ap) < 6) throw new RuntimeException('La contraseña del administrador debe tener al menos 6 caracteres');
                if (val('SELECT id FROM usuarios WHERE usuario=?', [$au]) || val('SELECT id FROM superadmins WHERE usuario=?', [$au])) throw new RuntimeException('Ese usuario ya existe');
                if (!$data['fecha_vencimiento'] && $data['plan_id']) {
                    $dias = (int) val('SELECT duracion_dias FROM planes WHERE id=?', [$data['plan_id']]);
                    $data['fecha_vencimiento'] = date('Y-m-d', strtotime("+$dias days"));
                }
                $id = insert('empresas', $data);
                insert('usuarios', ['empresa_id' => $id, 'nombre' => post('admin_nombre') ?: 'Administrador', 'apellido' => '', 'usuario' => $au, 'password' => password_hash($ap, PASSWORD_DEFAULT), 'rol' => 'admin', 'comision_cobro_pct' => 0]);
                insert('areas', ['empresa_id' => $id, 'nombre' => 'General']);
                if (post('registrar_pago') && $data['plan_id']) {
                    $pl = row('SELECT * FROM planes WHERE id=?', [$data['plan_id']]);
                    insert('membresia_pagos', ['empresa_id' => $id, 'plan_id' => $pl['id'], 'monto' => $pl['precio'], 'fecha_pago' => today(), 'desde' => today(), 'hasta' => $data['fecha_vencimiento'], 'metodo' => 'efectivo']);
                }
                flash('success', 'Empresa creada. Usuario administrador: ' . $au);
            }
            db()->commit();
        } elseif ($acc === 'pago') {
            $emp = row('SELECT * FROM empresas WHERE id=?', [$id]);
            if (!$emp) throw new RuntimeException('Empresa no encontrada');
            $plan = row('SELECT * FROM planes WHERE id=?', [(int) post('plan_id')]);
            if (!$plan) throw new RuntimeException('Selecciona un plan');
            $periodos = max(1, (int) post('periodos', 1));
            $desde = ($emp['fecha_vencimiento'] && $emp['fecha_vencimiento'] >= today()) ? date('Y-m-d', strtotime($emp['fecha_vencimiento'] . ' +1 day')) : today();
            $hasta = date('Y-m-d', strtotime($desde . ' +' . ($plan['duracion_dias'] * $periodos - 1) . ' days'));
            db()->beginTransaction();
            insert('membresia_pagos', ['empresa_id' => $id, 'plan_id' => $plan['id'], 'monto' => post('monto') !== '' ? dec(post('monto')) : $plan['precio'] * $periodos, 'fecha_pago' => post('fecha_pago') ?: today(), 'desde' => $desde, 'hasta' => $hasta, 'metodo' => nullable(post('metodo')), 'nota' => nullable(post('nota'))]);
            update('empresas', ['plan_id' => $plan['id'], 'fecha_vencimiento' => $hasta, 'estado' => 'activa'], 'id=?', [$id]);
            db()->commit();
            flash('success', 'Membresía renovada hasta ' . fdate($hasta));
        } elseif ($acc === 'estado') {
            update('empresas', ['estado' => post('estado') === 'suspendida' ? 'suspendida' : 'activa'], 'id=?', [$id]);
            flash('success', 'Estado actualizado');
        } elseif ($acc === 'entrar') {
            $adm = row("SELECT id FROM usuarios WHERE empresa_id=? AND rol='admin' AND activo=1 ORDER BY id LIMIT 1", [$id]);
            if (!$adm) throw new RuntimeException('La empresa no tiene un administrador activo');
            $sid = (int) $_SESSION['sid'];
            $_SESSION = ['uid' => (int) $adm['id'], 'sid_back' => $sid];
            redirect('index.php');
        } elseif ($acc === 'reset_admin') {
            $adm = row("SELECT id, usuario FROM usuarios WHERE empresa_id=? AND rol='admin' ORDER BY id LIMIT 1", [$id]);
            $np = (string) post('password');
            if (!$adm) throw new RuntimeException('Sin administrador');
            if (strlen($np) < 6) throw new RuntimeException('Mínimo 6 caracteres');
            update('usuarios', ['password' => password_hash($np, PASSWORD_DEFAULT), 'activo' => 1], 'id=?', [$adm['id']]);
            flash('success', 'Contraseña de ' . $adm['usuario'] . ' actualizada');
        }
    } catch (RuntimeException $ex) {
        if (db()->inTransaction()) db()->rollBack();
        flash('error', $ex->getMessage());
    }
    redirect('super_empresas');
}

$qstr = get('q', '');
$params = []; $w = '1=1';
if ($qstr !== '') { $w = '(e.nombre LIKE ? OR e.nit LIKE ? OR e.telefono LIKE ?)'; $l = "%$qstr%"; $params = [$l, $l, $l]; }
$list = rows("SELECT e.*, p.nombre plan, (SELECT usuario FROM usuarios u WHERE u.empresa_id=e.id AND u.rol='admin' ORDER BY u.id LIMIT 1) admin_usuario,
  (SELECT COUNT(*) FROM usuarios u WHERE u.empresa_id=e.id AND u.activo=1) usuarios, (SELECT COUNT(*) FROM clientes c WHERE c.empresa_id=e.id AND c.activo=1) clientes
  FROM empresas e LEFT JOIN planes p ON p.id=e.plan_id WHERE $w ORDER BY e.id DESC", $params);

layout_header('Empresas', ['actions' => '<button onclick="nueva()" class="btn btn-primary"><i data-lucide="plus"></i>Nueva empresa</button>']);
?>
<form class="mb-4 flex gap-2"><input type="hidden" name="p" value="super_empresas"><input type="search" name="q" value="<?= e($qstr) ?>" class="input" placeholder="Buscar empresa, NIT o teléfono"></form>
<div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4">
<?php foreach ($list as $e):
    $m = membresia_estado($e);
    $badge = !$m['ok'] ? ['badge-red', $e['estado'] === 'suspendida' ? 'Suspendida' : 'Vencida'] : ($m['dias'] <= 7 ? ['badge-yellow', 'Vence en ' . $m['dias'] . 'd'] : ['badge-green', 'Activa']); ?>
  <div class="card p-5 flex flex-col">
    <div class="flex items-start gap-3">
      <?php if ($e['logo']): ?><img src="<?= e($e['logo']) ?>" class="w-12 h-12 rounded-xl object-contain border"><?php else: ?><div class="w-12 h-12 rounded-xl bg-brand-50 text-brand-600 grid place-items-center"><i data-lucide="building-2" class="w-5 h-5"></i></div><?php endif; ?>
      <div class="min-w-0 flex-1"><div class="font-semibold truncate"><?= e($e['nombre']) ?></div><div class="text-xs text-slate-500"><?= e($e['telefono'] ?: '') ?> <?= $e['nit'] ? '· NIT ' . e($e['nit']) : '' ?></div></div>
      <span class="badge <?= $badge[0] ?>"><?= $badge[1] ?></span>
    </div>
    <div class="grid grid-cols-3 gap-2 mt-4 text-center text-xs">
      <div class="rounded-lg bg-slate-50 p-2"><div class="text-slate-500">Plan</div><div class="font-semibold"><?= e($e['plan'] ?: '—') ?></div></div>
      <div class="rounded-lg bg-slate-50 p-2"><div class="text-slate-500">Vence</div><div class="font-semibold"><?= fdate($e['fecha_vencimiento']) ?></div></div>
      <div class="rounded-lg bg-slate-50 p-2"><div class="text-slate-500">Usuarios / Clientes</div><div class="font-semibold"><?= (int) $e['usuarios'] ?> / <?= (int) $e['clientes'] ?></div></div>
    </div>
    <div class="text-xs text-slate-500 mt-3">Admin: <span class="font-mono"><?= e($e['admin_usuario'] ?: '—') ?></span></div>
    <div class="flex flex-wrap gap-2 mt-4 pt-4 border-t border-slate-100">
      <button class="btn btn-success btn-sm" onclick='openForm("tpl-pago", <?= e(json_encode(['id' => $e['id'], 'plan_id' => $e['plan_id'], 'info' => $e['nombre']])) ?>)'><i data-lucide="refresh-ccw"></i>Renovar</button>
      <button class="btn btn-light btn-sm" onclick='openForm("tpl", <?= e(json_encode($e)) ?>)'><i data-lucide="pencil"></i>Editar</button>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="entrar"><input type="hidden" name="id" value="<?= $e['id'] ?>"><button class="btn btn-light btn-sm"><i data-lucide="log-in"></i>Entrar</button></form>
      <button class="btn btn-ghost btn-sm" onclick='openForm("tpl-reset", {id: <?= (int) $e['id'] ?>})'><i data-lucide="key-round"></i></button>
      <form method="post" class="ml-auto" data-confirm="¿Cambiar estado de la empresa?"><?= csrf_field() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?= $e['id'] ?>"><input type="hidden" name="estado" value="<?= $e['estado'] === 'activa' ? 'suspendida' : 'activa' ?>">
        <button class="btn btn-ghost btn-sm <?= $e['estado'] === 'activa' ? 'text-rose-600' : 'text-emerald-600' ?>"><?= $e['estado'] === 'activa' ? 'Suspender' : 'Activar' ?></button></form>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php if (!$list) echo '<div class="card">' . empty_state('Aún no hay empresas', 'building-2') . '</div>'; ?>

<div id="tpl" class="hidden">
  <form method="post" class="p-6 space-y-4" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id">
    <h3 class="text-lg font-semibold" data-title="Nueva empresa" data-edit="Editar empresa"></h3>
    <div><label class="label">Nombre comercial *</label><input name="nombre" required class="input" autofocus></div>
    <div class="grid grid-cols-2 gap-3">
      <div><label class="label">NIT</label><input name="nit" class="input"></div>
      <div><label class="label">Teléfono</label><input name="telefono" class="input"></div>
      <div><label class="label">Correo</label><input name="email" type="email" class="input"></div>
      <div><label class="label">Estado</label><select name="estado" class="select"><option value="activa">Activa</option><option value="suspendida">Suspendida</option></select></div>
    </div>
    <div><label class="label">Dirección</label><input name="direccion" class="input"></div>
    <div class="grid grid-cols-2 gap-3">
      <div><label class="label">Plan</label><select name="plan_id" class="select"><?= select_options($planes, '', 'id', function ($p) { return $p['nombre'] . ' — Q' . number_format($p['precio'], 2) . ' / ' . $p['duracion_dias'] . ' días'; }, '— Sin plan —') ?></select></div>
      <div><label class="label">Vence</label><input type="date" name="fecha_vencimiento" class="input"><div class="hint" data-onlynew>Vacío = según el plan</div></div>
    </div>
    <div data-onlynew class="rounded-xl bg-slate-50 p-4 space-y-3">
      <div class="font-semibold text-sm">Usuario administrador de la empresa</div>
      <div><label class="label">Nombre</label><input name="admin_nombre" class="input" placeholder="Administrador"></div>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="label">Usuario</label><input name="admin_usuario" class="input" autocomplete="off"></div>
        <div><label class="label">Contraseña</label><input name="admin_password" type="password" class="input" autocomplete="new-password"></div>
      </div>
      <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="registrar_pago" value="1" class="w-4 h-4" checked> Registrar el pago del primer periodo</label>
    </div>
    <div><label class="label">Notas internas</label><textarea name="notas" rows="2" class="textarea"></textarea></div>
    <div class="flex gap-2"><button class="btn btn-primary flex-1">Guardar</button><button type="button" class="btn btn-light" onclick="closeModal()">Cancelar</button></div>
  </form>
</div>
<div id="tpl-pago" class="hidden">
  <form method="post" class="p-6 space-y-4"><?= csrf_field() ?><input type="hidden" name="accion" value="pago"><input type="hidden" name="id">
    <h3 class="text-lg font-semibold">Renovar membresía</h3>
    <input name="info" class="input bg-slate-50" readonly>
    <div class="grid grid-cols-2 gap-3">
      <div><label class="label">Plan</label><select name="plan_id" class="select" required><?= select_options($planes, '', 'id', function ($p) { return $p['nombre'] . ' — Q' . number_format($p['precio'], 2); }, 'Selecciona') ?></select></div>
      <div><label class="label">Periodos</label><input name="periodos" type="number" min="1" value="1" class="input"></div>
      <div><label class="label">Monto</label><input name="monto" type="number" step="0.01" class="input" placeholder="Precio del plan"></div>
      <div><label class="label">Fecha de pago</label><input name="fecha_pago" type="date" value="<?= today() ?>" class="input"></div>
      <div><label class="label">Método</label><input name="metodo" class="input" placeholder="Efectivo, depósito..."></div>
      <div><label class="label">Nota</label><input name="nota" class="input"></div>
    </div>
    <p class="hint">Se suma al vencimiento actual si aún está vigente.</p>
    <button class="btn btn-success btn-lg w-full">Registrar pago</button>
  </form>
</div>
<div id="tpl-reset" class="hidden">
  <form method="post" class="p-6 space-y-4"><?= csrf_field() ?><input type="hidden" name="accion" value="reset_admin"><input type="hidden" name="id">
    <h3 class="text-lg font-semibold">Restablecer contraseña del administrador</h3>
    <div><label class="label">Nueva contraseña</label><input name="password" type="text" required minlength="6" class="input"></div>
    <button class="btn btn-primary w-full">Guardar</button>
  </form>
</div>
<script>function nueva() { openForm('tpl', { estado: 'activa', plan_id: '<?= $planes ? $planes[0]['id'] : '' ?>' }); }<?php if (get('nueva')): ?> nueva();<?php endif; ?>
<?php if ($ed = (int) get('editar')): foreach ($list as $e) if ((int) $e['id'] === $ed) echo 'openForm("tpl", ' . json_encode($e) . ');'; endif; ?></script>
<?php layout_footer();
