<?php
require_perm('admin');
$eid = eid();
if (is_post()) {
    csrf_check();
    try {
        $data = [
            'nombre' => post('nombre'), 'nit' => nullable(post('nit')), 'telefono' => nullable(post('telefono')),
            'email' => nullable(post('email')), 'direccion' => nullable(post('direccion')),
            'ticket_mensaje' => nullable(post('ticket_mensaje')), 'moneda' => post('moneda') ?: 'Q',
            'dias_recoger' => max(1, (int) post('dias_recoger', 90)),
        ];
        if ($data['nombre'] === '') throw new RuntimeException('El nombre es obligatorio');
        $logo = upload_image('logo', 'logos', 600);
        if ($logo) $data['logo'] = $logo;
        if (post('quitar_logo')) $data['logo'] = null;
        update('empresas', $data, 'id=?', [$eid]);
        flash('success', 'Datos guardados');
    } catch (RuntimeException $ex) { flash('error', $ex->getMessage()); }
    redirect('empresa');
}
$emp = empresa();
$mem = membresia_estado();
layout_header('Empresa y ticket');
?>
<form method="post" enctype="multipart/form-data" class="grid lg:grid-cols-3 gap-4">
  <?= csrf_field() ?>
  <div class="card p-5 lg:col-span-2 space-y-4">
    <h3 class="font-semibold">Datos de la empresa</h3>
    <div class="grid sm:grid-cols-2 gap-4">
      <div class="sm:col-span-2"><label class="label">Nombre comercial *</label><input name="nombre" required value="<?= e($emp['nombre']) ?>" class="input"></div>
      <div><label class="label">NIT</label><input name="nit" value="<?= e($emp['nit']) ?>" class="input"></div>
      <div><label class="label">Teléfono</label><input name="telefono" value="<?= e($emp['telefono']) ?>" class="input"></div>
      <div><label class="label">Correo</label><input name="email" type="email" value="<?= e($emp['email']) ?>" class="input"></div>
      <div><label class="label">Moneda</label><input name="moneda" value="<?= e($emp['moneda']) ?>" class="input" maxlength="5"></div>
      <div class="sm:col-span-2"><label class="label">Dirección</label><input name="direccion" value="<?= e($emp['direccion']) ?>" class="input"></div>
    </div>
    <hr>
    <h3 class="font-semibold">Ticket (impresora térmica 58 mm)</h3>
    <div><label class="label">Mensaje al pie del ticket</label><input name="ticket_mensaje" value="<?= e($emp['ticket_mensaje']) ?>" class="input" placeholder="¡Gracias por su compra!"></div>
    <hr>
    <h3 class="font-semibold">Créditos</h3>
    <div class="max-w-xs"><label class="label">Días sin abono para recoger producto</label><input name="dias_recoger" type="number" min="1" value="<?= (int) $emp['dias_recoger'] ?>" class="input"><div class="hint">3 meses = 90 días</div></div>
    <button class="btn btn-primary btn-lg"><i data-lucide="save"></i> Guardar</button>
  </div>
  <div class="space-y-4">
    <div class="card p-5">
      <h3 class="font-semibold mb-3">Logo</h3>
      <img id="lprev" src="<?= e($emp['logo']) ?>" class="w-40 h-40 object-contain mx-auto rounded-xl bg-slate-50 border <?= $emp['logo'] ? '' : 'hidden' ?>">
      <input type="file" name="logo" accept="image/*" data-preview="lprev" class="mt-3 text-sm w-full">
      <div class="hint">Recomendado: PNG en blanco y negro con fondo transparente para que se imprima bien.</div>
      <?php if ($emp['logo']): ?><label class="flex items-center gap-2 text-sm mt-3"><input type="checkbox" name="quitar_logo" value="1"> Quitar logo</label><?php endif; ?>
    </div>
    <div class="card p-5">
      <h3 class="font-semibold mb-2">Membresía</h3>
      <div class="text-sm space-y-1">
        <div>Plan: <b><?= e($emp['plan_nombre'] ?: '—') ?></b></div>
        <div>Vence: <b><?= fdate($emp['fecha_vencimiento']) ?></b> <?= $mem['dias'] < 9999 ? '(' . (int) $mem['dias'] . ' días)' : '' ?></div>
        <div>Usuarios: <?= (int) val('SELECT COUNT(*) FROM usuarios WHERE empresa_id=? AND activo=1', [$eid]) ?> / <?= $emp['max_usuarios'] ? (int) $emp['max_usuarios'] : '∞' ?></div>
        <div>Clientes: <?= (int) val('SELECT COUNT(*) FROM clientes WHERE empresa_id=? AND activo=1', [$eid]) ?> / <?= $emp['max_clientes'] ? (int) $emp['max_clientes'] : '∞' ?></div>
      </div>
    </div>
    <a href="ticket.php?tipo=prueba" target="_blank" class="btn btn-light w-full"><i data-lucide="printer"></i> Imprimir ticket de prueba</a>
  </div>
</form>
<?php layout_footer();
