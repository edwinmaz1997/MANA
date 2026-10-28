<?php
if (!can('productos.ver')) require_perm('admin');
$eid = eid();
if (is_post()) {
    require_perm('admin');
    csrf_check();
    $id = (int) post('id');
    if (post('accion') === 'eliminar') {
        update('productos', ['activo' => 0], 'id=? AND empresa_id=?', [$id, $eid]);
        flash('success', 'Producto eliminado');
        redirect('productos');
    }
    try {
        $catName = trim((string) post('categoria'));
        $catId = null;
        if ($catName !== '') {
            $catId = val('SELECT id FROM categorias WHERE empresa_id=? AND nombre=?', [$eid, $catName]);
            if (!$catId) $catId = insert('categorias', ['empresa_id' => $eid, 'nombre' => $catName]);
        }
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
$cats = rows('SELECT id, nombre FROM categorias WHERE empresa_id=? ORDER BY nombre', [$eid]);
$valorInv = array_sum(array_map(function ($p) { return $p['stock'] * $p['precio_compra']; }, $list));
$admin = is_admin();

layout_header('Productos', ['actions' => $admin ? '<button onclick="openForm(\'tpl\', {unidad:\'Unidad\'})" class="btn btn-primary"><i data-lucide="plus"></i>Nuevo producto</button>' : '']);
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
      <td class="text-right"><?php if ($admin): $j = $p; $j['categoria'] = $p['categoria']; ?><button class="btn btn-light btn-sm" onclick='openForm("tpl", <?= e(json_encode($j)) ?>)'>Editar</button><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table><?php if (!$list) echo empty_state('Sin productos', 'package'); ?></div></div>

<?php if ($admin): ?>
<div id="tpl" class="hidden">
  <form method="post" enctype="multipart/form-data" class="p-6 space-y-4" id="fprod">
    <?= csrf_field() ?><input type="hidden" name="id">
    <h3 class="text-lg font-semibold" data-title="Nuevo producto" data-edit="Editar producto"></h3>
    <div><label class="label">Nombre *</label><input name="nombre" required class="input" autofocus></div>
    <div class="grid grid-cols-3 gap-3">
      <div><label class="label">Código</label><input name="codigo" class="input" placeholder="Auto"></div>
      <div><label class="label">Categoría</label><input name="categoria" list="catlist" class="input"><datalist id="catlist"><?php foreach ($cats as $c) echo '<option value="' . e($c['nombre']) . '">'; ?></datalist></div>
      <div><label class="label">Unidad</label><input name="unidad" list="unilist" class="input"><datalist id="unilist"><option value="Unidad"><option value="Juego"><option value="Par"><option value="Caja"><option value="Docena"></datalist></div>
    </div>
    <div class="grid grid-cols-3 gap-3">
      <div><label class="label">Precio compra</label><input name="precio_compra" type="number" step="0.01" class="input" oninput="calcContado(this.form)"></div>
      <div><label class="label">Descuento comisión</label><input name="descuento_comision" type="number" step="0.01" class="input" oninput="calcContado(this.form)"></div>
      <div><label class="label">Precio venta</label><input name="precio_venta" type="number" step="0.01" class="input"></div>
    </div>
    <div class="grid grid-cols-2 gap-3">
      <div><label class="label">Precio contado</label><input name="precio_contado" type="number" step="0.01" class="input bg-slate-50"><div class="hint">Compra + descuento comisión (automático, editable)</div></div>
      <div><label class="label">Precio crédito (plazo largo)</label><input name="precio_credito" type="number" step="0.01" class="input"></div>
    </div>
    <div class="grid grid-cols-2 gap-3">
      <div data-onlynew><label class="label">Stock inicial</label><input name="stock" type="number" step="0.01" class="input" value="0"></div>
      <div><label class="label">Stock mínimo</label><input name="stock_minimo" type="number" step="0.01" class="input"></div>
    </div>
    <p class="hint" data-onlyedit>El stock se modifica con Compras y Ventas.</p>
    <div><label class="label">Foto</label><input type="file" name="foto" accept="image/*" class="text-sm"></div>
    <div class="flex gap-2 pt-2"><button class="btn btn-primary flex-1">Guardar</button><button type="button" class="btn btn-light" onclick="closeModal()">Cancelar</button></div>
    <button type="submit" name="accion" value="eliminar" data-onlyedit class="btn btn-ghost text-rose-600 w-full" onclick="return confirm('¿Eliminar producto?')"><i data-lucide="trash-2"></i> Eliminar</button>
  </form>
</div>
<script>
function calcContado(f) { var c = parseFloat(f.precio_compra.value) || 0, d = parseFloat(f.descuento_comision.value) || 0; f.precio_contado.value = (c + d).toFixed(2); }
</script>
<?php endif; ?>
<?php layout_footer();
