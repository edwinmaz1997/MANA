<?php
require_perm('clientes');
$eid = eid();
$qstr = get('q', '');
$area = (int) get('area', 0);
$pg = max(1, (int) get('pg', 1));
$per = 25;

$where = 'c.empresa_id = ? AND c.activo = 1';
$params = [$eid];
if ($qstr !== '') {
    $where .= " AND (c.nombre LIKE ? OR c.apellido LIKE ? OR c.codigo LIKE ? OR c.telefono LIKE ? OR c.dpi LIKE ? OR CONCAT(c.nombre,' ',c.apellido) LIKE ?)";
    $like = '%' . $qstr . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($area) { $where .= ' AND c.area_id = ?'; $params[] = $area; }

$total = (int) val("SELECT COUNT(*) FROM clientes c WHERE $where", $params);
$list = rows("SELECT c.*, a.nombre AS area_nombre,
    (SELECT COALESCE(SUM(saldo),0) FROM ventas v WHERE v.cliente_id=c.id AND v.tipo='credito' AND v.estado='activa') AS saldo_total,
    (SELECT COUNT(*) FROM ventas v WHERE v.cliente_id=c.id AND v.estado<>'anulada') AS num_ventas
    FROM clientes c LEFT JOIN areas a ON a.id=c.area_id WHERE $where ORDER BY c.id DESC LIMIT $per OFFSET " . (($pg - 1) * $per), $params);

// Semáforo por cliente (peor estado de sus créditos activos)
$semCli = [];
if ($list) {
    $ids = implode(',', array_map('intval', array_column($list, 'id')));
    $creds = rows("SELECT v.id, v.cliente_id, v.tipo, v.estado, v.saldo, v.enganche_monto, v.fecha_enganche, v.fecha, " . sql_credito_cols() . " FROM ventas v WHERE v.cliente_id IN ($ids) AND v.tipo='credito' AND v.estado='activa'");
    $rank = ['verde' => 1, 'amarillo' => 2, 'rojo' => 3];
    foreach ($creds as $c) {
        $s = semaforo($c);
        $cur = $semCli[$c['cliente_id']] ?? null;
        if (!$cur || ($rank[$s[0]] ?? 0) > ($rank[$cur[0]] ?? 0)) $semCli[$c['cliente_id']] = $s;
    }
}
$areas = rows('SELECT id, nombre FROM areas WHERE empresa_id=? AND activo=1 ORDER BY nombre', [$eid]);

layout_header('Clientes', ['actions' => can('clientes.crear') ? '<a href="' . url('cliente_form') . '" class="btn btn-primary"><i data-lucide="user-plus"></i><span class="hidden sm:inline">Nuevo cliente</span></a>' : '']);
?>
<form class="flex flex-col sm:flex-row gap-2 mb-4">
  <input type="hidden" name="p" value="clientes">
  <div class="relative flex-1"><i data-lucide="search" class="w-4 h-4 absolute left-3 top-3 text-slate-400"></i><input type="search" name="q" value="<?= e($qstr) ?>" placeholder="Buscar por nombre, código, teléfono o DPI" class="input pl-9"></div>
  <select name="area" class="select sm:w-56" onchange="this.form.submit()"><?= select_options($areas, $area, 'id', null, 'Todas las áreas') ?></select>
</form>

<div class="flex flex-wrap gap-3 text-xs text-slate-500 mb-3">
  <span class="flex items-center gap-1.5"><?= semaforo_dot('verde') ?> Al día</span>
  <span class="flex items-center gap-1.5"><?= semaforo_dot('amarillo') ?> Atraso 1-30 días / pendiente de enganche</span>
  <span class="flex items-center gap-1.5"><?= semaforo_dot('rojo') ?> Atraso +30 días / recoger producto</span>
</div>

<div class="card overflow-hidden">
  <!-- Escritorio -->
  <div class="hidden md:block overflow-x-auto">
    <table class="table">
      <thead><tr><th></th><th>Cliente</th><th>Teléfono</th><th>Área</th><th>Estado</th><th class="num">Saldo</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($list as $c): $s = $semCli[$c['id']] ?? null; ?>
        <tr>
          <td class="w-14"><?php if ($c['foto_casa']): ?><img src="<?= e($c['foto_casa']) ?>" class="thumb"><?php else: ?><div class="thumb grid place-items-center text-slate-400"><i data-lucide="home" class="w-4 h-4"></i></div><?php endif; ?></td>
          <td><a href="<?= url('cliente', ['id' => $c['id']]) ?>" class="font-semibold hover:text-brand-600"><?= e(nombre_completo($c)) ?></a><div class="text-xs text-slate-500"><?= e($c['codigo']) ?><?= $c['dpi'] ? ' · DPI ' . e($c['dpi']) : '' ?></div></td>
          <td><?= e($c['telefono']) ?></td>
          <td><?= e($c['area_nombre'] ?: '—') ?></td>
          <td><?= $s ? '<span class="badge ' . $s[2] . '">' . e($s[1]) . '</span>' : '<span class="text-xs text-slate-400">Sin créditos</span>' ?></td>
          <td class="num font-semibold"><?= $c['saldo_total'] > 0 ? money($c['saldo_total']) : '—' ?></td>
          <td class="text-right whitespace-nowrap">
            <?php if ($m = maps_link($c)): ?><a href="<?= e($m) ?>" target="_blank" class="btn btn-ghost btn-icon" title="Mapa"><i data-lucide="map-pin"></i></a><?php endif; ?>
            <a href="<?= url('cliente', ['id' => $c['id']]) ?>" class="btn btn-light btn-sm">Ver</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <!-- Móvil -->
  <div class="md:hidden divide-y divide-slate-100">
    <?php foreach ($list as $c): $s = $semCli[$c['id']] ?? null; ?>
      <a href="<?= url('cliente', ['id' => $c['id']]) ?>" class="flex items-center gap-3 p-4 <?= $s ? 'sem-' . $s[0] : '' ?>">
        <?php if ($c['foto_casa']): ?><img src="<?= e($c['foto_casa']) ?>" class="thumb"><?php else: ?><div class="thumb grid place-items-center text-slate-400"><i data-lucide="home" class="w-4 h-4"></i></div><?php endif; ?>
        <div class="min-w-0 flex-1"><div class="font-semibold truncate"><?= e(nombre_completo($c)) ?></div><div class="text-xs text-slate-500 truncate"><?= e($c['codigo']) ?> · <?= e($c['area_nombre'] ?: 'Sin área') ?></div>
          <?php if ($s): ?><span class="badge <?= $s[2] ?> mt-1"><?= e($s[1]) ?></span><?php endif; ?></div>
        <div class="text-right"><div class="font-semibold text-sm"><?= $c['saldo_total'] > 0 ? money($c['saldo_total']) : '' ?></div></div>
      </a>
    <?php endforeach; ?>
  </div>
  <?php if (!$list) echo empty_state('No se encontraron clientes', 'users'); ?>
  <?= pager($total, $per, $pg) ?>
</div>
<?php layout_footer();
