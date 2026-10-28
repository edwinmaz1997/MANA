<?php
/* Lectura de archivos CSV / XLSX para carga masiva */

function norm_col($s)
{
    $s = mb_strtolower(trim((string) $s), 'UTF-8');
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    return trim($s, '_');
}

/** Devuelve [encabezados normalizados, filas (arrays asociativos con número de fila)] */
function leer_hoja($tmp, $nombreOriginal)
{
    $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') $data = leer_xlsx($tmp);
    elseif (in_array($ext, ['csv', 'txt'], true)) $data = leer_csv($tmp);
    else throw new RuntimeException('Formato no soportado. Sube un archivo .xlsx o .csv');
    // quitar filas vacías al inicio
    while ($data && !array_filter($data[0], function ($v) { return trim((string) $v) !== ''; })) array_shift($data);
    if (count($data) < 2) throw new RuntimeException('El archivo no tiene datos (se necesita la fila de encabezados y al menos una fila)');
    $heads = array_map('norm_col', array_shift($data));
    $filas = [];
    foreach ($data as $i => $r) {
        if (!array_filter($r, function ($v) { return trim((string) $v) !== ''; })) continue;
        $f = ['_fila' => $i + 2];
        foreach ($heads as $k => $h) if ($h !== '') $f[$h] = isset($r[$k]) ? trim((string) $r[$k]) : '';
        $filas[] = $f;
    }
    if (count($filas) > 5000) throw new RuntimeException('Máximo 5,000 filas por archivo');
    return [$heads, $filas];
}

function leer_csv($tmp)
{
    $txt = file_get_contents($tmp);
    if (substr($txt, 0, 3) === "\xEF\xBB\xBF") $txt = substr($txt, 3);
    if (!mb_check_encoding($txt, 'UTF-8')) $txt = mb_convert_encoding($txt, 'UTF-8', 'Windows-1252');
    $primera = strtok($txt, "\n");
    $sep = ',';
    foreach ([';', "\t", ','] as $c) if (substr_count($primera, $c) > substr_count($primera, $sep)) $sep = $c;
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $txt); rewind($fh);
    $out = [];
    while (($r = fgetcsv($fh, 0, $sep)) !== false) $out[] = $r;
    fclose($fh);
    return $out;
}

function leer_xlsx($tmp)
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('El servidor no puede leer .xlsx; guarda el archivo como CSV');
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) throw new RuntimeException('No se pudo abrir el archivo de Excel');
    $shared = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $sx = simplexml_load_string($xml);
        foreach ($sx->si as $si) {
            if (isset($si->t)) $shared[] = (string) $si->t;
            else { $t = ''; foreach ($si->r as $r) $t .= (string) $r->t; $shared[] = $t; }
        }
    }
    // primera hoja según workbook
    $sheetPath = 'xl/worksheets/sheet1.xml';
    if (($wb = $zip->getFromName('xl/workbook.xml')) !== false && ($rels = $zip->getFromName('xl/_rels/workbook.xml.rels')) !== false) {
        $w = simplexml_load_string($wb); $w->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $sh = $w->xpath('//m:sheets/m:sheet');
        if ($sh) {
            $rid = (string) $sh[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $rx = simplexml_load_string($rels);
            foreach ($rx->Relationship as $rel) if ((string) $rel['Id'] === $rid) { $t = ltrim((string) $rel['Target'], '/'); $sheetPath = strpos($t, 'xl/') === 0 ? $t : 'xl/' . $t; }
        }
    }
    $xml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($xml === false) throw new RuntimeException('No se encontró la hoja en el archivo de Excel');
    $sx = simplexml_load_string($xml);
    $out = [];
    foreach ($sx->sheetData->row as $row) {
        $r = [];
        foreach ($row->c as $c) {
            $ref = (string) $c['r'];
            $col = 0;
            foreach (str_split(preg_replace('/\d/', '', $ref)) as $ch) $col = $col * 26 + (ord($ch) - 64);
            $col--;
            $t = (string) $c['t'];
            if ($t === 's') $v = $shared[(int) $c->v] ?? '';
            elseif ($t === 'inlineStr') $v = (string) $c->is->t;
            else $v = (string) $c->v;
            $r[$col] = $v;
        }
        if ($r) { $max = max(array_keys($r)); for ($i = 0; $i <= $max; $i++) if (!isset($r[$i])) $r[$i] = ''; ksort($r); }
        $out[(int) $row['r'] - 1] = $r;
    }
    if (!$out) return [];
    $max = max(array_keys($out));
    $res = [];
    for ($i = 0; $i <= $max; $i++) $res[] = $out[$i] ?? [];
    return $res;
}

/** Genera un .xlsx sencillo (primera fila en negrita) y lo descarga */
function xlsx_descargar($nombre, array $filas, array $anchos = [])
{
    if (!class_exists('ZipArchive')) {
        // Respaldo: CSV
        require_once __DIR__ . '/reportes_csv.php';
        csv_salida($nombre, array_shift($filas), $filas);
    }
    $colL = function ($i) { $s = ''; $i++; while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = (int) (($i - $m) / 26); } return $s; };
    $x = function ($v) { return htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
    $rowsXml = '';
    foreach ($filas as $ri => $f) {
        $rowsXml .= '<row r="' . ($ri + 1) . '">';
        foreach (array_values($f) as $ci => $v) {
            $ref = $colL($ci) . ($ri + 1);
            $st = $ri === 0 ? ' s="1"' : '';
            if ($ri > 0 && is_numeric($v) && !preg_match('/^0\d/', (string) $v) && strlen((string) $v) < 12) $rowsXml .= '<c r="' . $ref . '"' . $st . '><v>' . $v . '</v></c>';
            else $rowsXml .= '<c r="' . $ref . '" t="inlineStr"' . $st . '><is><t xml:space="preserve">' . $x($v) . '</t></is></c>';
        }
        $rowsXml .= '</row>';
    }
    $cols = '';
    if ($anchos) { $cols = '<cols>'; foreach ($anchos as $i => $w) $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>'; $cols .= '</cols>'; }
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $z = new ZipArchive();
    $z->open($tmp, ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Datos" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF4F46E5"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
    $z->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' . $cols . '<sheetData>' . $rowsXml . '</sheetData></worksheet>');
    $z->close();
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nombre . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

/** Número desde Excel/CSV: acepta "Q 1,250.50", "1250,50" (coma decimal) y "1.250,50" */
function num_imp($v)
{
    $v = trim(str_ireplace(['Q', ' '], '', (string) $v));
    if ($v === '') return 0.0;
    if (preg_match('/^-?\d{1,3}(\.\d{3})+,\d+$/', $v)) $v = str_replace(['.', ','], ['', '.'], $v);
    elseif (preg_match('/^-?\d+,\d{1,2}$/', $v)) $v = str_replace(',', '.', $v);
    else $v = str_replace(',', '', $v);
    return is_numeric($v) ? round((float) $v, 2) : 0.0;
}
