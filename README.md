# MANA — Ventas al crédito, rutas y cobros

Sistema multiempresa en **PHP 7.4+ y MySQL**, responsive, pensado para cPanel (sin Node ni Composer en el servidor).

## Instalación (primera vez)

1. Crea una base de datos MySQL y su usuario en cPanel.
2. Abre `https://TU-DOMINIO/install.php`, ingresa los datos de la BD y crea el usuario **superadmin**.
3. Entra con el superadmin → **Empresas → Nueva empresa** (crea también su usuario administrador).
4. Borra `install.php` del servidor.

> El instalador también sirve para aplicar cambios de esquema: si ya está instalado, solo verifica/crea tablas faltantes.

## Despliegue automático (GitHub Actions → FTPS)

| Rama         | Destino en el servidor |
|--------------|------------------------|
| `produccion` | raíz del FTP (`./`)    |
| `dev`        | carpeta `./dev/`       |

Requiere el secret `FTP_PASSWORD`. `config.php` y `uploads/` nunca se sobrescriben (no están en el repositorio).
Cada carpeta (`/` y `/dev/`) necesita su propio `config.php` (corre `install.php` en cada una, idealmente con BD distinta).

## Roles

- **Superadmin**: crea empresas, planes y membresías; renueva, suspende y puede entrar a cualquier empresa.
- **Admin**: ve y administra todo en su empresa.
- **Vendedor**: vende (contado y crédito); gana la comisión configurada en cada producto.
- **Cobrador**: cobra en su ruta y también vende; gana % sobre lo cobrado (13% por defecto) y ve cuánto debe entregar.

## Reglas de crédito

- Precio contado = precio compra + descuento comisión (editable). Precio crédito se ingresa manual.
- Enganche: si no se paga al vender, la venta queda **pendiente de enganche** y el calendario se genera cuando se cobra.
- Primer abono: 1 mes después del enganche (el vendedor puede cambiar la fecha).
- Semáforo: 🟢 al día · 🟡 1–30 días de atraso o enganche pendiente · 🔴 +30 días o **recoger producto** (sin abonos por 90 días, configurable).
- Rutas: se generan automáticamente con las cuotas vencidas, enganches pendientes y visitas atrasadas del día anterior.

## Estilos

Ver `build/README.md` (Tailwind compilado a `assets/styles.css`).
