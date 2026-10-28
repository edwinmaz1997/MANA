<?php
require_perm('admin');
$eid = eid();

if (is_post()) {
    csrf_check();
    if (post('accion') === 'eliminar') {
        $id = (int) post('id');
        $c = row('SELECT * FROM compras WHERE id=? AND empresa_id=?', [$id, $eid]);
        if ($c) {
            db()->beginTransaction();
            foreach (rows('SELECT * FROM compra_detalle WHERE compra_id=?', [$id]) as $d) q('UPDATE productos SET stock=stock-? WHERE id=?', [$d['cantidad'], $d['producto_id']]);
            q('DELETE FROM compra_detalle WHERE compra_id=?', [$id]);
            q('DELETE FROM compras WHERE id=?', [$id]);
            db()->commit();
            flash('success', 'Compra eliminada y stock revertido');
        }
        redirect('compras');
    }
    $prods = (array) post('producto_id', []);
    $cants = (array) post('cantidad', []);
    $costos = (array) post('costo', []);
    try {
        $lineas = [];
        foreach ($prods as $i => $pid) {
            $pid = (int) $pid; $cant = dec($cants[$i] ?? 0); $costo = dec($costos[$i] ?? 0);
            if (!$pid || $cant <= 0) continue;
            if (!val('SELECT id FROM productos WHERE id=? AND empresa_id=?', [$pid, $eid])) throw new RuntimeException('Producto inválido');
            $lineas[] = [$pid, $cant, $costo];
        }
        if (!$lineas) throw new RuntimeException('Agrega al menos un producto con cantidad');
        db()->beginTransaction();
        $total = 0; foreach ($lineas as $l) $total += round($l[1] * $l[2], 2);
        $cid = insert('compras', ['empresa_id' => $eid, 'proveedor_id' => nullable(post('proveedor_id')), 'fecha' => post('fecha') ?: today(), 'numero_factura' => nullable(post('numero_factura')), 'total' => $total, 'notas' => nullable(post('notas')), 'usuario_id' => uid()]);
        foreach ($lineas as $l) {
            insert('compra_detalle', ['compra_id' => $cid, 'producto_id' => $l[0], 'cantidad' => $l[1], 'costo_unitario' => $l[2], 'subtotal' => round($l[1] * $l[2], 2)]);
            q('UPDATE productos SET stock=stock+? WHERE id=?', [$l[1], $l[0]]);
            if (post('actualizar_costo') && $l[2] > 0) q('UPDATE productos SET precio_compra=?, precio_contado = ? + descuento_comision WHERE id=?', [$l[2], $l[2], $l[0]]);
        }
        db()->commit();
        flash('success', 'Compra registrada por ' . money($total));
    } catch (RuntimeException $ex) {
        if (db()->inTransaction()) db()->rollBack();
        flash('error', $ex->getMessage());
    }
    redirect('compras');
}

$rng = rango_fechas();
$list = rows('SELECT c.*, p.nombre prov, p.apellido prov_ap, (SELECT COUNT(*) FROM compra_detalle d WHERE d.compra_id=c.id) items FROM compras c LEFT JOIN proveedores p ON p.id=c.proveedor_id WHERE c.empresa_id=? AND c.fecha BETWEEN ? AND ? ORDER BY c.fecha DESC, c.id DESC', [$eid, $rng['desde'], $rng['hasta']]);
$provs = rows('SELECT id, nombre, apellido FROM proveedores WHERE empresa_id=? AND activo=1 ORDER BY nombre', [$eid]);
$prods = rows('SELECT id, codigo, nombre, precio_compra FROM productos WHERE empresa_id=? AND activo=1 ORDER BY nombre', [$eid]);
$ver = (int) get('ver');
$detalle = $ver ? rows('SELECT d.*, p.nombre FROM compra_detalle d JOIN productos p ON p.id=d.producto_id JOIN compras c ON c.id=d.compra_id WHERE d.compra_id=? AND c.empresa_id=?', [$ver, $eid]) : [];

layout_header('Compras', ['actions' => '<button onclick="nuevaCompra()" class="btn btn-primary"><i data-lucide="plus"></i>Nueva compra</button>']);
filtro_fechas($rng);
?>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4"><?php stat_card('Total comprado', money(array_sum(array_column($list, 'total'))), 'truck', 'brand', count($list) . ' compra(s)'); ?></div>
<div class="card overflow-hidden"><div class="overflow-x-auto"><table class="table">
  <thead><tr><th>Fecha</th><th>Proveedor</th><th>Factura</th><th class="num">Productos</th><th class="num">Total</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($list as $c): ?>
    <tr><td><?= fdate($c['fecha']) ?></td><td><?= e(trim($c['prov'] . ' ' . $c['prov_ap']) ?: '—') ?></td><td><?= e($c['numero_factura']) ?></td><td class="num"><?= (int) $c['items'] ?></td><td class="num font-semibold"><?= money($c['total']) ?></td>
      <td class="text-right whitespace-nowrap"><a href="<?= url('compras', ['ver' => $c['id'], 'r' => $rng['r'], 'desde' => $rng['desde'], 'hasta' => $rng['hasta']]) ?>" class="btn btn-light btn-sm">Ver</a>
        <form method="post" class="inline" data-confirm="¿Eliminar compra? Se restará el stock."><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn btn-ghost btn-icon text-rose-500"><i data-lucide="trash-2"></i></button></form></td></tr>
    <?php if ($ver === (int) $c['id']): ?>
      <tr><td colspan="6" class="bg-slate-50"><table class="w-full text-sm"><?php foreach ($detalle as $d): ?><tr><td class="py-1"><?= e($d['nombre']) ?></td><td class="text-right"><?= num($d['cantidad'], 2) ?> x <?= money($d['costo_unitario']) ?></td><td class="text-right font-semibold w-32"><?= money($d['subtotal']) ?></td></tr><?php endforeach; ?></table><?php if ($c['notas']) echo '<div class="text-xs text-slate-500 mt-2">' . e($c['notas']) . '</div>'; ?></td></tr>
    <?php endif; ?>
  <?php endforeach; ?>
  </tbody></table><?php if (!$list) echo empty_state('Sin compras en este periodo', 'truck'); ?></div></div>

<div id="tpl" class="hidden">
  <form method="post" class="p-6 space-y-4"><?= csrf_field() ?>
    <h3 class="text-lg font-semibold">Nueva compra</h3>
    <div class="grid grid-cols-2 gap-3">
      <div class="col-span-2"><label class="label">Proveedor</label><select name="proveedor_id" class="select"><?= select_options($provs, '', 'id', 'nombre_completo', '— Sin proveedor —') ?></select></div>
      <div><label class="label">Fecha</label><input type="date" name="fecha" value="<?= today() ?>" class="input"></div>
      <div><label class="label">No. factura</label><input name="numero_factura" class="input"></div>
    </div>
    <div>
      <label class="label">Productos</label>
      <div class="lineas space-y-2"></div>
      <button type="button" class="btn btn-light btn-sm mt-2" onclick="addLinea(this.form)"><i data-lucide="plus"></i>Agregar producto</button>
    </div>
    <div class="flex justify-between font-bold text-lg border-t pt-3"><span>Total</span><span class="ctotal">Q 0.00</span></div>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="actualizar_costo" value="1" checked class="w-4 h-4"> Actualizar precio de compra de los productos</label>
    <div><label class="label">Notas</label><input name="notas" class="input"></div>
    <div class="flex gap-2"><button class="btn btn-primary flex-1">Registrar compra</button><button type="button" class="btn btn-light" onclick="closeModal()">Cancelar</button></div>
  </form>
</div>
<script>
var PRODS = <?= json_encode($prods) ?>;
function nuevaCompra() { var f = openForm('tpl'); addLinea(f); }
function addLinea(f) {
  var d = document.createElement('div');
  d.className = 'grid grid-cols-12 gap-2 items-center';
  d.innerHTML = '<select name="producto_id[]" class="select col-span-6 !px-2" onchange="var p=PRODS.find(x=>x.id==this.value); if(p) this.parentNode.querySelector(\'.cc\').value=Number(p.precio_compra).toFixed(2); ctot(this.form)"><option value="">Producto...</option>' + PRODS.map(function (p) { return '<option value="' + p.id + '">' + escapeHtml(p.nombre) + '</option>'; }).join('') + '</select>' +
    '<input name="cantidad[]" type="number" step="0.01" min="0" placeholder="Cant." class="input col-span-2 !px-2" oninput="ctot(this.form)">' +
    '<input name="costo[]" type="number" step="0.01" min="0" placeholder="Costo" class="input col-span-3 !px-2 cc" oninput="ctot(this.form)">' +
    '<button type="button" class="col-span-1 text-rose-500" onclick="var f=this.form; this.parentNode.remove(); ctot(f)"><i data-lucide="x" class="w-4 h-4"></i></button>';
  f.querySelector('.lineas').appendChild(d); lucide.createIcons();
}
function ctot(f) {
  var t = 0; f.querySelectorAll('.lineas > div').forEach(function (r) { t += (Number(r.children[1].value) || 0) * (Number(r.children[2].value) || 0); });
  f.querySelector('.ctotal').textContent = money(t);
}
</script>
<?php layout_footer();
