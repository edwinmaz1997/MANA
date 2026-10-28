<?php
/* ---------- Base de datos ---------- */
function db()
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

function q($sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function rows($sql, array $params = []) { return q($sql, $params)->fetchAll(); }
function row($sql, array $params = []) { $r = q($sql, $params)->fetch(); return $r ?: null; }
function val($sql, array $params = []) { $v = q($sql, $params)->fetchColumn(); return $v === false ? null : $v; }

function insert($table, array $data)
{
    $cols = array_keys($data);
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
    q($sql, array_values($data));
    return (int) db()->lastInsertId();
}

function update($table, array $data, $where, array $whereParams = [])
{
    $set = [];
    foreach (array_keys($data) as $c) $set[] = '`' . $c . '` = ?';
    $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE ' . $where;
    return q($sql, array_merge(array_values($data), $whereParams))->rowCount();
}

/* ---------- Salida ---------- */
function e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

function money($n, $sym = true)
{
    $m = number_format((float) $n, 2, '.', ',');
    return $sym ? (currency() . "\u{00A0}" . $m) : $m;
}
function currency()
{
    $emp = empresa();
    return $emp && !empty($emp['moneda']) ? $emp['moneda'] : 'Q';
}
function num($n, $d = 0) { return number_format((float) $n, $d, '.', ','); }

function fdate($d, $withTime = false)
{
    if (!$d) return '—';
    $t = strtotime($d);
    return $withTime ? date('d/m/Y H:i', $t) : date('d/m/Y', $t);
}
function today() { return date('Y-m-d'); }
function now() { return date('Y-m-d H:i:s'); }

function add_months($date, $months)
{
    $d = new DateTime($date);
    $day = (int) $d->format('d');
    $d->modify('first day of this month')->modify('+' . (int) $months . ' month');
    $last = (int) $d->format('t');
    $d->setDate((int) $d->format('Y'), (int) $d->format('m'), min($day, $last));
    return $d->format('Y-m-d');
}

function dias_entre($a, $b)
{
    return (int) floor((strtotime($b) - strtotime($a)) / 86400);
}

/* ---------- Request ---------- */
function post($k, $def = null) { return isset($_POST[$k]) ? (is_string($_POST[$k]) ? trim($_POST[$k]) : $_POST[$k]) : $def; }
function get($k, $def = null) { return isset($_GET[$k]) ? (is_string($_GET[$k]) ? trim($_GET[$k]) : $_GET[$k]) : $def; }
function is_post() { return $_SERVER['REQUEST_METHOD'] === 'POST'; }
function dec($v) { $v = str_replace([',', ' '], ['', ''], (string) $v); return is_numeric($v) ? round((float) $v, 2) : 0.0; }
function nullable($v) { $v = is_string($v) ? trim($v) : $v; return ($v === '' || $v === null) ? null : $v; }

function url($page = 'dashboard', array $params = [])
{
    $params = array_merge(['p' => $page], $params);
    return 'index.php?' . http_build_query($params);
}
function redirect($to)
{
    if (strpos($to, 'index.php') !== 0 && strpos($to, 'http') !== 0 && strpos($to, '/') !== 0 && strpos($to, '.php') === false) {
        $to = url($to);
    }
    header('Location: ' . $to);
    exit;
}

function flash($type = null, $msg = null)
{
    if ($type === null) {
        $f = isset($_SESSION['flash']) ? $_SESSION['flash'] : [];
        unset($_SESSION['flash']);
        return $f;
    }
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function csrf_token()
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field() { return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">'; }
function csrf_check()
{
    $t = isset($_POST['_csrf']) ? $_POST['_csrf'] : (isset($_SERVER['HTTP_X_CSRF']) ? $_SERVER['HTTP_X_CSRF'] : '');
    if (!hash_equals(csrf_token(), (string) $t)) {
        http_response_code(419);
        if (is_ajax()) json_out(['ok' => false, 'error' => 'Sesión expirada, recarga la página']);
        die('Token inválido. Recarga la página.');
    }
}
function is_ajax() { return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false); }
function json_out($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- Códigos ---------- */
function next_code($table, $prefix, $pad = 5)
{
    $eid = eid();
    $last = val("SELECT codigo FROM `$table` WHERE empresa_id = ? AND codigo LIKE ? ORDER BY id DESC LIMIT 1", [$eid, $prefix . '%']);
    $n = $last ? ((int) preg_replace('/\D/', '', substr($last, strlen($prefix)))) + 1 : 1;
    do {
        $code = $prefix . str_pad((string) $n, $pad, '0', STR_PAD_LEFT);
        $exists = val("SELECT 1 FROM `$table` WHERE empresa_id = ? AND codigo = ?", [$eid, $code]);
        $n++;
    } while ($exists);
    return $code;
}

/* ---------- Subida de imágenes ---------- */
function upload_image($field, $subdir, $maxW = 1280)
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Error al subir la imagen (código ' . $f['error'] . ')');
    if ($f['size'] > 12 * 1024 * 1024) throw new RuntimeException('La imagen supera 12 MB');
    $info = @getimagesize($f['tmp_name']);
    if (!$info) throw new RuntimeException('El archivo no es una imagen válida');
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!isset($allowed[$info[2]])) throw new RuntimeException('Formato no permitido (usa JPG, PNG o WEBP)');

    $dir = APP_ROOT . '/uploads/' . $subdir;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    $ext = $allowed[$info[2]];

    // Redimensionar con GD si está disponible
    if (function_exists('imagecreatetruecolor') && $info[0] > $maxW) {
        $src = null;
        switch ($info[2]) {
            case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($f['tmp_name']); break;
            case IMAGETYPE_PNG: $src = @imagecreatefrompng($f['tmp_name']); break;
            case IMAGETYPE_WEBP: $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($f['tmp_name']) : null; break;
        }
        if ($src) {
            $w = $maxW; $h = (int) round($info[1] * $maxW / $info[0]);
            $dst = imagecreatetruecolor($w, $h);
            if ($info[2] === IMAGETYPE_PNG) {
                imagealphablending($dst, false); imagesavealpha($dst, true);
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);
                imagepng($dst, $dir . '/' . $name . '.png', 8);
                $ext = 'png';
            } else {
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);
                imagejpeg($dst, $dir . '/' . $name . '.jpg', 82);
                $ext = 'jpg';
            }
            imagedestroy($src); imagedestroy($dst);
            return 'uploads/' . $subdir . '/' . $name . '.' . $ext;
        }
    }
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name . '.' . $ext)) throw new RuntimeException('No se pudo guardar la imagen');
    return 'uploads/' . $subdir . '/' . $name . '.' . $ext;
}

/* ---------- Rango de fechas para filtros ---------- */
function rango_fechas()
{
    $r = get('r', 'mes');
    $hoy = today();
    switch ($r) {
        case 'hoy': $d = $h = $hoy; break;
        case 'ayer': $d = $h = date('Y-m-d', strtotime('-1 day')); break;
        case 'semana': $d = date('Y-m-d', strtotime('monday this week')); $h = $hoy; break;
        case 'anio': $d = date('Y-01-01'); $h = date('Y-12-31'); break;
        case 'rango':
            $d = get('desde', date('Y-m-01'));
            $h = get('hasta', $hoy);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $d = date('Y-m-01');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $h)) $h = $hoy;
            break;
        default: $r = 'mes'; $d = date('Y-m-01'); $h = date('Y-m-t');
    }
    return ['r' => $r, 'desde' => $d, 'hasta' => $h];
}

function nombre_completo($r, $pref = '')
{
    return trim(($r[$pref . 'nombre'] ?? '') . ' ' . ($r[$pref . 'apellido'] ?? ''));
}

function maps_link($cli)
{
    if (!empty($cli['maps_url'])) return $cli['maps_url'];
    if (!empty($cli['lat']) && !empty($cli['lng'])) return 'https://www.google.com/maps?q=' . $cli['lat'] . ',' . $cli['lng'];
    return null;
}

function wa_link($tel, $msg = '')
{
    $t = preg_replace('/\D/', '', (string) $tel);
    if ($t === '') return null;
    if (strlen($t) === 8) $t = '502' . $t;
    return 'https://wa.me/' . $t . ($msg ? '?text=' . rawurlencode($msg) : '');
}

function tipo_label($t) { return $t === 'credito' ? 'Crédito' : 'Contado'; }

/** Ruta de un recurso estático con versión según su fecha de modificación (evita caché vieja) */
function asset($path)
{
    $f = APP_ROOT . '/' . $path;
    return $path . '?v=' . (is_file($f) ? filemtime($f) : APP_VERSION);
}
