<?php
require_perm('admin');
require_once APP_ROOT . '/app/importar.php';
$eid = eid();
$tipo = get('tipo') === 'clientes' ? 'clientes' : 'productos';

$cfg = [
    'productos' => [
        'titulo' => 'Carga masiva de productos',
        'volver' => 'productos',
        'cols' => [
            ['codigo', 'Código', 'Si ya existe un producto con ese código, se actualiza. Vacío = se genera.'],
            ['nombre', 'Nombre *', 'Obligatorio.'],
            ['categoria', 'Categoría', 'Si no existe, se crea.'],
            ['unidad', 'Unidad', 'Ej. Unidad, Juego, Caja. Si no existe, se crea.'],
            ['precio_compra', 'Precio compra', 'Número sin "Q".'],
            ['descuento_comision', 'Descuento comisión', 'Comisión del vendedor por unidad.'],
            ['precio_contado', 'Precio contado', 'Precio de venta al contado. (El costo real = compra + descuento comisión se calcula solo.)'],
            ['precio_credito', 'Precio crédito', ''],
            ['stock', 'Stock', 'Solo para productos nuevos (los existentes se ajustan con Compras).'],
            ['stock_minimo', 'Stock mínimo', ''],
        ],
        'ejemplo' => [
            ['P-1001', 'Refrigeradora LG 14 pies', 'Electrodomésticos', 'Unidad', 3000, 400, 4200, 5400, 5, 1],
            ['', 'Juego de sala 3 piezas', 'Muebles', 'Juego', 2500, 350, 3500, 4800, 2, 1],
        ],
    ],
    'clientes' => [
        'titulo' => 'Carga masiva de clientes',
        'volver' => 'clientes',
        'cols' => [
            ['codigo', 'Código', 'Si ya existe un cliente con ese código, se actualiza. Vacío = se genera.'],
            ['nombre', 'Nombre *', 'Obligatorio.'],
            ['apellido', 'Apellido', ''],
            ['telefono', 'Teléfono', ''],
            ['direccion', 'Dirección', 'Dirección escrita.'],
            ['maps_url', 'Google Maps', 'Enlace de Google Maps (opcional).'],
            ['dpi', 'DPI', 'Opcional, 13 dígitos.'],
            ['area', 'Área', 'Si no existe, se crea (sin cobrador asignado).'],
            ['notas', 'Notas', ''],
        ],
        'ejemplo' => [
            ['', 'María', 'López', '44445555', '3a calle 4-20 zona 1', '', '', 'Casco urbano', 'Cliente referido'],
            ['', 'José', 'García', '55556666', 'Aldea El Rosario, frente a la escuela', 'https://maps.app.goo.gl/xxxx', '2547896541201', 'El Rosario', ''],
        ],
    ],
];
$c = $cfg[$tipo];
$campos = array_column($c['cols'], 0);

// Plantilla
if (get('plantilla')) {
    $filas = [array_column($c['cols'], 1)];
    foreach ($c['ejemplo'] as $e) $filas[] = $e;
    if (get('plantilla') === 'csv') { require_once APP_ROOT . '/app/reportes_csv.php'; csv_salida('plantilla_' . $tipo, array_shift($filas), $filas); }
    xlsx_descargar('plantilla_' . $tipo, $filas, array_fill(0, count($filas[0]), 20));
}

$res = null;
if (is_post()) {
    csrf_check();
    @set_time_limit(300);
    $res = ['creados' => 0, 'actualizados' => 0, 'errores' => []];
    try {
        if (empty($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Selecciona el archivo a subir');
        list($heads, $filas) = leer_hoja($_FILES['archivo']['tmp_name'], $_FILES['archivo']['name']);
        // aceptar encabezados con el texto de la plantilla ("Nombre *", "Precio compra"...)
        $mapa = [];
        foreach ($c['cols'] as $col) { $mapa[norm_col($col[1])] = $col[0]; $mapa[$col[0]] = $col[0]; }
        $mapa['google_maps'] = 'maps_url'; $mapa['direccion_google_maps'] = 'maps_url'; $mapa['codigo_cliente'] = 'codigo'; $mapa['codigo_producto'] = 'codigo';
        if (!in_array('nombre', array_map(function ($h) use ($mapa) { return $mapa[$h] ?? $h; }, $heads), true)) throw new RuntimeException('No se encontró la columna "Nombre". Usa la plantilla.');
        $actualizar = (bool) post('actualizar');

        $cache = ['cat' => [], 'uni' => [], 'area' => []];
        db()->beginTransaction();
        foreach ($filas as $f) {
            $d = [];
            foreach ($f as $k => $v) if (isset($mapa[$k])) $d[$mapa[$k]] = $v;
            $fila = $f['_fila'];
            try {
                if (($d['nombre'] ?? '') === '') throw new RuntimeException('Falta el nombre');
                $codigo = trim($d['codigo'] ?? '');
                if ($tipo === 'productos') {
                    $catId = null;
                    if (($cn = trim($d['categoria'] ?? '')) !== '') {
                        $k = mb_strtolower($cn);
                        if (!isset($cache['cat'][$k])) $cache['cat'][$k] = val('SELECT id FROM categorias WHERE empresa_id=? AND nombre=?', [$eid, $cn]) ?: insert('categorias', ['empresa_id' => $eid, 'nombre' => mb_substr($cn, 0, 80)]);
                        $catId = $cache['cat'][$k];
                    }
                    $un = trim($d['unidad'] ?? '') ?: 'Unidad';
                    $k = mb_strtolower($un);
                    if (!isset($cache['uni'][$k])) { if (!val('SELECT id FROM unidades WHERE empresa_id=? AND nombre=?', [$eid, $un])) insert('unidades', ['empresa_id' => $eid, 'nombre' => mb_substr($un, 0, 40)]); $cache['uni'][$k] = 1; }
                    $compra = num_imp($d['precio_compra'] ?? 0); $desc = num_imp($d['descuento_comision'] ?? 0);
                    $data = ['nombre' => mb_substr($d['nombre'], 0, 150), 'categoria_id' => $catId, 'unidad' => mb_substr($un, 0, 30),
                        'precio_compra' => $compra, 'descuento_comision' => $desc, 'precio_venta' => round($compra + $desc, 2),
                        'precio_contado' => num_imp($d['precio_contado'] ?? 0),
                        'precio_credito' => num_imp($d['precio_credito'] ?? 0), 'stock_minimo' => num_imp($d['stock_minimo'] ?? 0)];
                    $existe = $codigo !== '' ? val('SELECT id FROM productos WHERE empresa_id=? AND codigo=?', [$eid, $codigo]) : null;
                    if ($existe) {
                        if (!$actualizar) throw new RuntimeException('El código ' . $codigo . ' ya existe');
                        update('productos', $data + ['activo' => 1], 'id=?', [$existe]); $res['actualizados']++;
                    } else {
                        insert('productos', $data + ['empresa_id' => $eid, 'codigo' => $codigo !== '' ? mb_substr($codigo, 0, 40) : next_code('productos', 'P-', 4), 'stock' => num_imp($d['stock'] ?? 0)]);
                        $res['creados']++;
                    }
                } else {
                    $areaId = null;
                    if (($an = trim($d['area'] ?? '')) !== '') {
                        $k = mb_strtolower($an);
                        if (!isset($cache['area'][$k])) $cache['area'][$k] = val('SELECT id FROM areas WHERE empresa_id=? AND nombre=? AND activo=1', [$eid, $an]) ?: insert('areas', ['empresa_id' => $eid, 'nombre' => mb_substr($an, 0, 100)]);
                        $areaId = $cache['area'][$k];
                    }
                    $dpi = preg_replace('/\D/', '', $d['dpi'] ?? '');
                    if ($dpi !== '' && strlen($dpi) !== 13) throw new RuntimeException('DPI inválido (' . $d['dpi'] . ')');
                    $maps = trim($d['maps_url'] ?? '');
                    if ($maps !== '' && !preg_match('#^https?://#i', $maps)) $maps = '';
                    $data = ['nombre' => mb_substr($d['nombre'], 0, 80), 'apellido' => mb_substr($d['apellido'] ?? '', 0, 80), 'telefono' => nullable($d['telefono'] ?? ''),
                        'direccion' => nullable($d['direccion'] ?? ''), 'maps_url' => nullable($maps), 'dpi' => $dpi !== '' ? $dpi : null, 'area_id' => $areaId, 'notas' => nullable($d['notas'] ?? '')];
                    $existe = $codigo !== '' ? val('SELECT id FROM clientes WHERE empresa_id=? AND codigo=?', [$eid, $codigo]) : null;
                    if ($dpi !== '' && val('SELECT id FROM clientes WHERE empresa_id=? AND dpi=? AND id<>?', [$eid, $dpi, (int) $existe])) throw new RuntimeException('Ya existe otro cliente con el DPI ' . $dpi);
                    if ($existe) {
                        if (!$actualizar) throw new RuntimeException('El código ' . $codigo . ' ya existe');
                        update('clientes', $data + ['activo' => 1], 'id=?', [$existe]); $res['actualizados']++;
                    } else {
                        $emp = empresa();
                        if (!empty($emp['max_clientes']) && (int) val('SELECT COUNT(*) FROM clientes WHERE empresa_id=? AND activo=1', [$eid]) >= (int) $emp['max_clientes']) throw new RuntimeException('Se alcanzó el límite de clientes de tu plan');
                        insert('clientes', $data + ['empresa_id' => $eid, 'codigo' => $codigo !== '' ? mb_substr($codigo, 0, 20) : next_code('clientes', 'CLI-', 5), 'created_by' => uid()]);
                        $res['creados']++;
                    }
                }
            } catch (RuntimeException $ex) {
                $res['errores'][] = [$fila, $d['nombre'] ?? '', $ex->getMessage()];
            }
        }
        db()->commit();
    } catch (RuntimeException $ex) {
        if (db()->inTransaction()) db()->rollBack();
        $res['fatal'] = $ex->getMessage();
    }
}

layout_header($c['titulo'], ['actions' => '<a href="' . url($c['volver']) . '" class="btn btn-light"><i data-lucide="arrow-left"></i><span class="hidden sm:inline">Volver</span></a>']);
?>
<div class="tabs mb-4">
  <a href="<?= url('importar', ['tipo' => 'productos']) ?>" class="tab <?= $tipo === 'productos' ? 'active' : '' ?>"><i data-lucide="package"></i>Productos</a>
  <a href="<?= url('importar', ['tipo' => 'clientes']) ?>" class="tab <?= $tipo === 'clientes' ? 'active' : '' ?>"><i data-lucide="users"></i>Clientes</a>
</div>

<?php if ($res): ?>
  <?php if (!empty($res['fatal'])): ?>
    <div class="mb-4 rounded-xl border px-4 py-3 text-sm bg-rose-50 border-rose-200 text-rose-800"><?= e($res['fatal']) ?></div>
  <?php else: ?>
    <div class="card p-5 mb-4">
      <div class="grid grid-cols-3 gap-3 text-center">
        <div class="rounded-xl bg-emerald-50 p-3"><div class="text-xs text-emerald-700">Creados</div><div class="text-2xl font-bold"><?= (int) $res['creados'] ?></div></div>
        <div class="rounded-xl bg-sky-50 p-3"><div class="text-xs text-sky-700">Actualizados</div><div class="text-2xl font-bold"><?= (int) $res['actualizados'] ?></div></div>
        <div class="rounded-xl <?= $res['errores'] ? 'bg-rose-50' : 'bg-slate-50' ?> p-3"><div class="text-xs <?= $res['errores'] ? 'text-rose-700' : 'text-slate-500' ?>">Con error</div><div class="text-2xl font-bold"><?= count($res['errores']) ?></div></div>
      </div>
      <?php if ($res['errores']): ?>
        <div class="mt-4 overflow-x-auto"><table class="table">
          <thead><tr><th>Fila</th><th>Nombre</th><th>Problema</th></tr></thead>
          <tbody><?php foreach (array_slice($res['errores'], 0, 200) as $er): ?><tr><td><?= (int) $er[0] ?></td><td><?= e($er[1]) ?></td><td class="text-rose-600"><?= e($er[2]) ?></td></tr><?php endforeach; ?></tbody>
        </table></div>
        <p class="hint mt-2">Las filas con error no se guardaron. Corrígelas en el archivo y súbelo de nuevo (las demás se actualizarán, no se duplican si tienen código).</p>
      <?php endif; ?>
      <a href="<?= url($c['volver']) ?>" class="btn btn-primary mt-4">Ver <?= e($tipo) ?></a>
    </div>
  <?php endif; ?>
<?php endif; ?>

<div class="grid lg:grid-cols-3 gap-4">
  <div class="card p-5 space-y-4 lg:col-span-1 h-fit">
    <div>
      <div class="font-semibold">1. Descarga la plantilla</div>
      <p class="text-sm text-slate-500 mt-1">Llénala en Excel o Google Sheets. No cambies los nombres de las columnas.</p>
      <div class="flex flex-wrap gap-2 mt-3">
        <a href="<?= url('importar', ['tipo' => $tipo, 'plantilla' => 'xlsx']) ?>" class="btn btn-success"><i data-lucide="file-spreadsheet"></i>Plantilla Excel</a>
        <a href="<?= url('importar', ['tipo' => $tipo, 'plantilla' => 'csv']) ?>" class="btn btn-light"><i data-lucide="file-text"></i>CSV</a>
      </div>
    </div>
    <hr>
    <form method="post" enctype="multipart/form-data" class="space-y-3">
      <?= csrf_field() ?>
      <div class="font-semibold">2. Sube el archivo</div>
      <label class="flex items-center gap-3 rounded-xl border-2 border-dashed border-slate-300 p-4 cursor-pointer hover:border-brand-400">
        <span class="w-11 h-11 rounded-xl bg-brand-50 text-brand-600 grid place-items-center shrink-0"><i data-lucide="upload" class="w-5 h-5"></i></span>
        <span class="min-w-0 text-sm"><b class="text-brand-600">Elegir archivo</b><br><span id="arch-nombre" class="text-xs text-slate-400 truncate block">.xlsx o .csv</span></span>
        <input type="file" name="archivo" accept=".xlsx,.csv,.txt" required class="hidden" onchange="document.getElementById('arch-nombre').textContent = this.files[0] ? this.files[0].name : ''">
      </label>
      <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="actualizar" value="1" checked class="w-4 h-4 mt-0.5"> Actualizar los que ya existen (mismo código)</label>
      <button class="btn btn-primary btn-lg w-full"><i data-lucide="upload-cloud"></i> Importar</button>
    </form>
  </div>
  <div class="card lg:col-span-2 overflow-hidden">
    <div class="card-h"><i data-lucide="info" class="w-4 h-4 text-brand-600"></i> Columnas de la plantilla</div>
    <div class="overflow-x-auto"><table class="table table-keep">
      <thead><tr><th>Columna</th><th>Descripción</th></tr></thead>
      <tbody><?php foreach ($c['cols'] as $col): ?><tr><td class="font-semibold whitespace-nowrap"><?= e($col[1]) ?></td><td class="text-slate-600"><?= e($col[2] ?: '—') ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
  </div>
</div>
<?php layout_footer();
