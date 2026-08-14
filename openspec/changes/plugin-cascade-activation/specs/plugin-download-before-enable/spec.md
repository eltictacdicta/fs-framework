# Delta: Descarga antes de activación

## Requirements

### MUST

- El orquestador **MUST** exponer `inspectActivation()` con plan, missing y pending_activation.
- `downloadPlugin()` **MUST** instalar un solo plugin vía `PluginInstallProvider` sin activar.
- `enablePluginStep()` **MUST** activar un plugin del plan solo si sus dependencias previas ya están activas.
- `enable()` **MUST** seguir descargando faltantes y activando en cascada (compatibilidad).

### SHOULD

- La tienda **SHOULD** separar descarga y activación (flujo WordPress).
