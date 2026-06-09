# Backup Progress via SSE Specification

## Purpose

Define the real-time backup progress reporting system using Server-Sent Events (SSE), replacing the previous worker+polling architecture. The system streams backup progress from `process_backup.php` to the browser via `EventSource`, matching the proven pattern used by `process_core_update.php` and `process_restore.php`.

## Requirements

### Requirement: SSE Stream Initialization

The system MUST initialize the SSE stream using `system_updater_process_init()` with mode `sse` and progress prefix `fs_backup`.

#### Scenario: Happy path initialization

- GIVEN a valid authenticated admin session exists
- WHEN `process_backup.php?action=start` is requested
- THEN the system calls `system_updater_process_init(['mode' => 'sse', 'progress_prefix' => 'fs_backup'])`
- AND SSE headers are set (Content-Type: text/event-stream, Cache-Control: no-cache, Connection: keep-alive, X-Accel-Buffering: no)
- AND output buffering is disabled
- AND CSRF validation passes via `ensure_request_csrf()`

#### Scenario: Missing config.php

- GIVEN `config.php` does not exist in FS_FOLDER
- WHEN `process_backup.php` is loaded
- THEN the system sends an SSE error event with message "Error: No se encuentra el archivo config.php."
- AND the connection closes

#### Scenario: Invalid CSRF token

- GIVEN the request includes an invalid or missing `_csrf_token`
- WHEN `process_backup.php?action=start` is requested
- THEN the system responds with HTTP 403
- AND no backup is started

### Requirement: Backup Execution via SSE

The system MUST execute the backup inline within the SSE connection, sending progress events in real time.

#### Scenario: Backup completes successfully

- GIVEN SSE stream is initialized
- WHEN `action=start` triggers the backup
- THEN the system sends a `start` event with initial message and percent 0
- AND the system sends `progress` events for each backup step via `system_updater_send_sse()`
- AND the system sends a `complete` event with success message, percent 100, backup_name, and redirect URL
- AND the progress file is deleted after completion

#### Scenario: Backup fails with backup_manager error

- GIVEN the backup_manager encounters an error during backup
- WHEN the error is detected
- THEN the system sends an `error` event with the error message
- AND the progress file is deleted

#### Scenario: PHP exception during backup

- GIVEN a Throwable is thrown during backup execution
- WHEN the exception is caught
- THEN the system sends an `error` event with "Excepción: {message}"
- AND the progress file is deleted

### Requirement: Keepalive Mechanism

The system MUST send keepalive pings to prevent nginx/fastcgi timeout during long operations.

#### Scenario: Keepalive sent during backup

- GIVEN an active SSE connection during backup
- WHEN 10 seconds pass without a progress event
- THEN the system sends an SSE comment `:keepalive\n\n`
- AND the connection remains open

#### Scenario: Keepalive does not interfere with progress

- GIVEN keepalive timer is active
- WHEN a progress event is ready to send
- THEN the progress event is sent normally
- AND the keepalive timer resets

### Requirement: Frontend EventSource Integration

The frontend MUST use `EventSource` to receive backup progress, replacing the current `setInterval` polling.

#### Scenario: Browser connects to backup SSE

- GIVEN the user clicks "Crear Copia de Seguridad"
- WHEN the backup starts
- THEN the frontend creates an `EventSource` pointing to `process_backup.php?action=start`
- AND listens for `start`, `progress`, `complete`, and `error` events
- AND updates the progress bar from event data

#### Scenario: Progress events update UI

- GIVEN an active EventSource connection
- WHEN a `progress` event is received
- THEN the progress bar width updates to the event's percent value
- AND the status text updates to the event's message
- AND the log entry is added to the details panel

#### Scenario: Completion event closes connection

- GIVEN an active EventSource connection
- WHEN a `complete` event is received
- THEN the progress bar shows 100% with success styling
- AND the success message is displayed
- AND the EventSource connection is closed
- AND a "Continue" button appears with the redirect URL

#### Scenario: Error event closes connection

- GIVEN an active EventSource connection
- WHEN an `error` event is received
- THEN the progress bar shows error styling
- AND the error message is displayed
- AND the EventSource connection is closed
- AND an error button appears

#### Scenario: Connection lost unexpectedly

- GIVEN an active EventSource connection
- WHEN the connection drops (onerror handler)
- THEN the frontend shows "Error de conexión con el servidor"
- AND the EventSource connection is closed
- AND the error button appears

### Requirement: Elimination of Worker Machinery

The system MUST remove all worker, queue, recovery, lock, and polling machinery.

#### Scenario: Worker functions removed

- GIVEN the refactored process_backup.php
- WHEN the file is loaded
- THEN `launch_cli_worker()` does not exist
- AND `recover_queued_job()` does not exist
- AND `should_attempt_queue_recovery()` does not exist
- AND `has_active_job()` does not exist

#### Scenario: Temp file operations removed

- GIVEN the refactored process_backup.php
- WHEN the file is loaded
- THEN no `/tmp/` progress files are created by the worker
- AND no `/tmp/` pointer files are created
- AND no `/tmp/` lock files are created
- AND only the bootstrap progress file (from `system_updater_process_init`) is used

#### Scenario: Response helpers removed

- GIVEN the refactored process_backup.php
- WHEN the file is loaded
- THEN `respond_json()` does not exist
- AND `respond_and_continue()` does not exist

#### Scenario: Worker actions removed

- GIVEN the refactored process_backup.php
- WHEN `action=worker` is requested
- THEN the system responds with an error (action not valid)
- WHEN `action=progress` is requested
- THEN the system responds with an error (action not valid)
- WHEN `action=status` is requested
- THEN the system responds with an error (action not valid)
- WHEN `action=cleanup` is requested
- THEN the system responds with an error (action not valid)

### Requirement: Error Handling

The system MUST handle all error conditions gracefully via SSE events.

#### Scenario: Missing backup_manager.php

- GIVEN `lib/backup_manager.php` does not exist
- WHEN backup is attempted
- THEN an SSE error event is sent with message "Error: No se encuentra el plugin system_updater."

#### Scenario: PHP error during execution

- GIVEN a PHP error occurs during backup
- WHEN the error is triggered
- THEN the system catches it and sends an SSE error event
- AND the connection closes cleanly
