<?php
/* ---------- Lógica de créditos, cuotas y abonos ---------- */

function frecuencia_factor($f)
{
    if ($f === 'semanal') return 4;
    if ($f === 'quincenal') return 2;
    return 1;
}

function fecha_cuota($base, $i, $frecuencia)
{
    if ($frecuencia === 'semanal') return date('Y-m-d', strtotime($base . ' +' . (7 * $i) . ' days'));
    if ($frecuencia === 'quincenal') return date('Y-m-d', strtotime($base . ' +' . (15 * $i) . ' days'));
    return add_months($base, $i);
}

/** Genera (o regenera) el plan de cuotas de un crédito */
function generar_cuotas($venta_id)
{
    $v = row('SELECT * FROM ventas WHERE id = ?', [$venta_id]);
    if (!$v || $v['tipo'] !== 'credito') return;
    $base = $v['fecha_primer_abono'];
    if (!$base) {
        if (!$v['fecha_enganche']) return; // pendiente de enganche: aún no hay calendario
        $base = add_months($v['fecha_enganche'], 1);
        update('ventas', ['fecha_primer_abono' => $base], 'id = ?', [$venta_id]);
    }
    $n = max(1, (int) $v['plazo_meses'] * frecuencia_factor($v['frecuencia']));
    $financiado = max(0, round($v['total'] - $v['enganche_monto'], 2));
    $cuota = $n > 0 ? floor($financiado / $n * 100) / 100 : 0;
    q('DELETE FROM cuotas WHERE venta_id = ?', [$venta_id]);
    $acum = 0;
    for ($i = 0; $i < $n; $i++) {
        $m = ($i === $n - 1) ? round($financiado - $acum, 2) : $cuota;
        $acum += $m;
        insert('cuotas', [
            'venta_id' => $venta_id,
            'numero' => $i + 1,
            'fecha_vencimiento' => fecha_cuota($base, $i, $v['frecuencia']),
            'monto' => $m,
        ]);
    }
    update('ventas', ['num_cuotas' => $n, 'cuota_monto' => $cuota], 'id = ?', [$venta_id]);
    recalcular_venta($venta_id);
}

/** Recalcula pagado, saldo, estado y aplica los pagos a las cuotas en orden */
function recalcular_venta($venta_id)
{
    $v = row('SELECT * FROM ventas WHERE id = ?', [$venta_id]);
    if (!$v) return;
    $pagado = (float) val('SELECT COALESCE(SUM(monto),0) FROM abonos WHERE venta_id = ? AND anulado = 0', [$venta_id]);
    $ultimo = val("SELECT DATE(MAX(fecha)) FROM abonos WHERE venta_id = ? AND anulado = 0 AND tipo IN ('abono','enganche','contado')", [$venta_id]);
    $saldo = round($v['total'] - $pagado, 2);
    $data = ['pagado' => $pagado, 'saldo' => max(0, $saldo), 'ultimo_abono' => $ultimo];

    if ($v['tipo'] === 'credito') {
        // Enganche cubierto
        if (!$v['fecha_enganche'] && $pagado + 0.001 >= (float) $v['enganche_monto']) {
            $fe = val("SELECT DATE(MAX(fecha)) FROM abonos WHERE venta_id = ? AND anulado = 0", [$venta_id]) ?: $v['fecha'];
            if ((float) $v['enganche_monto'] <= 0) $fe = $v['fecha'];
            update('ventas', ['fecha_enganche' => $fe], 'id = ?', [$venta_id]);
            if (!val('SELECT COUNT(*) FROM cuotas WHERE venta_id = ?', [$venta_id])) {
                generar_cuotas($venta_id);
                return;
            }
        }
        // Distribuir a cuotas
        $aplicable = max(0, $pagado - (float) $v['enganche_monto']);
        foreach (rows('SELECT id, monto FROM cuotas WHERE venta_id = ? ORDER BY numero', [$venta_id]) as $c) {
            $p = min((float) $c['monto'], $aplicable);
            $aplicable = round($aplicable - $p, 2);
            $estado = $p + 0.001 >= (float) $c['monto'] ? 'pagada' : ($p > 0 ? 'parcial' : 'pendiente');
            update('cuotas', ['pagado' => $p, 'estado' => $estado], 'id = ?', [$c['id']]);
        }
    }
    if (in_array($v['estado'], ['activa', 'pagada'], true)) {
        $data['estado'] = $saldo <= 0.009 ? 'pagada' : 'activa';
    }
    update('ventas', $data, 'id = ?', [$venta_id]);
}

/**
 * Registra un pago sobre una venta.
 * $opts: usuario (array), metodo, nota, visita_id, lat, lng, fecha
 */
function registrar_abono($venta_id, $monto, array $opts = [])
{
    $v = row('SELECT * FROM ventas WHERE id = ? AND empresa_id = ?', [$venta_id, eid()]);
    if (!$v) throw new RuntimeException('Venta no encontrada');
    if ($v['estado'] !== 'activa') throw new RuntimeException('La venta no está activa (' . $v['estado'] . ')');
    $monto = round((float) $monto, 2);
    if ($monto <= 0) throw new RuntimeException('El monto debe ser mayor a cero');
    if ($monto > (float) $v['saldo'] + 0.009) throw new RuntimeException('El monto supera el saldo pendiente (' . money($v['saldo']) . ')');

    $u = isset($opts['usuario']) ? $opts['usuario'] : user();
    if ($v['tipo'] === 'contado') $tipo = 'contado';
    elseif (!$v['fecha_enganche'] && (float) $v['enganche_monto'] > 0) $tipo = 'enganche';
    else $tipo = 'abono';

    $pct = ($u['rol'] === 'cobrador' && $tipo !== 'contado') ? (float) $u['comision_cobro_pct'] : 0;
    $id = insert('abonos', [
        'empresa_id' => $v['empresa_id'],
        'venta_id' => $venta_id,
        'cliente_id' => $v['cliente_id'],
        'usuario_id' => $u['id'],
        'fecha' => isset($opts['fecha']) ? $opts['fecha'] : now(),
        'monto' => $monto,
        'tipo' => $tipo,
        'metodo' => isset($opts['metodo']) && $opts['metodo'] ? $opts['metodo'] : 'efectivo',
        'nota' => isset($opts['nota']) ? nullable($opts['nota']) : null,
        'comision_pct' => $pct,
        'comision_monto' => round($monto * $pct / 100, 2),
        'visita_id' => isset($opts['visita_id']) ? $opts['visita_id'] : null,
        'lat' => isset($opts['lat']) ? nullable($opts['lat']) : null,
        'lng' => isset($opts['lng']) ? nullable($opts['lng']) : null,
    ]);
    recalcular_venta($venta_id);
    if ($tipo !== 'contado') {
        $nv = row('SELECT saldo FROM ventas WHERE id = ?', [$venta_id]);
        chat_sistema($venta_id, ucfirst($tipo) . ' de ' . money($monto) . ' recibido por ' . $u['nombre'] . '. Saldo: ' . money($nv['saldo']));
    }
    return $id;
}

function anular_abono($abono_id)
{
    $a = row('SELECT * FROM abonos WHERE id = ? AND empresa_id = ?', [$abono_id, eid()]);
    if (!$a || $a['anulado']) throw new RuntimeException('Pago no encontrado');
    if ($a['liquidacion_id']) throw new RuntimeException('El pago ya fue liquidado, no se puede anular');
    update('abonos', ['anulado' => 1], 'id = ?', [$abono_id]);
    $v = row('SELECT * FROM ventas WHERE id = ?', [$a['venta_id']]);
    if ($v['estado'] === 'pagada') update('ventas', ['estado' => 'activa'], 'id = ?', [$v['id']]);
    // Si se anuló el enganche, regresar a pendiente
    if ($a['tipo'] === 'enganche' && (float) $v['enganche_monto'] > 0) {
        $pag = (float) val('SELECT COALESCE(SUM(monto),0) FROM abonos WHERE venta_id = ? AND anulado = 0', [$v['id']]);
        if ($pag < (float) $v['enganche_monto']) {
            update('ventas', ['fecha_enganche' => null], 'id = ?', [$v['id']]);
        }
    }
    if ($a['visita_id']) update('ruta_visitas', ['estado' => 'pendiente', 'abono_id' => null, 'visitado_at' => null], 'id = ?', [$a['visita_id']]);
    recalcular_venta($a['venta_id']);
    chat_sistema($a['venta_id'], 'Pago de ' . money($a['monto']) . ' ANULADO por ' . user()['nombre']);
}

function chat_sistema($venta_id, $msg)
{
    insert('venta_mensajes', ['venta_id' => $venta_id, 'usuario_id' => uid() ?: null, 'mensaje' => $msg, 'sistema' => 1]);
}

/* ---------- Semáforo ---------- */

/** Columnas SQL calculadas para créditos (alias de ventas = v) */
function sql_credito_cols()
{
    return "(SELECT MIN(c.fecha_vencimiento) FROM cuotas c WHERE c.venta_id = v.id AND c.estado <> 'pagada') AS prox_venc,
            GREATEST(0, DATEDIFF(CURDATE(), (SELECT MIN(c.fecha_vencimiento) FROM cuotas c WHERE c.venta_id = v.id AND c.estado <> 'pagada'))) AS dias_atraso,
            (SELECT COALESCE(SUM(c.monto - c.pagado),0) FROM cuotas c WHERE c.venta_id = v.id AND c.estado <> 'pagada' AND c.fecha_vencimiento < CURDATE()) AS monto_vencido,
            (SELECT COALESCE(SUM(c.monto - c.pagado),0) FROM cuotas c WHERE c.venta_id = v.id AND c.estado <> 'pagada' AND c.fecha_vencimiento <= CURDATE()) AS monto_exigible,
            DATEDIFF(CURDATE(), COALESCE(v.ultimo_abono, v.fecha_enganche, v.fecha)) AS dias_sin_abono";
}

/** Devuelve [color, etiqueta, clase css] */
function semaforo(array $v)
{
    if ($v['tipo'] === 'contado') return ['gris', 'Contado', 'badge-gray'];
    if ($v['estado'] === 'pagada') return ['azul', 'Pagado', 'badge-blue'];
    if ($v['estado'] === 'anulada') return ['gris', 'Anulado', 'badge-gray'];
    if ($v['estado'] === 'recogida') return ['gris', 'Recogido', 'badge-gray'];
    $diasRecoger = (int) (empresa()['dias_recoger'] ?? 90);
    $sinAbono = isset($v['dias_sin_abono']) ? (int) $v['dias_sin_abono'] : 0;
    if ($sinAbono >= $diasRecoger && (float) $v['saldo'] > 0) return ['rojo', 'Recoger producto', 'badge-red'];
    if (!$v['fecha_enganche'] && (float) $v['enganche_monto'] > 0) {
        return $sinAbono > 30 ? ['rojo', 'Enganche pendiente', 'badge-red'] : ['amarillo', 'Pendiente de enganche', 'badge-yellow'];
    }
    $d = isset($v['dias_atraso']) ? (int) $v['dias_atraso'] : 0;
    if ($d <= 0) return (isset($v['prox_venc']) && $v['prox_venc'] === today()) ? ['verde', 'Vence hoy', 'badge-green'] : ['verde', 'Al día', 'badge-green'];
    if ($d <= 30) return ['amarillo', $d . ' días de atraso', 'badge-yellow'];
    return ['rojo', $d . ' días de atraso', 'badge-red'];
}

function semaforo_dot($color)
{
    $map = ['verde' => 'bg-emerald-500', 'amarillo' => 'bg-amber-400', 'rojo' => 'bg-rose-500', 'azul' => 'bg-sky-500', 'gris' => 'bg-slate-300'];
    return '<span class="inline-block w-2.5 h-2.5 rounded-full ' . ($map[$color] ?? 'bg-slate-300') . '"></span>';
}

/* ---------- Comisiones ---------- */

/** Resumen del cobrador para un rango de fechas */
function resumen_cobrador($usuario_id, $desde, $hasta)
{
    $r = row("SELECT COUNT(*) AS pagos, COALESCE(SUM(monto),0) AS cobrado, COALESCE(SUM(comision_monto),0) AS comision,
                     COALESCE(SUM(CASE WHEN liquidacion_id IS NULL THEN monto ELSE 0 END),0) AS cobrado_pend,
                     COALESCE(SUM(CASE WHEN liquidacion_id IS NULL THEN comision_monto ELSE 0 END),0) AS comision_pend
              FROM abonos WHERE usuario_id = ? AND anulado = 0 AND empresa_id = ? AND DATE(fecha) BETWEEN ? AND ?",
        [$usuario_id, eid(), $desde, $hasta]);
    $vis = row("SELECT COUNT(*) AS total, SUM(CASE WHEN rv.estado <> 'pendiente' AND rv.estado <> 'reprogramada' THEN 1 ELSE 0 END) AS logradas,
                       SUM(CASE WHEN rv.estado = 'pago' THEN 1 ELSE 0 END) AS con_pago
                FROM ruta_visitas rv JOIN rutas r ON r.id = rv.ruta_id
                WHERE r.cobrador_id = ? AND r.empresa_id = ? AND r.fecha BETWEEN ? AND ?", [$usuario_id, eid(), $desde, $hasta]);
    $r['visitas_total'] = (int) $vis['total'];
    $r['visitas_logradas'] = (int) $vis['logradas'];
    $r['visitas_pago'] = (int) $vis['con_pago'];
    $r['entregar'] = round($r['cobrado_pend'] - $r['comision_pend'], 2);
    return $r;
}

/* ---------- Rutas ---------- */

/** Crea (o completa) la ruta del día de un cobrador con los créditos que toca visitar */
function generar_ruta($cobrador_id, $fecha)
{
    $eid = eid();
    $ruta = row('SELECT * FROM rutas WHERE empresa_id=? AND fecha=? AND cobrador_id=?', [$eid, $fecha, $cobrador_id]);
    $rid = $ruta ? (int) $ruta['id'] : insert('rutas', ['empresa_id' => $eid, 'fecha' => $fecha, 'cobrador_id' => $cobrador_id]);

    // Visitas atrasadas: pendientes de rutas anteriores del mismo cobrador
    $atrasadas = rows("SELECT rv.id, rv.venta_id, rv.fecha_programada FROM ruta_visitas rv JOIN rutas r ON r.id=rv.ruta_id
        WHERE r.empresa_id=? AND r.cobrador_id=? AND r.fecha < ? AND rv.estado='pendiente'", [$eid, $cobrador_id, $fecha]);
    $carry = [];
    foreach ($atrasadas as $a) {
        update('ruta_visitas', ['estado' => 'reprogramada'], 'id=?', [$a['id']]);
        $carry[$a['venta_id']] = $a['fecha_programada'];
    }

    $ya = array_flip(array_column(rows('SELECT venta_id FROM ruta_visitas WHERE ruta_id=?', [$rid]), 'venta_id'));
    $cands = rows("SELECT v.id, v.cliente_id, c.area_id, a.nombre area, c.nombre, v.fecha_enganche, v.enganche_monto,
            (SELECT MIN(cu.fecha_vencimiento) FROM cuotas cu WHERE cu.venta_id=v.id AND cu.estado<>'pagada') prox
        FROM ventas v JOIN clientes c ON c.id=v.cliente_id JOIN areas a ON a.id=c.area_id
        WHERE v.empresa_id=? AND v.tipo='credito' AND v.estado='activa' AND a.cobrador_id=? AND a.activo=1
        ORDER BY a.nombre, c.nombre", [$eid, $cobrador_id]);
    $orden = (int) val('SELECT COALESCE(MAX(orden),0) FROM ruta_visitas WHERE ruta_id=?', [$rid]);
    $n = 0;
    foreach ($cands as $c) {
        if (isset($ya[$c['id']])) continue;
        $pendEng = !$c['fecha_enganche'] && $c['enganche_monto'] > 0;
        $toca = $pendEng || ($c['prox'] && $c['prox'] <= $fecha) || isset($carry[$c['id']]);
        if (!$toca) continue;
        $prog = isset($carry[$c['id']]) && $carry[$c['id']] ? $carry[$c['id']] : ($c['prox'] ?: $fecha);
        insert('ruta_visitas', [
            'ruta_id' => $rid, 'venta_id' => $c['id'], 'cliente_id' => $c['cliente_id'], 'area_id' => $c['area_id'],
            'orden' => ++$orden, 'fecha_programada' => $prog,
            'atrasada' => (isset($carry[$c['id']]) || $prog < $fecha) ? 1 : 0,
        ]);
        $n++;
    }
    // Pendientes de rutas viejas cuyo crédito ya no está en las áreas del cobrador: igual se agregan
    foreach ($carry as $vid => $prog) {
        if (isset($ya[$vid]) || val('SELECT id FROM ruta_visitas WHERE ruta_id=? AND venta_id=?', [$rid, $vid])) continue;
        $v = row("SELECT v.id, v.cliente_id, c.area_id FROM ventas v LEFT JOIN clientes c ON c.id=v.cliente_id WHERE v.id=? AND v.estado='activa'", [$vid]);
        if (!$v) continue;
        insert('ruta_visitas', ['ruta_id' => $rid, 'venta_id' => $vid, 'cliente_id' => $v['cliente_id'], 'area_id' => $v['area_id'], 'orden' => ++$orden, 'fecha_programada' => $prog ?: $fecha, 'atrasada' => 1]);
        $n++;
    }
    return [$rid, $n];
}
