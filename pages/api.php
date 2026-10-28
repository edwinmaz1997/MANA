<?php
/* Endpoints AJAX */
$a = get('a', '');
$eid = eid();
if (!$eid) json_out(['ok' => false, 'error' => 'Sesión inválida'], 401);

switch ($a) {
    case 'clientes':
        require_perm('clientes');
        $t = '%' . get('q', '') . '%';
        $list = rows("SELECT c.id, c.codigo, c.nombre, c.apellido, c.telefono, c.direccion, c.foto_casa, a.nombre area,
            (SELECT COALESCE(SUM(saldo),0) FROM ventas v WHERE v.cliente_id=c.id AND v.tipo='credito' AND v.estado='activa') saldo
            FROM clientes c LEFT JOIN areas a ON a.id=c.area_id
            WHERE c.empresa_id=? AND c.activo=1 AND (CONCAT(c.nombre,' ',c.apellido) LIKE ? OR c.codigo LIKE ? OR c.telefono LIKE ? OR c.dpi LIKE ?)
            ORDER BY c.nombre LIMIT 12", [$eid, $t, $t, $t, $t]);
        json_out(['ok' => true, 'data' => $list]);

    case 'productos':
        require_perm('productos.ver');
        $t = '%' . get('q', '') . '%';
        $list = rows("SELECT id, codigo, nombre, unidad, precio_venta, precio_contado, precio_credito, stock, foto" . (is_admin() ? ', precio_compra' : '') . " FROM productos
            WHERE empresa_id=? AND activo=1 AND (nombre LIKE ? OR codigo LIKE ?) ORDER BY nombre LIMIT 15", [$eid, $t, $t]);
        json_out(['ok' => true, 'data' => $list]);

    case 'creditos':
        // créditos activos para registrar cobro
        require_perm('cobros');
        $t = '%' . get('q', '') . '%';
        $list = rows("SELECT v.id, v.codigo, v.saldo, v.cuota_monto, v.total, v.tipo, v.estado, v.enganche_monto, v.fecha_enganche, v.fecha, c.nombre, c.apellido, c.codigo cli_codigo, " . sql_credito_cols() . "
            FROM ventas v JOIN clientes c ON c.id=v.cliente_id
            WHERE v.empresa_id=? AND v.tipo='credito' AND v.estado='activa' AND (CONCAT(c.nombre,' ',c.apellido) LIKE ? OR c.codigo LIKE ? OR v.codigo LIKE ? OR c.telefono LIKE ?)
            ORDER BY c.nombre LIMIT 15", [$eid, $t, $t, $t, $t]);
        foreach ($list as &$r) { $s = semaforo($r); $r['sem'] = $s; }
        json_out(['ok' => true, 'data' => $list]);

    case 'chat':
        require_perm('chat');
        $vid = (int) get('venta');
        if (!val('SELECT id FROM ventas WHERE id=? AND empresa_id=?', [$vid, $eid])) json_out(['ok' => false, 'error' => 'Venta no encontrada'], 404);
        if (is_post()) {
            csrf_check();
            $m = trim((string) post('mensaje', ''));
            if ($m === '') json_out(['ok' => false, 'error' => 'Mensaje vacío']);
            insert('venta_mensajes', ['venta_id' => $vid, 'usuario_id' => uid(), 'mensaje' => mb_substr($m, 0, 2000)]);
        }
        $after = (int) get('after', 0);
        $msgs = rows('SELECT m.id, m.mensaje, m.sistema, m.created_at, m.usuario_id, u.nombre, u.rol FROM venta_mensajes m LEFT JOIN usuarios u ON u.id=m.usuario_id WHERE m.venta_id=? AND m.id>? ORDER BY m.id', [$vid, $after]);
        foreach ($msgs as &$m) { $m['me'] = (int) $m['usuario_id'] === uid(); $m['hora'] = fdate($m['created_at'], true); }
        json_out(['ok' => true, 'data' => $msgs]);

    default:
        json_out(['ok' => false, 'error' => 'Acción desconocida'], 400);
}
