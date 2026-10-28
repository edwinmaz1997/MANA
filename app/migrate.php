<?php
/* Migraciones automáticas de base de datos (se aplican solas al cargar el sistema) */
function migraciones()
{
    return [
        '2026_10_28_proxima_visita' => [
            "ALTER TABLE ventas ADD COLUMN proxima_visita DATE NULL COMMENT 'Fecha en que el cobrador debe regresar' AFTER ultimo_abono",
            "ALTER TABLE ruta_visitas ADD COLUMN reprogramar_para DATE NULL AFTER nota",
        ],
        '2026_10_28_unidades' => [
            "CREATE TABLE IF NOT EXISTS unidades (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, empresa_id INT UNSIGNED NOT NULL, nombre VARCHAR(40) NOT NULL, KEY (empresa_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "INSERT INTO unidades (empresa_id, nombre) SELECT DISTINCT empresa_id, unidad FROM productos WHERE unidad <> ''",
        ],
    ];
}

function migrar()
{
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS migraciones (id VARCHAR(80) PRIMARY KEY, aplicada DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $hechas = array_flip(db()->query('SELECT id FROM migraciones')->fetchAll(PDO::FETCH_COLUMN));
        foreach (migraciones() as $id => $sqls) {
            if (isset($hechas[$id])) continue;
            foreach ($sqls as $sql) {
                try { db()->exec($sql); }
                catch (PDOException $e) { if (strpos($e->getMessage(), 'Duplicate column') === false && strpos($e->getMessage(), 'Duplicate key') === false && strpos($e->getMessage(), 'already exists') === false) throw $e; }
            }
            q('INSERT INTO migraciones (id) VALUES (?)', [$id]);
        }
    } catch (Throwable $e) {
        error_log('[MANA migraciones] ' . $e->getMessage());
    }
}
