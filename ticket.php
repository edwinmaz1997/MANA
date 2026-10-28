<?php
/* Ticket para impresora térmica de 58 mm */
require __DIR__ . '/app/bootstrap.php';
if (!user()) { header('Location: index.php'); exit; }
$eid = eid();
$emp = empresa();
$tipo = get('tipo', 'venta');
$id = (int) get('id');

$v = null; $a = null; $det = []; $cli = null; $usr = null;
if ($tipo === 'venta') {
    $v = row('SELECT * FROM ventas WHERE id=? AND empresa_id=?', [$id, $eid]);
    if (!$v) die('Venta no encontrada');
    $det = rows('SELECT * FROM venta_detalle WHERE venta_id=?', [$id]);
    $usr = row('SELECT nombre, apellido FROM usuarios WHERE id=?', [$v['vendedor_id']]);
} elseif ($tipo === 'abono') {
    $a = row('SELECT * FROM abonos WHERE id=? AND empresa_id=?', [$id, $eid]);
    if (!$a) die('Pago no encontrado');
    $v = row('SELECT * FROM ventas WHERE id=?', [$a['venta_id']]);
    $usr = row('SELECT nombre, apellido FROM usuarios WHERE id=?', [$a['usuario_id']]);
    $saldoDespues = round($v['total'] - (float) val('SELECT COALESCE(SUM(monto),0) FROM abonos WHERE venta_id=? AND anulado=0 AND id<=?', [$v['id'], $a['id']]), 2);
    $prox = val("SELECT MIN(fecha_vencimiento) FROM cuotas WHERE venta_id=? AND estado<>'pagada'", [$v['id']]);
    $cuotasPag = (int) val("SELECT COUNT(*) FROM cuotas WHERE venta_id=? AND estado='pagada'", [$v['id']]);
}
if ($v && $v['cliente_id']) $cli = row('SELECT * FROM clientes WHERE id=?', [$v['cliente_id']]);

function tline($l, $r) { return '<div class="row"><span>' . $l . '</span><span>' . $r . '</span></div>'; }
?><!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ticket</title>
<style>
@page { size: 58mm auto; margin: 0; }
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; background: #fff; }
body { width: 58mm; padding: 2mm 3mm 6mm; font-family: 'Courier New', ui-monospace, monospace; font-size: 11px; line-height: 1.3; color: #000; }
.c { text-align: center; } .b { font-weight: 700; } .big { font-size: 15px; } .sm { font-size: 9.5px; }
.logo { display: block; max-width: 38mm; max-height: 22mm; margin: 0 auto 2mm; filter: grayscale(1) contrast(1.4); }
hr { border: 0; border-top: 1px dashed #000; margin: 2mm 0; }
.row { display: flex; justify-content: space-between; gap: 2mm; }
.row span:last-child { text-align: right; white-space: nowrap; }
.item { margin-bottom: 1mm; }
.box { border: 1px solid #000; padding: 1.5mm; margin: 2mm 0; text-align: center; }
.noprint { position: fixed; top: 8px; right: 8px; font-family: sans-serif; }
.noprint button { padding: 8px 14px; border-radius: 8px; border: 0; background: #4f46e5; color: #fff; font-weight: 600; cursor: pointer; }
@media screen { body { margin: 16px auto; box-shadow: 0 0 0 1px #ddd, 0 6px 24px rgba(0,0,0,.12); } html { background: #f1f5f9; } }
@media print { .noprint { display: none; } }
</style></head>
<body>
<div class="noprint"><button onclick="window.print()">Imprimir</button></div>

<?php if ($emp['logo']): ?><img src="<?= e($emp['logo']) ?>" class="logo" alt=""><?php endif; ?>
<div class="c b big"><?= e($emp['nombre']) ?></div>
<?php if ($emp['nit']): ?><div class="c sm">NIT: <?= e($emp['nit']) ?></div><?php endif; ?>
<?php if ($emp['direccion']): ?><div class="c sm"><?= e($emp['direccion']) ?></div><?php endif; ?>
<?php if ($emp['telefono']): ?><div class="c sm">Tel. <?= e($emp['telefono']) ?></div><?php endif; ?>
<hr>

<?php if ($tipo === 'prueba'): ?>
  <div class="c b">TICKET DE PRUEBA</div>
  <div class="c sm"><?= date('d/m/Y H:i') ?></div>
  <hr><?= tline('Producto de ejemplo', money(100)) ?><?= tline('<b>TOTAL</b>', '<b>' . money(100) . '</b>') ?>

<?php elseif ($tipo === 'venta'): ?>
  <div class="c b"><?= $v['tipo'] === 'credito' ? 'VENTA AL CRÉDITO' : 'VENTA DE CONTADO' ?></div>
  <div class="c b"><?= e($v['codigo']) ?></div>
  <div class="c sm"><?= fdate($v['created_at'], true) ?></div>
  <?php if ($v['estado'] === 'anulada'): ?><div class="box b">*** ANULADA ***</div><?php endif; ?>
  <hr>
  <?php if ($cli): ?>
    <div class="b"><?= e(nombre_completo($cli)) ?></div>
    <div class="sm">Código: <?= e($cli['codigo']) ?><?= $cli['telefono'] ? ' · Tel. ' . e($cli['telefono']) : '' ?></div>
    <?php if ($cli['direccion']): ?><div class="sm"><?= e($cli['direccion']) ?></div><?php endif; ?>
  <?php else: ?><div>Cliente: Consumidor final</div><?php endif; ?>
  <div class="sm">Vendedor: <?= e(nombre_completo($usr)) ?></div>
  <hr>
  <?php foreach ($det as $d): ?>
    <div class="item"><div><?= e($d['descripcion']) ?></div><?= tline(num($d['cantidad'], $d['cantidad'] == floor($d['cantidad']) ? 0 : 2) . ' x ' . money($d['precio_unitario'], false), money($d['subtotal'], false)) ?></div>
  <?php endforeach; ?>
  <hr>
  <?= tline('Subtotal', money($v['subtotal'])) ?>
  <?php if ($v['descuento'] > 0) echo tline('Descuento', '-' . money($v['descuento'])); ?>
  <?php if ($v['extra_monto'] > 0) echo tline(e($v['extra_concepto']), '+' . money($v['extra_monto'])); ?>
  <div class="row b big"><span>TOTAL</span><span><?= money($v['total']) ?></span></div>
  <?php if ($v['tipo'] === 'credito'): ?>
    <hr>
    <?= tline('Enganche', money($v['enganche_monto'])) ?>
    <?= tline('Fecha enganche', $v['fecha_enganche'] ? fdate($v['fecha_enganche']) : '________') ?>
    <?php if (!$v['fecha_enganche'] && $v['enganche_monto'] > 0): ?><div class="box b">PENDIENTE DE ENGANCHE</div><?php endif; ?>
    <?= tline('Plazo', (int) $v['plazo_meses'] . ' meses') ?>
    <?= tline('Frecuencia', ucfirst($v['frecuencia'])) ?>
    <?php if ($v['num_cuotas']) echo tline((int) $v['num_cuotas'] . ' cuotas de', '<b>' . money($v['cuota_monto']) . '</b>'); ?>
    <?= tline('Primer abono', $v['fecha_primer_abono'] ? fdate($v['fecha_primer_abono']) : '1 mes desp. enganche') ?>
    <?= tline('Pagado', money($v['pagado'])) ?>
    <div class="row b"><span>SALDO</span><span><?= money($v['saldo']) ?></span></div>
    <hr>
    <div class="sm">Si no realiza abonos durante <?= (int) round($emp['dias_recoger'] / 30) ?> meses, el producto será recogido.</div>
    <br><br><div class="c">______________________</div><div class="c sm">Firma del cliente</div>
  <?php else: ?>
    <?= tline('Pagado', money($v['pagado'])) ?>
  <?php endif; ?>

<?php else: /* abono */ ?>
  <div class="c b">RECIBO DE PAGO</div>
  <div class="c b">No. <?= str_pad((string) $a['id'], 6, '0', STR_PAD_LEFT) ?></div>
  <div class="c sm"><?= fdate($a['fecha'], true) ?></div>
  <?php if ($a['anulado']): ?><div class="box b">*** ANULADO ***</div><?php endif; ?>
  <hr>
  <?php if ($cli): ?><div class="b"><?= e(nombre_completo($cli)) ?></div><div class="sm">Código: <?= e($cli['codigo']) ?></div><?php endif; ?>
  <div class="sm">Crédito: <?= e($v['codigo']) ?></div>
  <hr>
  <?= tline('Concepto', ucfirst($a['tipo'])) ?>
  <?= tline('Método', ucfirst($a['metodo'])) ?>
  <div class="row b big"><span>RECIBIDO</span><span><?= money($a['monto']) ?></span></div>
  <hr>
  <?= tline('Total crédito', money($v['total'])) ?>
  <?= tline('Saldo anterior', money($saldoDespues + $a['monto'])) ?>
  <div class="row b"><span>SALDO ACTUAL</span><span><?= money(max(0, $saldoDespues)) ?></span></div>
  <?php if ($v['num_cuotas']) echo tline('Cuotas pagadas', $cuotasPag . ' de ' . (int) $v['num_cuotas']); ?>
  <?php if ($prox && $saldoDespues > 0) echo tline('Próximo pago', fdate($prox)); ?>
  <?php if ($a['nota']): ?><div class="sm">Nota: <?= e($a['nota']) ?></div><?php endif; ?>
  <hr>
  <div class="sm">Recibió: <?= e(nombre_completo($usr)) ?></div>
  <?php if ($saldoDespues <= 0): ?><div class="box b">CRÉDITO CANCELADO</div><?php endif; ?>
<?php endif; ?>

<hr>
<div class="c sm"><?= e($emp['ticket_mensaje'] ?: '¡Gracias por su preferencia!') ?></div>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 350); });</script>
</body></html>
