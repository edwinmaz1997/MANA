<?php
/* Exportación de reportes a CSV (compatible con Excel) */

function csv_salida($nombre, array $encabezados, array $filas)
{
    // Formato Excel (.xlsx): sin problemas de acentos ni de separador
    if (!empty($GLOBALS['EXPORT_XLSX'])) {
        require_once __DIR__ . '/importar.php';
        $datos = [$encabezados];
        foreach ($filas as $f) $datos[] = array_values($f);
        xlsx_descargar($nombre, $datos, array_fill(0, count($encabezados), 18));
    }
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nombre . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM para que Excel lea acentos
    fputcsv($out, $encabezados);
    foreach ($filas as $f) fputcsv($out, array_values($f));
    fclose($out);
    exit;
}

function reporte_csv($tipo, $D, $H)
{
    $eid = eid();
    $sufijo = $D . '_a_' . $H;
    switch ($tipo) {
        case 'ventas':
            $rows = rows("SELECT v.codigo, v.fecha, v.tipo, TRIM(CONCAT(COALESCE(c.nombre,'Consumidor final'),' ',COALESCE(c.apellido,''))) cliente, c.codigo cli_codigo,
                    TRIM(CONCAT(u.nombre,' ',u.apellido)) vendedor, v.estado, v.subtotal, v.descuento, v.extra_concepto, v.extra_monto, v.total, v.costo_total,
                    v.total - v.costo_total utilidad, v.comision_vendedor, v.plazo_meses, v.enganche_monto, v.pagado, v.saldo
                FROM ventas v LEFT JOIN clientes c ON c.id=v.cliente_id LEFT JOIN usuarios u ON u.id=v.vendedor_id
                WHERE v.empresa_id=? AND v.fecha BETWEEN ? AND ? ORDER BY v.fecha, v.id", [$eid, $D, $H]);
            csv_salida('ventas_' . $sufijo, ['Código', 'Fecha', 'Tipo', 'Cliente', 'Código cliente', 'Vendedor', 'Estado', 'Subtotal', 'Descuento', 'Extra (concepto)', 'Extra (monto)', 'Total', 'Costo', 'Utilidad', 'Comisión vendedor', 'Plazo (meses)', 'Enganche', 'Pagado', 'Saldo'], $rows);

        case 'clientes':
            $rows = rows("SELECT c.codigo, TRIM(CONCAT(c.nombre,' ',c.apellido)) cliente, c.telefono, c.dpi, a.nombre area, COUNT(v.id) n, SUM(v.total) t,
                    (SELECT COALESCE(SUM(ab.monto),0) FROM abonos ab WHERE ab.cliente_id=c.id AND ab.anulado=0 AND DATE(ab.fecha) BETWEEN ? AND ?) pagado,
                    (SELECT COALESCE(SUM(v2.saldo),0) FROM ventas v2 WHERE v2.cliente_id=c.id AND v2.estado='activa') saldo
                FROM ventas v JOIN clientes c ON c.id=v.cliente_id LEFT JOIN areas a ON a.id=c.area_id
                WHERE v.empresa_id=? AND v.estado<>'anulada' AND v.fecha BETWEEN ? AND ?
                GROUP BY c.id, c.codigo, c.nombre, c.apellido, c.telefono, c.dpi, a.nombre ORDER BY t DESC", [$D, $H, $eid, $D, $H]);
            csv_salida('clientes_' . $sufijo, ['Código', 'Cliente', 'Teléfono', 'DPI', 'Área', 'Compras', 'Total comprado', 'Pagado en periodo', 'Saldo actual'], $rows);

        case 'productos':
            $rows = rows("SELECT p.codigo, d.descripcion, SUM(d.cantidad) cant, COUNT(DISTINCT d.venta_id) ventas, SUM(d.subtotal) t, SUM(d.subtotal - d.costo_unitario*d.cantidad) u, p.stock
                FROM venta_detalle d JOIN ventas v ON v.id=d.venta_id LEFT JOIN productos p ON p.id=d.producto_id
                WHERE v.empresa_id=? AND v.estado<>'anulada' AND v.fecha BETWEEN ? AND ?
                GROUP BY d.producto_id, p.codigo, d.descripcion, p.stock ORDER BY cant DESC", [$eid, $D, $H]);
            csv_salida('productos_vendidos_' . $sufijo, ['Código', 'Producto', 'Unidades vendidas', 'Ventas', 'Monto', 'Utilidad', 'Stock actual'], $rows);

        case 'inventario':
            $rows = rows("SELECT p.codigo, p.nombre, c.nombre cat, p.unidad, p.precio_compra, p.descuento_comision, p.precio_venta, p.precio_contado, p.precio_credito, p.stock, p.stock_minimo, p.stock*p.precio_compra valor
                FROM productos p LEFT JOIN categorias c ON c.id=p.categoria_id WHERE p.empresa_id=? AND p.activo=1 ORDER BY p.nombre", [$eid]);
            csv_salida('inventario_' . date('Y-m-d'), ['Código', 'Producto', 'Categoría', 'Unidad', 'Precio compra', 'Descuento comisión', 'Costo real', 'Precio contado', 'Precio crédito', 'Stock', 'Stock mínimo', 'Valor (costo)'], $rows);

        case 'creditos':
            $list = rows("SELECT v.*, c.codigo cli_codigo, c.nombre, c.apellido, c.telefono, a.nombre area, " . sql_credito_cols() . "
                FROM ventas v JOIN clientes c ON c.id=v.cliente_id LEFT JOIN areas a ON a.id=c.area_id
                WHERE v.empresa_id=? AND v.tipo='credito' AND v.estado='activa' ORDER BY v.saldo DESC", [$eid]);
            $rows = [];
            foreach ($list as $v) {
                $s = semaforo($v);
                $rows[] = [$v['codigo'], $v['cli_codigo'], nombre_completo($v), $v['telefono'], $v['area'], $v['fecha'], $v['total'], $v['enganche_monto'],
                    $v['fecha_enganche'] ?: 'Pendiente', $v['plazo_meses'], $v['cuota_monto'], $v['pagado'], $v['saldo'], $v['monto_vencido'], $v['prox_venc'], (int) $v['dias_atraso'], $v['ultimo_abono'], $s[1]];
            }
            csv_salida('cartera_creditos_' . date('Y-m-d'), ['Crédito', 'Código cliente', 'Cliente', 'Teléfono', 'Área', 'Fecha venta', 'Total', 'Enganche', 'Fecha enganche', 'Plazo (meses)', 'Cuota', 'Pagado', 'Saldo', 'Monto vencido', 'Próximo pago', 'Días de atraso', 'Último abono', 'Estado'], $rows);

        case 'abonos':
            $rows = rows("SELECT a.fecha, v.codigo, TRIM(CONCAT(c.nombre,' ',c.apellido)) cliente, a.tipo, a.metodo, a.monto, a.comision_monto, TRIM(CONCAT(u.nombre,' ',u.apellido)) recibio, IF(a.liquidacion_id IS NULL,'Por entregar','Entregado') est, a.nota
                FROM abonos a JOIN ventas v ON v.id=a.venta_id LEFT JOIN clientes c ON c.id=a.cliente_id LEFT JOIN usuarios u ON u.id=a.usuario_id
                WHERE a.empresa_id=? AND a.anulado=0 AND DATE(a.fecha) BETWEEN ? AND ? ORDER BY a.fecha", [$eid, $D, $H]);
            csv_salida('pagos_' . $sufijo, ['Fecha', 'Venta', 'Cliente', 'Tipo', 'Método', 'Monto', 'Comisión', 'Recibió', 'Estado', 'Nota'], $rows);

        case 'compras':
            $rows = rows("SELECT co.fecha, TRIM(CONCAT(COALESCE(pr.nombre,''),' ',COALESCE(pr.apellido,''))) prov, pr.nit, co.numero_factura, p.codigo, p.nombre, d.cantidad, d.costo_unitario, d.subtotal
                FROM compra_detalle d JOIN compras co ON co.id=d.compra_id JOIN productos p ON p.id=d.producto_id LEFT JOIN proveedores pr ON pr.id=co.proveedor_id
                WHERE co.empresa_id=? AND co.fecha BETWEEN ? AND ? ORDER BY co.fecha, co.id", [$eid, $D, $H]);
            csv_salida('compras_' . $sufijo, ['Fecha', 'Proveedor', 'NIT', 'Factura', 'Código producto', 'Producto', 'Cantidad', 'Costo unitario', 'Subtotal'], $rows);

        case 'gastos':
            $rows = rows("SELECT g.fecha, g.categoria, g.descripcion, g.monto, u.nombre FROM gastos g LEFT JOIN usuarios u ON u.id=g.usuario_id WHERE g.empresa_id=? AND g.fecha BETWEEN ? AND ? ORDER BY g.fecha", [$eid, $D, $H]);
            csv_salida('gastos_' . $sufijo, ['Fecha', 'Categoría', 'Descripción', 'Monto', 'Registró'], $rows);

        case 'rutas':
            $rows = rows("SELECT a.nombre, TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) cob,
                    (SELECT COUNT(*) FROM clientes c WHERE c.area_id=a.id AND c.activo=1) clientes,
                    (SELECT COALESCE(SUM(ab.monto),0) FROM abonos ab JOIN clientes c ON c.id=ab.cliente_id WHERE c.area_id=a.id AND ab.anulado=0 AND DATE(ab.fecha) BETWEEN ? AND ?) cobrado,
                    (SELECT COALESCE(SUM(v.total),0) FROM ventas v JOIN clientes c ON c.id=v.cliente_id WHERE c.area_id=a.id AND v.estado<>'anulada' AND v.fecha BETWEEN ? AND ?) vendido,
                    (SELECT COALESCE(SUM(v.saldo),0) FROM ventas v JOIN clientes c ON c.id=v.cliente_id WHERE c.area_id=a.id AND v.estado='activa' AND v.tipo='credito') cartera
                FROM areas a LEFT JOIN usuarios u ON u.id=a.cobrador_id WHERE a.empresa_id=? AND a.activo=1 ORDER BY cobrado DESC", [$D, $H, $D, $H, $eid]);
            csv_salida('areas_' . $sufijo, ['Área', 'Cobrador', 'Clientes', 'Cobrado', 'Vendido', 'Cartera'], $rows);

        case 'visitas':
            $rows = rows("SELECT r.fecha, TRIM(CONCAT(u.nombre,' ',u.apellido)) cob, a.nombre area, TRIM(CONCAT(c.nombre,' ',c.apellido)) cliente, v.codigo, rv.estado, rv.atrasada, rv.visitado_at, rv.nota, rv.reprogramar_para, ab.monto
                FROM ruta_visitas rv JOIN rutas r ON r.id=rv.ruta_id JOIN ventas v ON v.id=rv.venta_id LEFT JOIN clientes c ON c.id=rv.cliente_id LEFT JOIN areas a ON a.id=rv.area_id
                LEFT JOIN usuarios u ON u.id=r.cobrador_id LEFT JOIN abonos ab ON ab.id=rv.abono_id AND ab.anulado=0
                WHERE r.empresa_id=? AND r.fecha BETWEEN ? AND ? AND rv.estado<>'reprogramada' ORDER BY r.fecha, u.nombre, a.nombre", [$eid, $D, $H]);
            foreach ($rows as &$r) $r['atrasada'] = $r['atrasada'] ? 'Sí' : 'No';
            csv_salida('visitas_' . $sufijo, ['Fecha', 'Cobrador', 'Área', 'Cliente', 'Crédito', 'Resultado', 'Atrasada', 'Hora visita', 'Nota', 'Regresa', 'Monto cobrado'], $rows);

        case 'empleados':
            $rows = [];
            foreach (rows("SELECT * FROM usuarios WHERE empresa_id=? AND activo=1 ORDER BY nombre", [$eid]) as $e) {
                $c = row("SELECT COALESCE(SUM(monto),0) cob, COALESCE(SUM(comision_monto),0) com, COUNT(*) n FROM abonos WHERE empresa_id=? AND usuario_id=? AND anulado=0 AND DATE(fecha) BETWEEN ? AND ?", [$eid, $e['id'], $D, $H]);
                $v = row("SELECT COUNT(*) n, COALESCE(SUM(total),0) t, COALESCE(SUM(comision_vendedor),0) com FROM ventas WHERE empresa_id=? AND vendedor_id=? AND estado<>'anulada' AND fecha BETWEEN ? AND ?", [$eid, $e['id'], $D, $H]);
                $vis = resumen_cobrador($e['id'], $D, $H);
                $rows[] = [nombre_completo($e), ucfirst($e['rol']), $c['n'], $c['cob'], $c['com'], round($c['cob'] - $c['com'], 2), $vis['visitas_logradas'] . '/' . $vis['visitas_total'], $v['n'], $v['t'], $v['com'], $e['salario_base'], round($v['com'] + $e['salario_base'], 2)];
            }
            csv_salida('empleados_' . $sufijo, ['Empleado', 'Rol', 'Pagos', 'Recaudado', 'Comisión cobro', 'Debe entregar', 'Visitas', 'Ventas', 'Monto vendido', 'Comisión ventas', 'Salario base', 'Total a pagar'], $rows);
    }
}
