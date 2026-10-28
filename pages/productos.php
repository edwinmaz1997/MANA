<?php
if (!can('productos.ver')) require_perm('admin');
$eid = eid();
if (is_post()) {
    require_perm('admin');
    csrf_check();
    $id = (int) post('id');
    $acc = (string) post('accion');
    // Categorías y unidades
    if (in_array($acc, ['cat_guardar', 'uni_guardar', 'cat_eliminar', 'uni_eliminar'], true)) {
        $tabla = strpos($acc, 'cat_') === 0 ? 'categorias' : 'unidades';
        $nombre = trim((string) post('nombre'));
        try {
            if (substr($acc, -8) === 'eliminar') {
                if ($tabla === 'categorias') {
                    q('UPDATE productos SET categoria_id=NULL WHERE categoria_id=? AND empresa_id=?', [$id, $eid]);
                }
                q("DELETE FROM $tabla WHERE id=? AND empresa_id=?", [$id, $eid]);
                $res = ['ok' => true];
            } else {
                if ($nombre === '') throw new RuntimeException('Escribe el nombre');
                if (mb_strlen($nombre) > ($tabla === 'unidades' ? 40 : 80)) throw new RuntimeException('Nombre demasiado largo');
                $dup = val("SELECT id FROM $tabla WHERE empresa_id=? AND nombre=? AND id<>?", [$eid, $nombre, $id]);
                if ($dup) throw new RuntimeException('Ya existe');
                if ($id) {
                    $viejo = val("SELECT nombre FROM $tabla WHERE id=? AND empresa_id=?", [$id, $eid]);
                    update($tabla, ['nombre' => $nombre], 'id=? AND empresa_id=?', [$id, $eid]);
                    if ($tabla === 'unidades' && $viejo !== null) q('UPDATE productos SET unidad=? WHERE empresa_id=? AND unidad=?', [$nombre, $eid, $viejo]);
                } else {
                    $id = insert($tabla, ['empresa_id' => $eid, 'nombre' => $nombre]);
                }
                $res = ['ok' => true, 'id' => $id, 'nombre' => $nombre];
            }
        } catch (RuntimeException $ex) { $res = ['ok' => false, 'error' => $ex->getMessage()]; }
        if (is_ajax()) json_out($res);
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Guardado' : $res['error']);
        redirect(url('productos', ['catalogos' => 1]));
    }
    if ($acc === 'eliminar') {
        update('productos', ['activo' => 0], 'id=? AND empresa_id=?', [$id, $eid]);
        flash('success', 'Producto eliminado');
        redirect('productos');
    }
    try {
        $catId = (int) post('categoria_id') ?: null;
        if ($catId && !val('SELECT id FROM categorias WHERE id=? AND empresa_id=?', [$catId, $eid])) $catId = null;
        $compra = dec(post('precio_compra'));
        $desc = dec(post('descuento_comision'));
        $data = [
            'nombre' => post('nombre'), 'categoria_id' => $catId, 'unidad' => post('unidad') ?: 'Unidad',
            'precio_compra' => $compra, 'precio_venta' => dec(post('precio_venta')), 'descuento_comision' => $desc,
            'precio_contado' => post('precio_contado') !== '' ? dec(post('precio_contado')) : $compra + $desc,
            'precio_credito' => dec(post('precio_credito')), 'stock_minimo' => dec(post('stock_minimo')),
        ];
        if ($data['nombre'] === '') throw new RuntimeException('El nombre es obligatorio');
        $codigo = trim((string) post('codigo'));
        if ($codigo !== '' && val('SELECT id FROM productos WHERE empresa_id=? AND codigo=? AND id<>?', [$eid, $codigo, $id])) throw new RuntimeException('El código de producto ya existe');
        $foto = upload_image('foto', 'productos/' . $eid, 800);
        if ($foto) $data['foto'] = $foto;
        if ($id) {
            if ($codigo !== '') $data['codigo'] = $codigo;
            update('productos', $data, 'id=? AND empresa_id=?', [$id, $eid]);
        } else {
            $data['empresa_id'] = $eid;
            $data['codigo'] = $codigo !== '' ? $codigo : next_code('productos', 'P-', 4);
            $data['stock'] = dec(post('stock'));
            insert('productos', $data);
        }
        flash('success', 'Producto guardado');
    } catch (RuntimeException $ex) { flash('error', $ex->getMessage()); }
    redirect('productos');
}

$qstr = get('q', '');
$cat = (int) get('cat', 0);
$params = [$eid]; $w = '';
if ($qstr !== '') { $w .= ' AND (p.nombre LIKE ? OR p.codigo LIKE ?)'; $l = "%$qstr%"; array_push($params, $l, $l); }
if ($cat) { $w .= ' AND p.categoria_id=?'; $params[] = $cat; }
$list = rows("SELECT p.*, c.nombre categoria FROM productos p LEFT JOIN categorias c ON c.id=p.categoria_id WHERE p.empresa_id=? AND p.activo=1 $w ORDER BY p.nombre", $params);
$cats = rows('SELECT c.id, c.nombre, (SELECT COUNT(*) FROM productos p WHERE p.categoria_id=c.id AND p.activo=1) usos FROM categorias c WHERE c.empresa_id=? ORDER BY c.nombre', [$eid]);
if (!val('SELECT COUNT(*) FROM unidades WHERE empresa_id=?', [$eid])) {
    foreach (['Unidad', 'Juego', 'Par', 'Caja', 'Docena', 'Paquete', 'Metro', 'Libra'] as $un) insert('unidades', ['empresa_id' => $eid, 'nombre' => $un]);
}
$unis = rows('SELECT u.id, u.nombre, (SELECT COUNT(*) FROM productos p WHERE p.empresa_id=u.empresa_id AND p.unidad=u.nombre AND p.activo=1) usos FROM unidades u WHERE u.empresa_id=? ORDER BY u.nombre', [$eid]);
$valorInv = array_sum(array_map(function ($p) { return $p['stock'] * $p['precio_compra']; }, $list));
$admin = is_admin();

layout_header('Productos', ['actions' => $admin ? '<button onclick="abrirCatalogos()" class="btn btn-light"><i data-lucide="tags"></i><span class="hidden sm:inline">Categorías y unidades</span></button><button onclick="nuevoProducto()" class="btn btn-primary"><i data-lucide="plus"></i><span class="hidden sm:inline">Nuevo producto</span></button>' : '']);
?>
<form class="flex flex-col sm:flex-row gap-2 mb-4">
  <input type="hidden" name="p" value="productos">
  <input name="q" value="<?= e($qstr) ?>" class="input flex-1" placeholder="Buscar por nombre o código">
  <select name="cat" class="select sm:w-56" onchange="this.form.submit()"><?= select_options($cats, $cat, 'id', null, 'Todas las categorías') ?></select>
  <button class="btn btn-dark">Buscar</button>
</form>
<?php if ($admin): ?><div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4"><?php stat_card('Productos', count($list), 'package', 'brand'); stat_card('Valor del inventario (costo)', money($valorInv), 'warehouse', 'green'); ?></div><?php endif; ?>
<div class="card overflow-hidden"><div class="overflow-x-auto"><table class="table">
  <thead><tr><th>Producto</th><th>Categoría</th><?php if ($admin): ?><th class="num">Compra</th><th class="num">Comisión</th><?php endif; ?><th class="num">Venta</th><th class="num">Contado</th><th class="num">Crédito</th><th class="num">Stock</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($list as $p): ?>
    <tr>
      <td><div class="flex items-center gap-3"><?php if ($p['foto']): ?><img src="<?= e($p['foto']) ?>" class="thumb"><?php endif; ?><div><div class="font-semibold"><?= e($p['nombre']) ?></div><div class="text-xs text-slate-500"><?= e($p['codigo']) ?> · <?= e($p['unidad']) ?></div></div></div></td>
      <td><?= e($p['categoria'] ?: '—') ?></td>
      <?php if ($admin): ?><td class="num"><?= money($p['precio_compra']) ?></td><td class="num text-slate-500"><?= money($p['descuento_comision']) ?></td><?php endif; ?>
      <td class="num"><?= money($p['precio_venta']) ?></td>
      <td class="num font-semibold"><?= money($p['precio_contado']) ?></td>
      <td class="num font-semibold text-brand-700"><?= money($p['precio_credito']) ?></td>
      <td class="num"><span class="badge <?= $p['stock'] <= 0 ? 'badge-red' : ($p['stock'] <= $p['stock_minimo'] ? 'badge-yellow' : 'badge-green') ?>"><?= num($p['stock'], $p['stock'] == floor($p['stock']) ? 0 : 2) ?></span></td>
      <td class="text-right"><?php if ($admin): $j = $p; $j['categoria'] = $p['categoria']; ?><button class="btn btn-light btn-sm" onclick='editarProducto(<?= e(json_encode($j)) ?>)'>Editar</button><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table><?php if (!$list) echo empty_state('Sin productos', 'package'); ?></div></div>

<?php if ($admin): ?>
<div id="tpl" class="hidden">
  <form method="post" enctype="multipart/form-data" class="p-5 sm:p-6 space-y-4" id="fprod">
    <?= csrf_field() ?><input type="hidden" name="id">
    <div class="flex items-center"><h3 class="text-lg font-semibold" data-title="Nuevo producto" data-edit="Editar producto"></h3></div>
    <div><label class="label">Nombre *</label><input name="nombre" required class="input" autofocus></div>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
      <div><label class="label">Código</label><input name="codigo" class="input" placeholder="Automático"></div>
      <div>
        <label class="label">Categoría</label>
        <div class="flex gap-2"><select name="categoria_id" class="select flex-1 min-w-0"><option value="">Sin categoría</option><?php foreach ($cats as $c) echo '<option value="' . (int) $c['id'] . '">' . e($c['nombre']) . '</option>'; ?></select>
          <button type="button" class="btn btn-light btn-icon shrink-0" title="Nueva categoría" onclick="nuevoItem(this, 'cat')"><i data-lucide="plus"></i></button></div>
      </div>
      <div>
        <label class="label">Unidad</label>
        <div class="flex gap-2"><select name="unidad" class="select flex-1 min-w-0"><?php foreach ($unis as $u) echo '<option value="' . e($u['nombre']) . '">' . e($u['nombre']) . '</option>'; ?></select>
          <button type="button" class="btn btn-light btn-icon shrink-0" title="Nueva unidad" onclick="nuevoItem(this, 'uni')"><i data-lucide="plus"></i></button></div>
      </div>
    </div>
    <div class="nuevo-item hidden rounded-xl bg-slate-50 border border-slate-200 p-3">
      <label class="label nuevo-item-lbl">Nueva categoría</label>
      <div class="flex gap-2"><input class="input flex-1 min-w-0 nuevo-item-txt" maxlength="80"><button type="button" class="btn btn-primary shrink-0" onclick="guardarItem(this)">Agregar</button></div>
    </div>
    <div class="rounded-xl border border-slate-200 p-3 sm:p-4 space-y-3">
      <div class="text-xs font-semibold text-slate-500 uppercase">Precios</div>
      <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
        <div><label class="label">Precio compra</label><input name="precio_compra" type="number" step="0.01" min="0" inputmode="decimal" class="input" oninput="calcContado(this.form)"></div>
        <div><label class="label">Desc. comisión</label><input name="descuento_comision" type="number" step="0.01" min="0" inputmode="decimal" class="input" oninput="calcContado(this.form)"></div>
        <div class="col-span-2 sm:col-span-1"><label class="label">Precio venta</label><input name="precio_venta" type="number" step="0.01" min="0" inputmode="decimal" class="input"></div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="label">Precio contado</label><input name="precio_contado" type="number" step="0.01" min="0" inputmode="decimal" class="input bg-slate-50"></div>
        <div><label class="label">Precio crédito</label><input name="precio_credito" type="number" step="0.01" min="0" inputmode="decimal" class="input"></div>
      </div>
      <p class="hint !mt-1">Contado = compra + descuento comisión (se calcula solo, puedes cambiarlo). Crédito = precio a plazo largo.</p>
    </div>
    <div class="grid grid-cols-2 gap-3">
      <div data-onlynew><label class="label">Stock inicial</label><input name="stock" type="number" step="0.01" min="0" inputmode="decimal" class="input" value="0"></div>
      <div><label class="label">Stock mínimo</label><input name="stock_minimo" type="number" step="0.01" min="0" inputmode="decimal" class="input"></div>
    </div>
    <p class="hint" data-onlyedit>El stock se modifica con Compras y Ventas.</p>
    <div>
      <label class="label">Foto</label>
      <label class="flex items-center gap-3 rounded-xl border-2 border-dashed border-slate-300 p-3 cursor-pointer hover:border-brand-400">
        <img class="foto-prev hidden w-14 h-14 rounded-lg object-cover" alt="">
        <span class="w-14 h-14 rounded-lg bg-slate-100 grid place-items-center text-slate-400 foto-ico"><i data-lucide="image-plus" class="w-6 h-6"></i></span>
        <span class="text-sm text-slate-600 min-w-0"><b class="text-brand-600">Elegir o tomar foto</b><br><span class="foto-nombre text-xs text-slate-400 truncate block">Ningún archivo</span></span>
        <input type="file" name="foto" accept="image/*" class="hidden" onchange="prevFoto(this)">
      </label>
    </div>
    <div class="flex gap-2 pt-1"><button class="btn btn-primary btn-lg flex-1">Guardar</button><button type="button" class="btn btn-light btn-lg" onclick="closeModal()">Cancelar</button></div>
    <button type="submit" name="accion" value="eliminar" data-onlyedit formnovalidate class="btn btn-ghost text-rose-600 w-full" onclick="return confirm('¿Eliminar producto?')"><i data-lucide="trash-2"></i> Eliminar producto</button>
  </form>
</div>

<div id="tpl-catalogos" class="hidden">
  <div class="p-5 sm:p-6 space-y-5">
    <div class="flex items-center"><h3 class="text-lg font-semibold">Categorías y unidades</h3></div>
    <?php foreach (['cat' => ['Categorías', $cats, 'Ej. Electrodomésticos'], 'uni' => ['Unidades', $unis, 'Ej. Juego']] as $k => $cfg): ?>
    <div>
      <div class="font-semibold mb-2"><?= $cfg[0] ?></div>
      <form method="post" class="flex gap-2 mb-2"><?= csrf_field() ?><input type="hidden" name="accion" value="<?= $k ?>_guardar"><input name="nombre" required class="input flex-1 min-w-0" placeholder="<?= e($cfg[2]) ?>"><button class="btn btn-primary shrink-0"><i data-lucide="plus"></i>Agregar</button></form>
      <div class="rounded-xl border border-slate-200 divide-y divide-slate-100">
        <?php foreach ($cfg[1] as $it): ?>
          <div class="flex items-center gap-2 px-3 py-2">
            <span class="flex-1 min-w-0 truncate"><?= e($it['nombre']) ?></span>
            <span class="text-xs text-slate-400 shrink-0"><?= (int) $it['usos'] ?> prod.</span>
            <button type="button" class="btn btn-ghost btn-icon shrink-0" title="Renombrar" onclick="renombrar('<?= $k ?>', <?= (int) $it['id'] ?>, <?= e(json_encode($it['nombre'])) ?>)"><i data-lucide="pencil"></i></button>
            <form method="post" class="shrink-0" data-confirm="¿Eliminar &quot;<?= e($it['nombre']) ?>&quot;?<?= $k === 'cat' && $it['usos'] ? ' Los productos quedarán sin categoría.' : '' ?>"><?= csrf_field() ?><input type="hidden" name="accion" value="<?= $k ?>_eliminar"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>"><button class="btn btn-ghost btn-icon text-rose-500" title="Eliminar"><i data-lucide="trash-2"></i></button></form>
          </div>
        <?php endforeach; if (!$cfg[1]) echo '<div class="px-3 py-4 text-sm text-slate-400 text-center">Sin registros</div>'; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<script>
function calcContado(f) { var c = parseFloat(f.precio_compra.value) || 0, d = parseFloat(f.descuento_comision.value) || 0; f.precio_contado.value = (c + d).toFixed(2); }
function nuevoProducto() { var f = openForm('tpl', { unidad: <?= json_encode(in_array('Unidad', array_column($unis, 'nombre'), true) ? 'Unidad' : ($unis ? $unis[0]['nombre'] : '')) ?> }); }
function editarProducto(d) {
  var sel = document.getElementById('tpl').querySelector('select[name=unidad]');
  if (d.unidad && !Array.prototype.some.call(sel.options, function (o) { return o.value === d.unidad; })) { var o = document.createElement('option'); o.value = o.textContent = d.unidad; sel.appendChild(o); }
  openForm('tpl', d);
}
function abrirCatalogos() { openModal(document.getElementById('tpl-catalogos').innerHTML); }
function prevFoto(inp) {
  var w = inp.closest('label'); if (!inp.files[0]) return;
  var img = w.querySelector('.foto-prev'); img.src = URL.createObjectURL(inp.files[0]); img.classList.remove('hidden');
  w.querySelector('.foto-ico').classList.add('hidden'); w.querySelector('.foto-nombre').textContent = inp.files[0].name;
}
function nuevoItem(btn, tipo) {
  var f = btn.closest('form'), box = f.querySelector('.nuevo-item');
  box.dataset.tipo = tipo; box.classList.remove('hidden');
  box.querySelector('.nuevo-item-lbl').textContent = tipo === 'cat' ? 'Nueva categoría' : 'Nueva unidad';
  var t = box.querySelector('.nuevo-item-txt'); t.value = ''; t.focus();
  t.onkeydown = function (e) { if (e.key === 'Enter') { e.preventDefault(); guardarItem(t); } };
}
async function guardarItem(el) {
  var f = el.closest('form'), box = f.querySelector('.nuevo-item'), tipo = box.dataset.tipo, t = box.querySelector('.nuevo-item-txt');
  if (!t.value.trim()) return toast('Escribe el nombre');
  var r = await api('index.php?p=productos', { accion: tipo + '_guardar', nombre: t.value.trim() });
  if (!r.ok) return toast(r.error || 'Error');
  var sel = f.querySelector(tipo === 'cat' ? 'select[name=categoria_id]' : 'select[name=unidad]');
  var o = document.createElement('option'); o.value = tipo === 'cat' ? r.id : r.nombre; o.textContent = r.nombre; sel.appendChild(o); sel.value = o.value;
  // mantener la plantilla al día para la próxima vez
  var tplSel = document.getElementById('tpl').querySelector(tipo === 'cat' ? 'select[name=categoria_id]' : 'select[name=unidad]'); tplSel.appendChild(o.cloneNode(true));
  box.classList.add('hidden'); toast((tipo === 'cat' ? 'Categoría' : 'Unidad') + ' agregada');
}
async function renombrar(tipo, id, actual) {
  var n = prompt('Nuevo nombre', actual); if (!n || !n.trim() || n.trim() === actual) return;
  var r = await api('index.php?p=productos', { accion: tipo + '_guardar', id: id, nombre: n.trim() });
  if (!r.ok) return toast(r.error || 'Error');
  location.href = 'index.php?p=productos&catalogos=1';
}
<?php if (get('catalogos')): ?>abrirCatalogos();<?php endif; ?>
</script>
<?php endif; ?>
<?php layout_footer();
