# list-search-security Specification

## Purpose

Seguridad del escaping en búsquedas de list controllers. Prevenir SQL injection en la interpolación de términos de búsqueda del usuario en cláusulas LIKE.

## Requirements

| ID | Requirement | Strength |
|----|-------------|----------|
| LSS-01 | Las queries de búsqueda del usuario **MUST** escaparse con `$this->db->escape_string()` antes de interpolarse en cláusulas SQL LIKE | MUST |
| LSS-02 | HTML escaping (`htmlspecialchars`) **MUST NOT** usarse para contexto SQL | MUST |

### Scenario: Normal search term returns matching rows

- **GIVEN** a list controller with search columns configured
- **WHEN** the user submits a search query containing only alphanumeric characters (e.g. "juan")
- **THEN** the query is escaped via `escape_string()` and interpolated into `LIKE '%juan%'`
- **AND** matching rows are returned identically to current behavior

### Scenario: Search term with single quote is safely escaped

- **GIVEN** a list controller with search columns configured
- **WHEN** the user submits a search query containing a single quote (e.g. `O'Brien`)
- **THEN** `escape_string()` escapes the quote (e.g. `O\'Brien` or `O''Brien` per driver)
- **AND** the SQL query executes without error
- **AND** no SQL injection occurs

### Scenario: Search term with HTML entities is handled correctly

- **GIVEN** a list controller with search columns configured
- **WHEN** the user submits a search query containing HTML characters (e.g. `<script>`)
- **THEN** the characters are passed through `escape_string()` (not `htmlspecialchars`)
- **AND** the LIKE match operates on the raw characters, not HTML entities
- **AND** no XSS is introduced (output escaping remains the view's responsibility)
