<?php
require_perm('dashboard');
$u = user();
$eid = eid();
$hoy = today();
$mesIni = date('Y-m-01');

if (is_admin()) {
    $ventasHoy = row("SELECT COUNT(*) n, COALESCE(SUM(total),0) t FROM ventas WHERE empresa_id=? AND fecha=? AND estado<>'anulada'", [$eid, $hoy]);
    $ventasMes = row("SELECT COUNT(*) n, COALESCE(SUM(total),0) t, COALESCE(SUM(total-costo_total),0) u FROM ventas WHERE empresa_id=? AND fecha BETWEEN ? AND ? AND estado<>'anulada'", [$eid, $mesIni, $hoy]);
    $cobradoHoy = row("SELECT COUNT(*) n, COALESCE(SUM(monto),0) t FROM abonos WHERE empresa_id=? AND DATE(fecha)=? AND anulado=0", [$eid, $hoy]);
    $cartera = row("SELECT COUNT(*) n, COALESCE(SUM(saldo),0) t FROM ventas WHERE empresa_id=? AND tipo='credito' AND estado='activa'", [$eid]);
    $creditos = rows("SELECT v.id, v.codigo, v.tipo, v.estado, v.saldo, v.enganche_monto, v.fecha_enganche, v.fecha, v.total, c.nombre, c.apellido, c.telefono, " . sql_credito_cols() . "
        FROM ventas v LEFT JOIN clientes c ON c.id=v.cliente_id WHERE v.empresa_id=? AND v.tipo='credito' AND v.estado='activa'", [$eid]);
    $sem = ['verde' => 0, 'amarillo' => 0, 'rojo' => 0]; $recoger = []; $pendEng = 0;
    foreach ($creditos as $c) {
        $s = semaforo($c);
        if (isset($sem[$s[0]])) $sem[$s[0]]++;
        if ($s[1] === 'Recoger producto') $recoger[] = $c;
        if (!$c['fecha_enganche'] && $c['enganche_monto'] > 0) $pendEng++;
    }
    $stockBajo = rows("SELECT nombre, stock, stock_minimo FROM productos WHERE empresa_id=? AND activo=1 AND stock <= stock_minimo ORDER BY stock LIMIT 6", [$eid]);
    $cobradores = rows("SELECT id, nombre, apellido FROM usuarios WHERE empresa_id=? AND activo=1 AND rol='cobrador' ORDER BY nombre", [$eid]);
    $gastosMes = (float) val("SELECT COALESCE(SUM(monto),0) FROM gastos WHERE empresa_id=? AND fecha BETWEEN ? AND ?", [$eid, $mesIni, $hoy]);
    $ult7 = rows("SELECT DATE(fecha) d, SUM(monto) t FROM abonos WHERE empresa_id=? AND anulado=0 AND fecha >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY DATE(fecha)", [$eid]);
    $serie = []; for ($i = 13; $i >= 0; $i--) $serie[date('Y-m-d', strtotime("-$i day"))] = 0;
    foreach ($ult7 as $r) $serie[$r['d']] = (float) $r['t'];

    layout_header('Inicio', ['charts' => true, 'actions' => '<a href="' . url('ventas_nueva') . '" class="btn btn-primary"><i data-lucide="plus"></i><span class="hidden sm:inline">Nueva venta</span></a>']);
    ?>
    <div class="grid grid-cols-2 xl:grid-cols-4 gap-3 lg:gap-4">
      <?php stat_card('Ventas de hoy', money($ventasHoy['t']), 'shopping-bag', 'brand', $ventasHoy['n'] . ' venta(s)', url('ventas', ['desde' => $hoy, 'hasta' => $hoy])); ?>
      <?php stat_card('Cobrado hoy', money($cobradoHoy['t']), 'hand-coins', 'green', $cobradoHoy['n'] . ' pago(s)', url('cobros', ['r' => 'hoy'])); ?>
      <?php stat_card('Ventas del mes', money($ventasMes['t']), 'trending-up', 'violet', 'Utilidad bruta ' . money($ventasMes['u']), url('reportes', ['t' => 'ventas', 'r' => 'mes'])); ?>
      <?php stat_card('Cartera por cobrar', money($cartera['t']), 'wallet', 'yellow', $cartera['n'] . ' crédito(s) activos', url('ventas', ['tipo' => 'credito', 'estado' => 'activa'])); ?>
    </div>

    <div class="grid lg:grid-cols-3 gap-4 mt-4">
      <div class="card lg:col-span-2">
        <div class="card-h"><i data-lucide="activity" class="w-4 h-4 text-brand-600"></i> Cobros últimos 14 días <a href="<?= url('gastos', ['r' => 'mes']) ?>" class="ml-auto text-sm font-normal text-slate-500 hover:text-brand-600 underline decoration-dotted">Gastos del mes: <?= money($gastosMes) ?></a></div>
        <div class="p-4 h-64"><canvas id="chCobros"></canvas></div>
      </div>
      <div class="card">
        <div class="card-h"><i data-lucide="traffic-cone" class="w-4 h-4 text-brand-600"></i> Estado de la cartera</div>
        <div class="p-5 space-y-3">
          <a href="<?= url('ventas', ['tipo' => 'credito', 'sem' => 'verde']) ?>" class="flex items-center gap-3 p-3 rounded-xl bg-emerald-50 hover:bg-emerald-100"><span class="w-3 h-3 rounded-full bg-emerald-500"></span><span class="font-medium">Al día</span><span class="ml-auto text-xl font-bold"><?= $sem['verde'] ?></span></a>
          <a href="<?= url('ventas', ['tipo' => 'credito', 'sem' => 'amarillo']) ?>" class="flex items-center gap-3 p-3 rounded-xl bg-amber-50 hover:bg-amber-100"><span class="w-3 h-3 rounded-full bg-amber-400"></span><span class="font-medium">Atraso leve (1-30 días)</span><span class="ml-auto text-xl font-bold"><?= $sem['amarillo'] ?></span></a>
          <a href="<?= url('ventas', ['tipo' => 'credito', 'sem' => 'rojo']) ?>" class="flex items-center gap-3 p-3 rounded-xl bg-rose-50 hover:bg-rose-100"><span class="w-3 h-3 rounded-full bg-rose-500"></span><span class="font-medium">Atraso grave / recoger</span><span class="ml-auto text-xl font-bold"><?= $sem['rojo'] ?></span></a>
          <div class="text-sm text-slate-500 pt-1"><?= $pendEng ?> crédito(s) pendientes de enganche</div>
        </div>
      </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4 mt-4">
      <div class="card lg:col-span-2">
        <div class="card-h"><i data-lucide="users" class="w-4 h-4 text-brand-600"></i> Cobradores hoy <a href="<?= url('empleados') ?>" class="ml-auto text-sm text-brand-600 font-medium">Ver todo</a></div>
        <div class="overflow-x-auto">
          <table class="table">
            <thead><tr><th>Cobrador</th><th class="num">Pagos</th><th class="num">Cobrado</th><th class="num">Comisión</th><th class="num">A entregar</th><th class="num">Visitas</th></tr></thead>
            <tbody>
            <?php foreach ($cobradores as $cb): $r = resumen_cobrador($cb['id'], $hoy, $hoy); ?>
              <tr class="cursor-pointer hover:bg-slate-50" onclick="location.href='<?= url('ruta', ['cobrador' => $cb['id']]) ?>'"><td class="font-medium text-brand-700"><?= e(nombre_completo($cb)) ?></td><td class="num"><?= (int) $r['pagos'] ?></td><td class="num"><?= money($r['cobrado']) ?></td><td class="num text-slate-500"><?= money($r['comision']) ?></td><td class="num font-semibold"><?= money($r['entregar']) ?></td><td class="num"><?= $r['visitas_logradas'] ?>/<?= $r['visitas_total'] ?></td></tr>
            <?php endforeach; if (!$cobradores): ?><tr><td colspan="6"><?= empty_state('Aún no hay cobradores registrados', 'user-plus') ?></td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="space-y-4">
        <div class="card">
          <div class="card-h"><i data-lucide="package-x" class="w-4 h-4 text-rose-500"></i> Recoger producto</div>
          <div class="divide-y divide-slate-100">
            <?php foreach (array_slice($recoger, 0, 6) as $c): ?>
              <a href="<?= url('venta', ['id' => $c['id']]) ?>" class="flex items-center gap-3 px-5 py-3 hover:bg-slate-50"><?= semaforo_dot('rojo') ?><div class="min-w-0"><div class="font-medium truncate"><?= e(nombre_completo($c)) ?></div><div class="text-xs text-slate-500"><?= e($c['codigo']) ?> · <?= (int) $c['dias_sin_abono'] ?> días sin abonar</div></div><div class="ml-auto text-sm font-semibold"><?= money($c['saldo']) ?></div></a>
            <?php endforeach; if (!$recoger): ?><div class="px-5 py-6 text-sm text-slate-400 text-center">Ningún crédito para recoger</div><?php endif; ?>
          </div>
        </div>
        <div class="card">
          <div class="card-h"><i data-lucide="package" class="w-4 h-4 text-amber-500"></i> Stock bajo</div>
          <div class="divide-y divide-slate-100">
            <?php foreach ($stockBajo as $p): ?><div class="flex px-5 py-2.5 text-sm"><span class="truncate"><?= e($p['nombre']) ?></span><span class="ml-auto font-semibold <?= $p['stock'] <= 0 ? 'text-rose-600' : 'text-amber-600' ?>"><?= num($p['stock']) ?></span></div><?php endforeach;
            if (!$stockBajo): ?><div class="px-5 py-6 text-sm text-slate-400 text-center">Inventario en orden</div><?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <script>
    var DIAS = <?= json_encode(array_keys($serie)) ?>;
    new Chart(document.getElementById('chCobros'), { type: 'bar', data: { labels: <?= json_encode(array_map(function ($d) { return date('d/m', strtotime($d)); }, array_keys($serie))) ?>, datasets: [{ data: <?= json_encode(array_values($serie)) ?>, backgroundColor: '#6366f1', borderRadius: 6 }] }, options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: '#f1f5f9' } }, x: { grid: { display: false } } },
      onHover: function (e, el) { e.native.target.style.cursor = el.length ? 'pointer' : 'default'; },
      onClick: function (e, el) { if (!el.length) return; var d = DIAS[el[0].index]; location.href = 'index.php?p=cobros&r=rango&desde=' + d + '&hasta=' + d; } } });
    </script>
    <?php
    layout_footer();
    return;
}

/* ---------- Vendedor / Cobrador ---------- */
$res = resumen_cobrador($u['id'], $hoy, $hoy);
$vHoy = row("SELECT COUNT(*) n, COALESCE(SUM(total),0) t, COALESCE(SUM(comision_vendedor),0) c FROM ventas WHERE empresa_id=? AND vendedor_id=? AND fecha=? AND estado<>'anulada'", [$eid, $u['id'], $hoy]);
$vMes = row("SELECT COUNT(*) n, COALESCE(SUM(total),0) t, COALESCE(SUM(comision_vendedor),0) c FROM ventas WHERE empresa_id=? AND vendedor_id=? AND fecha BETWEEN ? AND ? AND estado<>'anulada'", [$eid, $u['id'], $mesIni, $hoy]);
$ultimos = rows("SELECT a.*, v.codigo, c.nombre, c.apellido FROM abonos a JOIN ventas v ON v.id=a.venta_id LEFT JOIN clientes c ON c.id=a.cliente_id WHERE a.usuario_id=? AND a.anulado=0 AND DATE(a.fecha)=? ORDER BY a.fecha DESC LIMIT 15", [$u['id'], $hoy]);

layout_header('Hola, ' . $u['nombre']);
?>
<?php if ($u['rol'] === 'cobrador'): ?>
  <div class="card p-5 lg:p-6 bg-gradient-to-br from-slate-900 to-indigo-900 text-white border-0">
    <a href="<?= url('cobros', ['r' => 'hoy']) ?>" class="text-sm text-indigo-200 underline decoration-dotted">Debes entregar hoy</a>
    <div class="text-4xl font-extrabold tracking-tight mt-1"><?= money($res['entregar']) ?></div>
    <div class="text-sm text-indigo-200 mt-1">Cobrado <?= money($res['cobrado_pend']) ?> − tu comisión <?= money($res['comision_pend']) ?> (<?= num($u['comision_cobro_pct'], 2) ?>%)</div>
    <a href="<?= url('ruta') ?>" class="btn bg-white text-slate-900 mt-5 w-full sm:w-auto btn-lg"><i data-lucide="map"></i> Abrir mi ruta de hoy</a>
  </div>
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mt-4">
    <?php stat_card('Recaudado hoy', money($res['cobrado']), 'hand-coins', 'green', '', url('cobros', ['r' => 'hoy'])); ?>
    <?php stat_card('Pagos realizados', (int) $res['pagos'], 'receipt', 'brand', '', url('cobros', ['r' => 'hoy'])); ?>
    <?php stat_card('Mi comisión hoy', money($res['comision']), 'badge-dollar-sign', 'violet', '', url('cobros', ['r' => 'hoy'])); ?>
    <?php stat_card('Visitas logradas', $res['visitas_logradas'] . ' / ' . $res['visitas_total'], 'map-pin-check', 'yellow', $res['visitas_pago'] . ' con pago', url('ruta')); ?>
  </div>
<?php else: ?>
  <div class="card p-5 lg:p-6 bg-gradient-to-br from-slate-900 to-indigo-900 text-white border-0">
    <div class="text-sm text-indigo-200">Mis comisiones del mes</div>
    <div class="text-4xl font-extrabold tracking-tight mt-1"><?= money($vMes['c']) ?></div>
    <div class="text-sm text-indigo-200 mt-1"><?= (int) $vMes['n'] ?> venta(s) por <?= money($vMes['t']) ?></div>
    <a href="<?= url('ventas_nueva') ?>" class="btn bg-white text-slate-900 mt-5 w-full sm:w-auto btn-lg"><i data-lucide="plus-circle"></i> Nueva venta</a>
  </div>
<?php endif; ?>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mt-3">
  <?php stat_card('Mis ventas hoy', money($vHoy['t']), 'shopping-bag', 'brand', (int) $vHoy['n'] . ' venta(s)', url('ventas', ['desde' => $hoy, 'hasta' => $hoy])); ?>
  <?php stat_card('Comisión ventas hoy', money($vHoy['c']), 'percent', 'slate', '', url('ventas', ['desde' => $hoy, 'hasta' => $hoy])); ?>
  <?php if ($u['rol'] !== 'cobrador'): stat_card('Efectivo recibido hoy', money($res['cobrado']), 'banknote', 'green', 'Enganches y contado', url('ventas', ['desde' => $hoy, 'hasta' => $hoy])); stat_card('Ventas del mes', (int) $vMes['n'], 'calendar', 'yellow', '', url('ventas', ['desde' => $mesIni, 'hasta' => $hoy])); endif; ?>
</div>

<div class="card mt-4">
  <div class="card-h"><i data-lucide="list" class="w-4 h-4 text-brand-600"></i> Mis cobros de hoy</div>
  <div class="divide-y divide-slate-100">
    <?php foreach ($ultimos as $a): ?>
      <a href="<?= url('venta', ['id' => $a['venta_id']]) ?>" class="flex items-center gap-3 px-5 py-3 hover:bg-slate-50">
        <div class="w-9 h-9 rounded-full bg-emerald-50 text-emerald-600 grid place-items-center"><i data-lucide="check" class="w-4 h-4"></i></div>
        <div class="min-w-0"><div class="font-medium truncate"><?= e(nombre_completo($a)) ?></div><div class="text-xs text-slate-500"><?= e($a['codigo']) ?> · <?= date('H:i', strtotime($a['fecha'])) ?> · <?= e($a['tipo']) ?></div></div>
        <div class="ml-auto font-semibold"><?= money($a['monto']) ?></div>
      </a>
    <?php endforeach; if (!$ultimos): ?><?= empty_state('Aún no registras cobros hoy', 'coins') ?><?php endif; ?>
  </div>
</div>
<?php layout_footer();
