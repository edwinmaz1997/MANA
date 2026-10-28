<?php
/* ---------- Autenticación y permisos ---------- */

function user()
{
    static $u = false;
    if ($u !== false) return $u;
    $u = null;
    if (!empty($_SESSION['uid'])) {
        $u = row('SELECT * FROM usuarios WHERE id = ? AND activo = 1', [$_SESSION['uid']]);
        if (!$u) unset($_SESSION['uid']);
    }
    return $u;
}

function super_user()
{
    static $s = false;
    if ($s !== false) return $s;
    $s = null;
    if (!empty($_SESSION['sid'])) $s = row('SELECT id, nombre, usuario FROM superadmins WHERE id = ?', [$_SESSION['sid']]);
    return $s;
}

function uid() { $u = user(); return $u ? (int) $u['id'] : 0; }
function eid() { $u = user(); return $u ? (int) $u['empresa_id'] : 0; }
function rol() { $u = user(); return $u ? $u['rol'] : null; }
function is_admin() { return rol() === 'admin'; }

function empresa()
{
    static $emp = false;
    if ($emp !== false) return $emp;
    $emp = eid() ? row('SELECT e.*, p.nombre AS plan_nombre, p.max_usuarios, p.max_clientes FROM empresas e LEFT JOIN planes p ON p.id = e.plan_id WHERE e.id = ?', [eid()]) : null;
    return $emp;
}

/** Permisos por rol */
function can($perm)
{
    $r = rol();
    if ($r === 'admin') return true;
    $map = [
        'vendedor' => ['dashboard', 'clientes', 'clientes.crear', 'ventas', 'ventas.crear', 'productos.ver', 'perfil', 'chat'],
        'cobrador' => ['dashboard', 'clientes', 'clientes.crear', 'ventas', 'ventas.crear', 'productos.ver', 'perfil', 'chat', 'cobros', 'rutas.mia'],
    ];
    return $r && isset($map[$r]) && in_array($perm, $map[$r], true);
}

function require_perm($perm)
{
    if (!can($perm)) {
        http_response_code(403);
        if (is_ajax()) json_out(['ok' => false, 'error' => 'Sin permiso'], 403);
        layout_header('Sin permiso');
        echo '<div class="card p-10 text-center"><i data-lucide="shield-x" class="w-12 h-12 mx-auto text-rose-500"></i><h2 class="text-xl font-semibold mt-3">No tienes permiso para ver esta sección</h2><a class="btn btn-primary mt-5" href="' . url('dashboard') . '">Ir al inicio</a></div>';
        layout_footer();
        exit;
    }
}

/** Estado de la membresía de la empresa */
function membresia_estado($emp = null)
{
    $emp = $emp ?: empresa();
    if (!$emp) return ['ok' => false, 'msg' => 'Empresa no encontrada', 'dias' => 0];
    if ($emp['estado'] !== 'activa') return ['ok' => false, 'msg' => 'La empresa está suspendida', 'dias' => 0];
    if (empty($emp['fecha_vencimiento'])) return ['ok' => true, 'msg' => '', 'dias' => 9999];
    $dias = dias_entre(today(), $emp['fecha_vencimiento']);
    if ($dias < 0) return ['ok' => false, 'msg' => 'La membresía venció el ' . fdate($emp['fecha_vencimiento']), 'dias' => $dias];
    return ['ok' => true, 'msg' => '', 'dias' => $dias];
}

function login_attempt($usuario, $password)
{
    $usuario = trim($usuario);
    // Superadmin
    $s = row('SELECT * FROM superadmins WHERE usuario = ?', [$usuario]);
    if ($s && password_verify($password, $s['password'])) {
        session_regenerate_id(true);
        $_SESSION = ['sid' => (int) $s['id']];
        return 'super';
    }
    $u = row('SELECT * FROM usuarios WHERE usuario = ? AND activo = 1', [$usuario]);
    if ($u && password_verify($password, $u['password'])) {
        session_regenerate_id(true);
        $_SESSION = ['uid' => (int) $u['id']];
        update('usuarios', ['ultimo_login' => now()], 'id = ?', [$u['id']]);
        return 'user';
    }
    return false;
}

function logout()
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
