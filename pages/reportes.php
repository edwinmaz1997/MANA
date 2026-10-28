<?php
require_perm('admin');
$eid = eid();
$tab = get('t', 'ventas');
$rng = rango_fechas();
$D = $rng['desde']; $H = $rng['hasta'];
$tabs = ['ventas' => ['Ventas', 'trending-up'], 'clientes' => ['Clientes', 'users'], 'productos' => ['Productos', 'package'], 'creditos' => ['Créditos', 'wallet'], 'compras' => ['Compras', 'truck'], 'rutas' => ['Rutas / Áreas', 'map'], 'empleados' => ['Empleados', 'badge-dollar-sign']];
if (!isset($tabs[$tab])) $tab = 'ventas';

layout_header('Reportes', ['charts' => true, 'actions' => '<button onclick="window.print()" class="btn btn-light"><i data-lucide="printer"></i><span class="hidden sm:inline">Imprimir</span></button>']);
?>
<div class="tabs mb-4">
  <?php foreach ($tabs as $k => $t): ?><a href="<?= url('reportes', ['t' => $k, 'r' => $rng['r'], 'desde' => $D, 'hasta' => $H]) ?>" class="tab <?= $tab === $k ? 'active' : '' ?>"><i data-lucide="<?= $t[1] ?>" class="w-4 h-4 inline -mt-0.5"></i> <?= $t[0] ?></a><?php endforeach; ?>
</div>
<?php filtro_fechas($rng, ['t' => $tab]); ?>
<script>
Chart.defaults.font.family = 'Inter, system-ui, sans-serif'; Chart.defaults.color = '#64748b';
function fmtQ(v) { return money(v); }
</script>
<?php
/* ================= VENTAS ================= */
if ($tab === 'ventas'):
    $r = row("SELECT COUNT(*) n, COALESCE(SUM(total),0) total, COALESCE(SUM(total-costo_total),0) util, COALESCE(SUM(comision_vendedor),0) com,
        COALESCE(SUM(CASE WHEN tipo='contado' THEN total END),0) contado, COALESCE(SUM(CASE WHEN tipo='credito' THEN total END),0) credito,
        COALESCE(SUM(descuento),0) desc_, COALESCE(SUM(extra_monto),0) extra
        FROM ventas WHERE empresa_id=? AND estado<>'anulada' AND fecha BETWEEN ? AND ?", [$eid, $D, $H]);
    $gastos = (float) val('SELECT COALESCE(SUM(monto),0) FROM gastos WHERE empresa_id=? AND fecha BETWEEN ? AND ?', [$eid, $D, $H]);
    $comCob = (float) val('SELECT COALESCE(SUM(comision_monto),0) FROM abonos WHERE empresa_id=? AND anulado=0 AND DATE(fecha) BETWEEN ? AND ?', [$eid, $D, $H]);
    $neta = $r['util'] - $r['com'] - $comCob - $gastos;
    $diaria = dias_entre($D, $H) <= 62;
    $serie = rows($diaria ? "SELECT fecha k, SUM(total) t, SUM(total-costo_total) u FROM ventas WHERE empresa_id=? AND estado<>'anulada' AND fecha BETWEEN ? AND ? GROUP BY fecha ORDER BY fecha"
        : "SELECT DATE_FORMAT(fecha,'%Y-%m') k, SUM(total) t, SUM(total-costo_total) u FROM ventas WHERE empresa_id=? AND estado<>'anulada' AND fecha BETWEEN ? AND ? GROUP BY k ORDER BY k", [$eid, $D, $H]);
    $anio = (int) date('Y', strtotime($H));
    $comp = [];
    foreach ([$anio - 1, $anio] as $y) {
        $comp[$y] = array_fill(1, 12, 0);
        foreach (rows("SELECT MONTH(fecha) m, SUM(total) t FROM ventas WHERE empresa_id=? AND estado<>'anulada' AND YEAR(fecha)=? GROUP BY m", [$eid, $y]) as $x) $comp[$y][(int) $x['m']] = (float) $x['t'];
    }
    $porVend = rows("SELECT u.nombre, u.apellido, COUNT(*) n, SUM(v.total) t, SUM(v.comision_vendedor) c FROM ventas v JOIN usuarios u ON u.id=v.vendedor_id WHERE v.empresa_id=? AND v.estado<>'anulada' AND v.fecha BETWEEN ? AND ? GROUP BY u.id, u.nombre, u.apellido ORDER BY t DESC", [$eid, $D, $H]);
    ?>
    <div class="grid grid-cols-2 xl:grid-cols-4 gap-3">
      <?php stat_card('Ventas', money($r['total']), 'shopping-bag', 'brand', (int) $r['n'] . ' venta(s)'); ?>
      <?php stat_card('Utilidad bruta', money($r['util']), 'trending-up', 'green', 'Venta − costo'); ?>
      <?php stat_card('Gastos', money($gastos), 'wallet', 'red'); ?>
      <?php stat_card('Utilidad neta', money($neta), 'piggy-bank', $neta >= 0 ? 'violet' : 'red', 'Restando comisiones y gastos'); ?>
    </div>
    <div class="grid grid-cols-2 xl:grid-cols-4 gap-3 mt-3">
      <?php stat_card('Contado', money($r['contado']), 'banknote', 'slate'); ?>
      <?php stat_card('Crédito', money($r['credito']), 'calendar-clock', 'slate'); ?>
      <?php stat_card('Comisiones vendedores', money($r['com']), 'percent', 'slate'); ?>
      <?php stat_card('Comisiones cobradores', money($comCob), 'percent', 'slate'); ?>
    </div>
    <div class="grid lg:grid-cols-2 gap-4 mt-4">
      <div class="card"><div class="card-h">Ventas y utilidad <?= $diaria ? 'por día' : 'por mes' ?></div><div class="p-4 h-72"><canvas id="c1"></canvas></div></div>
      <div class="card"><div class="card-h">Comparativo <?= $anio - 1 ?> vs <?= $anio ?></div><div class="p-4 h-72"><canvas id="c2"></canvas></div></div>
    </div>
    <div class="card mt-4 overflow-hidden"><div class="card-h">Ventas por vendedor</div><div class="overflow-x-auto"><table class="table">
      <thead><tr><th>Vendedor</th><th class="num">Ventas</th><th class="num">Total</th><th class="num">Comisión</th></tr></thead>
      <tbody><?php foreach ($porVend as $x): ?><tr><td><?= e(nombre_completo($x)) ?></td><td class="num"><?= (int) $x['n'] ?></td><td class="num font-semibold"><?= money($x['t']) ?></td><td class="num"><?= money($x['c']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
    <script>
    new Chart(c1, { type: 'bar', data: { labels: <?= json_encode(array_column($serie, 'k')) ?>, datasets: [{ label: 'Ventas', data: <?= json_encode(array_map('floatval', array_column($serie, 't'))) ?>, backgroundColor: '#6366f1', borderRadius: 5 }, { label: 'Utilidad', data: <?= json_encode(array_map('floatval', array_column($serie, 'u'))) ?>, backgroundColor: '#34d399', borderRadius: 5 }] }, options: { maintainAspectRatio: false, scales: { y: { beginAtZero: true } } } });
    new Chart(c2, { type: 'line', data: { labels: ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'], datasets: [{ label: '<?= $anio - 1 ?>', data: <?= json_encode(array_values($comp[$anio - 1])) ?>, borderColor: '#cbd5e1', backgroundColor: '#cbd5e1', tension: .3 }, { label: '<?= $anio ?>', data: <?= json_encode(array_values($comp[$anio])) ?>, borderColor: '#4f46e5', backgroundColor: '#4f46e5', tension: .3 }] }, options: { maintainAspectRatio: false, scales: { y: { beginAtZero: true } } } });
    </script>

<?php /* ================= CLIENTES ================= */
elseif ($tab === 'clientes'):
    $top = rows("SELECT c.id, c.codigo, c.nombre, c.apellido, a.nombre area, COUNT(v.id) n, SUM(v.total) t,
        (SELECT COALESCE(SUM(ab.monto),0) FROM abonos ab WHERE ab.cliente_id=c.id AND ab.anulado=0 AND DATE(ab.fecha) BETWEEN ? AND ?) pagado,
        (SELECT COALESCE(SUM(v2.saldo),0) FROM ventas v2 WHERE v2.cliente_id=c.id AND v2.estado='activa') saldo
        FROM ventas v JOIN clientes c ON c.id=v.cliente_id LEFT JOIN areas a ON a.id=c.area_id
        WHERE v.empresa_id=? AND v.estado<>'anulada' AND v.fecha BETWEEN ? AND ? GROUP BY c.id, c.codigo, c.nombre, c.apellido, a.nombre ORDER BY t DESC LIMIT 30", [$D, $H, $eid, $D, $H]);
    $nuevos = (int) val('SELECT COUNT(*) FROM clientes WHERE empresa_id=? AND DATE(created_at) BETWEEN ? AND ?', [$eid, $D, $H]);
    $activos = (int) val("SELECT COUNT(DISTINCT cliente_id) FROM ventas WHERE empresa_id=? AND tipo='credito' AND estado='activa'", [$eid]);
    ?>
    <div class="grid grid-cols-2 xl:grid-cols-4 gap-3 mb-4">
      <?php stat_card('Mejor cliente', $top ? e(nombre_completo($top[0])) : '—', 'crown', 'yellow', $top ? money($top[0]['t']) : ''); ?>
      <?php stat_card('Clientes que compraron', count($top) >= 30 ? '30+' : count($top), 'users', 'brand'); ?>
      <?php stat_card('Clientes nuevos', $nuevos, 'user-plus', 'green'); ?>
      <?php stat_card('Con crédito activo', $activos, 'calendar-clock', 'violet'); ?>
    </div>
    <div class="card overflow-hidden"><div class="card-h"><i data-lucide="crown" class="w-4 h-4 text-amber-500"></i> Ranking de clientes (quién más compra)</div><div class="overflow-x-auto"><table class="table">
      <thead><tr><th>#</th><th>Cliente</th><th>Área</th><th class="num">Compras</th><th class="num">Total comprado</th><th class="num">Pagado en periodo</th><th class="num">Saldo actual</th></tr></thead>
      <tbody><?php foreach ($top as $i => $x): ?><tr><td class="font-bold <?= $i < 3 ? 'text-amber-500' : 'text-slate-400' ?>"><?= $i + 1 ?></td><td><a class="font-semibold hover:text-brand-600" href="<?= url('cliente', ['id' => $x['id']]) ?>"><?= e(nombre_completo($x)) ?></a><div class="text-xs text-slate-500"><?= e($x['codigo']) ?></div></td><td><?= e($x['area']) ?></td><td class="num"><?= (int) $x['n'] ?></td><td class="num font-semibold"><?= money($x['t']) ?></td><td class="num"><?= money($x['pagado']) ?></td><td class="num"><?= money($x['saldo']) ?></td></tr><?php endforeach; ?></tbody>
    </table><?php if (!$top) echo empty_state('Sin ventas en el periodo'); ?></div></div>

<?php /* ================= PRODUCTOS ================= */
elseif ($tab === 'productos'):
    $top = rows("SELECT d.producto_id, d.descripcion, SUM(d.cantidad) cant, SUM(d.subtotal) t, SUM(d.subtotal - d.costo_unitario*d.cantidad) u, COUNT(DISTINCT d.venta_id) ventas
        FROM venta_detalle d JOIN ventas v ON v.id=d.venta_id WHERE v.empresa_id=? AND v.estado<>'anulada' AND v.fecha BETWEEN ? AND ? GROUP BY d.producto_id, d.descripcion ORDER BY cant DESC LIMIT 30", [$eid, $D, $H]);
    $porCat = rows("SELECT COALESCE(c.nombre,'Sin categoría') cat, SUM(d.subtotal) t FROM venta_detalle d JOIN ventas v ON v.id=d.venta_id LEFT JOIN productos p ON p.id=d.producto_id LEFT JOIN categorias c ON c.id=p.categoria_id
        WHERE v.empresa_id=? AND v.estado<>'anulada' AND v.fecha BETWEEN ? AND ? GROUP BY cat ORDER BY t DESC", [$eid, $D, $H]);
    ?>
    <div class="grid grid-cols-2 xl:grid-cols-4 gap-3 mb-4">
      <?php stat_card('Producto más vendido', $top ? e($top[0]['descripcion']) : '—', 'trophy', 'yellow', $top ? num($top[0]['cant']) . ' unidades' : ''); ?>
      <?php stat_card('Unidades vendidas', num(array_sum(array_column($top, 'cant'))), 'package', 'brand'); ?>
    </div>
    <div class="grid lg:grid-cols-3 gap-4">
      <div class="card lg:col-span-2 overflow-hidden"><div class="card-h"><i data-lucide="trophy" class="w-4 h-4 text-amber-500"></i> Productos más vendidos</div><div class="overflow-x-auto"><table class="table">
        <thead><tr><th>#</th><th>Producto</th><th class="num">Unidades</th><th class="num">Ventas</th><th class="num">Monto</th><th class="num">Utilidad</th></tr></thead>
        <tbody><?php foreach ($top as $i => $x): ?><tr><td class="font-bold text-slate-400"><?= $i + 1 ?></td><td class="font-medium"><?= e($x['descripcion']) ?></td><td class="num font-semibold"><?= num($x['cant']) ?></td><td class="num"><?= (int) $x['ventas'] ?></td><td class="num"><?= money($x['t']) ?></td><td class="num text-emerald-700"><?= money($x['u']) ?></td></tr><?php endforeach; ?></tbody>
      </table><?php if (!$top) echo empty_state('Sin ventas en el periodo'); ?></div></div>
      <div class="card"><div class="card-h">Por categoría</div><div class="p-4 h-72"><canvas id="c1"></canvas></div></div>
    </div>
    <script>new Chart(c1, { type: 'doughnut', data: { labels: <?= json_encode(array_column($porCat, 'cat')) ?>, datasets: [{ data: <?= json_encode(array_map('floatval', array_column($porCat, 't'))) ?>, backgroundColor: ['#6366f1','#34d399','#f59e0b','#f43f5e','#0ea5e9','#a855f7','#64748b','#14b8a6'] }] }, options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } } });</script>

<?php /* ================= CRÉDITOS ================= */
elseif ($tab === 'creditos'):
    $cobrado = (float) val("SELECT COALESCE(SUM(a.monto),0) FROM abonos a JOIN ventas v ON v.id=a.venta_id WHERE a.empresa_id=? AND a.anulado=0 AND v.tipo='credito' AND DATE(a.fecha) BETWEEN ? AND ?", [$eid, $D, $H]);
    $esperado = (float) val("SELECT COALESCE(SUM(c.monto),0) FROM cuotas c JOIN ventas v ON v.id=c.venta_id WHERE v.empresa_id=? AND v.estado IN ('activa','pagada') AND c.fecha_vencimiento BETWEEN ? AND ?", [$eid, $D, $H]);
    $pend = row("SELECT COUNT(*) n, COALESCE(SUM(saldo),0) s, COALESCE(SUM(total),0) t FROM ventas WHERE empresa_id=? AND tipo='credito' AND estado='activa'", [$eid]);
    $vencido = (float) val("SELECT COALESCE(SUM(c.monto-c.pagado),0) FROM cuotas c JOIN ventas v ON v.id=c.venta_id WHERE v.empresa_id=? AND v.estado='activa' AND c.estado<>'pagada' AND c.fecha_vencimiento < CURDATE()", [$eid]);
    $recogidos = row("SELECT COUNT(*) n, COALESCE(SUM(saldo),0) s FROM ventas WHERE empresa_id=? AND estado='recogida' AND fecha_recogido BETWEEN ? AND ?", [$eid, $D, $H]);
    $anio = (int) date('Y', strtotime($H));
    $esp = array_fill(1, 12, 0); $cob = array_fill(1, 12, 0);
    foreach (rows("SELECT MONTH(c.fecha_vencimiento) m, SUM(c.monto) t FROM cuotas c JOIN ventas v ON v.id=c.venta_id WHERE v.empresa_id=? AND v.estado IN ('activa','pagada') AND YEAR(c.fecha_vencimiento)=? GROUP BY m", [$eid, $anio]) as $x) $esp[(int) $x['m']] = (float) $x['t'];
    foreach (rows("SELECT MONTH(a.fecha) m, SUM(a.monto) t FROM abonos a JOIN ventas v ON v.id=a.venta_id WHERE a.empresa_id=? AND a.anulado=0 AND v.tipo='credito' AND a.tipo='abono' AND YEAR(a.fecha)=? GROUP BY m", [$eid, $anio]) as $x) $cob[(int) $x['m']] = (float) $x['t'];
    $grandes = rows("SELECT v.*, c.nombre, c.apellido, " . sql_credito_cols() . " FROM ventas v JOIN clientes c ON c.id=v.cliente_id WHERE v.empresa_id=? AND v.tipo='credito' AND v.estado='activa' ORDER BY v.saldo DESC LIMIT 15", [$eid]);
    $pct = $esperado > 0 ? round($cobrado / $esperado * 100) : 0;
    ?>
    <div class="grid grid-cols-2 xl:grid-cols-4 gap-3">
      <?php stat_card('Total cobrado', money($cobrado), 'hand-coins', 'green', 'En el periodo'); ?>
      <?php stat_card('Total esperado', money($esperado), 'target', 'brand', 'Cuotas que vencían · ' . $pct . '% cumplido'); ?>
      <?php stat_card('Pendiente de cobro', money($pend['s']), 'wallet', 'yellow', (int) $pend['n'] . ' créditos activos'); ?>
      <?php stat_card('Vencido hoy', money($vencido), 'alert-triangle', 'red', 'Recogidos en periodo: ' . (int) $recogidos['n']); ?>
    </div>
    <div class="card mt-4"><div class="card-h">Año <?= $anio ?> completo: esperado vs cobrado (abonos)</div><div class="p-4 h-72"><canvas id="c1"></canvas></div></div>
    <div class="card mt-4 overflow-hidden"><div class="card-h"><i data-lucide="gem" class="w-4 h-4 text-brand-600"></i> Créditos grandes (mayor saldo)</div><div class="overflow-x-auto"><table class="table">
      <thead><tr><th>Crédito</th><th>Cliente</th><th>Estado</th><th class="num">Total</th><th class="num">Pagado</th><th class="num">Saldo</th></tr></thead>
      <tbody><?php foreach ($grandes as $g): $s = semaforo($g); ?><tr><td><a class="font-semibold text-brand-600" href="<?= url('venta', ['id' => $g['id']]) ?>"><?= e($g['codigo']) ?></a></td><td><?= e(nombre_completo($g)) ?></td><td><span class="badge <?= $s[2] ?>"><?= e($s[1]) ?></span></td><td class="num"><?= money($g['total']) ?></td><td class="num"><?= money($g['pagado']) ?></td><td class="num font-bold"><?= money($g['saldo']) ?></td></tr><?php endforeach; ?></tbody>
    </table></div></div>
    <script>new Chart(c1, { type: 'bar', data: { labels: ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'], datasets: [{ label: 'Esperado', data: <?= json_encode(array_values($esp)) ?>, backgroundColor: '#c7d2fe', borderRadius: 5 }, { label: 'Cobrado', data: <?= json_encode(array_values($cob)) ?>, backgroundColor: '#10b981', borderRadius: 5 }] }, options: { maintainAspectRatio: false, scales: { y: { beginAtZero: true } } } });</script>

<?php /* ================= COMPRAS ================= */
elseif ($tab === 'compras'):
    $tot = row('SELECT COUNT(*) n, COALESCE(SUM(total),0) t FROM compras WHERE empresa_id=? AND fecha BETWEEN ? AND ?', [$eid, $D, $H]);
    $gastos = (float) val('SELECT COALESCE(SUM(monto),0) FROM gastos WHERE empresa_id=? AND fecha BETWEEN ? AND ?', [$eid, $D, $H]);
    $prov = rows("SELECT p.nombre, p.apellido, p.nit, COUNT(c.id) n, SUM(c.total) t FROM compras c JOIN proveedores p ON p.id=c.proveedor_id WHERE c.empresa_id=? AND c.fecha BETWEEN ? AND ? GROUP BY p.id, p.nombre, p.apellido, p.nit ORDER BY t DESC", [$eid, $D, $H]);
    $prods = rows("SELECT p.nombre, SUM(d.cantidad) cant, SUM(d.subtotal) t FROM compra_detalle d JOIN compras c ON c.id=d.compra_id JOIN productos p ON p.id=d.producto_id WHERE c.empresa_id=? AND c.fecha BETWEEN ? AND ? GROUP BY p.id, p.nombre ORDER BY t DESC LIMIT 15", [$eid, $D, $H]);
    ?>
    <div class="grid grid-cols-2 xl:grid-cols-4 gap-3 mb-4">
      <?php stat_card('Total gastado en compras', money($tot['t']), 'truck', 'brand', (int) $tot['n'] . ' compra(s)'); ?>
      <?php stat_card('Proveedor más comprado', $prov ? e(nombre_completo($prov[0])) : '—', 'factory', 'yellow', $prov ? money($prov[0]['t']) : ''); ?>
      <?php stat_card('Gastos operativos', money($gastos), 'wallet', 'red'); ?>
      <?php stat_card('Total egresos', money($tot['t'] + $gastos), 'arrow-down-circle', 'slate'); ?>
    </div>
    <div class="grid lg:grid-cols-2 gap-4">
      <div class="card overflow-hidden"><div class="card-h">Proveedores</div><table class="table"><thead><tr><th>Proveedor</th><th class="num">Compras</th><th class="num">Total</th></tr></thead>
        <tbody><?php foreach ($prov as $x): ?><tr><td><?= e(nombre_completo($x)) ?><div class="text-xs text-slate-500"><?= e($x['nit']) ?></div></td><td class="num"><?= (int) $x['n'] ?></td><td class="num font-semibold"><?= money($x['t']) ?></td></tr><?php endforeach; ?></tbody></table><?php if (!$prov) echo empty_state('Sin compras'); ?></div>
      <div class="card overflow-hidden"><div class="card-h">Productos más comprados</div><table class="table"><thead><tr><th>Producto</th><th class="num">Cantidad</th><th class="num">Total</th></tr></thead>
        <tbody><?php foreach ($prods as $x): ?><tr><td><?= e($x['nombre']) ?></td><td class="num"><?= num($x['cant']) ?></td><td class="num font-semibold"><?= money($x['t']) ?></td></tr><?php endforeach; ?></tbody></table></div>
    </div>

<?php /* ================= RUTAS / ÁREAS ================= */
elseif ($tab === 'rutas'):
    $areas = rows("SELECT a.id, a.nombre, u.nombre cob,
        (SELECT COALESCE(SUM(ab.monto),0) FROM abonos ab JOIN clientes c ON c.id=ab.cliente_id WHERE c.area_id=a.id AND ab.anulado=0 AND DATE(ab.fecha) BETWEEN ? AND ?) cobrado,
        (SELECT COALESCE(SUM(v.total),0) FROM ventas v JOIN clientes c ON c.id=v.cliente_id WHERE c.area_id=a.id AND v.estado<>'anulada' AND v.fecha BETWEEN ? AND ?) vendido,
        (SELECT COALESCE(SUM(v.saldo),0) FROM ventas v JOIN clientes c ON c.id=v.cliente_id WHERE c.area_id=a.id AND v.estado='activa' AND v.tipo='credito') cartera,
        (SELECT COUNT(*) FROM clientes c WHERE c.area_id=a.id AND c.activo=1) clientes
        FROM areas a LEFT JOIN usuarios u ON u.id=a.cobrador_id WHERE a.empresa_id=? AND a.activo=1 ORDER BY cobrado DESC", [$D, $H, $D, $H, $eid]);
    $vis = row("SELECT COUNT(*) t, SUM(rv.estado IN ('pago','no_pago','no_encontrado')) hechas, SUM(rv.estado='pago') pago, SUM(rv.estado='reprogramada') atr FROM ruta_visitas rv JOIN rutas r ON r.id=rv.ruta_id WHERE r.empresa_id=? AND r.fecha BETWEEN ? AND ?", [$eid, $D, $H]);
    ?>
    <div class="grid grid-cols-2 xl:grid-cols-4 gap-3 mb-4">
      <?php stat_card('Área que más genera', $areas && $areas[0]['cobrado'] > 0 ? e($areas[0]['nombre']) : '—', 'trophy', 'yellow', $areas ? money($areas[0]['cobrado']) . ' cobrado' : ''); ?>
      <?php stat_card('Visitas realizadas', (int) $vis['hechas'] . ' / ' . (int) $vis['t'], 'map-pin-check', 'brand'); ?>
      <?php stat_card('Visitas con pago', (int) $vis['pago'], 'hand-coins', 'green', $vis['hechas'] ? round($vis['pago'] / $vis['hechas'] * 100) . '% efectividad' : ''); ?>
      <?php stat_card('Visitas atrasadas', (int) $vis['atr'], 'clock-alert', 'red', 'Reprogramadas al día siguiente'); ?>
    </div>
    <div class="grid lg:grid-cols-3 gap-4">
      <div class="card lg:col-span-2 overflow-hidden"><div class="card-h">Dinero generado por área</div><div class="overflow-x-auto"><table class="table">
        <thead><tr><th>Área</th><th>Cobrador</th><th class="num">Clientes</th><th class="num">Cobrado</th><th class="num">Vendido</th><th class="num">Cartera</th></tr></thead>
        <tbody><?php foreach ($areas as $a): ?><tr><td class="font-semibold"><?= e($a['nombre']) ?></td><td><?= e($a['cob'] ?: '—') ?></td><td class="num"><?= (int) $a['clientes'] ?></td><td class="num font-bold text-emerald-700"><?= money($a['cobrado']) ?></td><td class="num"><?= money($a['vendido']) ?></td><td class="num"><?= money($a['cartera']) ?></td></tr><?php endforeach; ?></tbody>
      </table></div></div>
      <div class="card"><div class="card-h">Cobrado por área</div><div class="p-4 h-72"><canvas id="c1"></canvas></div></div>
    </div>
    <script>new Chart(c1, { type: 'bar', data: { labels: <?= json_encode(array_column($areas, 'nombre')) ?>, datasets: [{ data: <?= json_encode(array_map('floatval', array_column($areas, 'cobrado'))) ?>, backgroundColor: '#10b981', borderRadius: 5 }] }, options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false } } } });</script>

<?php /* ================= EMPLEADOS ================= */
else:
    $emps = rows("SELECT * FROM usuarios WHERE empresa_id=? AND activo=1 ORDER BY FIELD(rol,'cobrador','vendedor','admin'), nombre", [$eid]);
    $tot = ['cob' => 0, 'comc' => 0, 'comv' => 0, 'base' => 0];
    ?>
    <div class="card overflow-hidden"><div class="card-h">Cuánto hay que pagarle a cada empleado (<?= fdate($D) ?> – <?= fdate($H) ?>)</div><div class="overflow-x-auto"><table class="table">
      <thead><tr><th>Empleado</th><th>Rol</th><th class="num">Recaudado</th><th class="num">Com. cobro (retenida)</th><th class="num">Debe entregar</th><th class="num">Ventas</th><th class="num">Com. ventas</th><th class="num">Salario base</th><th class="num">Total a pagar</th><th class="num">Ingreso total</th></tr></thead>
      <tbody>
      <?php foreach ($emps as $e):
        $c = row("SELECT COALESCE(SUM(monto),0) cob, COALESCE(SUM(comision_monto),0) com FROM abonos WHERE empresa_id=? AND usuario_id=? AND anulado=0 AND DATE(fecha) BETWEEN ? AND ?", [$eid, $e['id'], $D, $H]);
        $v = row("SELECT COALESCE(SUM(total),0) t, COALESCE(SUM(comision_vendedor),0) com FROM ventas WHERE empresa_id=? AND vendedor_id=? AND estado<>'anulada' AND fecha BETWEEN ? AND ?", [$eid, $e['id'], $D, $H]);
        $pagar = $v['com'] + $e['salario_base'];
        $tot['cob'] += $c['cob']; $tot['comc'] += $c['com']; $tot['comv'] += $v['com']; $tot['base'] += $e['salario_base']; ?>
        <tr><td class="font-semibold"><?= e(nombre_completo($e)) ?></td><td><?= e(ucfirst($e['rol'])) ?></td><td class="num"><?= money($c['cob']) ?></td><td class="num text-violet-700"><?= money($c['com']) ?></td><td class="num"><?= money($c['cob'] - $c['com']) ?></td><td class="num"><?= money($v['t']) ?></td><td class="num text-violet-700"><?= money($v['com']) ?></td><td class="num"><?= money($e['salario_base']) ?></td><td class="num font-bold"><?= money($pagar) ?></td><td class="num font-bold text-emerald-700"><?= money($pagar + $c['com']) ?></td></tr>
      <?php endforeach; ?>
      <tr class="bg-slate-50 font-bold"><td colspan="2">Totales</td><td class="num"><?= money($tot['cob']) ?></td><td class="num"><?= money($tot['comc']) ?></td><td class="num"><?= money($tot['cob'] - $tot['comc']) ?></td><td></td><td class="num"><?= money($tot['comv']) ?></td><td class="num"><?= money($tot['base']) ?></td><td class="num"><?= money($tot['comv'] + $tot['base']) ?></td><td class="num"><?= money($tot['comv'] + $tot['base'] + $tot['comc']) ?></td></tr>
      </tbody>
    </table></div></div>
    <p class="text-xs text-slate-500 mt-2">La comisión de cobro ya la retiene el cobrador al entregar el efectivo; por eso "Total a pagar" solo incluye comisiones de venta + salario base. El salario base se muestra completo por periodo.</p>
<?php endif; ?>
<?php layout_footer();
