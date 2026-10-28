<?php
require_perm('perfil');
$u = user();
if (is_post()) {
    csrf_check();
    $act = (string) post('actual', '');
    $new = (string) post('nueva', '');
    if (!password_verify($act, $u['password'])) flash('error', 'La contraseña actual no es correcta');
    elseif (strlen($new) < 6) flash('error', 'La nueva contraseña debe tener al menos 6 caracteres');
    elseif ($new !== post('confirmar')) flash('error', 'Las contraseñas no coinciden');
    else { update('usuarios', ['password' => password_hash($new, PASSWORD_DEFAULT)], 'id=?', [$u['id']]); flash('success', 'Contraseña actualizada'); }
    redirect('perfil');
}
layout_header('Mi perfil');
?>
<div class="grid lg:grid-cols-2 gap-4 max-w-4xl">
  <div class="card p-5">
    <div class="flex items-center gap-4">
      <div class="w-14 h-14 rounded-2xl bg-brand-600 text-white grid place-items-center text-xl font-bold"><?= e(mb_strtoupper(mb_substr($u['nombre'], 0, 1))) ?></div>
      <div><div class="text-lg font-semibold"><?= e(nombre_completo($u)) ?></div><div class="text-sm text-slate-500"><?= e(ucfirst($u['rol'])) ?> · <?= e($u['usuario']) ?></div></div>
    </div>
    <dl class="mt-5 text-sm space-y-2">
      <div class="flex justify-between"><dt class="text-slate-500">Teléfono</dt><dd><?= e($u['telefono'] ?: '—') ?></dd></div>
      <div class="flex justify-between"><dt class="text-slate-500">DPI</dt><dd><?= e($u['dpi'] ?: '—') ?></dd></div>
      <?php if ($u['rol'] === 'cobrador'): ?><div class="flex justify-between"><dt class="text-slate-500">Comisión por cobro</dt><dd><?= num($u['comision_cobro_pct'], 2) ?>%</dd></div><?php endif; ?>
      <div class="flex justify-between"><dt class="text-slate-500">Salario base</dt><dd><?= money($u['salario_base']) ?></dd></div>
    </dl>
  </div>
  <form method="post" class="card p-5 space-y-3">
    <?= csrf_field() ?>
    <h3 class="font-semibold">Cambiar contraseña</h3>
    <div><label class="label">Contraseña actual</label><input type="password" name="actual" required class="input"></div>
    <div><label class="label">Nueva contraseña</label><input type="password" name="nueva" required minlength="6" class="input"></div>
    <div><label class="label">Confirmar</label><input type="password" name="confirmar" required class="input"></div>
    <button class="btn btn-primary">Actualizar</button>
  </form>
</div>
<?php layout_footer();
