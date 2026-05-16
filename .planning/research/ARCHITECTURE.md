# Architecture Research: Deferred Items Integration

## Integration Overview

All 4 items modify existing components without adding new architectural patterns.

## Item 1: MailService Integration

**Current architecture:**
```
empresa.php → PHPMailer (direct instantiation)
MailService.php → PHPMailer (via createMailer/send)
```

**Target architecture:**
```
empresa.php → MailService → PHPMailer
```

**Integration points:**
- `empresa::new_mail()` → delegates to `MailService::createMailer()`
- `empresa::mail_connect()` → delegates to `MailService::testConnection()`
- `empresa::can_send_mail()` → delegates to `MailService::canSendMail()`
- `empresa::$email_config` → harmonized with `MailService::getConfig()`

**Risk:** `empresa` has its own email_config loading (separate from MailService config). Need to ensure both read from same `fs_var` keys.

## Item 2: fs_mysql Schema Decomposition

**Current architecture:**
```
fs_mysql (1577 lines, monolithic)
  ├── Core DB: connect, select, exec, transactions
  ├── Schema: compare_columns, generate_table, get_columns/constraints/indexes
  ├── Type normalization: convert_pg_type, normalize_mysql_default, compare_data_types
  └── FK: get_fk_column_names, validate_fk_constraints
```

**Target architecture (2-3 new classes):**
```
src/Database/
  ├── SchemaComparator.php  — compare_columns, compare_constraints + helpers
  ├── TypeNormalizer.php     — convert_pg_type, normalize_mysql_default, compare_data_types
  └── (SchemaManager kept if no separate class needed for generate_table)
```

**Critical interface:**
- Schema methods call `$this->select()`, `$this->exec()`, `$this->escape_string()`, `$this->quoteIdentifier()`, `$this->quoteStringLiteral()` on the fs_mysql instance
- These methods use `self::$link`, `self::$core_log` static state — can't just extract to standalone objects
- Solution: Pass `fs_mysql` as a dependency, or extract static helpers first, or use a trait

**Build order recommendation:**
1. Extract pure functions first (no fs_mysql dependency): `convert_pg_type`, `normalize_mysql_default`, `compare_data_types`, `extract_type_info`
2. Extract schema methods that only need read access (get_columns, get_constraints, get_indexes)
3. Extract mutation methods (compare_columns, compare_constraints, generate_table)
4. Keep core connection/transaction methods in fs_mysql

## Item 3: Plugin Management Extraction

**Current architecture:**
```
admin_home (1053 lines)
  ├── Dashboard logic (~500 lines)
  ├── Plugin HTTP actions (exec_actions router + methods, ~150 lines)
  ├── System updater installer (~86 lines)
  └── Page management (~100 lines)
```

**Target architecture:**
```
admin_home → delegate plugin actions to PluginController
src/Controller/
  └── PluginController.php (or PluginActionHandler)
      ├── handleDownload()
      ├── handleInstall()
      ├── handleDelete()
      ├── handleBackup()
      └── installSystemUpdater()
```

**Integration:** `admin_home::exec_actions()` maps GET params to methods on the new controller instead of private methods. `admin_home` keeps dashboard + page management logic.

**Minimal approach:** Extract only the `install_system_updater` + its 4 private helpers (86 lines — the most self-contained non-trivial logic). Keep 3-line delegations in admin_home.

## Item 4: Test Fixes

**Test Environment:**
- PHPUnit 11, DDEV, PHP 8.3
- Bootstrap: `tests/bootstrap.php` defines constants
- No real database connection needed for failing tests

**Fix approaches:**
1. SessionManagerTest path issue: Make test use current working dir or mock the path resolution
2. DataSrcRepositoryTest isolation: Reset `TestDataSrc::$testData` in `tearDown()`
3. ResourceTransformerTest (2 failures): Needs investigation of the actual assertions
4. DebugBarTest (1 error): Needs investigation
