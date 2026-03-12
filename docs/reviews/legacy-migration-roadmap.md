# Legacy Migration Roadmap

## Objetivo
Reducir progresivamente la dependencia de rutas, APIs y capas legacy hasta su retirada planificada en **v3.0**, minimizando riesgo operativo mediante telemetría y criterios de salida objetivos.

## Métricas de adopción (fuente: telemetría legacy)
- **route_hits**: total de accesos a endpoints legacy (`index.php?page=...` / controladores legacy).
- **component_hits**: uso de componentes legacy (loader/translator RainTPL, alias API legacy).
- **unique_routes**: número de endpoints legacy distintos aún activos.
- **unique_components**: número de componentes legacy con uso real.

## Fases

### Fase 0 — Instrumentación y baseline (completada)
- Activar contadores por endpoint/componente legacy en `legacy_support`.
- Exponer panel resumido en `admin_info`.
- Publicar mensajes de deprecación con objetivo de retirada en v3.0.

**Criterio de salida:**
- 100% de instalaciones con telemetría disponible y visible en admin.
- Baseline de 30 días para identificar top legacy.

### Fase 1 — Migración de alto impacto
- Priorizar top-10 endpoints/componentes por volumen de hits.
- Migrar vistas RainTPL (`.html`) a Twig (`.html.twig`).
- Sustituir alias legacy de API helper por métodos modernos.

**Criterio de salida:**
- `route_hits` legacy reducido al menos **50%** vs baseline.
- `component_hits` legacy reducido al menos **40%** vs baseline.
- `unique_components <= 5`.

### Fase 2 — Congelación de superficie legacy
- No introducir nuevos endpoints/clases legacy.
- Aumentar severidad de avisos deprecados (observabilidad y QA).
- Completar migración de plugins internos restantes.

**Criterio de salida:**
- `unique_routes <= 10`.
- `component_hits` de conversión RainTPL residual (<10% del total de render legacy).

### Fase 3 — Retirada controlada (objetivo v3.0)
- Eliminar aliases legacy de API y capas de compatibilidad no usadas.
- Retirar fallback RainTPL y rutas legacy inactivas.
- Mantener guía de contingencia para rollback menor.

**Criterio de salida:**
- `route_hits = 0` durante 2 ciclos de release.
- `component_hits = 0` durante 2 ciclos de release.
- Sin incidencias críticas atribuibles a migración durante 30 días.

## Reglas de gobernanza
- Toda API/clase legacy nueva requiere excepción formal + fecha de retirada.
- Cada PR de migración debe incluir impacto en métricas de adopción.
- Revisiones quincenales de top legacy en panel de admin.

## Señales de riesgo
- Persistencia de `unique_routes` sin descenso por 2 iteraciones.
- Concentración >30% en un único endpoint legacy crítico.
- Incremento semanal sostenido de `component_hits` legacy.
