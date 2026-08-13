# Design: Sonar Complexity Refactors

## Principio

Extracción mecánica de métodos privados/protegidos o funciones helper con **misma semántica**. Sin renombrar APIs públicas ni mover lógica entre capas.

## Patrones aplicados

1. **Guard clauses tempranas** — mantener flujo principal lineal tras extracción.
2. **Métodos `protected` en controladores legacy** — permiten tests con subclases anónimas sin boot completo.
3. **Helpers globales en `fs_functions.php`** — solo para lógica duplicada intra-función (`require_all_models`).
4. **Tests behavior-first** — assert sobre salida/efecto, no sobre estructura interna.

## Verificación

```bash
ddev exec php vendor/bin/phpunit --testsuite Base,Core,Security,Components
```

Tests nuevos fase 1:

- `tests/Core/AdminHomeUpdatesTest.php`
- Ampliación de `tests/Base/FsFunctionsTest.php` (`require_all_models` / helper)
- Ampliación de `tests/Base/FsMaintenanceModeTest.php` (stealth desde constantes)
