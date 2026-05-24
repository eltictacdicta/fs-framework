---
status: complete
quick_id: 260524-fvg
---

# Quick Task: Revisar fallo Twig CoercesChildrenToStringInterface en /account

## Diagnóstico

Error en logs DDEV al acceder a `GET /account`:

```
Router Error: Interface "Twig\Node\CoercesChildrenToStringInterface" not found
```

Tras reinstalar Twig, apareció un error secundario en `/oauth/login`:

```
Class "Symfony\Component\Yaml\ParserState" not found
```

## Causa raíz

Instalación parcial/corrupta de paquetes en `vendor/`:
- `twig/twig` v3.26.0 tenía nodos que implementan `CoercesChildrenToStringInterface` pero faltaba el archivo de la interfaz.
- `symfony/yaml` v7.4.12 tenía `Parser.php` actualizado pero faltaba `ParserState.php`.

Probable origen: actualización de dependencias interrumpida o mezcla de archivos de distintas versiones (timestamps 2026-05-24 11:15).

## Tareas

### Task 1: Reinstalar paquetes afectados

**Action:** Ejecutar `composer reinstall twig/twig symfony/yaml` vía ddev.

**Verify:**
- Existe `vendor/twig/twig/src/Node/CoercesChildrenToStringInterface.php`
- Existe `vendor/symfony/yaml/ParserState.php`
- `GET /account` responde 302 (redirect a login)
- `GET /oauth/login` responde 200

**Done:** Endpoints operativos, sin errores en logs.
