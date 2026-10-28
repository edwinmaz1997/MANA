<?php
/* Notificaciones (campanita): alertas calculadas según el rol + mensajes del chat */

function notificaciones_usuario()
{
    $u = user();
    if (!$u) return [];
    $eid = (int) $u['empresa_id'];
    $uidv = (int) $u['id'];
    $rol = $u['rol'];
    $hoy = today();
    $n = [];
    $add = function ($clave, $icono, $color, $titulo, $texto, $url, $fecha = null) use (&$n) {
        $n[] = ['clave' => $clave, 'icono' => $icono, 'color' => $color, 'titulo' => $titulo, 'texto' => $texto, 'url' => $url, 'fecha' => $fecha];
    };

    // Filtro de créditos visibles según el rol
    $filtroV = '';
    $pv = [$eid];
    if ($rol === 'vendedor') { $filtroV = ' AND v.vendedor_id = ?'; $pv[] = $uidv; }
    if ($rol === 'cobrador') { $filtroV = ' AND (v.vendedor_id = ? OR c.area_id IN (SELECT id FROM areas WHERE cobrador_id = ?))'; $pv[] = $uidv; $pv[] = $uidv; }

    /* --- Créditos para recoger --- */
    $diasRec = (int) (empresa()['dias_recoger'] ?? 90);
    $rec = rows("SELECT v.id FROM ventas v LEFT JOIN clientes c ON c.id=v.cliente_id WHERE v.empresa_id=? AND v.tipo='credito' AND v.estado='activa' AND v.saldo>0
        AND DATEDIFF(CURDATE(), COALESCE(v.ultimo_abono, v.fecha_enganche, v.fecha)) >= $diasRec $filtroV", $pv);
    if ($rec) $add('recoger:' . md5(implode(',', array_column($rec, 'id'))), 'package-x', 'red', count($rec) . ' crédito(s) para recoger producto', 'Llevan ' . $diasRec . ' días o más sin abonar.', url('ventas', ['tipo' => 'credito', 'sem' => 'rojo']));

    /* --- Enganches pendientes con más de 7 días --- */
    $eng = rows("SELECT v.id FROM ventas v LEFT JOIN clientes c ON c.id=v.cliente_id WHERE v.empresa_id=? AND v.tipo='credito' AND v.estado='activa' AND v.fecha_enganche IS NULL AND v.enganche_monto>0
        AND v.fecha <= DATE_SUB(CURDATE(), INTERVAL 7 DAY) $filtroV", $pv);
    if ($eng) $add('enganche:' . md5(implode(',', array_column($eng, 'id'))), 'clock', 'yellow', count($eng) . ' enganche(s) pendientes', 'Ventas al crédito con más de 7 días sin enganche.', url('ventas', ['tipo' => 'credito', 'estado' => 'pend_eng']));

    if ($rol === 'admin') {
        /* --- Visitas atrasadas hoy --- */
        $atr = (int) val("SELECT COUNT(*) FROM ruta_visitas rv JOIN rutas r ON r.id=rv.ruta_id WHERE r.empresa_id=? AND r.fecha=? AND rv.atrasada=1 AND rv.estado='pendiente'", [$eid, $hoy]);
        if ($atr) $add("atrasadas:$hoy:$atr", 'clock-alert', 'red', "$atr visita(s) atrasada(s) en las rutas de hoy", 'Visitas que no se hicieron en días anteriores.', url('rutas'));

        /* --- Inventario --- */
        $agot = (int) val('SELECT COUNT(*) FROM productos WHERE empresa_id=? AND activo=1 AND stock<=0', [$eid]);
        $bajo = (int) val('SELECT COUNT(*) FROM productos WHERE empresa_id=? AND activo=1 AND stock>0 AND stock<=stock_minimo', [$eid]);
        if ($agot || $bajo) $add("stock:$agot:$bajo", 'package', $agot ? 'red' : 'yellow', ($agot ? "$agot producto(s) agotado(s)" : '') . ($agot && $bajo ? ' y ' : '') . ($bajo ? "$bajo con stock bajo" : ''), 'Revisa el inventario y registra compras.', url('productos'));

        /* --- Efectivo pendiente de entregar (días anteriores) --- */
        foreach (rows("SELECT a.usuario_id, u.nombre, SUM(a.monto - a.comision_monto) neto, MIN(DATE(a.fecha)) desde FROM abonos a JOIN usuarios u ON u.id=a.usuario_id
            WHERE a.empresa_id=? AND a.anulado=0 AND a.liquidacion_id IS NULL AND DATE(a.fecha) < ? AND u.rol<>'admin' GROUP BY a.usuario_id, u.nombre HAVING neto > 0", [$eid, $hoy]) as $r) {
            $add('entrega:' . $r['usuario_id'] . ':' . $r['neto'], 'banknote', 'yellow', $r['nombre'] . ' tiene ' . money($r['neto']) . ' por entregar', 'Cobros desde el ' . fdate($r['desde']) . ' sin liquidar.', url('empleados'));
        }

        /* --- Ventas nuevas de otros usuarios (últimos 2 días) --- */
        foreach (rows("SELECT v.id, v.codigo, v.tipo, v.total, v.created_at, u.nombre, c.nombre cn, c.apellido ca FROM ventas v JOIN usuarios u ON u.id=v.vendedor_id LEFT JOIN clientes c ON c.id=v.cliente_id
            WHERE v.empresa_id=? AND v.vendedor_id<>? AND v.estado<>'anulada' AND v.created_at >= DATE_SUB(NOW(), INTERVAL 2 DAY) ORDER BY v.id DESC LIMIT 10", [$eid, $uidv]) as $r) {
            $add('venta:' . $r['id'], 'shopping-bag', 'brand', 'Nueva venta ' . $r['codigo'] . ' de ' . $r['nombre'], tipo_label($r['tipo']) . ' · ' . money($r['total']) . ($r['cn'] ? ' · ' . trim($r['cn'] . ' ' . $r['ca']) : ''), url('venta', ['id' => $r['id']]), $r['created_at']);
        }

        /* --- Membresía --- */
        $mem = membresia_estado();
        if ($mem['ok'] && $mem['dias'] <= 7) $add('membresia:' . empresa()['fecha_vencimiento'], 'badge-alert', 'red', 'Tu membresía vence en ' . $mem['dias'] . ' día(s)', 'Renueva para no perder el acceso.', url('empresa'));
    }

    if ($rol === 'cobrador') {
        /* --- Ruta de hoy --- */
        $r = row("SELECT COUNT(*) t, SUM(rv.estado='pendiente') pend, SUM(rv.estado='pendiente' AND rv.atrasada=1) atr FROM ruta_visitas rv JOIN rutas r ON r.id=rv.ruta_id
            WHERE r.empresa_id=? AND r.cobrador_id=? AND r.fecha=? AND rv.estado<>'reprogramada'", [$eid, $uidv, $hoy]);
        if ($r && (int) $r['pend'] > 0) $add("ruta:$hoy", 'map', 'brand', (int) $r['pend'] . ' visita(s) pendientes en tu ruta de hoy', ((int) $r['atr'] ? (int) $r['atr'] . ' atrasada(s). ' : '') . 'Toca para abrir tu ruta.', url('ruta'));
        $ag = (int) val("SELECT COUNT(*) FROM ventas v JOIN clientes c ON c.id=v.cliente_id JOIN areas a ON a.id=c.area_id WHERE v.empresa_id=? AND a.cobrador_id=? AND v.estado='activa' AND v.proxima_visita=?", [$eid, $uidv, $hoy]);
        if ($ag) $add("agendadas:$hoy", 'calendar-check', 'violet', "$ag visita(s) agendada(s) para hoy", 'Clientes con los que quedaste en regresar hoy.', url('ruta'));
        $ent = row("SELECT COALESCE(SUM(monto - comision_monto),0) neto FROM abonos WHERE empresa_id=? AND usuario_id=? AND anulado=0 AND liquidacion_id IS NULL AND DATE(fecha) < ?", [$eid, $uidv, $hoy]);
        if ((float) $ent['neto'] > 0) $add('entrega:' . $uidv . ':' . $ent['neto'], 'banknote', 'yellow', 'Tienes ' . money($ent['neto']) . ' pendiente de entregar', 'Cobros de días anteriores sin liquidar.', url('cobros', ['r' => 'mes']));
    }

    /* --- Mensajes del chat de otras personas (últimos 7 días) --- */
    $msgs = rows("SELECT m.id, m.mensaje, m.created_at, m.venta_id, v.codigo, u.nombre FROM venta_mensajes m JOIN ventas v ON v.id=m.venta_id LEFT JOIN clientes c ON c.id=v.cliente_id
        LEFT JOIN usuarios u ON u.id=m.usuario_id WHERE v.empresa_id=? AND m.sistema=0 AND (m.usuario_id IS NULL OR m.usuario_id<>?) AND m.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) $filtroV
        ORDER BY m.id DESC LIMIT 15", array_merge([$eid, $uidv], array_slice($pv, 1)));
    foreach ($msgs as $m) {
        $add('msg:' . $m['id'], 'message-circle', 'blue', ($m['nombre'] ?: 'Alguien') . ' escribió en ' . $m['codigo'], mb_strimwidth($m['mensaje'], 0, 90, '…'), url('venta', ['id' => $m['venta_id']]), $m['created_at']);
    }

    // Marcar leídas
    $vistas = [];
    if ($n) {
        $claves = array_column($n, 'clave');
        $ph = implode(',', array_fill(0, count($claves), '?'));
        foreach (rows("SELECT clave FROM notif_vistas WHERE usuario_id=? AND clave IN ($ph)", array_merge([$uidv], $claves)) as $r) $vistas[$r['clave']] = 1;
    }
    foreach ($n as &$x) $x['nueva'] = !isset($vistas[$x['clave']]);
    unset($x);
    usort($n, function ($a, $b) { if ($a['nueva'] !== $b['nueva']) return $a['nueva'] ? -1 : 1; return strcmp($b['fecha'] ?: '9999', $a['fecha'] ?: '9999'); });
    return $n;
}

function notificaciones_marcar(array $claves)
{
    $uidv = uid();
    foreach (array_slice($claves, 0, 100) as $c) {
        $c = mb_substr((string) $c, 0, 120);
        q('INSERT IGNORE INTO notif_vistas (usuario_id, clave) VALUES (?, ?)', [$uidv, $c]);
    }
    if (mt_rand(1, 50) === 1) q('DELETE FROM notif_vistas WHERE visto_at < DATE_SUB(NOW(), INTERVAL 60 DAY)');
}
