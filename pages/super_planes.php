<?php
if (!super_user()) redirect('index.php');
if (is_post()) {
    csrf_check();
    $id = (int) post('id');
    $data = ['nombre' => post('nombre'), 'precio' => dec(post('precio')), 'duracion_dias' => max(1, (int) post('duracion_dias')), 'max_usuarios' => max(0, (int) post('max_usuarios')), 'max_clientes' => max(0, (int) post('max_clientes')), 'descripcion' => nullable(post('descripcion')), 'activo' => post('activo') ? 1 : 0];
    if ($data['nombre'] === '') flash('error', 'El nombre es obligatorio');
    else { if ($id) update('planes', $data, 'id=?', [$id]); else insert('planes', $data); flash('success', 'Plan guardado'); }
    redirect('super_planes');
}
$list = rows('SELECT p.*, (SELECT COUNT(*) FROM empresas e WHERE e.plan_id=p.id) empresas FROM planes p ORDER BY precio');
layout_header('Planes de membresía', ['actions' => '<button onclick="openForm(\'tpl\', {activo:1, duracion_dias:30})" class="btn btn-primary"><i data-lucide="plus"></i>Nuevo plan</button>']);
?>
<div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-4">
<?php foreach ($list as $p): ?>
  <div class="card p-6 <?= $p['activo'] ? '' : 'opacity-60' ?>">
    <div class="flex items-start"><div class="text-lg font-bold"><?= e($p['nombre']) ?></div><button class="ml-auto btn btn-ghost btn-icon" onclick='openForm("tpl", <?= e(json_encode($p)) ?>)'><i data-lucide="pencil"></i></button></div>
    <div class="text-3xl font-extrabold mt-2"><?= money($p['precio']) ?> <span class="text-sm font-medium text-slate-500">/ <?= (int) $p['duracion_dias'] ?> días</span></div>
    <p class="text-sm text-slate-500 mt-2"><?= e($p['descripcion']) ?></p>
    <ul class="text-sm mt-4 space-y-1.5">
      <li class="flex gap-2"><i data-lucide="users" class="w-4 h-4 text-brand-600"></i><?= $p['max_usuarios'] ? (int) $p['max_usuarios'] . ' usuarios' : 'Usuarios ilimitados' ?></li>
      <li class="flex gap-2"><i data-lucide="contact" class="w-4 h-4 text-brand-600"></i><?= $p['max_clientes'] ? num($p['max_clientes']) . ' clientes' : 'Clientes ilimitados' ?></li>
      <li class="flex gap-2"><i data-lucide="building-2" class="w-4 h-4 text-brand-600"></i><?= (int) $p['empresas'] ?> empresa(s) en este plan</li>
    </ul>
  </div>
<?php endforeach; ?>
</div>
<div id="tpl" class="hidden">
  <form method="post" class="p-6 space-y-4"><?= csrf_field() ?><input type="hidden" name="id">
    <h3 class="text-lg font-semibold" data-title="Nuevo plan" data-edit="Editar plan"></h3>
    <div><label class="label">Nombre *</label><input name="nombre" required class="input"></div>
    <div class="grid grid-cols-2 gap-3">
      <div><label class="label">Precio</label><input name="precio" type="number" step="0.01" class="input"></div>
      <div><label class="label">Duración (días)</label><input name="duracion_dias" type="number" min="1" class="input"></div>
      <div><label class="label">Máx. usuarios (0 = ∞)</label><input name="max_usuarios" type="number" min="0" class="input"></div>
      <div><label class="label">Máx. clientes (0 = ∞)</label><input name="max_clientes" type="number" min="0" class="input"></div>
    </div>
    <div><label class="label">Descripción</label><input name="descripcion" class="input"></div>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="activo" value="1" class="w-4 h-4"> Activo</label>
    <button class="btn btn-primary w-full">Guardar</button>
  </form>
</div>
<?php layout_footer();
