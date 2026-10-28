<?php
require_perm('ventas.crear');
$eid = eid();
$u = user();

if (is_post()) {
    csrf_check();
    $tipo = post('tipo') === 'credito' ? 'credito' : 'contado';
    $items = json_decode((string) post('items', '[]'), true);
    $clienteId = (int) post('cliente_id') ?: null;
    $descuento = max(0, dec(post('descuento')));
    $extraMonto = max(0, dec(post('extra_monto')));
    $extraConcepto = nullable(post('extra_concepto'));
    $fecha = today();
    try {
        if (!is_array($items) || !$items) throw new RuntimeException('Agrega al menos un producto');
        if ($tipo === 'credito' && !$clienteId) throw new RuntimeException('Selecciona el cliente para la venta al crédito');
        if ($clienteId && !val('SELECT id FROM clientes WHERE id=? AND empresa_id=? AND activo=1', [$clienteId, $eid])) throw new RuntimeException('Cliente no válido');
        if ($extraMonto > 0 && !$extraConcepto) $extraConcepto = 'Extra';

        db()->beginTransaction();
        $subtotal = 0; $costo = 0; $comision = 0; $lineas = [];
        foreach ($items as $it) {
            $pid = (int) ($it['id'] ?? 0);
            $cant = round((float) ($it['cantidad'] ?? 0), 2);
            $precio = round((float) ($it['precio'] ?? 0), 2);
            if ($cant <= 0) throw new RuntimeException('Cantidad inválida');
            $p = row('SELECT * FROM productos WHERE id=? AND empresa_id=? AND activo=1 FOR UPDATE', [$pid, $eid]);
            if (!$p) throw new RuntimeException('Producto no encontrado');
            if ((float) $p['stock'] < $cant) throw new RuntimeException('Stock insuficiente de "' . $p['nombre'] . '" (disponible: ' . num($p['stock']) . ')');
            if ($precio < 0) throw new RuntimeException('Precio inválido');
            $sub = round($cant * $precio, 2);
            $subtotal += $sub;
            $costo += $cant * (float) $p['precio_compra'];
            $comision += $cant * (float) $p['descuento_comision'];
            $lineas[] = [$p, $cant, $precio, $sub];
        }
        $total = round($subtotal - $descuento + $extraMonto, 2);
        if ($total <= 0) throw new RuntimeException('El total debe ser mayor a cero');
        $comision = round($comision + $total * (float) $u['comision_venta_pct'] / 100, 2);

        $venta = [
            'empresa_id' => $eid, 'codigo' => next_code('ventas', 'V-', 6), 'tipo' => $tipo, 'cliente_id' => $clienteId,
            'vendedor_id' => $u['id'], 'fecha' => $fecha, 'subtotal' => $subtotal, 'descuento' => $descuento,
            'extra_concepto' => $extraConcepto, 'extra_monto' => $extraMonto, 'total' => $total, 'costo_total' => round($costo, 2),
            'comision_vendedor' => $comision, 'saldo' => $total, 'notas' => nullable(post('notas')),
        ];
        $engancheAhora = false; $enganche = 0;
        if ($tipo === 'credito') {
            $meses = max(1, min(60, (int) post('plazo_meses', 12)));
            $frec = in_array(post('frecuencia'), ['semanal', 'quincenal', 'mensual'], true) ? post('frecuencia') : 'mensual';
            $enganche = max(0, dec(post('enganche_monto')));
            if ($enganche >= $total) throw new RuntimeException('El enganche no puede ser igual o mayor al total');
            $engancheAhora = $enganche > 0 && post('enganche_ahora') === '1';
            $primer = nullable(post('fecha_primer_abono'));
            if ($primer && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $primer)) $primer = null;
            $venta += [
                'plazo_meses' => $meses, 'frecuencia' => $frec, 'enganche_monto' => $enganche,
                'fecha_enganche' => $enganche <= 0 ? $fecha : null, 'fecha_primer_abono' => $primer,
            ];
        }
        $vid = insert('ventas', $venta);
        foreach ($lineas as $l) {
            list($p, $cant, $precio, $sub) = $l;
            insert('venta_detalle', [
                'venta_id' => $vid, 'producto_id' => $p['id'], 'descripcion' => $p['nombre'], 'cantidad' => $cant,
                'precio_unitario' => $precio, 'costo_unitario' => $p['precio_compra'], 'comision_unitaria' => $p['descuento_comision'], 'subtotal' => $sub,
            ]);
            q('UPDATE productos SET stock = stock - ? WHERE id=?', [$cant, $p['id']]);
        }
        chat_sistema($vid, 'Venta ' . $venta['codigo'] . ' (' . $tipo . ') creada por ' . $u['nombre'] . ' por ' . money($total));

        if ($tipo === 'contado') {
            registrar_abono($vid, $total, ['metodo' => post('metodo', 'efectivo')]);
        } else {
            if ($enganche <= 0) generar_cuotas($vid);
            elseif ($engancheAhora) {
                registrar_abono($vid, $enganche, ['metodo' => post('metodo', 'efectivo'), 'nota' => 'Enganche']);
                $v = row('SELECT fecha_enganche FROM ventas WHERE id=?', [$vid]);
                if (!$v['fecha_enganche']) { // enganche parcial
                    update('ventas', ['fecha_enganche' => $fecha], 'id=?', [$vid]);
                    generar_cuotas($vid);
                }
            }
        }
        db()->commit();
        json_out(['ok' => true, 'id' => $vid, 'codigo' => $venta['codigo']]);
    } catch (RuntimeException $ex) {
        if (db()->inTransaction()) db()->rollBack();
        json_out(['ok' => false, 'error' => $ex->getMessage()]);
    }
}

$cliente = null;
if ($cid = (int) get('cliente')) $cliente = row('SELECT c.id, c.codigo, c.nombre, c.apellido, c.telefono, c.direccion, a.nombre area FROM clientes c LEFT JOIN areas a ON a.id=c.area_id WHERE c.id=? AND c.empresa_id=?', [$cid, $eid]);

layout_header('Nueva venta');
?>
<div class="grid lg:grid-cols-5 gap-4" id="pos">
  <div class="lg:col-span-3 space-y-4">
    <div class="card p-4">
      <div class="seg">
        <input type="radio" name="tipo" id="t_contado" value="contado"><label for="t_contado"><i data-lucide="banknote" class="w-4 h-4 inline -mt-0.5"></i> Contado</label>
        <input type="radio" name="tipo" id="t_credito" value="credito" checked><label for="t_credito"><i data-lucide="calendar-clock" class="w-4 h-4 inline -mt-0.5"></i> Crédito</label>
      </div>
    </div>

    <!-- Cliente -->
    <div class="card p-4">
      <div class="flex items-center justify-between mb-2"><label class="label !mb-0">Cliente <span id="cli-req" class="text-rose-500">*</span></label>
        <a href="<?= url('cliente_form', ['volver' => 'venta']) ?>" class="text-sm text-brand-600 font-medium flex items-center gap-1"><i data-lucide="user-plus" class="w-4 h-4"></i>Nuevo cliente</a></div>
      <div id="cli-sel" class="hidden items-center gap-3 p-3 rounded-xl bg-brand-50 border border-brand-100">
        <div class="w-10 h-10 rounded-full bg-brand-600 text-white grid place-items-center"><i data-lucide="user" class="w-5 h-5"></i></div>
        <div class="min-w-0 flex-1"><div class="font-semibold truncate" id="cli-nombre"></div><div class="text-xs text-slate-600 truncate" id="cli-info"></div></div>
        <button class="btn btn-ghost btn-icon" onclick="setCliente(null)"><i data-lucide="x"></i></button>
      </div>
      <div class="relative" id="cli-search-box">
        <i data-lucide="search" class="w-4 h-4 absolute left-3 top-3.5 text-slate-400"></i>
        <input id="cli-q" class="input input-lg pl-9" placeholder="Buscar cliente por nombre, código o teléfono" autocomplete="off">
        <div id="cli-sug" class="suggest hidden"></div>
      </div>
    </div>

    <!-- Productos -->
    <div class="card">
      <div class="p-4 relative">
        <label class="label">Productos</label>
        <i data-lucide="package-search" class="w-4 h-4 absolute left-7 top-[3.05rem] text-slate-400"></i>
        <input id="prod-q" class="input input-lg pl-9" placeholder="Buscar producto por nombre o código" autocomplete="off">
        <div id="prod-sug" class="suggest hidden mx-4"></div>
      </div>
      <div id="lineas" class="divide-y divide-slate-100 border-t border-slate-100"></div>
      <div id="lineas-empty" class="text-center text-slate-400 py-10 border-t border-slate-100"><i data-lucide="shopping-cart" class="w-8 h-8 mx-auto mb-1"></i>Agrega productos</div>
    </div>

    <div class="card p-4 grid sm:grid-cols-3 gap-3">
      <div><label class="label">Descuento</label><input id="descuento" type="number" step="0.01" min="0" class="input" placeholder="0.00"></div>
      <div><label class="label">Extra (concepto)</label><input id="extra_concepto" class="input" placeholder="Ej. Flete, instalación"></div>
      <div><label class="label">Extra (monto)</label><input id="extra_monto" type="number" step="0.01" min="0" class="input" placeholder="0.00"></div>
    </div>
  </div>

  <!-- Resumen -->
  <div class="lg:col-span-2">
    <div class="card p-5 lg:sticky lg:top-20 space-y-4">
      <div id="box-credito" class="space-y-4">
        <div>
          <div class="flex justify-between items-end"><label class="label">Plazo</label><div class="text-2xl font-extrabold text-brand-700"><span id="meses-lbl">12</span> <span class="text-sm font-semibold text-slate-500">meses</span></div></div>
          <input type="range" id="plazo" class="range mt-2" min="1" max="24" value="12">
          <div class="flex justify-between text-[11px] text-slate-400 mt-1"><span>1</span><span>6</span><span>12</span><span>18</span><span>24</span></div>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="label">Frecuencia de abono</label><select id="frecuencia" class="select"><option value="mensual">Mensual</option><option value="quincenal">Quincenal</option><option value="semanal">Semanal</option></select></div>
          <div><label class="label">Enganche</label><input id="enganche" type="number" step="0.01" min="0" class="input" placeholder="0.00"></div>
        </div>
        <label id="eng-ahora-box" class="flex items-start gap-3 p-3 rounded-xl border border-slate-200 cursor-pointer">
          <input type="checkbox" id="enganche_ahora" class="w-5 h-5 mt-0.5" checked>
          <span class="text-sm"><b>El cliente paga el enganche ahora</b><br><span class="text-slate-500">Si no, la venta queda <b>pendiente de enganche</b> (fecha de enganche vacía).</span></span>
        </label>
        <div><label class="label">Fecha del primer abono</label><input id="primer_abono" type="date" class="input"><div class="hint" id="primer-hint">Por defecto: 1 mes después del enganche.</div></div>
      </div>

      <div class="rounded-xl bg-slate-50 p-4 space-y-1.5 text-sm">
        <div class="flex justify-between"><span class="text-slate-500">Subtotal</span><span id="r-sub">Q 0.00</span></div>
        <div class="flex justify-between"><span class="text-slate-500">Descuento</span><span id="r-desc" class="text-rose-600">- Q 0.00</span></div>
        <div class="flex justify-between"><span class="text-slate-500">Extra</span><span id="r-extra">+ Q 0.00</span></div>
        <div class="flex justify-between text-lg font-bold pt-2 border-t border-slate-200 mt-2"><span>Total</span><span id="r-total">Q 0.00</span></div>
        <div class="credito-only flex justify-between"><span class="text-slate-500">Enganche</span><span id="r-eng">Q 0.00</span></div>
        <div class="credito-only flex justify-between"><span class="text-slate-500">A financiar</span><span id="r-fin">Q 0.00</span></div>
        <div class="credito-only flex justify-between items-center pt-2 mt-1 border-t border-dashed border-slate-300"><span class="font-semibold"><span id="r-ncuotas">12</span> cuotas de</span><span id="r-cuota" class="text-xl font-extrabold text-brand-700">Q 0.00</span></div>
      </div>

      <div class="contado-only hidden grid grid-cols-2 gap-3">
        <div><label class="label">Recibido</label><input id="recibido" type="number" step="0.01" class="input" placeholder="0.00"></div>
        <div><label class="label">Cambio</label><div id="cambio" class="input bg-slate-50 font-semibold">Q 0.00</div></div>
      </div>
      <div><label class="label">Método de pago</label><select id="metodo" class="select"><option value="efectivo">Efectivo</option><option value="transferencia">Transferencia</option><option value="deposito">Depósito</option><option value="tarjeta">Tarjeta</option></select></div>
      <div><label class="label">Notas</label><textarea id="notas" rows="2" class="textarea" placeholder="Opcional"></textarea></div>
      <button id="btn-guardar" class="btn btn-success btn-lg w-full" onclick="guardar()"><i data-lucide="check-circle"></i> <span>Registrar venta</span></button>
    </div>
  </div>
</div>

<script>
var state = { cliente: <?= json_encode($cliente) ?>, lineas: [] };
var $ = function (id) { return document.getElementById(id); };
function tipo() { return document.querySelector('input[name=tipo]:checked').value; }
function precioPara(p) {
  var v = tipo() === 'credito' ? Number(p.precio_credito) : Number(p.precio_contado);
  return v > 0 ? v : Number(p.precio_venta);
}
function addMonths(d, m) { var x = new Date(d + 'T12:00:00'); var day = x.getDate(); x.setDate(1); x.setMonth(x.getMonth() + m); var last = new Date(x.getFullYear(), x.getMonth() + 1, 0).getDate(); x.setDate(Math.min(day, last)); return x.toISOString().slice(0, 10); }
var HOY = '<?= today() ?>';

autocomplete($('cli-q'), $('cli-sug'), 'index.php?p=api&a=clientes', function (c) {
  return '<div class="font-medium">' + escapeHtml(c.nombre + ' ' + c.apellido) + '</div><div class="text-xs text-slate-500">' + escapeHtml(c.codigo) + ' · ' + escapeHtml(c.telefono || '') + ' · ' + escapeHtml(c.area || 'Sin área') + (Number(c.saldo) > 0 ? ' · <span class="text-amber-600">Saldo ' + money(c.saldo) + '</span>' : '') + '</div>';
}, setCliente);
autocomplete($('prod-q'), $('prod-sug'), 'index.php?p=api&a=productos', function (p) {
  return '<div class="flex justify-between gap-3"><div><div class="font-medium">' + escapeHtml(p.nombre) + '</div><div class="text-xs text-slate-500">' + escapeHtml(p.codigo) + ' · Stock: <b class="' + (Number(p.stock) <= 0 ? 'text-rose-600' : '') + '">' + Number(p.stock) + '</b></div></div><div class="text-right font-semibold">' + money(precioPara(p)) + '</div></div>';
}, addProducto);

function setCliente(c) {
  state.cliente = c;
  $('cli-sel').classList.toggle('hidden', !c); $('cli-sel').classList.toggle('flex', !!c);
  $('cli-search-box').classList.toggle('hidden', !!c);
  if (c) { $('cli-nombre').textContent = c.nombre + ' ' + c.apellido; $('cli-info').textContent = c.codigo + ' · ' + (c.telefono || '') + ' · ' + (c.area || 'Sin área'); }
}
function addProducto(p) {
  if (Number(p.stock) <= 0) { toast('Sin stock disponible de ' + p.nombre); return; }
  var ex = state.lineas.find(function (l) { return l.id == p.id; });
  if (ex) { if (ex.cantidad + 1 > Number(p.stock)) { toast('Stock máximo: ' + p.stock); return; } ex.cantidad++; }
  else state.lineas.push({ id: p.id, nombre: p.nombre, codigo: p.codigo, stock: Number(p.stock), p: p, precio: precioPara(p), cantidad: 1 });
  render();
}
function render() {
  var h = state.lineas.map(function (l, i) {
    return '<div class="p-4 flex flex-wrap items-center gap-3">' +
      '<div class="min-w-0 flex-1 basis-48"><div class="font-semibold">' + escapeHtml(l.nombre) + '</div><div class="text-xs text-slate-500">' + escapeHtml(l.codigo) + ' · Stock ' + l.stock + '</div></div>' +
      '<div class="flex items-center gap-1"><button class="btn btn-light btn-icon" onclick="qty(' + i + ',-1)"><i data-lucide="minus"></i></button><input type="number" min="1" step="1" value="' + l.cantidad + '" class="input w-16 text-center !px-1" onchange="setQty(' + i + ',this.value)"><button class="btn btn-light btn-icon" onclick="qty(' + i + ',1)"><i data-lucide="plus"></i></button></div>' +
      '<div class="w-28"><input type="number" step="0.01" value="' + l.precio.toFixed(2) + '" class="input text-right" onchange="setPrecio(' + i + ',this.value)"></div>' +
      '<div class="w-28 text-right font-bold">' + money(l.precio * l.cantidad) + '</div>' +
      '<button class="btn btn-ghost btn-icon text-rose-500" onclick="state.lineas.splice(' + i + ',1);render()"><i data-lucide="trash-2"></i></button></div>';
  }).join('');
  $('lineas').innerHTML = h;
  $('lineas-empty').classList.toggle('hidden', state.lineas.length > 0);
  lucide.createIcons();
  calc();
}
function qty(i, d) { var l = state.lineas[i]; var n = l.cantidad + d; if (n < 1) return; if (n > l.stock) { toast('Stock máximo: ' + l.stock); return; } l.cantidad = n; render(); }
function setQty(i, v) { var l = state.lineas[i]; v = Math.max(1, Number(v) || 1); if (v > l.stock) { toast('Stock máximo: ' + l.stock); v = l.stock; } l.cantidad = v; render(); }
function setPrecio(i, v) { state.lineas[i].precio = Math.max(0, Number(v) || 0); render(); }

function calc() {
  var sub = state.lineas.reduce(function (s, l) { return s + l.precio * l.cantidad; }, 0);
  var desc = Number($('descuento').value) || 0, extra = Number($('extra_monto').value) || 0;
  var total = Math.round((sub - desc + extra) * 100) / 100;
  $('r-sub').textContent = money(sub); $('r-desc').textContent = '- ' + money(desc); $('r-extra').textContent = '+ ' + money(extra); $('r-total').textContent = money(total);
  var meses = Number($('plazo').value); $('meses-lbl').textContent = meses;
  var f = $('frecuencia').value, factor = f === 'semanal' ? 4 : (f === 'quincenal' ? 2 : 1);
  var eng = Number($('enganche').value) || 0, fin = Math.max(0, total - eng), n = meses * factor;
  $('r-eng').textContent = money(eng); $('r-fin').textContent = money(fin); $('r-ncuotas').textContent = n; $('r-cuota').textContent = money(n ? Math.floor(fin / n * 100) / 100 : 0);
  $('eng-ahora-box').classList.toggle('hidden', eng <= 0);
  var pend = eng > 0 && !$('enganche_ahora').checked;
  $('primer-hint').textContent = pend ? 'Opcional: si la dejas vacía se calcula 1 mes después de recibir el enganche.' : 'Por defecto: 1 mes después del enganche (hoy).';
  if (!$('primer_abono').dataset.touched) $('primer_abono').value = pend ? '' : addMonths(HOY, f === 'mensual' ? 1 : 0) ;
  if (!$('primer_abono').dataset.touched && f !== 'mensual' && !pend) { var d = new Date(HOY + 'T12:00:00'); d.setDate(d.getDate() + (f === 'semanal' ? 7 : 15)); $('primer_abono').value = d.toISOString().slice(0, 10); }
  var rec = Number($('recibido').value) || 0; $('cambio').textContent = money(Math.max(0, rec - total));
  return total;
}
function onTipo() {
  var cr = tipo() === 'credito';
  $('box-credito').classList.toggle('hidden', !cr);
  document.querySelectorAll('.credito-only').forEach(function (e) { e.classList.toggle('hidden', !cr); });
  document.querySelectorAll('.contado-only').forEach(function (e) { e.classList.toggle('hidden', cr); });
  $('cli-req').classList.toggle('hidden', !cr);
  state.lineas.forEach(function (l) { l.precio = precioPara(l.p); });
  render();
}
document.querySelectorAll('input[name=tipo]').forEach(function (r) { r.addEventListener('change', onTipo); });
['descuento', 'extra_monto', 'enganche', 'recibido'].forEach(function (id) { $(id).addEventListener('input', calc); });
['plazo', 'frecuencia', 'enganche_ahora'].forEach(function (id) { $(id).addEventListener('input', calc); $(id).addEventListener('change', calc); });
$('primer_abono').addEventListener('change', function () { this.dataset.touched = 1; });

async function guardar() {
  var total = calc();
  if (!state.lineas.length) return toast('Agrega al menos un producto');
  if (tipo() === 'credito' && !state.cliente) return toast('Selecciona un cliente');
  if (total <= 0) return toast('El total debe ser mayor a cero');
  var b = $('btn-guardar'); b.disabled = true;
  var r = await api('index.php?p=ventas_nueva', {
    tipo: tipo(), cliente_id: state.cliente ? state.cliente.id : '',
    items: state.lineas.map(function (l) { return { id: l.id, cantidad: l.cantidad, precio: l.precio }; }),
    descuento: $('descuento').value, extra_concepto: $('extra_concepto').value, extra_monto: $('extra_monto').value,
    plazo_meses: $('plazo').value, frecuencia: $('frecuencia').value, enganche_monto: $('enganche').value,
    enganche_ahora: $('enganche_ahora').checked ? '1' : '0', fecha_primer_abono: $('primer_abono').value,
    metodo: $('metodo').value, notas: $('notas').value
  });
  if (!r.ok) { b.disabled = false; return toast(r.error || 'Error'); }
  location.href = 'index.php?p=venta&id=' + r.id + '&nueva=1';
}
if (state.cliente) setCliente(state.cliente);
onTipo();
</script>
<?php layout_footer();
