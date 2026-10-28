<?php
require_perm('admin');
$eid = eid();

if (is_post()) {
    csrf_check();
    $uidE = (int) post('usuario_id');
    $emp = row('SELECT * FROM usuarios WHERE id=? AND empresa_id=?', [$uidE, $eid]);
    try {
        if (!$emp) throw new RuntimeException('Empleado no encontrado');
        $hasta = post('hasta') ?: today();
        db()->beginTransaction();
        if (post('accion') === 'entrega') {
            $r = row("SELECT COALESCE(SUM(monto),0) cob, COALESCE(SUM(comision_monto),0) com, MIN(DATE(fecha)) desde FROM abonos WHERE empresa_id=? AND usuario_id=? AND anulado=0 AND liquidacion_id IS NULL AND DATE(fecha)<=?", [$eid, $uidE, $hasta]);
            if ($r['cob'] <= 0) throw new RuntimeException('No hay cobros pendientes de entregar');
            $lid = insert('liquidaciones', ['empresa_id' => $eid, 'usuario_id' => $uidE, 'tipo' => 'cobro', 'fecha' => today(), 'desde' => $r['desde'], 'hasta' => $hasta,
                'total_cobrado' => $r['cob'], 'comision_cobro' => $r['com'], 'monto' => dec(post('monto')), 'nota' => nullable(post('nota')), 'created_by' => uid()]);
            q("UPDATE abonos SET liquidacion_id=? WHERE empresa_id=? AND usuario_id=? AND anulado=0 AND liquidacion_id IS NULL AND DATE(fecha)<=?", [$lid, $eid, $uidE, $hasta]);
            $esperado = round($r['cob'] - $r['com'], 2);
            $dif = round(dec(post('monto')) - $esperado, 2);
            flash($dif == 0 ? 'success' : 'warning', 'Entrega registrada: ' . money(dec(post('monto'))) . ($dif != 0 ? ' (diferencia de ' . money($dif) . ')' : ''));
        } elseif (post('accion') === 'comision') {
            $r = row("SELECT COALESCE(SUM(comision_vendedor),0) com, COALESCE(SUM(total),0) tot, MIN(fecha) desde FROM ventas WHERE empresa_id=? AND vendedor_id=? AND estado<>'anulada' AND liquidacion_id IS NULL AND fecha<=?", [$eid, $uidE, $hasta]);
            $base = post('incluir_base') ? dec(post('salario_base')) : 0;
            if ($r['com'] <= 0 && $base <= 0) throw new RuntimeException('No hay comisiones pendientes');
            $lid = insert('liquidaciones', ['empresa_id' => $eid, 'usuario_id' => $uidE, 'tipo' => 'comision', 'fecha' => today(), 'desde' => $r['desde'], 'hasta' => $hasta,
                'total_ventas' => $r['tot'], 'comision_venta' => $r['com'], 'salario_base' => $base, 'monto' => round($r['com'] + $base, 2), 'nota' => nullable(post('nota')), 'created_by' => uid()]);
            q("UPDATE ventas SET liquidacion_id=? WHERE empresa_id=? AND vendedor_id=? AND estado<>'anulada' AND liquidacion_id IS NULL AND fecha<=?", [$lid, $eid, $uidE, $hasta]);
            flash('success', 'Pago de comisiones declarado: ' . money($r['com'] + $base));
        }
        db()->commit();
    } catch (RuntimeException $ex) {
        if (db()->inTransaction()) db()->rollBack();
        flash('error', $ex->getMessage());
    }
    redirect(url('empleados', ['r' => get('r', 'mes')]));
}

$rng = rango_fechas();
$emps = rows("SELECT * FROM usuarios WHERE empresa_id=? AND activo=1 ORDER BY FIELD(rol,'cobrador','vendedor','admin'), nombre", [$eid]);
$data = [];
foreach ($emps as $e) {
    $per = row("SELECT COALESCE(SUM(monto),0) cob, COALESCE(SUM(comision_monto),0) com, COUNT(*) pagos FROM abonos WHERE empresa_id=? AND usuario_id=? AND anulado=0 AND DATE(fecha) BETWEEN ? AND ?", [$eid, $e['id'], $rng['desde'], $rng['hasta']]);
    $pendCob = row("SELECT COALESCE(SUM(monto),0) cob, COALESCE(SUM(comision_monto),0) com FROM abonos WHERE empresa_id=? AND usuario_id=? AND anulado=0 AND liquidacion_id IS NULL", [$eid, $e['id']]);
    $ven = row("SELECT COUNT(*) n, COALESCE(SUM(total),0) tot, COALESCE(SUM(comision_vendedor),0) com FROM ventas WHERE empresa_id=? AND vendedor_id=? AND estado<>'anulada' AND fecha BETWEEN ? AND ?", [$eid, $e['id'], $rng['desde'], $rng['hasta']]);
    $venPend = (float) val("SELECT COALESCE(SUM(comision_vendedor),0) FROM ventas WHERE empresa_id=? AND vendedor_id=? AND estado<>'anulada' AND liquidacion_id IS NULL", [$eid, $e['id']]);
    $vis = resumen_cobrador($e['id'], $rng['desde'], $rng['hasta']);
    $data[] = ['u' => $e, 'per' => $per, 'pendCob' => $pendCob, 'ven' => $ven, 'venPend' => $venPend, 'vis' => $vis];
}
$hist = rows("SELECT l.*, u.nombre, u.apellido FROM liquidaciones l JOIN usuarios u ON u.id=l.usuario_id WHERE l.empresa_id=? AND l.fecha BETWEEN ? AND ? ORDER BY l.id DESC LIMIT 100", [$eid, $rng['desde'], $rng['hasta']]);

layout_header('Empleados y comisiones');
filtro_fechas($rng);
?>
<div class="rounded-xl bg-brand-50 border border-brand-100 text-brand-900 text-sm px-4 py-3 mb-4">
  <b>Cobradores:</b> se quedan con su comisión del dinero cobrado y entregan la diferencia. <b>Vendedores:</b> ganan la comisión de cada producto vendido (+ % si se configuró), que se declara y paga aquí junto con el salario base.
</div>

<div class="space-y-4">
<?php foreach ($data as $d): $e = $d['u']; $entregar = round($d['pendCob']['cob'] - $d['pendCob']['com'], 2); $totalPagar = $d['venPend'] + (float) $e['salario_base']; ?>
  <div class="card overflow-hidden">
    <div class="p-5 flex flex-wrap items-center gap-4">
      <div class="flex items-center gap-3 min-w-[220px] flex-1">
        <div class="w-11 h-11 rounded-full bg-slate-900 text-white grid place-items-center font-semibold"><?= e(mb_strtoupper(mb_substr($e['nombre'], 0, 1))) ?></div>
        <div><div class="font-semibold"><?= e(nombre_completo($e)) ?></div><div class="text-xs text-slate-500"><?= e(ucfirst($e['rol'])) ?><?= $e['rol'] === 'cobrador' ? ' · ' . num($e['comision_cobro_pct'], 2) . '% cobro' : '' ?> · Base <?= money($e['salario_base']) ?></div></div>
      </div>
      <div class="flex flex-wrap gap-2">
        <?php if ($d['pendCob']['cob'] > 0): ?><button class="btn btn-success" onclick='openForm("tpl-entrega", <?= e(json_encode(['usuario_id' => $e['id'], 'monto' => number_format($entregar, 2, '.', ''), 'info' => nombre_completo($e) . ': cobrado ' . money($d['pendCob']['cob']) . ' − comisión ' . money($d['pendCob']['com']) . ' = ' . money($entregar)])) ?>)'><i data-lucide="banknote"></i>Recibir entrega <?= money($entregar) ?></button><?php endif; ?>
        <?php if ($d['venPend'] > 0 || $e['salario_base'] > 0): ?><button class="btn btn-light" onclick='openForm("tpl-comision", <?= e(json_encode(['usuario_id' => $e['id'], 'salario_base' => $e['salario_base'], 'info' => nombre_completo($e) . ': comisiones de venta pendientes ' . money($d['venPend'])])) ?>)'><i data-lucide="badge-dollar-sign"></i>Declarar comisiones</button><?php endif; ?>
      </div>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-8 border-t border-slate-100 text-center divide-x divide-slate-100">
      <div class="p-3"><div class="text-[11px] text-slate-500">Recaudado</div><div class="font-bold"><?= money($d['per']['cob']) ?></div></div>
      <div class="p-3"><div class="text-[11px] text-slate-500">Pagos</div><div class="font-bold"><?= (int) $d['per']['pagos'] ?></div></div>
      <div class="p-3"><div class="text-[11px] text-slate-500">Com. cobro</div><div class="font-bold text-violet-700"><?= money($d['per']['com']) ?></div></div>
      <div class="p-3"><div class="text-[11px] text-slate-500">Visitas logradas</div><div class="font-bold"><?= $d['vis']['visitas_logradas'] ?>/<?= $d['vis']['visitas_total'] ?></div></div>
      <div class="p-3"><div class="text-[11px] text-slate-500">Ventas</div><div class="font-bold"><?= (int) $d['ven']['n'] ?> · <?= money($d['ven']['tot']) ?></div></div>
      <div class="p-3"><div class="text-[11px] text-slate-500">Com. ventas</div><div class="font-bold text-violet-700"><?= money($d['ven']['com']) ?></div></div>
      <div class="p-3 bg-amber-50/50"><div class="text-[11px] text-slate-500">Debe entregar</div><div class="font-bold"><?= money($entregar) ?></div></div>
      <div class="p-3 bg-emerald-50/50"><div class="text-[11px] text-slate-500">A pagarle</div><div class="font-bold"><?= money($totalPagar) ?></div></div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<div class="card mt-6 overflow-hidden">
  <div class="card-h"><i data-lucide="history" class="w-4 h-4 text-brand-600"></i> Historial de entregas y pagos</div>
  <div class="overflow-x-auto"><table class="table">
    <thead><tr><th>Fecha</th><th>Empleado</th><th>Tipo</th><th>Periodo</th><th class="num">Cobrado</th><th class="num">Comisión</th><th class="num">Base</th><th class="num">Monto</th><th>Nota</th></tr></thead>
    <tbody><?php foreach ($hist as $h): ?>
      <tr><td><?= fdate($h['fecha']) ?></td><td><?= e(nombre_completo($h)) ?></td><td><?= $h['tipo'] === 'cobro' ? '<span class="badge badge-green">Entrega de cobros</span>' : '<span class="badge badge-violet">Pago comisiones</span>' ?></td>
        <td class="text-xs"><?= fdate($h['desde']) ?> – <?= fdate($h['hasta']) ?></td><td class="num"><?= money($h['total_cobrado']) ?></td><td class="num"><?= money($h['tipo'] === 'cobro' ? $h['comision_cobro'] : $h['comision_venta']) ?></td><td class="num"><?= money($h['salario_base']) ?></td><td class="num font-semibold"><?= money($h['monto']) ?></td><td class="text-xs"><?= e($h['nota']) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table><?php if (!$hist) echo empty_state('Sin movimientos en este periodo', 'history'); ?></div>
</div>

<div id="tpl-entrega" class="hidden">
  <form method="post" class="p-6 space-y-4"><?= csrf_field() ?><input type="hidden" name="accion" value="entrega"><input type="hidden" name="usuario_id">
    <h3 class="text-lg font-semibold">Recibir entrega del cobrador</h3>
    <input name="info" class="input bg-slate-50 text-sm" readonly>
    <div><label class="label">Cobros hasta</label><input type="date" name="hasta" value="<?= today() ?>" class="input"></div>
    <div><label class="label">Monto recibido</label><input name="monto" type="number" step="0.01" class="input input-lg font-bold" required></div>
    <div><label class="label">Nota</label><input name="nota" class="input"></div>
    <button class="btn btn-success btn-lg w-full">Registrar entrega</button>
  </form>
</div>
<div id="tpl-comision" class="hidden">
  <form method="post" class="p-6 space-y-4"><?= csrf_field() ?><input type="hidden" name="accion" value="comision"><input type="hidden" name="usuario_id">
    <h3 class="text-lg font-semibold">Declarar pago de comisiones</h3>
    <input name="info" class="input bg-slate-50 text-sm" readonly>
    <div><label class="label">Ventas hasta</label><input type="date" name="hasta" value="<?= today() ?>" class="input"></div>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="incluir_base" value="1" class="w-4 h-4" checked> Incluir salario base</label>
    <div><label class="label">Salario base</label><input name="salario_base" type="number" step="0.01" class="input"></div>
    <div><label class="label">Nota</label><input name="nota" class="input"></div>
    <button class="btn btn-primary btn-lg w-full">Declarar pago</button>
  </form>
</div>
<?php layout_footer();
