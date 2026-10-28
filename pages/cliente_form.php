<?php
require_perm('clientes.crear');
$eid = eid();
$id = (int) get('id', 0);
$c = $id ? row('SELECT * FROM clientes WHERE id=? AND empresa_id=?', [$id, $eid]) : null;
if ($id && !$c) redirect('clientes');
if ($c && !is_admin() && (int) $c['created_by'] !== uid()) {
    // vendedores/cobradores solo editan los clientes que crearon
    flash('error', 'Solo el administrador puede editar este cliente');
    redirect(url('cliente', ['id' => $id]));
}
$err = null;

if (is_post()) {
    csrf_check();
    $data = [
        'nombre' => post('nombre'),
        'apellido' => post('apellido', ''),
        'telefono' => nullable(post('telefono')),
        'direccion' => nullable(post('direccion')),
        'maps_url' => nullable(post('maps_url')),
        'lat' => nullable(post('lat')),
        'lng' => nullable(post('lng')),
        'dpi' => nullable(post('dpi')),
        'area_id' => nullable(post('area_id')),
        'notas' => nullable(post('notas')),
    ];
    try {
        if ($data['nombre'] === '') throw new RuntimeException('El nombre es obligatorio');
        if ($data['dpi'] && !preg_match('/^\d{13}$/', preg_replace('/\s/', '', $data['dpi']))) throw new RuntimeException('El DPI debe tener 13 dígitos');
        if ($data['dpi']) $data['dpi'] = preg_replace('/\s/', '', $data['dpi']);
        if ($data['maps_url'] && !preg_match('#^https?://#i', $data['maps_url'])) throw new RuntimeException('El enlace de Google Maps no es válido');
        if ($data['dpi'] && val('SELECT id FROM clientes WHERE empresa_id=? AND dpi=? AND id<>?', [$eid, $data['dpi'], $id])) throw new RuntimeException('Ya existe un cliente con ese DPI');
        $foto = upload_image('foto_casa', 'clientes/' . $eid);
        if ($foto) $data['foto_casa'] = $foto;

        if ($c) {
            update('clientes', $data, 'id=? AND empresa_id=?', [$id, $eid]);
            flash('success', 'Cliente actualizado');
        } else {
            $emp = empresa();
            if (!empty($emp['max_clientes']) && (int) val('SELECT COUNT(*) FROM clientes WHERE empresa_id=? AND activo=1', [$eid]) >= (int) $emp['max_clientes']) {
                throw new RuntimeException('Tu plan permite un máximo de ' . $emp['max_clientes'] . ' clientes');
            }
            $data['empresa_id'] = $eid;
            $data['codigo'] = post('codigo') ?: next_code('clientes', 'CLI-', 5);
            if (val('SELECT 1 FROM clientes WHERE empresa_id=? AND codigo=?', [$eid, $data['codigo']])) throw new RuntimeException('El código de cliente ya existe');
            $data['created_by'] = uid();
            $id = insert('clientes', $data);
            flash('success', 'Cliente creado: ' . $data['codigo']);
        }
        if (post('volver') === 'venta') redirect(url('ventas_nueva', ['cliente' => $id]));
        redirect(url('cliente', ['id' => $id]));
    } catch (RuntimeException $ex) {
        $err = $ex->getMessage();
        $c = array_merge($c ?: [], $data, ['codigo' => post('codigo')]);
    }
}
$areas = rows('SELECT id, nombre FROM areas WHERE empresa_id=? AND activo=1 ORDER BY nombre', [$eid]);
$v = function ($k) use ($c) { return e($c[$k] ?? ''); };

layout_header($id ? 'Editar cliente' : 'Nuevo cliente');
?>
<?php if ($err): ?><div class="mb-4 rounded-xl border px-4 py-3 text-sm bg-rose-50 border-rose-200 text-rose-800"><?= e($err) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="grid lg:grid-cols-3 gap-4">
  <?= csrf_field() ?>
  <input type="hidden" name="volver" value="<?= e(get('volver', post('volver', ''))) ?>">
  <div class="card p-5 lg:col-span-2 space-y-4">
    <div class="grid sm:grid-cols-2 gap-4">
      <div><label class="label">Nombre *</label><input name="nombre" required value="<?= $v('nombre') ?>" class="input"></div>
      <div><label class="label">Apellido</label><input name="apellido" value="<?= $v('apellido') ?>" class="input"></div>
      <div><label class="label">Número de teléfono</label><input name="telefono" type="tel" inputmode="tel" value="<?= $v('telefono') ?>" class="input" placeholder="5555 5555"></div>
      <div><label class="label">DPI <span class="text-slate-400 font-normal">(opcional)</span></label><input name="dpi" inputmode="numeric" maxlength="15" value="<?= $v('dpi') ?>" class="input" placeholder="13 dígitos"></div>
      <div><label class="label">Código de cliente</label><input name="codigo" value="<?= $v('codigo') ?>" class="input" <?= $id ? 'disabled' : '' ?> placeholder="Automático"></div>
      <div><label class="label">Área</label><select name="area_id" class="select"><?= select_options($areas, $c['area_id'] ?? '', 'id', null, '— Sin área —') ?></select></div>
    </div>
    <div><label class="label">Dirección (escrita)</label><textarea name="direccion" rows="2" class="textarea" placeholder="Ej. 3a calle 4-20 zona 1, frente a la iglesia"><?= $v('direccion') ?></textarea></div>
    <div>
      <label class="label">Dirección Google Maps</label>
      <div class="flex gap-2">
        <input name="maps_url" id="maps_url" value="<?= $v('maps_url') ?>" class="input" placeholder="Pega el enlace de Google Maps">
        <button type="button" class="btn btn-light" onclick="ubicacionActual()" title="Usar mi ubicación actual"><i data-lucide="locate-fixed"></i><span class="hidden sm:inline">Mi ubicación</span></button>
      </div>
      <input type="hidden" name="lat" id="lat" value="<?= $v('lat') ?>"><input type="hidden" name="lng" id="lng" value="<?= $v('lng') ?>">
      <div class="hint" id="geo-hint"><?= !empty($c['lat']) ? 'Coordenadas: ' . e($c['lat']) . ', ' . e($c['lng']) : 'Estando en la casa del cliente, toca "Mi ubicación" para guardar el punto exacto.' ?></div>
    </div>
    <div><label class="label">Notas</label><textarea name="notas" rows="2" class="textarea"><?= $v('notas') ?></textarea></div>
  </div>
  <div class="card p-5 space-y-3 h-fit">
    <label class="label">Foto de la casa</label>
    <img id="prev" src="<?= $v('foto_casa') ?>" class="w-full aspect-video object-cover rounded-xl bg-slate-100 <?= empty($c['foto_casa']) ? 'hidden' : '' ?>">
    <label class="flex flex-col items-center justify-center gap-2 border-2 border-dashed border-slate-300 rounded-xl p-6 text-slate-500 cursor-pointer hover:border-brand-400 hover:text-brand-600">
      <i data-lucide="camera" class="w-7 h-7"></i><span class="text-sm font-medium">Tomar o subir foto</span>
      <input type="file" name="foto_casa" accept="image/*" capture="environment" class="hidden" data-preview="prev">
    </label>
    <button class="btn btn-primary btn-lg w-full mt-2"><i data-lucide="save"></i> Guardar cliente</button>
    <a href="<?= $id ? url('cliente', ['id' => $id]) : url('clientes') ?>" class="btn btn-ghost w-full">Cancelar</a>
  </div>
</form>
<script>
async function ubicacionActual() {
  var h = document.getElementById('geo-hint'); h.textContent = 'Obteniendo ubicación...';
  var g = await getGeo();
  if (!g) { h.textContent = 'No se pudo obtener la ubicación. Activa el GPS y da permiso al navegador.'; return; }
  document.getElementById('lat').value = g.lat; document.getElementById('lng').value = g.lng;
  var m = document.getElementById('maps_url'); if (!m.value) m.value = 'https://www.google.com/maps?q=' + g.lat + ',' + g.lng;
  h.textContent = 'Coordenadas: ' + g.lat + ', ' + g.lng;
}
</script>
<?php layout_footer();
