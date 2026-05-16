# Feature Research: Deferred Items

## Item 1: empresa.php → MailService

**Current state:**
- `MailService` (`src/Core/MailService.php`, 490 lines) fully implements SMTP config, send, and test
- `empresa.php` has duplicate mail logic at lines 460-504 (`new_mail()`, `mail_connect()`)
- `empresa::new_mail()` reads from its own `email_config` property (loaded from fs_var)
- `empresa::mail_connect()` calls PHPMailer's `SMTPConnect()` directly

**Target behavior:**
- `empresa` delegates to `MailService` for all mail operations
- Remove duplicate PHPMailer config logic from `empresa`
- Preserve backward compatibility: `empresa->new_mail()` still works but delegates
- `empresa->mail_connect()` uses `MailService::testConnection()`

**Potential callers to update:**
- Any code calling `$empresa->new_mail()` or `$empresa->mail_connect()`
- These are in plugins (facturacion_base, clientes_core, etc.)

## Item 2: fs_mysql Decomposition

**Current state:**
- `base/fs_mysql.php`: 1577 lines, 68 methods, monolithic class
- Schema methods interleaved with connection, query, and transaction methods
- Clear groupings identified:

| Group | Methods | Lines approx |
|-------|---------|-------------|
| Schema comparison | `compare_columns`, `compare_constraints`, helpers | ~300 |
| Table generation | `generate_table`, `validate_fk_constraints`, collation | ~200 |
| Type normalization | `convert_pg_type`, `normalize_mysql_default`, `compare_data_types`, etc. | ~200 |
| FK utilities | `get_fk_column_names`, `get_fk_column_collations`, helper methods | ~100 |
| DB introspection | `get_columns`, `get_constraints`, `get_indexes`, `list_tables` | ~150 |
| Core operations | `connect`, `select`, `exec`, transactions, etc. | ~600 |

**Target behavior:**
- Schema operations extracted to `src/Database/SchemaManager.php` or similar
- Type normalization to `src/Database/TypeNormalizer.php`
- `fs_mysql` keeps core DB operations, delegates schema work
- Backward compatible: no change to public API

**Precedent:** Phase 3 of v0.10.8 decomposed `StealthMode` into `CssSanitizer` + `HtmlSanitizer`

**Complexity note:** HIGH — schema methods have deep interdependencies (e.g., `compare_columns` calls `buildAddColumnSql`, `buildTypeChangeSql`, etc., which need access to `fs_mysql` internals like escaping and quoting). Requires careful interface design.

## Item 3: Plugin Management Extraction

**Current state:**
- `admin_home.php`: 1053 lines, ~150 plugin-related lines
- Plugin methods: `install_system_updater()` (86 lines + 4 helpers), `install_plugin()` (70 lines), `download_plugin()` (60 lines), `enable_plugin()` / `disable_plugin()` / `delete_plugin()` (each 3-5 lines, all delegate to `fs_plugin_manager`), `restore_plugin_backup()` (12 lines)
- Action router: `exec_actions()` (110 lines) — maps GET/POST params to methods
- `fs_plugin_manager` already handles core operations

**Opportunity:**
- Extract plugin HTTP actions into `PluginActionController` or `PluginInstaller`
- `admin_home` becomes a thin delegator for plugin actions
- The `install_system_updater` logic (GitHub download, 4 private helpers) is the most value to extract

**Table stakes:** Separate plugin install/update/remove concerns from admin dashboard
**Anti-pattern:** Over-extracting — some methods are 3-line delegations, not worth their own class

## Item 4: Fix 5 Test Failures

**Failures identified:**

| # | Test | Type | Root Cause |
|---|------|------|------------|
| 1 | `SessionManagerTest::testResolveCookiePathUsesCurrentInstallationPath` | Failure | Environment mismatch: expects `/fs-framework/`, gets `/var/www/html/` in ddev |
| 2 | `DataSrcRepositoryTest::testClearOnEmptyCacheDoesNotError` | Failure | Test isolation: `TestDataSrc::$testData` static leaks between tests in same class |
| 3 | `ResourceTransformerTest::testFilterWritableFieldsAcceptsApiAlias` | Failure | Needs investigation |
| 4 | `ResourceTransformerTest::testValidateWritableFieldsExecutesSymfonyConstraints` | Failure | Needs investigation |
| 5 | `DebugBarTest::testAddQueryStoresSqlStatements` | Error (not Failure) | Needs investigation |

**Target behavior:**
- All 5 tests pass (or are properly skipped with reason)
- No test isolation issues (static state reset)
- Environment-agnostic tests (don't depend on filesystem path)
