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
                $reg = (string) post('regreso');
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $reg) && $reg > today()) {
                    update('ventas', ['proxima_visita' => $reg], 'id=?', [$id]);
                    chat_sistema($id, 'Abono parcial: el cobrador regresará por el resto el ' . fdate($reg));
                }
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
            case 'editar_abono':
                editar_abono((int) post('abono_id'), dec(post('monto')), post('metodo'), post('nota'), post('motivo'));
                if (is_ajax()) json_out(['ok' => true]);
                flash('success', 'Pago corregido');
                redirect(url('venta', ['id' => $id]));
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
            case 'modificar':
                require_perm('admin');
                if ($v['estado'] !== 'activa') throw new RuntimeException('Solo se pueden modificar ventas activas');
                $desc = max(0, dec(post('descuento')));
                $extraM = max(0, dec(post('extra_monto')));
                $extraC = nullable(post('extra_concepto'));
                if ($extraM > 0 && !$extraC) $extraC = 'Extra';
                $total = round($v['subtotal'] - $desc + $extraM, 2);
                if ($total <= 0) throw new RuntimeException('El total debe ser mayor a cero');
                if ($total + 0.009 < (float) $v['pagado']) throw new RuntimeException('El nuevo total (' . money($total) . ') es menor a lo ya pagado (' . money($v['pagado']) . ')');
                $data = ['descuento' => $desc, 'extra_monto' => $extraM, 'extra_concepto' => $extraC, 'total' => $total];
                $vend = row('SELECT comision_venta_pct FROM usuarios WHERE id=?', [$v['vendedor_id']]);
                $comProd = (float) val('SELECT COALESCE(SUM(comision_unitaria*cantidad),0) FROM venta_detalle WHERE venta_id=?', [$id]);
                if (!$v['liquidacion_id']) $data['comision_vendedor'] = round($comProd + $total * (float) ($vend['comision_venta_pct'] ?? 0) / 100, 2);
                $cambios = [];
                if (abs($desc - (float) $v['descuento']) > 0.004) $cambios[] = 'descuento ' . money($v['descuento']) . ' → ' . money($desc);
                if (abs($extraM - (float) $v['extra_monto']) > 0.004 || $extraC !== $v['extra_concepto']) $cambios[] = 'extra ' . ($extraC ?: '—') . ' ' . money($extraM);
                if ($v['tipo'] === 'credito') {
                    $meses = max(1, min(60, (int) post('plazo_meses', $v['plazo_meses'])));
                    $frec = in_array(post('frecuencia'), ['semanal', 'quincenal', 'mensual'], true) ? post('frecuencia') : $v['frecuencia'];
                    $data['plazo_meses'] = $meses;
                    $data['frecuencia'] = $frec;
                    if ((int) $meses !== (int) $v['plazo_meses']) $cambios[] = 'plazo ' . (int) $v['plazo_meses'] . ' → ' . $meses . ' meses';
                    if ($frec !== $v['frecuencia']) $cambios[] = 'frecuencia ' . $v['frecuencia'] . ' → ' . $frec;
                    if (!$v['fecha_enganche'] && post('enganche_monto') !== null) {
                        $eng = max(0, dec(post('enganche_monto')));
                        if ($eng >= $total) throw new RuntimeException('El enganche no puede ser igual o mayor al total');
                        if (abs($eng - (float) $v['enganche_monto']) > 0.004) $cambios[] = 'enganche ' . money($v['enganche_monto']) . ' → ' . money($eng);
                        $data['enganche_monto'] = $eng;
                        if ($eng <= 0) $data['fecha_enganche'] = today();
                    }
                }
                if (!$cambios) throw new RuntimeException('No hiciste ningún cambio');
                db()->beginTransaction();
                update('ventas', $data + ['saldo' => max(0, round($total - (float) $v['pagado'], 2))], 'id=?', [$id]);
                if ($v['tipo'] === 'credito') replanificar_cuotas($id); else recalcular_venta($id);
                $nv = row('SELECT total, saldo, cuota_monto, num_cuotas, estado FROM ventas WHERE id=?', [$id]);
                chat_sistema($id, 'Venta modificada por ' . user()['nombre'] . ': ' . implode(', ', $cambios) . '. Nuevo total ' . money($nv['total']) . ', saldo ' . money($nv['saldo'])
                    . ($v['tipo'] === 'credito' && $nv['saldo'] > 0 && $nv['cuota_monto'] ? ', cuota ' . money($nv['cuota_monto']) : '') . (post('motivo') ? '. Motivo: ' . post('motivo') : '') . ($nv['estado'] === 'pagada' ? '. CRÉDITO CANCELADO.' : ''));
                db()->commit();
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
      <div class="p-4 sm:p-5 flex flex-col sm:flex-row gap-4 items-stretch sm:items-start">
        <div class="min-w-0 flex-1">
          <div class="flex flex-wrap items-center gap-2">
            <span class="text-xl font-bold"><?= e($v['codigo']) ?></span>
            <span class="badge <?= $v['tipo'] === 'credito' ? 'badge-violet' : 'badge-gray' ?>"><?= e(tipo_label($v['tipo'])) ?></span>
            <span class="badge <?= $sem[2] ?>"><?= semaforo_dot($sem[0]) ?> <?= e($sem[1]) ?></span>
            <?php if ($v['proxima_visita'] && $v['estado'] === 'activa'): ?><span class="badge badge-violet"><i data-lucide="calendar-check" class="w-3 h-3"></i> Próxima visita <?= fdate($v['proxima_visita']) ?></span><?php endif; ?>
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
        <div class="flex flex-wrap sm:block gap-x-6 gap-y-2 sm:text-right rounded-xl sm:rounded-none bg-slate-50 sm:bg-transparent p-3 sm:p-0 shrink-0">
          <div class="min-w-0 max-w-full"><div class="text-xs text-slate-500">Total</div><div class="text-xl sm:text-2xl font-extrabold fit"><?= money($v['total']) ?></div></div>
          <?php if ($v['tipo'] === 'credito'): ?><div class="sm:mt-2 min-w-0 max-w-full"><div class="text-xs text-slate-500">Saldo</div><div class="text-xl font-bold text-brand-700 fit"><?= money($v['saldo']) ?></div></div><?php endif; ?>
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
            <div class="rounded-xl bg-slate-50 p-3"><div class="text-xs text-slate-500">Próximo pago</div><div class="font-semibold"><?= $v['estado'] !== 'activa' ? '—' : ($v['prox_venc'] ? fdate($v['prox_venc']) : ($v['fecha_primer_abono'] ? fdate($v['fecha_primer_abono']) : '—')) ?></div><?php if ($v['estado'] === 'activa' && $v['monto_vencido'] > 0): ?><div class="text-xs text-rose-600 font-semibold">Vencido <?= money($v['monto_vencido']) ?></div><?php elseif ($v['estado'] === 'activa' && $v['prox_venc'] === today()): ?><div class="text-xs text-amber-600 font-semibold">Vence hoy <?= money($v['monto_exigible']) ?></div><?php endif; ?></div>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- Productos -->
    <div class="card overflow-hidden">
      <div class="card-h"><i data-lucide="package" class="w-4 h-4 text-brand-600"></i> Productos</div>
      <div class="overflow-x-auto"><table class="table">
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
            $b = $c['estado'] === 'pagada' ? ['badge-green', 'Pagada'] : ($venc ? ['badge-red', 'Vencida'] : ($c['fecha_vencimiento'] === today() ? ['badge-yellow', 'Vence hoy'] : ($c['estado'] === 'parcial' ? ['badge-yellow', 'Parcial'] : ['badge-gray', 'Pendiente']))); ?>
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
            <td><?= fdate($a['fecha'], true) ?></td><td><?= e(ucfirst($a['tipo'])) ?></td><td><?= e($a['usuario']) ?></td><td><?= e($a['metodo']) ?></td><td class="num font-semibold"><?= money($a['monto']) ?><?php if ($a['editado_at']): ?><div class="text-[11px] font-normal text-amber-600" title="Corregido el <?= e(fdate($a['editado_at'], true)) ?>">corregido (antes <?= money($a['monto_original']) ?>)</div><?php endif; ?></td>
            <td class="text-right whitespace-nowrap">
              <?php if (!$a['anulado']): ?>
                <a href="ticket.php?tipo=abono&id=<?= $a['id'] ?>" target="_blank" class="btn btn-ghost btn-icon" title="Ticket"><i data-lucide="printer"></i></a>
                <?php if (puede_editar_abono($a) && in_array($v['estado'], ['activa', 'pagada'], true) && $a['tipo'] !== 'contado'): ?><button type="button" class="btn btn-ghost btn-icon text-brand-600" title="Corregir pago" onclick='editarAbono(<?= e(json_encode(['abono_id' => (int) $a['id'], 'monto' => $a['monto'], 'metodo' => $a['metodo'], 'nota' => $a['nota'], 'tipo' => $a['tipo'], 'fecha' => fdate($a['fecha'], true)])) ?>)'><i data-lucide="pencil"></i></button><?php endif; ?>
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
        <?php if ($v['estado'] === 'activa'): ?><button class="btn btn-primary" onclick="abrirModificar()"><i data-lucide="pencil"></i>Modificar venta</button><?php endif; ?>
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

<?php if (is_admin() && $v['estado'] === 'activa'):
    $cuotasPag = (int) val("SELECT COUNT(*) FROM cuotas WHERE venta_id=? AND estado='pagada'", [$id]); ?>
<div id="tpl-modificar" class="hidden">
  <form method="post" class="p-5 sm:p-6 space-y-4" id="fmod"><?= csrf_field() ?><input type="hidden" name="accion" value="modificar">
    <h3 class="text-lg font-semibold">Modificar venta <?= e($v['codigo']) ?></h3>
    <div class="rounded-xl bg-slate-50 p-3 text-sm grid grid-cols-3 gap-2 text-center">
      <div><div class="text-xs text-slate-500">Productos</div><div class="font-semibold"><?= money($v['subtotal']) ?></div></div>
      <div><div class="text-xs text-slate-500">Pagado</div><div class="font-semibold"><?= money($v['pagado']) ?></div></div>
      <div><div class="text-xs text-slate-500">Saldo actual</div><div class="font-semibold"><?= money($v['saldo']) ?></div></div>
    </div>
    <?php if ($v['saldo'] > 0): ?>
    <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 space-y-2">
      <div class="text-sm font-semibold text-emerald-800">¿Paga antes? Descuento por pronto pago</div>
      <div class="flex gap-2 items-center"><span class="text-sm text-emerald-800 shrink-0">El cliente liquida con</span><input type="number" step="0.01" min="0" inputmode="decimal" class="input !py-2" id="liquida" placeholder="<?= e(number_format($v['saldo'], 2, '.', '')) ?>"></div>
      <div class="text-xs text-emerald-700">Calcula el descuento para que el saldo quede en ese monto. Después registra el pago con "Cobrar".</div>
    </div>
    <?php endif; ?>
    <div class="grid grid-cols-2 gap-3">
      <div class="col-span-2"><label class="label">Descuento</label><input name="descuento" type="number" step="0.01" min="0" inputmode="decimal" class="input" value="<?= e($v['descuento']) ?>"></div>
      <div><label class="label">Extra (concepto)</label><input name="extra_concepto" class="input" value="<?= e($v['extra_concepto']) ?>" placeholder="Ej. Flete"></div>
      <div><label class="label">Extra (monto)</label><input name="extra_monto" type="number" step="0.01" min="0" inputmode="decimal" class="input" value="<?= e($v['extra_monto']) ?>"></div>
    </div>
    <?php if ($v['tipo'] === 'credito'): ?>
    <div>
      <div class="flex justify-between items-end"><label class="label">Plazo</label><div class="text-xl font-extrabold text-brand-700"><span id="m-meses"><?= (int) $v['plazo_meses'] ?></span> <span class="text-sm font-semibold text-slate-500">meses</span></div></div>
      <input type="range" name="plazo_meses" class="range mt-1" min="1" max="24" value="<?= (int) $v['plazo_meses'] ?>">
    </div>
    <div class="grid grid-cols-2 gap-3">
      <div><label class="label">Frecuencia</label><select name="frecuencia" class="select"><?php foreach (['mensual' => 'Mensual', 'quincenal' => 'Quincenal', 'semanal' => 'Semanal'] as $k => $l) echo '<option value="' . $k . '"' . ($v['frecuencia'] === $k ? ' selected' : '') . '>' . $l . '</option>'; ?></select></div>
      <?php if (!$v['fecha_enganche']): ?><div><label class="label">Enganche</label><input name="enganche_monto" type="number" step="0.01" min="0" inputmode="decimal" class="input" value="<?= e($v['enganche_monto']) ?>"></div><?php endif; ?>
    </div>
    <?php if ($cuotasPag): ?><p class="hint !mt-0">Las <?= $cuotasPag ?> cuota(s) ya pagadas se conservan; el saldo se reparte en las cuotas que faltan del nuevo plazo.</p><?php endif; ?>
    <?php endif; ?>
    <div class="rounded-xl bg-slate-900 text-white p-4 space-y-1 text-sm">
      <div class="flex justify-between"><span class="text-slate-300">Nuevo total</span><b id="m-total"></b></div>
      <div class="flex justify-between"><span class="text-slate-300">Nuevo saldo</span><b id="m-saldo" class="text-lg"></b></div>
      <?php if ($v['tipo'] === 'credito'): ?><div class="flex justify-between"><span class="text-slate-300">Cuota aproximada</span><b id="m-cuota"></b></div><?php endif; ?>
    </div>
    <div><label class="label">Motivo</label><input name="motivo" class="input" placeholder="Ej. Pronto pago, cliente pidió más plazo"></div>
    <button class="btn btn-primary btn-lg w-full"><i data-lucide="save"></i> Guardar cambios</button>
  </form>
</div>
<script>
function abrirModificar() {
  var f = openForm('tpl-modificar');
  var SUB = <?= json_encode((float) $v['subtotal']) ?>, PAG = <?= json_encode((float) $v['pagado']) ?>, SALDO = <?= json_encode((float) $v['saldo']) ?>, DESC0 = <?= json_encode((float) $v['descuento']) ?>;
  var ENG = <?= json_encode((float) $v['enganche_monto']) ?>, ENG_PAG = <?= $v['fecha_enganche'] ? 'true' : 'false' ?>, PAGADAS = <?= (int) $cuotasPag ?>, PAG_CUOTAS = <?= json_encode((float) val("SELECT COALESCE(SUM(monto),0) FROM cuotas WHERE venta_id=? AND estado='pagada'", [$id])) ?>;
  function calc() {
    var total = Math.round((SUB - (Number(f.descuento.value) || 0) + (Number(f.extra_monto.value) || 0)) * 100) / 100;
    var saldo = Math.max(0, total - PAG);
    document.getElementById('m-total').textContent = money(total);
    var s = document.getElementById('m-saldo'); s.textContent = money(saldo); s.classList.toggle('text-rose-300', total < PAG);
    if (f.plazo_meses) {
      document.getElementById('m-meses').textContent = f.plazo_meses.value;
      var fac = f.frecuencia.value === 'semanal' ? 4 : (f.frecuencia.value === 'quincenal' ? 2 : 1);
      var eng = f.enganche_monto ? (Number(f.enganche_monto.value) || 0) : ENG;
      var n = Math.max(1, f.plazo_meses.value * fac - PAGADAS);
      var resto = Math.max(0, total - eng - PAG_CUOTAS);
      document.getElementById('m-cuota').textContent = saldo > 0 ? n + ' x ' + money(Math.floor(resto / n * 100) / 100) : '—';
    }
  }
  f.querySelectorAll('input, select').forEach(function (el) { el.addEventListener('input', calc); el.addEventListener('change', calc); });
  var liq = document.getElementById('liquida');
  if (liq) liq.addEventListener('input', function () {
    var x = Number(liq.value); if (!liq.value || isNaN(x)) return;
    var desc = Math.max(0, Math.round((DESC0 + (SALDO - x)) * 100) / 100);
    f.descuento.value = desc.toFixed(2);
    if (!f.motivo.value) f.motivo.value = 'Pronto pago: liquida con ' + money(x);
    calc();
  });
  calc();
}
</script>
<?php endif; ?>

<div id="tpl-editar-abono" class="hidden">
  <form method="post" class="p-5 sm:p-6 space-y-4" id="fea"><?= csrf_field() ?><input type="hidden" name="accion" value="editar_abono"><input type="hidden" name="abono_id">
    <h3 class="text-lg font-semibold">Corregir pago</h3>
    <div class="rounded-xl bg-slate-50 p-3 text-sm"><span class="ea-info"></span></div>
    <div><label class="label">Monto correcto</label><input name="monto" type="number" step="0.01" min="0.01" inputmode="decimal" required class="input input-lg text-2xl font-bold"></div>
    <div class="grid grid-cols-2 gap-3">
      <div><label class="label">Método</label><select name="metodo" class="select"><option value="efectivo">Efectivo</option><option value="transferencia">Transferencia</option><option value="deposito">Depósito</option><option value="tarjeta">Tarjeta</option></select></div>
      <div><label class="label">Nota</label><input name="nota" class="input"></div>
    </div>
    <div><label class="label">Motivo de la corrección *</label><input name="motivo" required class="input" placeholder="Ej. Escribí 1500 en vez de 150"></div>
    <p class="hint !mt-0">El saldo, las cuotas y tu comisión se recalculan. Queda registrado en el chat.</p>
    <button class="btn btn-primary btn-lg w-full"><i data-lucide="save"></i> Guardar corrección</button>
  </form>
</div>
<script>
function editarAbono(d) {
  var f = openForm('tpl-editar-abono', d);
  f.querySelector('.ea-info').textContent = 'Pago de ' + d.tipo + ' del ' + d.fecha + ' por ' + money(d.monto);
  setTimeout(function () { f.monto.focus(); f.monto.select(); }, 50);
}
<?php if ($ea = (int) get('editar_abono')): foreach ($abonos as $a) if ((int) $a['id'] === $ea && puede_editar_abono($a) && $a['tipo'] !== 'contado') echo 'document.addEventListener("DOMContentLoaded", function () { editarAbono(' . json_encode(['abono_id' => (int) $a['id'], 'monto' => $a['monto'], 'metodo' => $a['metodo'], 'nota' => $a['nota'], 'tipo' => $a['tipo'], 'fecha' => fdate($a['fecha'], true)]) . '); });'; endif; ?>
</script>

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
      value="<?= e(number_format(min((float) $v['saldo'], $pendEng ? (float) $v['enganche_monto'] : ((float) $v['monto_exigible'] > 0 ? (float) $v['monto_exigible'] : (float) $v['cuota_monto'])), 2, '.', '')) ?>"></div>
    <div class="grid grid-cols-2 gap-3">
      <div><label class="label">Método</label><select name="metodo" class="select"><option value="efectivo">Efectivo</option><option value="transferencia">Transferencia</option><option value="deposito">Depósito</option></select></div>
      <div><label class="label">Nota</label><input name="nota" class="input"></div>
    </div>
    <?php if (!$pendEng): ?><div><label class="label">¿Regresar por el resto? <span class="font-normal text-slate-400">(opcional)</span></label><input type="date" name="regreso" class="input" min="<?= date('Y-m-d', strtotime('+1 day')) ?>"><div class="hint">Si lo dejas vacío, la próxima visita será en el vencimiento de la siguiente cuota.</div></div><?php endif; ?>
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
