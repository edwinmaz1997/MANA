# MANA

Sistema en PHP 7.4 + MySQL.

## Despliegue automático (GitHub Actions → FTPS)

| Rama         | Destino en el servidor |
|--------------|------------------------|
| `produccion` | raíz del FTP (`./`)    |
| `dev`        | carpeta `./dev/`       |

Requiere el secret `FTP_PASSWORD` en **Settings → Secrets and variables → Actions**.
