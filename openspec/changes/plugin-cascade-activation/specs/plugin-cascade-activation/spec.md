# Spec: plugin-cascade-activation

## ADDED Requirements

### Requirement: Topological activation order

The system MUST activate plugin dependencies before the requested plugin, in an order that satisfies all `require` fields from each plugin's `fsframework.ini`.

#### Scenario: Transitive dependencies

- **GIVEN** `tpvmod` requires `clientes_facturacion,catalogo_core,business_data,clientes_core`
- **AND** `business_data` requires `catalogo_core`
- **AND** all plugins exist under `plugins/` but none are enabled
- **WHEN** `enable('tpvmod')` is called
- **THEN** plugins are enabled in an order where every dependency appears before its dependents
- **AND** `catalogo_core` is enabled before `business_data`
- **AND** `tpvmod` is enabled last

#### Scenario: Already active dependency skipped

- **GIVEN** `catalogo_core` is already enabled
- **WHEN** `enable('business_data')` is called
- **THEN** `catalogo_core` is not re-enabled
- **AND** `business_data` is enabled successfully

### Requirement: Circular dependency detection

The system MUST reject activation when the dependency graph contains a cycle.

#### Scenario: Cycle A→B→A

- **GIVEN** plugin A requires B and plugin B requires A (test fixtures)
- **WHEN** `enable('A')` is called
- **THEN** activation fails
- **AND** neither A nor B is newly enabled
- **AND** an error message identifies the cycle

### Requirement: Local-only activation without system_updater

The cascade activation MUST work when `system_updater` is not installed or not enabled, provided all required plugins exist on disk.

#### Scenario: All deps local, no updater

- **GIVEN** `system_updater` is not present under `plugins/`
- **AND** all required plugins for `clientes_facturacion` exist locally
- **WHEN** `enable('clientes_facturacion')` is called
- **THEN** `clientes_core` is enabled first
- **AND** `clientes_facturacion` is enabled successfully

### Requirement: Unsatisfiable dependency blocks activation

The system MUST NOT activate the target plugin if a required dependency is neither installed locally nor installable via the registered `PluginInstallProvider`.

#### Scenario: Missing private dependency

- **GIVEN** plugin X requires `api_base`
- **AND** `api_base` is not installed locally
- **AND** `api_base` is not in the public catalog
- **WHEN** `enable('X')` is called
- **THEN** activation fails with a clear error naming `api_base`

#### Scenario: Local private dependency satisfies requirement

- **GIVEN** plugin X requires `api_base`
- **AND** `api_base` exists under `plugins/api_base/`
- **WHEN** `enable('X')` is called
- **THEN** `api_base` is enabled before X
- **AND** X is enabled successfully

### Requirement: All enable entry points use orchestrator

Every code path that activates a plugin via `fs_plugin_manager::enable()` MUST use the orchestrator.

#### Scenario: admin_home enable checkbox

- **WHEN** user enables a plugin from admin home
- **THEN** cascade rules apply identically to store download+enable
