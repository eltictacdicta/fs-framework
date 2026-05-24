---
status: complete
quick_id: 260524-fvg
date: 2026-05-24
---

# Quick Task 260524-fvg — Summary

## Resultado

Fallo resuelto. No se requirieron cambios en código fuente.

## Causa

Vendor corrupto: archivos nuevos de `twig/twig` v3.26.0 y `symfony/yaml` v7.4.12 sin sus dependencias internas (interfaz `CoercesChildrenToStringInterface`, clase `ParserState`).

## Remediación aplicada

```bash
ddev exec composer reinstall twig/twig --no-interaction
ddev exec composer reinstall symfony/yaml --no-interaction
```

## Verificación

| Check | Resultado |
|-------|-----------|
| `interface_exists('Twig\Node\CoercesChildrenToStringInterface')` | true |
| `vendor/symfony/yaml/ParserState.php` | presente |
| `GET /account` | 302 → `/oauth/login?return_to=%2Faccount` |
| `GET /oauth/login` | 200 |
| `StealthModeTest` (7 tests) | OK |

## Recomendación

Si vuelve a ocurrir, ejecutar reinstalación completa:

```bash
rm -rf vendor && ddev exec composer install --no-interaction
```

## Commits de código

Ninguno (`vendor/` está en `.gitignore`).
