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
  <form method="post" class="p-5 sm:p-6 space-y-4" id="fcompra"><?= csrf_field() ?>
    <h3 class="text-lg font-semibold">Nueva compra</h3>
    <div class="grid grid-cols-2 gap-3">
      <div class="col-span-2"><label class="label">Proveedor</label><select name="proveedor_id" class="select"><?= select_options($provs, '', 'id', 'nombre_completo', '— Sin proveedor —') ?></select></div>
      <div><label class="label">Fecha</label><input type="date" name="fecha" value="<?= today() ?>" class="input"></div>
      <div><label class="label">No. factura</label><input name="numero_factura" class="input"></div>
    </div>
    <div>
      <label class="label">Productos</label>
      <div class="relative">
        <i data-lucide="search" class="w-4 h-4 absolute left-3 top-3.5 text-slate-400"></i>
        <input class="input pl-9 prod-q" placeholder="Buscar producto por nombre o código" autocomplete="off">
        <div class="suggest hidden prod-sug"></div>
      </div>
      <div class="lineas space-y-2 mt-3"></div>
      <div class="lineas-vacio text-center text-sm text-slate-400 py-4 border border-dashed border-slate-200 rounded-xl mt-3">Busca y agrega los productos comprados</div>
    </div>
    <div class="flex justify-between font-bold text-lg border-t pt-3"><span>Total</span><span class="ctotal">Q 0.00</span></div>
    <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="actualizar_costo" value="1" checked class="w-4 h-4 mt-0.5"> Actualizar el precio de compra de los productos</label>
    <div><label class="label">Notas</label><input name="notas" class="input"></div>
    <div class="flex gap-2"><button class="btn btn-primary btn-lg flex-1">Registrar compra</button><button type="button" class="btn btn-light btn-lg" onclick="closeModal()">Cancelar</button></div>
  </form>
</div>
<script>
function nuevaCompra() {
  var f = openForm('tpl');
  autocomplete(f.querySelector('.prod-q'), f.querySelector('.prod-sug'), 'index.php?p=api&a=productos', function (p) {
    return '<div class="flex justify-between gap-3"><div class="min-w-0"><div class="font-medium truncate">' + escapeHtml(p.nombre) + '</div><div class="text-xs text-slate-500">' + escapeHtml(p.codigo) + ' · Stock ' + Number(p.stock) + '</div></div><div class="text-right text-sm text-slate-600 shrink-0">Costo ' + money(p.precio_compra) + '</div></div>';
  }, function (p) { addLinea(f, p); });
  f.addEventListener('submit', function (e) {
    if (!f.querySelectorAll('.lineas > div').length) { e.preventDefault(); toast('Agrega al menos un producto'); setTimeout(function () { f.querySelector('button.btn-primary').disabled = false; }, 20); }
  });
  setTimeout(function () { f.querySelector('.prod-q').focus(); }, 50);
}
function addLinea(f, p) {
  var ex = f.querySelector('.lineas > div[data-id="' + p.id + '"]');
  if (ex) { var c = ex.querySelector('.cant'); c.value = (Number(c.value) || 0) + 1; ctot(f); return; }
  var d = document.createElement('div');
  d.dataset.id = p.id;
  d.className = 'rounded-xl border border-slate-200 p-3';
  d.innerHTML = '<input type="hidden" name="producto_id[]" value="' + p.id + '">' +
    '<div class="flex items-start gap-2"><div class="min-w-0 flex-1"><div class="font-semibold truncate">' + escapeHtml(p.nombre) + '</div><div class="text-xs text-slate-500">' + escapeHtml(p.codigo) + ' · Stock actual ' + Number(p.stock) + '</div></div>' +
    '<button type="button" class="btn btn-ghost btn-icon text-rose-500 -mt-1 -mr-1" onclick="var f=this.form; this.closest(\'.lineas > div\').remove(); ctot(f)"><i data-lucide="trash-2"></i></button></div>' +
    '<div class="grid grid-cols-3 gap-2 mt-2 items-end">' +
    '<div><label class="text-[11px] text-slate-500">Cantidad</label><input name="cantidad[]" type="number" step="0.01" min="0.01" value="1" inputmode="decimal" class="input !px-2 cant" oninput="ctot(this.form)"></div>' +
    '<div><label class="text-[11px] text-slate-500">Costo unit.</label><input name="costo[]" type="number" step="0.01" min="0" value="' + Number(p.precio_compra || 0).toFixed(2) + '" inputmode="decimal" class="input !px-2 cc" oninput="ctot(this.form)"></div>' +
    '<div class="text-right"><div class="text-[11px] text-slate-500">Subtotal</div><div class="font-bold sub py-2">' + money(p.precio_compra || 0) + '</div></div></div>';
  f.querySelector('.lineas').appendChild(d); lucide.createIcons(); ctot(f);
}
function ctot(f) {
  var t = 0, rows = f.querySelectorAll('.lineas > div');
  rows.forEach(function (r) { var st = (Number(r.querySelector('.cant').value) || 0) * (Number(r.querySelector('.cc').value) || 0); t += st; r.querySelector('.sub').textContent = money(st); });
  f.querySelector('.ctotal').textContent = money(t);
  f.querySelector('.lineas-vacio').classList.toggle('hidden', rows.length > 0);
}
</script>
<?php layout_footer();
