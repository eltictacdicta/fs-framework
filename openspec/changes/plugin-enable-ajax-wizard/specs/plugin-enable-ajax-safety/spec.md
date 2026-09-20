# Spec: plugin-enable-ajax-safety

## ADDED Requirements

### Requirement: AJAX enable requests never receive a redirect

When a plugin activation request is identified as AJAX, the system MUST NOT emit an HTTP redirect (`Location` header / 3xx status). The response MUST be a JSON payload, even when the enabled plugin declares a wizard.

#### Scenario: Wizard plugin activated via AJAX

- **GIVEN** plugin `factura_pdf1` declares `wizard = admin_factura_pdf1` in its `fsframework.ini`
- **AND** the request carries `X-Requested-With: XMLHttpRequest` and/or `ajax=1`
- **WHEN** the activation of `factura_pdf1` succeeds
- **THEN** the response status is not 3xx
- **AND** no `Location` header is emitted
- **AND** the response body is JSON
- **AND** the JSON includes `wizard` with value `admin_factura_pdf1` (page name and/or URL)

#### Scenario: Non-wizard plugin activated via AJAX

- **GIVEN** a plugin without a `wizard` declared in its `fsframework.ini`
- **AND** the request is AJAX
- **WHEN** the activation succeeds
- **THEN** the response remains JSON with `success: true`
- **AND** no `Location` header is emitted
- **AND** the `wizard` field is absent or null

### Requirement: Non-AJAX enable requests keep the redirect-to-wizard behavior

For non-AJAX callers, the existing behavior MUST be preserved: when the enabled plugin declares a wizard and the wizard run is requested, the response MUST redirect to `index.php?page={wizard}`.

#### Scenario: Wizard plugin activated via normal request

- **GIVEN** plugin `factura_pdf1` declares `wizard = admin_factura_pdf1`
- **AND** the request is a normal (non-AJAX) request
- **WHEN** the activation succeeds and the wizard run is requested
- **THEN** the response is an HTTP 302 redirect
- **AND** the `Location` header points to `index.php?page=admin_factura_pdf1`
- **AND** the activation remains persisted

### Requirement: Activation result is observable in the JSON response

The activation result MUST be conveyed to AJAX callers as structured data, so the caller can navigate to the wizard on the client side without a page-reload race.

#### Scenario: Store navigates after successful activation

- **GIVEN** the plugin store activates `factura_pdf1` through `action=activate_step&ajax=1`
- **WHEN** the activation succeeds
- **THEN** the JSON response includes the enabled plugin identifier and the `wizard` page name when present
- **AND** the store can navigate to the wizard URL derived from that response
- **AND** no HTTP redirect is followed by the AJAX transport

### Requirement: Role-permission gateway provides an explicit allow_delete value (plugin-local follow-up)

> Traceability note: this requirement is **plugin-local to `factura_pdf1`**, not core scope. It is recorded here because the same activation flow triggers it, and it is delivered under `plugins/factura_pdf1/openspec/` or as a small direct fix.

The role-permission gateway MUST construct `fs_rol_access` instances with an explicit `allow_delete` value so that no undefined-key warning is emitted during plugin activation.

#### Scenario: roleHasPageAccess without allow_delete key

- **GIVEN** `plugins/factura_pdf1/Services/LegacyRolePermissionsGateway.php::roleHasPageAccess()` constructs `new \fs_rol_access(['codrol' => ..., 'fs_page' => ...])`
- **AND** `model/fs_rol_access.php:40` reads `$data['allow_delete']`
- **WHEN** the gateway checks page access during activation
- **THEN** no PHP warning `Undefined array key "allow_delete"` is emitted
- **AND** the constructed access row uses `allow_delete = false` by default
