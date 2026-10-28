<?php
require_perm('ventas');
$eid = eid();
$id = (int) get('id');
$v = row("SELECT v.*, " . sql_credito_cols() . " FROM ventas v WHERE v.id=? AND v.empresa_id=?", [$id, $eid]);
if (!$v) redirect('ventas');
if (!is_admin() && rol() === 'vendedor' && (int) $v['vendedor_id'] !== uid()) { flash('error', 'Solo puedes ver tus ventas'); redirect('ventas'); }

$pendEng = $v['tipo'] === 'credito' && !$v['fecha_enganche'] && $v['enganche_monto'] > 0;
$puedeCobrar = $v['estado'] === 'activa' && (can('cobros') || ($pendEng && can('ventas.crear')));

if (is_post()) {
    csrf_check();
    $acc = post('accion');
    try {
        switch ($acc) {
            case 'abono':
                if (!$puedeCobrar) throw new RuntimeException('No puedes registrar pagos en esta venta');
                $aid = registrar_abono($id, dec(post('monto')), ['metodo' => post('metodo'), 'nota' => post('nota'), 'lat' => post('lat'), 'lng' => post('lng'), 'visita_id' => nullable(post('visita_id'))]);
                if ($vis = (int) post('visita_id')) update('ruta_visitas', ['estado' => 'pago', 'visitado_at' => now(), 'abono_id' => $aid, 'lat' => nullable(post('lat')), 'lng' => nullable(post('lng'))], 'id=?', [$vis]);
                if (is_ajax()) json_out(['ok' => true, 'abono_id' => $aid]);
                flash('success', 'Pago registrado');
                redirect(url('venta', ['id' => $id, 'print_abono' => $aid]));
            case 'recoger':
                require_perm('admin');
                update('ventas', ['recoger_producto' => post('valor') ? 1 : 0], 'id=?', [$id]);
                chat_sistema($id, post('valor') ? 'Marcado para RECOGER PRODUCTO' : 'Se quitó la marca de recoger producto');
                break;
            case 'recogido':
                require_perm('admin');
                if ($v['estado'] !== 'activa') throw new RuntimeException('La venta no está activa');
                db()->beginTransaction();
                update('ventas', ['estado' => 'recogida', 'fecha_recogido' => today(), 'recoger_producto' => 1], 'id=?', [$id]);
                if (post('devolver_stock')) foreach (rows('SELECT producto_id, cantidad FROM venta_detalle WHERE venta_id=?', [$id]) as $d) if ($d['producto_id']) q('UPDATE productos SET stock=stock+? WHERE id=?', [$d['cantidad'], $d['producto_id']]);
                chat_sistema($id, 'PRODUCTO RECOGIDO. Saldo no pagado: ' . money($v['saldo']) . (post('devolver_stock') ? ' (regresó al inventario)' : ''));
                db()->commit();
                break;
            case 'reactivar':
                require_perm('admin');
                if ($v['estado'] !== 'recogida') throw new RuntimeException('Solo se reactivan ventas recogidas');
                update('ventas', ['estado' => 'activa', 'fecha_recogido' => null, 'recoger_producto' => 0], 'id=?', [$id]);
                recalcular_venta($id);
                chat_sistema($id, 'Crédito reactivado');
                break;
            case 'anular':
                require_perm('admin');
                if ($v['estado'] === 'anulada') throw new RuntimeException('Ya está anulada');
                if (val('SELECT COUNT(*) FROM abonos WHERE venta_id=? AND anulado=0 AND liquidacion_id IS NOT NULL', [$id])) throw new RuntimeException('Tiene pagos ya liquidados, no se puede anular');
                db()->beginTransaction();
                q('UPDATE abonos SET anulado=1 WHERE venta_id=?', [$id]);
                if ($v['estado'] !== 'recogida') foreach (rows('SELECT producto_id, cantidad FROM venta_detalle WHERE venta_id=?', [$id]) as $d) if ($d['producto_id']) q('UPDATE productos SET stock=stock+? WHERE id=?', [$d['cantidad'], $d['producto_id']]);
                update('ventas', ['estado' => 'anulada', 'saldo' => 0, 'pagado' => 0], 'id=?', [$id]);
                chat_sistema($id, 'VENTA ANULADA por ' . user()['nombre'] . '. Motivo: ' . (post('motivo') ?: '—'));
                db()->commit();
                break;
            case 'anular_abono':
                require_perm('admin');
                anular_abono((int) post('abono_id'));
                break;
            case 'fecha_abono':
                if (!is_admin() && (int) $v['vendedor_id'] !== uid()) throw new RuntimeException('Sin permiso');
                if (val("SELECT COUNT(*) FROM abonos WHERE venta_id=? AND anulado=0 AND tipo='abono'", [$id])) throw new RuntimeException('Ya hay abonos registrados; solo el calendario futuro se puede mover desde las cuotas');
                $f = post('fecha');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) throw new RuntimeException('Fecha inválida');
                update('ventas', ['fecha_primer_abono' => $f], 'id=?', [$id]);
                if ($v['fecha_enganche']) generar_cuotas($id);
                chat_sistema($id, 'Fecha del primer abono cambiada a ' . fdate($f));
                break;
            case 'cuota_fecha':
                require_perm('admin');
                $f = post('fecha');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) throw new RuntimeException('Fecha inválida');
                update('cuotas', ['fecha_vencimiento' => $f], 'id=? AND venta_id=?', [(int) post('cuota_id'), $id]);
                break;
        }
        flash('success', 'Cambios guardados');
    } catch (RuntimeException $ex) {
        if (db()->inTransaction()) db()->rollBack();
        if (is_ajax()) json_out(['ok' => false, 'error' => $ex->getMessage()]);
        flash('error', $ex->getMessage());
    }
    redirect(url('venta', ['id' => $id]));
}

$cli = $v['cliente_id'] ? row('SELECT c.*, a.nombre area FROM clientes c LEFT JOIN areas a ON a.id=c.area_id WHERE c.id=?', [$v['cliente_id']]) : null;
$vend = row('SELECT nombre, apellido FROM usuarios WHERE id=?', [$v['vendedor_id']]);
$det = rows('SELECT * FROM venta_detalle WHERE venta_id=?', [$id]);
$cuotas = rows('SELECT * FROM cuotas WHERE venta_id=? ORDER BY numero', [$id]);
$abonos = rows('SELECT a.*, u.nombre usuario FROM abonos a LEFT JOIN usuarios u ON u.id=a.usuario_id WHERE a.venta_id=? ORDER BY a.fecha DESC', [$id]);
$sem = semaforo($v);
$pct = $v['total'] > 0 ? min(100, round($v['pagado'] / $v['total'] * 100)) : 0;
$sinAbonos = !val("SELECT COUNT(*) FROM abonos WHERE venta_id=? AND anulado=0 AND tipo='abono'", [$id]);

$acts = '<a href="ticket.php?tipo=venta&id=' . $id . '" target="_blank" class="btn btn-light"><i data-lucide="printer"></i><span class="hidden sm:inline">Ticket</span></a>';
if ($puedeCobrar) $acts .= '<button onclick="abrirCobro()" class="btn btn-success"><i data-lucide="hand-coins"></i>' . ($pendEng ? 'Cobrar enganche' : 'Cobrar') . '</button>';
layout_header('Venta ' . $v['codigo'], ['actions' => $acts]);
?>
<div class="grid lg:grid-cols-3 gap-4">
  <div class="lg:col-span-2 space-y-4">
    <!-- Card de venta -->
    <div class="card overflow-hidden sem-<?= $sem[0] ?>">
      <div class="p-5 flex flex-wrap gap-4 items-start">
        <div class="min-w-0 flex-1">
          <div class="flex flex-wrap items-center gap-2">
            <span class="text-xl font-bold"><?= e($v['codigo']) ?></span>
            <span class="badge <?= $v['tipo'] === 'credito' ? 'badge-violet' : 'badge-gray' ?>"><?= e(tipo_label($v['tipo'])) ?></span>
            <span class="badge <?= $sem[2] ?>"><?= semaforo_dot($sem[0]) ?> <?= e($sem[1]) ?></span>
            <?php if ($v['recoger_producto'] && $v['estado'] === 'activa'): ?><span class="badge badge-red"><i data-lucide="package-x" class="w-3 h-3"></i> Recoger producto</span><?php endif; ?>
          </div>
          <div class="text-sm text-slate-500 mt-1"><?= fdate($v['fecha']) ?> · Vendedor: <?= e(nombre_completo($vend)) ?></div>
          <?php if ($cli): ?>
            <a href="<?= url('cliente', ['id' => $cli['id']]) ?>" class="mt-3 flex items-center gap-3 group">
              <?php if ($cli['foto_casa']): ?><img src="<?= e($cli['foto_casa']) ?>" class="thumb"><?php else: ?><div class="thumb grid place-items-center text-slate-400"><i data-lucide="user" class="w-5 h-5"></i></div><?php endif; ?>
              <div><div class="font-semibold group-hover:text-brand-600"><?= e(nombre_completo($cli)) ?></div><div class="text-xs text-slate-500"><?= e($cli['codigo']) ?> · <?= e($cli['telefono']) ?> · <?= e($cli['area'] ?: 'Sin área') ?></div></div>
            </a>
          <?php else: ?><div class="mt-3 text-sm text-slate-500">Consumidor final</div><?php endif; ?>
        </div>
        <div class="text-right">
          <div class="text-xs text-slate-500">Total</div><div class="text-2xl font-extrabold"><?= money($v['total']) ?></div>
          <?php if ($v['tipo'] === 'credito'): ?><div class="text-xs text-slate-500 mt-2">Saldo</div><div class="text-xl font-bold text-brand-700"><?= money($v['saldo']) ?></div><?php endif; ?>
        </div>
      </div>
      <?php if ($v['tipo'] === 'credito'): ?>
        <div class="px-5 pb-5">
          <div class="flex justify-between text-xs text-slate-500 mb-1"><span>Pagado <?= money($v['pagado']) ?></span><span><?= $pct ?>%</span></div>
          <div class="h-2.5 rounded-full bg-slate-100 overflow-hidden"><div class="h-full bg-gradient-to-r from-brand-500 to-emerald-400" style="width:<?= $pct ?>%"></div></div>
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4 text-sm">
            <div class="rounded-xl bg-slate-50 p-3"><div class="text-xs text-slate-500">Plazo</div><div class="font-semibold"><?= (int) $v['plazo_meses'] ?> meses · <?= e($v['frecuencia']) ?></div></div>
            <div class="rounded-xl bg-slate-50 p-3"><div class="text-xs text-slate-500">Cuota</div><div class="font-semibold"><?= $v['cuota_monto'] ? money($v['cuota_monto']) : '—' ?></div></div>
            <div class="rounded-xl bg-slate-50 p-3"><div class="text-xs text-slate-500">Enganche</div><div class="font-semibold"><?= money($v['enganche_monto']) ?></div><div class="text-xs <?= $pendEng ? 'text-amber-600 font-semibold' : 'text-slate-500' ?>"><?= $v['fecha_enganche'] ? fdate($v['fecha_enganche']) : 'Pendiente de enganche' ?></div></div>
            <div class="rounded-xl bg-slate-50 p-3"><div class="text-xs text-slate-500">Próximo pago</div><div class="font-semibold"><?= $v['prox_venc'] ? fdate($v['prox_venc']) : ($v['fecha_primer_abono'] ? fdate($v['fecha_primer_abono']) : '—') ?></div><?php if ($v['monto_vencido'] > 0): ?><div class="text-xs text-rose-600 font-semibold">Vencido <?= money($v['monto_vencido']) ?></div><?php endif; ?></div>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- Productos -->
    <div class="card overflow-hidden">
      <div class="card-h"><i data-lucide="package" class="w-4 h-4 text-brand-600"></i> Productos</div>
      <div class="overflow-x-auto"><table class="table table-keep">
        <thead><tr><th>Descripción</th><th class="num">Cant.</th><th class="num">Precio</th><th class="num">Subtotal</th></tr></thead>
        <tbody>
          <?php foreach ($det as $d): ?><tr><td><?= e($d['descripcion']) ?></td><td class="num"><?= num($d['cantidad'], $d['cantidad'] == floor($d['cantidad']) ? 0 : 2) ?></td><td class="num"><?= money($d['precio_unitario']) ?></td><td class="num"><?= money($d['subtotal']) ?></td></tr><?php endforeach; ?>
          <?php if ($v['descuento'] > 0): ?><tr><td colspan="3" class="text-right text-slate-500">Descuento</td><td class="num text-rose-600">- <?= money($v['descuento']) ?></td></tr><?php endif; ?>
          <?php if ($v['extra_monto'] > 0): ?><tr><td colspan="3" class="text-right text-slate-500"><?= e($v['extra_concepto']) ?></td><td class="num">+ <?= money($v['extra_monto']) ?></td></tr><?php endif; ?>
          <tr><td colspan="3" class="text-right font-semibold">Total</td><td class="num font-bold"><?= money($v['total']) ?></td></tr>
        </tbody>
      </table></div>
      <?php if ($v['notas']): ?><div class="px-5 py-3 border-t border-slate-100 text-sm text-slate-600"><b>Notas:</b> <?= nl2br(e($v['notas'])) ?></div><?php endif; ?>
    </div>

    <?php if ($v['tipo'] === 'credito'): ?>
    <!-- Plan de pagos -->
    <div class="card overflow-hidden">
      <div class="card-h"><i data-lucide="calendar-days" class="w-4 h-4 text-brand-600"></i> Plan de pagos
        <?php if ($sinAbonos && $v['estado'] === 'activa' && (is_admin() || (int) $v['vendedor_id'] === uid())): ?>
          <form method="post" class="ml-auto flex items-center gap-2 font-normal"><?= csrf_field() ?><input type="hidden" name="accion" value="fecha_abono">
            <span class="text-xs text-slate-500 hidden sm:inline">Primer abono</span><input type="date" name="fecha" value="<?= e($v['fecha_primer_abono']) ?>" class="input !py-1.5 !w-auto text-sm" required><button class="btn btn-light btn-sm">Aplicar</button></form>
        <?php endif; ?>
      </div>
      <?php if ($cuotas): ?>
      <div class="overflow-x-auto"><table class="table table-keep">
        <thead><tr><th>#</th><th>Vence</th><th class="num">Cuota</th><th class="num hidden sm:table-cell">Pagado</th><th>Estado</th></tr></thead>
        <tbody>
        <?php foreach ($cuotas as $c):
            $venc = $c['estado'] !== 'pagada' && $c['fecha_vencimiento'] < today();
            $b = $c['estado'] === 'pagada' ? ['badge-green', 'Pagada'] : ($venc ? ['badge-red', 'Vencida'] : ($c['estado'] === 'parcial' ? ['badge-yellow', 'Parcial'] : ['badge-gray', 'Pendiente'])); ?>
          <tr class="<?= $venc ? 'bg-rose-50/40' : '' ?>"><td><?= (int) $c['numero'] ?></td>
            <td><?php if (is_admin() && $c['estado'] !== 'pagada'): ?><form method="post" class="flex gap-1 items-center"><?= csrf_field() ?><input type="hidden" name="accion" value="cuota_fecha"><input type="hidden" name="cuota_id" value="<?= $c['id'] ?>"><input type="date" name="fecha" value="<?= e($c['fecha_vencimiento']) ?>" class="input input-date-sm !py-1 !px-2 text-sm" onchange="this.form.submit()"></form><?php else: ?><?= fdate($c['fecha_vencimiento']) ?><?php endif; ?></td>
            <td class="num"><?= money($c['monto']) ?></td><td class="num hidden sm:table-cell"><?= money($c['pagado']) ?></td><td><span class="badge <?= $b[0] ?>"><?= $b[1] ?></span></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php else: ?><div class="p-6 text-center text-sm text-slate-500"><i data-lucide="clock" class="w-6 h-6 mx-auto mb-1 text-amber-500"></i>El calendario se genera al recibir el enganche<?= $v['fecha_primer_abono'] ? ' (primer abono programado: ' . fdate($v['fecha_primer_abono']) . ')' : ' (primer abono: 1 mes después)' ?>.</div><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Pagos -->
    <div class="card overflow-hidden">
      <div class="card-h"><i data-lucide="history" class="w-4 h-4 text-brand-600"></i> Pagos recibidos</div>
      <div class="overflow-x-auto"><table class="table">
        <thead><tr><th>Fecha</th><th>Tipo</th><th>Recibió</th><th>Método</th><th class="num">Monto</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($abonos as $a): ?>
          <tr class="<?= $a['anulado'] ? 'line-through text-slate-400' : '' ?>">
            <td><?= fdate($a['fecha'], true) ?></td><td><?= e(ucfirst($a['tipo'])) ?></td><td><?= e($a['usuario']) ?></td><td><?= e($a['metodo']) ?></td><td class="num font-semibold"><?= money($a['monto']) ?></td>
            <td class="text-right whitespace-nowrap">
              <?php if (!$a['anulado']): ?>
                <a href="ticket.php?tipo=abono&id=<?= $a['id'] ?>" target="_blank" class="btn btn-ghost btn-icon" title="Ticket"><i data-lucide="printer"></i></a>
                <?php if (is_admin() && !$a['liquidacion_id']): ?><form method="post" class="inline" data-confirm="¿Anular este pago?"><?= csrf_field() ?><input type="hidden" name="accion" value="anular_abono"><input type="hidden" name="abono_id" value="<?= $a['id'] ?>"><button class="btn btn-ghost btn-icon text-rose-500" title="Anular"><i data-lucide="x-circle"></i></button></form><?php endif; ?>
              <?php else: ?><span class="badge badge-gray">Anulado</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table><?php if (!$abonos) echo empty_state('Sin pagos registrados', 'coins'); ?></div>
    </div>

    <?php if (is_admin()): ?>
    <div class="card p-5">
      <h3 class="font-semibold mb-3">Acciones</h3>
      <div class="flex flex-wrap gap-2">
        <?php if ($v['tipo'] === 'credito' && $v['estado'] === 'activa'): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="recoger"><input type="hidden" name="valor" value="<?= $v['recoger_producto'] ? 0 : 1 ?>">
            <button class="btn <?= $v['recoger_producto'] ? 'btn-light' : 'btn-warning' ?>"><i data-lucide="flag"></i><?= $v['recoger_producto'] ? 'Quitar marca de recoger' : 'Marcar para recoger' ?></button></form>
          <button class="btn btn-danger" onclick="openForm('tpl-recogido')"><i data-lucide="package-x"></i>Producto recogido</button>
        <?php endif; ?>
        <?php if ($v['estado'] === 'recogida'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="reactivar"><button class="btn btn-light"><i data-lucide="rotate-ccw"></i>Reactivar crédito</button></form><?php endif; ?>
        <?php if ($v['estado'] !== 'anulada'): ?><button class="btn btn-ghost text-rose-600" onclick="openForm('tpl-anular')"><i data-lucide="ban"></i>Anular venta</button><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Chat -->
  <div>
    <div class="card overflow-hidden lg:sticky lg:top-20">
      <div class="card-h"><i data-lucide="messages-square" class="w-4 h-4 text-brand-600"></i> Chat de la venta <span class="ml-auto text-xs font-normal text-slate-400">Notas del equipo</span></div>
      <div id="chat" class="chat-box p-4 space-y-2"></div>
      <form id="chat-form" class="p-3 border-t border-slate-100 flex gap-2" data-nolock="1">
        <input id="chat-msg" class="input" placeholder="Escribe una nota o comentario..." autocomplete="off">
        <button class="btn btn-primary btn-icon" data-nolock><i data-lucide="send"></i></button>
      </form>
    </div>
  </div>
</div>

<div id="tpl-recogido" class="hidden">
  <form method="post" class="p-6 space-y-4"><?= csrf_field() ?><input type="hidden" name="accion" value="recogido">
    <h3 class="text-lg font-semibold">Registrar producto recogido</h3>
    <p class="text-sm text-slate-600">El crédito se cerrará como <b>Recogido</b> con un saldo no pagado de <b><?= money($v['saldo']) ?></b>.</p>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="devolver_stock" value="1" checked class="w-4 h-4"> Regresar los productos al inventario</label>
    <div class="flex gap-2"><button class="btn btn-danger flex-1">Confirmar</button><button type="button" class="btn btn-light" onclick="closeModal()">Cancelar</button></div>
  </form>
</div>
<div id="tpl-anular" class="hidden">
  <form method="post" class="p-6 space-y-4"><?= csrf_field() ?><input type="hidden" name="accion" value="anular">
    <h3 class="text-lg font-semibold">Anular venta</h3>
    <p class="text-sm text-slate-600">Se anularán todos sus pagos y se regresará el producto al inventario.</p>
    <div><label class="label">Motivo</label><input name="motivo" class="input" required></div>
    <div class="flex gap-2"><button class="btn btn-danger flex-1">Anular</button><button type="button" class="btn btn-light" onclick="closeModal()">Cancelar</button></div>
  </form>
</div>

<?php if ($puedeCobrar): ?>
<div id="tpl-cobro" class="hidden">
  <form method="post" class="p-6 space-y-4" id="f-cobro" data-nolock="1"><?= csrf_field() ?><input type="hidden" name="accion" value="abono"><input type="hidden" name="lat"><input type="hidden" name="lng"><input type="hidden" name="visita_id" value="<?= (int) get('visita') ?: '' ?>">
    <h3 class="text-lg font-semibold"><?= $pendEng ? 'Cobrar enganche' : 'Registrar pago' ?></h3>
    <div class="rounded-xl bg-slate-50 p-3 text-sm grid grid-cols-2 gap-2">
      <div><span class="text-slate-500">Saldo</span><div class="font-bold"><?= money($v['saldo']) ?></div></div>
      <div><span class="text-slate-500"><?= $pendEng ? 'Enganche' : 'Cuota' ?></span><div class="font-bold"><?= money($pendEng ? $v['enganche_monto'] : $v['cuota_monto']) ?></div></div>
      <?php if ($v['monto_vencido'] > 0): ?><div class="col-span-2 text-rose-600">Vencido: <b><?= money($v['monto_vencido']) ?></b></div><?php endif; ?>
    </div>
    <div><label class="label">Monto recibido</label><input name="monto" type="number" step="0.01" min="0.01" max="<?= e($v['saldo']) ?>" required class="input input-lg text-2xl font-bold" autofocus
      value="<?= e(number_format(min((float) $v['saldo'], $pendEng ? (float) $v['enganche_monto'] : max((float) $v['cuota_monto'], (float) $v['monto_vencido'])), 2, '.', '')) ?>"></div>
    <div class="grid grid-cols-2 gap-3">
      <div><label class="label">Método</label><select name="metodo" class="select"><option value="efectivo">Efectivo</option><option value="transferencia">Transferencia</option><option value="deposito">Depósito</option></select></div>
      <div><label class="label">Nota</label><input name="nota" class="input"></div>
    </div>
    <button class="btn btn-success btn-lg w-full"><i data-lucide="check"></i> Confirmar pago</button>
  </form>
</div>
<?php endif; ?>

<script>
var VID = <?= $id ?>, lastId = 0;
function abrirCobro() {
  var f = openForm('tpl-cobro');
  getGeo().then(function (g) { if (g) { f.lat.value = g.lat; f.lng.value = g.lng; } });
  f.addEventListener('submit', function () { f.querySelector('button').disabled = true; });
}
async function cargarChat() {
  var r = await api('index.php?p=api&a=chat&venta=' + VID + '&after=' + lastId);
  if (!r.ok || !r.data.length) return;
  var box = document.getElementById('chat');
  var stick = box.scrollTop + box.clientHeight >= box.scrollHeight - 30 || lastId === 0;
  r.data.forEach(function (m) {
    lastId = Math.max(lastId, m.id);
    var d = document.createElement('div');
    if (Number(m.sistema)) { d.className = 'msg msg-sys'; d.innerHTML = escapeHtml(m.mensaje) + '<div class="text-[10px] opacity-60 mt-0.5">' + m.hora + '</div>'; }
    else { d.className = 'msg ' + (m.me ? 'msg-me' : 'msg-other'); d.innerHTML = (m.me ? '' : '<div class="text-[11px] font-semibold text-brand-600">' + escapeHtml(m.nombre || '') + '</div>') + escapeHtml(m.mensaje) + '<div class="text-[10px] opacity-60 mt-0.5 text-right">' + m.hora + '</div>'; }
    box.appendChild(d);
  });
  if (stick) box.scrollTop = box.scrollHeight;
}
document.getElementById('chat-form').addEventListener('submit', async function (e) {
  e.preventDefault();
  var inp = document.getElementById('chat-msg'), m = inp.value.trim(); if (!m) return;
  inp.value = '';
  var r = await api('index.php?p=api&a=chat&venta=' + VID + '&after=' + lastId, { mensaje: m });
  if (!r.ok) return toast(r.error);
  lastId = 0; document.getElementById('chat').innerHTML = ''; cargarChat();
});
cargarChat(); setInterval(cargarChat, 8000);
function modalImprimir(titulo, url) {
  openModal('<div class="p-6 text-center"><div class="w-14 h-14 rounded-full bg-emerald-100 text-emerald-600 grid place-items-center mx-auto"><i data-lucide="check" class="w-7 h-7"></i></div><h3 class="text-lg font-semibold mt-3">' + titulo + '</h3><div class="grid gap-2 mt-5"><a href="' + url + '" target="_blank" onclick="closeModal()" class="btn btn-primary btn-lg"><i data-lucide="printer"></i> Imprimir ticket</a><button class="btn btn-light" onclick="closeModal()">Cerrar</button></div></div>');
}
<?php if (get('nueva')): ?>modalImprimir('Venta registrada', 'ticket.php?tipo=venta&id=<?= $id ?>');<?php endif; ?>
<?php if ($pa = (int) get('print_abono')): ?>modalImprimir('Pago registrado', 'ticket.php?tipo=abono&id=<?= $pa ?>');<?php endif; ?>
<?php if (get('cobrar') && $puedeCobrar): ?>abrirCobro();<?php endif; ?>
</script>
<?php layout_footer();
