# Feature: user-unassigned-contacts

## Objective
En la vista de usuario "Mis Contactos" (`user_infrico_contacts`): ver los contactos sin evento (sin asignar), avisar al usuario con un contador/aviso, y poder asignarlos TODOS de golpe a un evento (programado o propio). Es la versión por-usuario del asistente de huérfanos que ya existe solo para admin.

## Problem
El panel admin (`admin_infrico_contacts.php`) ya cuenta, lista y asigna huérfanos globalmente (`load_orphan_stats`, `load_orphan_contacts`, `assign_orphans_to_event`). El usuario regular no tiene esa capacidad en su propia vista: solo ve el badge "Sin evento" en la tabla, sin aviso ni acción de asignación masiva.

## Why
Los contactos se crean sin evento por error (no se seleccionó evento en la app). El usuario debe poder detectarlos y reasignarlos desde el servidor sin depender de un admin.

## Scope
- `plugins/api_auth/controller/user_infrico_contacts.php`
- `plugins/api_auth/view/user_infrico_contacts.html.twig`
- `plugins/api_auth/tests/UserContactsUnassignedAssignTest.php` (NUEVO)

## Constraints
- Todo debe estar SIEMPRE acotado al usuario actual (`$this->user->nick`). Nada global como en admin.
- El evento destino debe ser visible para el usuario: propio (mismo nick) o de un admin. Se valida contra la lista `assignable_eventos` (`infrico_evento::all_user_and_admin`).
- No tocar el flujo admin existente ni el modelo salvo lo indicado.
- Reusar `evento_contacto::add_contacto_to_evento()` y `validateUuid()`.
- No crear migraciones de esquema.

## Diseño (contrato exacto)

### Controlador `user_infrico_contacts.php`
1. Constante de filtro: `private const SIN_EVENTO_FILTER = 'sin_evento';`
2. Propiedades públicas nuevas: `public $filter_sin_evento; public $orphan_count; public $assignable_eventos;`
3. En `private_core()`:
   - Tras leer `filter_evento_uuid`: `$this->filter_sin_evento = ($this->filter_evento_uuid === self::SIN_EVENTO_FILTER);`
   - Tras `$this->load_all_eventos();`: llamar `$this->load_assignable_eventos();`
   - Tras `$this->load_stats();`: llamar `$this->load_orphan_stats();`
4. `load_stats()`: cuando `filter_sin_evento` sea true, el filtro de eventos debe ser `AND NOT EXISTS (SELECT 1 FROM evento_contactos ec WHERE ec.contactoUuid = infrico_contacts.uuid)` (sin JOIN). El conteo de `total_contacts` debe usar la consulta filtrada (no `count_by_user`) cuando `filter_sin_evento`.
5. `load_contacts()`: si `filter_sin_evento`, llamar `load_contacts_sin_evento()` y retornar.
6. Nuevo `load_contacts_sin_evento()`: mismo patrón que `load_contacts_by_evento()` pero con
   `SELECT DISTINCT ic.* FROM infrico_contacts ic WHERE ic.userNick = <nick> AND ic.deletedAt IS NULL AND NOT EXISTS (SELECT 1 FROM evento_contactos ec WHERE ec.contactoUuid = ic.uuid)` + mismos filtros de búsqueda/fecha + `ORDER BY ic.createdAt DESC` + mismo enriquecimiento (requirements, requirements_by_event, eventos, eventos_texto, campos CRM).
7. Nuevo `load_orphan_stats()`: `orphan_count` = COUNT(DISTINCT ic.uuid) de los contactos del usuario con `deletedAt IS NULL` y `NOT EXISTS (SELECT 1 FROM evento_contactos ec WHERE ec.contactoUuid = ic.uuid)`.
8. Nuevo `load_assignable_eventos()`: `$this->assignable_eventos = (new infrico_evento())->all_user_and_admin($this->user->nick, 0, 500);`
9. `handle_action()`: nuevo `case 'assign_unassigned': $this->assign_unassigned_to_event(); break;`
10. Nuevo `assign_unassigned_to_event()`:
    - Lee `evento_uuid` de POST, valida con `validateUuid`; si inválido → `new_error_msg` + `header('Location: ' . $this->url())` + exit.
    - Verifica que `evento_uuid` esté en `$this->assignable_eventos`; si no → `new_error_msg('No tienes permisos para usar este evento.')` + redirect + exit.
    - Obtiene los contactos sin evento del usuario (mismo SELECT que `load_orphan_stats` pero con uuid).
    - `add_contacto_to_evento($evento_uuid, $uuid)` por cada uno; cuenta asignados.
    - Actualiza requerimientos huérfanos DEL USUARIO: `UPDATE contact_requirements SET eventoUuid = <uuid> WHERE eventoUuid IS NULL AND contactUuid IN (SELECT uuid FROM infrico_contacts WHERE userNick = <nick>)`.
    - `new_message("... N contacto(s) asignado(s) al evento ...", TRUE)` + redirect a `$this->url()` + exit.

### Vista `user_infrico_contacts.html.twig`
1. En el `<select name="evento_uuid">` del filtro, agregar tras "-- Todos los eventos --":
   `<option value="sin_evento" {% if fsc.filter_evento_uuid == 'sin_evento' %}selected{% endif %}>⚠️ Sin evento (sin asignar)</option>`
2. Aviso: insertar un bloque `{% if fsc.orphan_count and fsc.orphan_count > 0 %}` (row + alert-warning) justo después del bloque de estadísticas y antes de `<!-- Acciones masivas -->`:
   - Texto con `{{ fsc.orphan_count }}` contactos sin evento.
   - Enlace "Ver contactos sin evento" → `{{ fsc.url()|raw }}&evento_uuid=sin_evento`.
   - Form POST a `{{ fsc.url()|raw }}&action=assign_unassigned` con `<select name="evento_uuid" required>` poblado con `fsc.assignable_eventos` y botón "Asignar todos a este evento" (con `onsubmit` de confirmación).
   - Si `fsc.assignable_eventos|length == 0`: mostrar aviso de que no hay eventos disponibles.

### Test `plugins/api_auth/tests/UserContactsUnassignedAssignTest.php`
Análisis estático de fuente (mismo patrón que `AdminApkUploadPathsTest` / `AdminAuthorityGuardsTest`: el controlador no es instanciable bajo el harness unit). Debe fijar las invariantes de seguridad:
- `assign_unassigned_to_event` está acotado al usuario: su cuerpo contiene `$this->user->nick` en el SELECT de contactos y en el UPDATE de requerimientos, y NO contiene un UPDATE global sin scoping.
- El evento destino se valida contra `assignable_eventos`.
- `handle_action` mapea `assign_unassigned`.
- El controlador define el filtro `sin_evento` (`filter_sin_evento`).
- La vista contiene `action=assign_unassigned`, `value="sin_evento"` y `fsc.orphan_count`.

## Checklist
- [ ] T1: controlador — filtro `sin_evento` (stats + load_contacts_sin_evento).
- [ ] T2: controlador — `load_orphan_stats`, `load_assignable_eventos`, props.
- [ ] T3: controlador — acción `assign_unassigned` + `assign_unassigned_to_event` + handle_action.
- [ ] T4: vista — opción de filtro + aviso con asignación masiva.
- [ ] T5: test de invariantes + `php -l` + phpunit verde.

## Acceptance criteria
- El usuario ve un aviso con el conteo de sus contactos sin evento.
- Puede filtrar por "Sin evento" y ver solo esos contactos.
- Puede asignar TODOS sus contactos sin evento a un evento elegido (propio o programado/admin), y sus requerimientos huérfanos pasan a ese evento.
- Ninguna operación toca contactos/requerimientos de otros usuarios.
- `phpunit --testsuite Plugins --filter UserContactsUnassignedAssign` verde; `php -l` sin errores.

## Progress
- 2026-10-10: doc creado; rama feat/user-unassigned-contacts.
