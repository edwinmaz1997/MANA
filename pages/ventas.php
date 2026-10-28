<?php
require_perm('ventas');
$eid = eid();
$tipo = get('tipo', '');
$estado = get('estado', $tipo === 'credito' ? 'activa' : '');
$sem = get('sem', '');
$qstr = get('q', '');
$vend = (int) get('vendedor', 0);
$pg = max(1, (int) get('pg', 1));
$per = 30;

$w = 'v.empresa_id = ?'; $params = [$eid];
if (rol() === 'vendedor') { $w .= ' AND v.vendedor_id = ?'; $params[] = uid(); }
elseif ($vend) { $w .= ' AND v.vendedor_id = ?'; $params[] = $vend; }
if (in_array($tipo, ['contado', 'credito'], true)) { $w .= ' AND v.tipo = ?'; $params[] = $tipo; }
if (in_array($estado, ['activa', 'pagada', 'anulada', 'recogida'], true)) { $w .= ' AND v.estado = ?'; $params[] = $estado; }
if ($estado === 'pend_eng') { $w .= " AND v.tipo='credito' AND v.estado='activa' AND v.fecha_enganche IS NULL AND v.enganche_monto > 0"; }
if ($estado === 'recoger') { $w .= " AND v.tipo='credito' AND v.estado='activa' AND v.recoger_producto=1"; }
$fd = get('desde'); $fh = get('hasta');
if ($fd && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fd)) { $w .= ' AND v.fecha >= ?'; $params[] = $fd; } else $fd = null;
if ($fh && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fh)) { $w .= ' AND v.fecha <= ?'; $params[] = $fh; } else $fh = null;
if ($qstr !== '') { $w .= " AND (v.codigo LIKE ? OR CONCAT(c.nombre,' ',c.apellido) LIKE ? OR c.codigo LIKE ?)"; $l = "%$qstr%"; array_push($params, $l, $l, $l); }

$sql = "SELECT v.*, c.nombre, c.apellido, c.codigo cli_codigo, u.nombre vendedor, " . sql_credito_cols() . "
        FROM ventas v LEFT JOIN clientes c ON c.id=v.cliente_id LEFT JOIN usuarios u ON u.id=v.vendedor_id WHERE $w ORDER BY v.id DESC";
if ($sem) {
    $all = array_values(array_filter(rows($sql, $params), function ($r) use ($sem) { return semaforo($r)[0] === $sem && $r['estado'] === 'activa'; }));
    $total = count($all);
    $list = array_slice($all, ($pg - 1) * $per, $per);
    $sumSaldo = array_sum(array_column($all, 'saldo'));
} else {
    $total = (int) val("SELECT COUNT(*) FROM ventas v LEFT JOIN clientes c ON c.id=v.cliente_id WHERE $w", $params);
    $sumSaldo = (float) val("SELECT COALESCE(SUM(v.saldo),0) FROM ventas v LEFT JOIN clientes c ON c.id=v.cliente_id WHERE $w AND v.estado='activa'", $params);
    $list = rows($sql . " LIMIT $per OFFSET " . (($pg - 1) * $per), $params);
}
$vendedores = is_admin() ? rows("SELECT id, nombre, apellido FROM usuarios WHERE empresa_id=? AND rol IN ('vendedor','cobrador','admin') ORDER BY nombre", [$eid]) : [];

layout_header('Ventas y créditos', ['actions' => '<a href="' . url('ventas_nueva') . '" class="btn btn-primary"><i data-lucide="plus"></i><span class="hidden sm:inline">Nueva venta</span></a>']);
$tabs = ['' => 'Todas', 'credito' => 'Créditos', 'contado' => 'Contado'];
?>
<div class="tabs mb-3">
  <?php foreach ($tabs as $k => $l): ?><a href="<?= url('ventas', ['tipo' => $k]) ?>" class="tab <?= $tipo === $k && !$sem ? 'active' : '' ?>"><?= $l ?></a><?php endforeach; ?>
  <a href="<?= url('ventas', ['tipo' => 'credito', 'sem' => 'verde']) ?>" class="tab <?= $sem === 'verde' ? 'active' : '' ?>"><?= semaforo_dot('verde') ?> Al día</a>
  <a href="<?= url('ventas', ['tipo' => 'credito', 'sem' => 'amarillo']) ?>" class="tab <?= $sem === 'amarillo' ? 'active' : '' ?>"><?= semaforo_dot('amarillo') ?> Atraso leve</a>
  <a href="<?= url('ventas', ['tipo' => 'credito', 'sem' => 'rojo']) ?>" class="tab <?= $sem === 'rojo' ? 'active' : '' ?>"><?= semaforo_dot('rojo') ?> Atraso grave</a>
</div>
<form class="flex flex-col sm:flex-row gap-2 mb-4">
  <input type="hidden" name="p" value="ventas"><input type="hidden" name="tipo" value="<?= e($tipo) ?>"><input type="hidden" name="sem" value="<?= e($sem) ?>"><?php if ($fd): ?><input type="hidden" name="desde" value="<?= e($fd) ?>"><?php endif; ?><?php if ($fh): ?><input type="hidden" name="hasta" value="<?= e($fh) ?>"><?php endif; ?>
  <input type="search" name="q" value="<?= e($qstr) ?>" class="input flex-1" placeholder="Buscar por código de venta o cliente">
  <select name="estado" class="select sm:w-52">
    <?php foreach (['' => 'Todos los estados', 'activa' => 'Activas', 'pend_eng' => 'Pendiente de enganche', 'recoger' => 'Marcadas para recoger', 'pagada' => 'Pagadas', 'recogida' => 'Recogidas', 'anulada' => 'Anuladas'] as $k => $l) echo '<option value="' . $k . '"' . ($estado === $k ? ' selected' : '') . '>' . $l . '</option>'; ?>
  </select>
  <?php if ($vendedores): ?><select name="vendedor" class="select sm:w-48"><?= select_options($vendedores, $vend, 'id', 'nombre_completo', 'Todos los vendedores') ?></select><?php endif; ?>
</form>
<?php if ($fd || $fh): $sinF = $_GET; unset($sinF['desde'], $sinF['hasta'], $sinF['pg']); ?>
<div class="mb-3"><span class="inline-flex items-center gap-2 rounded-full bg-brand-50 border border-brand-100 text-brand-800 text-sm px-3 py-1"><i data-lucide="calendar" class="w-4 h-4"></i><?= $fd === $fh ? ($fd === today() ? 'Hoy' : fdate($fd)) : fdate($fd) . ' – ' . fdate($fh) ?><a href="index.php?<?= e(http_build_query($sinF)) ?>" class="ml-1 text-brand-500 hover:text-brand-800" aria-label="Quitar filtro"><i data-lucide="x" class="w-4 h-4"></i></a></span></div>
<?php endif; ?>
<div class="text-sm text-slate-500 mb-2"><?= num($total) ?> resultado(s) · Saldo pendiente: <b class="text-slate-800"><?= money($sumSaldo) ?></b></div>

<div class="card overflow-hidden">
  <div class="hidden md:block overflow-x-auto"><table class="table">
    <thead><tr><th>Venta</th><th>Cliente</th><th>Vendedor</th><th>Estado</th><th class="num">Total</th><th class="num">Saldo</th><th>Próx. pago</th></tr></thead>
    <tbody>
    <?php foreach ($list as $v): $s = semaforo($v); ?>
      <tr class="cursor-pointer" onclick="location.href='<?= url('venta', ['id' => $v['id']]) ?>'">
        <td><div class="font-semibold"><?= e($v['codigo']) ?></div><div class="text-xs text-slate-500"><?= fdate($v['fecha']) ?> · <?= e(tipo_label($v['tipo'])) ?></div></td>
        <td><?= $v['cliente_id'] ? e(nombre_completo($v)) . '<div class="text-xs text-slate-500">' . e($v['cli_codigo']) . '</div>' : '<span class="text-slate-400">Consumidor final</span>' ?></td>
        <td><?= e($v['vendedor']) ?></td>
        <td><span class="badge <?= $s[2] ?>"><?= e($s[1]) ?></span><?= $v['recoger_producto'] && $v['estado'] === 'activa' ? ' <span class="badge badge-red">Recoger</span>' : '' ?></td>
        <td class="num"><?= money($v['total']) ?></td>
        <td class="num font-semibold"><?= $v['tipo'] === 'credito' && $v['estado'] === 'activa' ? money($v['saldo']) : '—' ?></td>
        <td><?= $v['estado'] === 'activa' && $v['prox_venc'] ? fdate($v['prox_venc']) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="md:hidden divide-y divide-slate-100">
    <?php foreach ($list as $v): $s = semaforo($v); ?>
      <a href="<?= url('venta', ['id' => $v['id']]) ?>" class="flex gap-3 p-4 sem-<?= $s[0] ?>">
        <div class="min-w-0 flex-1"><div class="font-semibold truncate"><?= $v['cliente_id'] ? e(nombre_completo($v)) : 'Consumidor final' ?></div><div class="text-xs text-slate-500"><?= e($v['codigo']) ?> · <?= fdate($v['fecha']) ?></div><span class="badge <?= $s[2] ?> mt-1"><?= e($s[1]) ?></span></div>
        <div class="text-right"><div class="font-semibold"><?= money($v['total']) ?></div><?php if ($v['tipo'] === 'credito' && $v['estado'] === 'activa'): ?><div class="text-xs text-slate-500">Saldo <?= money($v['saldo']) ?></div><?php endif; ?></div>
      </a>
    <?php endforeach; ?>
  </div>
  <?php if (!$list) echo empty_state('No hay ventas con estos filtros', 'receipt-text'); ?>
  <?= pager($total, $per, $pg) ?>
</div>
<?php layout_footer();
