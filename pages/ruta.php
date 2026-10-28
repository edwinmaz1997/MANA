<?php
if (!is_admin()) require_perm('rutas.mia');
$eid = eid();
$cobId = is_admin() ? (int) get('cobrador', uid()) : uid();
$fecha = get('fecha', today());
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $fecha = today();
$cob = row('SELECT * FROM usuarios WHERE id=? AND empresa_id=?', [$cobId, $eid]);
if (!$cob) redirect('rutas');

if (is_post()) {
    csrf_check();
    $acc = post('accion');
    if ($acc === 'generar') {
        list($rid, $n) = generar_ruta($cobId, $fecha);
        flash('success', $n ? "Se agregaron $n visita(s) a la ruta" : 'La ruta ya está al día');
        redirect(url('ruta', ['cobrador' => $cobId, 'fecha' => $fecha]));
    }
    if ($acc === 'visita') {
        $vid = (int) post('visita_id');
        $vis = row('SELECT rv.* FROM ruta_visitas rv JOIN rutas r ON r.id=rv.ruta_id WHERE rv.id=? AND r.empresa_id=?', [$vid, $eid]);
        if (!$vis) json_out(['ok' => false, 'error' => 'Visita no encontrada']);
        $est = in_array(post('estado'), ['no_pago', 'no_encontrado', 'pendiente'], true) ? post('estado') : 'no_pago';
        update('ruta_visitas', ['estado' => $est, 'visitado_at' => $est === 'pendiente' ? null : now(), 'nota' => nullable(post('nota')), 'lat' => nullable(post('lat')), 'lng' => nullable(post('lng'))], 'id=?', [$vid]);
        if ($est !== 'pendiente') {
            $txt = ['no_pago' => 'Visita: cliente NO PAGÓ', 'no_encontrado' => 'Visita: cliente NO ENCONTRADO'][$est];
            chat_sistema($vis['venta_id'], $txt . (post('nota') ? ' — ' . post('nota') : ''));
        }
        json_out(['ok' => true]);
    }
}

// La ruta del día se crea automáticamente al abrirla
if ($fecha === today() && ($cob['rol'] === 'cobrador' || is_admin())) {
    generar_ruta($cobId, $fecha); // solo agrega los créditos que faltan (ventas nuevas, cuotas de hoy, atrasadas)
}
$ruta = row('SELECT * FROM rutas WHERE empresa_id=? AND fecha=? AND cobrador_id=?', [$eid, $fecha, $cobId]);
$visitas = $ruta ? rows("SELECT rv.*, c.nombre, c.apellido, c.codigo cli_codigo, c.telefono, c.direccion, c.maps_url, c.lat clat, c.lng clng, c.foto_casa, a.nombre area,
        v.codigo, v.saldo, v.cuota_monto, v.enganche_monto, v.fecha_enganche, v.estado vestado, v.tipo, v.fecha, v.ultimo_abono, ab.monto abono_monto, " . sql_credito_cols() . "
    FROM ruta_visitas rv JOIN ventas v ON v.id=rv.venta_id LEFT JOIN clientes c ON c.id=rv.cliente_id LEFT JOIN areas a ON a.id=rv.area_id LEFT JOIN abonos ab ON ab.id=rv.abono_id AND ab.anulado=0
    WHERE rv.ruta_id=? AND rv.estado<>'reprogramada' ORDER BY (rv.estado='pendiente') DESC, rv.atrasada DESC, rv.orden", [$ruta['id']]) : [];
$res = resumen_cobrador($cobId, $fecha, $fecha);
$pend = count(array_filter($visitas, function ($x) { return $x['estado'] === 'pendiente'; }));
$hechas = count($visitas) - $pend;
$pctV = count($visitas) ? round($hechas / count($visitas) * 100) : 0;

layout_header(is_admin() && $cobId !== uid() ? 'Ruta de ' . $cob['nombre'] : 'Mi ruta', ['actions' =>
    '<form method="post">' . csrf_field() . '<input type="hidden" name="accion" value="generar"><button class="btn btn-light" title="Agregar créditos que tocan hoy"><i data-lucide="refresh-cw"></i><span class="hidden sm:inline">Actualizar ruta</span></button></form>']);
?>
<div class="flex flex-wrap items-center gap-2 mb-4">
  <form class="flex items-center gap-2"><input type="hidden" name="p" value="ruta"><input type="hidden" name="cobrador" value="<?= $cobId ?>">
    <input type="date" name="fecha" value="<?= e($fecha) ?>" class="input !w-auto" onchange="this.form.submit()"></form>
  <span class="text-sm text-slate-500"><?= $fecha === today() ? 'Hoy' : fdate($fecha) ?> · <?= e(nombre_completo($cob)) ?></span>
</div>

<div class="card p-5 mb-4">
  <div class="flex items-center justify-between text-sm"><span class="font-semibold">Avance de visitas</span><span><?= $hechas ?> de <?= count($visitas) ?></span></div>
  <div class="h-3 rounded-full bg-slate-100 mt-2 overflow-hidden"><div class="h-full bg-gradient-to-r from-brand-500 to-emerald-400" style="width:<?= $pctV ?>%"></div></div>
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4 text-center">
    <div class="rounded-xl bg-emerald-50 p-3"><div class="text-xs text-emerald-700">Cobrado</div><div class="font-bold text-lg"><?= money($res['cobrado']) ?></div></div>
    <div class="rounded-xl bg-brand-50 p-3"><div class="text-xs text-brand-700">Pagos</div><div class="font-bold text-lg"><?= (int) $res['pagos'] ?></div></div>
    <div class="rounded-xl bg-violet-50 p-3"><div class="text-xs text-violet-700">Comisión</div><div class="font-bold text-lg"><?= money($res['comision']) ?></div></div>
    <div class="rounded-xl bg-slate-900 text-white p-3"><div class="text-xs text-slate-300">A entregar</div><div class="font-bold text-lg"><?= money($res['entregar']) ?></div></div>
  </div>
</div>

<div class="tabs mb-3" id="ftabs">
  <button class="tab active" data-f="pendiente">Pendientes (<?= $pend ?>)</button>
  <button class="tab" data-f="hecha">Visitadas (<?= $hechas ?>)</button>
  <button class="tab" data-f="all">Todas</button>
</div>

<div class="space-y-3" id="visitas">
<?php foreach ($visitas as $x):
    $s = semaforo(array_merge($x, ['estado' => $x['vestado']]));
    $pendEng = !$x['fecha_enganche'] && $x['enganche_monto'] > 0;
    $sugerido = $pendEng ? $x['enganche_monto'] : ((float) $x['monto_exigible'] > 0 ? (float) $x['monto_exigible'] : (float) $x['cuota_monto']);
    $sugerido = min($sugerido, (float) $x['saldo']);
    $map = maps_link(['maps_url' => $x['maps_url'], 'lat' => $x['clat'], 'lng' => $x['clng']]);
    $estados = ['pendiente' => ['badge-gray', 'Pendiente'], 'pago' => ['badge-green', 'Pagó ' . money($x['abono_monto'])], 'no_pago' => ['badge-red', 'No pagó'], 'no_encontrado' => ['badge-yellow', 'No encontrado']];
    $eb = $estados[$x['estado']] ?? ['badge-gray', $x['estado']]; ?>
  <div class="card overflow-hidden sem-<?= $s[0] ?> visita" data-estado="<?= $x['estado'] === 'pendiente' ? 'pendiente' : 'hecha' ?>">
    <div class="p-4 flex gap-3">
      <?php if ($x['foto_casa']): ?><a href="<?= e($x['foto_casa']) ?>" target="_blank"><img src="<?= e($x['foto_casa']) ?>" class="w-16 h-16 rounded-xl object-cover"></a><?php else: ?><div class="w-16 h-16 rounded-xl bg-slate-100 grid place-items-center text-slate-300"><i data-lucide="home" class="w-6 h-6"></i></div><?php endif; ?>
      <div class="min-w-0 flex-1">
        <div class="flex items-start gap-2">
          <div class="min-w-0 flex-1"><div class="font-semibold truncate"><?= e(nombre_completo($x)) ?></div><div class="text-xs text-slate-500 truncate"><?= e($x['area']) ?> · <?= e($x['codigo']) ?></div></div>
          <span class="badge <?= $eb[0] ?>"><?= e($eb[1]) ?></span>
        </div>
        <div class="text-xs text-slate-600 mt-1 line-clamp-2"><?= e($x['direccion']) ?></div>
        <div class="flex flex-wrap gap-1.5 mt-2">
          <span class="badge <?= $s[2] ?>"><?= e($s[1]) ?></span>
          <?php if ($x['atrasada']): ?><span class="badge badge-red"><i data-lucide="clock-alert" class="w-3 h-3"></i> Visita atrasada · <?= fdate($x['fecha_programada']) ?></span><?php endif; ?>
        </div>
      </div>
    </div>
    <div class="px-4 pb-3 grid grid-cols-3 gap-2 text-center text-sm">
      <div class="rounded-lg bg-slate-50 py-1.5"><div class="text-[11px] text-slate-500">Cobrar</div><div class="font-bold"><?= money($sugerido) ?></div></div>
      <div class="rounded-lg bg-slate-50 py-1.5"><?php if ($x['monto_vencido'] > 0): ?><div class="text-[11px] text-slate-500">Vencido</div><div class="font-bold text-rose-600"><?= money($x['monto_vencido']) ?></div><?php else: ?><div class="text-[11px] text-slate-500">Vence</div><div class="font-bold <?= $x['prox_venc'] === today() ? 'text-amber-600' : '' ?>"><?= $x['prox_venc'] === today() ? 'Hoy' : ($x['prox_venc'] ? fdate($x['prox_venc']) : '—') ?></div><?php endif; ?></div>
      <div class="rounded-lg bg-slate-50 py-1.5"><div class="text-[11px] text-slate-500">Saldo</div><div class="font-bold"><?= money($x['saldo']) ?></div></div>
    </div>
    <?php if ($x['nota']): ?><div class="px-4 pb-3 text-xs text-slate-600"><i data-lucide="sticky-note" class="w-3 h-3 inline"></i> <?= e($x['nota']) ?></div><?php endif; ?>
    <div class="border-t border-slate-100 grid grid-cols-4 divide-x divide-slate-100 text-xs font-medium">
      <?php if ($map): ?><a href="<?= e($map) ?>" target="_blank" class="py-3 flex flex-col items-center gap-1 text-slate-600 hover:bg-slate-50"><i data-lucide="navigation" class="w-4 h-4"></i>Ir</a><?php else: ?><span class="py-3 flex flex-col items-center gap-1 text-slate-300"><i data-lucide="navigation" class="w-4 h-4"></i>Sin mapa</span><?php endif; ?>
      <?php if ($x['telefono']): ?><a href="tel:<?= e($x['telefono']) ?>" class="py-3 flex flex-col items-center gap-1 text-slate-600 hover:bg-slate-50"><i data-lucide="phone" class="w-4 h-4"></i>Llamar</a><?php else: ?><span class="py-3 flex flex-col items-center gap-1 text-slate-300"><i data-lucide="phone" class="w-4 h-4"></i>—</span><?php endif; ?>
      <?php if ($x['estado'] === 'pendiente' && $x['vestado'] === 'activa'): ?>
        <button onclick='noPago(<?= (int) $x['id'] ?>)' class="py-3 flex flex-col items-center gap-1 text-rose-600 hover:bg-rose-50"><i data-lucide="x-circle" class="w-4 h-4"></i>No pagó</button>
        <button onclick='cobrar(<?= e(json_encode(['visita' => (int) $x['id'], 'venta' => (int) $x['venta_id'], 'nombre' => nombre_completo($x), 'monto' => round($sugerido, 2), 'saldo' => (float) $x['saldo'], 'eng' => $pendEng])) ?>)' class="py-3 flex flex-col items-center gap-1 bg-emerald-600 text-white hover:bg-emerald-700"><i data-lucide="hand-coins" class="w-4 h-4"></i>Cobrar</button>
      <?php else: ?>
        <a href="<?= url('venta', ['id' => $x['venta_id']]) ?>" class="py-3 flex flex-col items-center gap-1 text-slate-600 hover:bg-slate-50"><i data-lucide="file-text" class="w-4 h-4"></i>Crédito</a>
        <?php if ($x['abono_id']): ?><a href="ticket.php?tipo=abono&id=<?= (int) $x['abono_id'] ?>" target="_blank" class="py-3 flex flex-col items-center gap-1 text-slate-600 hover:bg-slate-50"><i data-lucide="printer" class="w-4 h-4"></i>Recibo</a>
        <?php elseif ($x['vestado'] === 'activa'): ?><button onclick="marcar(<?= (int) $x['id'] ?>,'pendiente')" class="py-3 flex flex-col items-center gap-1 text-slate-600 hover:bg-slate-50"><i data-lucide="undo-2" class="w-4 h-4"></i>Deshacer</button><?php else: ?><span></span><?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php if (!$visitas): ?>
  <div class="card"><?= empty_state($ruta ? 'No hay visitas programadas para esta fecha' : 'No hay ruta para esta fecha', 'map') ?>
    <?php if (!val('SELECT COUNT(*) FROM areas WHERE empresa_id=? AND cobrador_id=? AND activo=1', [$eid, $cobId])): ?><p class="text-center text-sm text-amber-600 pb-8 -mt-6">No tienes áreas asignadas. El administrador debe asignarte áreas en Configuración → Áreas.</p><?php endif; ?></div>
<?php endif; ?>

<script>
var RUTA_URL = 'index.php?p=ruta&cobrador=<?= $cobId ?>&fecha=<?= e($fecha) ?>';
document.querySelectorAll('#ftabs .tab').forEach(function (t) {
  t.addEventListener('click', function () {
    document.querySelectorAll('#ftabs .tab').forEach(function (x) { x.classList.remove('active'); }); t.classList.add('active');
    document.querySelectorAll('.visita').forEach(function (v) { v.classList.toggle('hidden', t.dataset.f !== 'all' && v.dataset.estado !== t.dataset.f); });
  });
});
document.querySelector('#ftabs .tab').click();

function cobrar(d) {
  openModal('<form class="p-6 space-y-4" id="fc"><h3 class="text-lg font-semibold">' + (d.eng ? 'Cobrar enganche' : 'Registrar cobro') + '</h3><div class="text-sm text-slate-600">' + escapeHtml(d.nombre) + ' · Saldo ' + money(d.saldo) + '</div>' +
    '<div><label class="label">Monto recibido</label><input name="monto" type="number" step="0.01" min="0.01" max="' + d.saldo + '" value="' + d.monto.toFixed(2) + '" required class="input input-lg text-2xl font-bold" autofocus></div>' +
    '<div class="grid grid-cols-2 gap-3"><div><label class="label">Método</label><select name="metodo" class="select"><option value="efectivo">Efectivo</option><option value="transferencia">Transferencia</option><option value="deposito">Depósito</option></select></div><div><label class="label">Nota</label><input name="nota" class="input"></div></div>' +
    '<button class="btn btn-success btn-lg w-full"><i data-lucide="check"></i> Confirmar pago</button></form>');
  var f = document.getElementById('fc'), geo = null;
  getGeo().then(function (g) { geo = g; });
  f.addEventListener('submit', async function (e) {
    e.preventDefault(); var b = f.querySelector('button'); b.disabled = true;
    var r = await api('index.php?p=venta&id=' + d.venta, { accion: 'abono', monto: f.monto.value, metodo: f.metodo.value, nota: f.nota.value, visita_id: d.visita, lat: geo ? geo.lat : '', lng: geo ? geo.lng : '' });
    if (!r.ok) { b.disabled = false; return toast(r.error || 'Error'); }
    openModal('<div class="p-6 text-center"><div class="w-14 h-14 rounded-full bg-emerald-100 text-emerald-600 grid place-items-center mx-auto"><i data-lucide="check" class="w-7 h-7"></i></div><h3 class="text-lg font-semibold mt-3">Pago registrado</h3><div class="text-2xl font-extrabold mt-1">' + money(f.monto.value) + '</div><div class="grid gap-2 mt-5"><a href="ticket.php?tipo=abono&id=' + r.abono_id + '" target="_blank" class="btn btn-primary btn-lg" onclick="setTimeout(function(){location.href=RUTA_URL},800)"><i data-lucide="printer"></i> Imprimir recibo</a><a href="' + RUTA_URL + '" class="btn btn-light">Continuar ruta</a></div></div>');
  });
}
function noPago(id) {
  openModal('<form class="p-6 space-y-4" id="fn"><h3 class="text-lg font-semibold">Registrar visita sin pago</h3><div class="seg"><input type="radio" name="estado" id="e1" value="no_pago" checked><label for="e1">No pagó</label><input type="radio" name="estado" id="e2" value="no_encontrado"><label for="e2">No encontrado</label></div><div><label class="label">Nota / motivo</label><input name="nota" class="input" placeholder="Ej. Paga el viernes" autofocus></div><button class="btn btn-danger btn-lg w-full">Guardar visita</button></form>');
  var f = document.getElementById('fn');
  f.addEventListener('submit', async function (e) { e.preventDefault(); f.querySelector('button').disabled = true; var g = await getGeo(); await marcar(id, f.querySelector('input[name=estado]:checked').value, f.nota.value, g); });
}
async function marcar(id, estado, nota, g) {
  var r = await api(RUTA_URL, { accion: 'visita', visita_id: id, estado: estado, nota: nota || '', lat: g ? g.lat : '', lng: g ? g.lng : '' });
  if (!r.ok) return toast(r.error || 'Error');
  location.reload();
}
</script>
<?php layout_footer();
