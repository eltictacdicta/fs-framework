---
description: How to run PHP commands in this project
---

# Running PHP

In this project, always use `ddev` to execute PHP scripts or commands.

## Usage

Instead of `php [script]`, use:

```bash
ddev exec php [script]
```

## Examples

Run a specific file:
```bash
ddev exec php test_script.php
```

Run a command with arguments:
```bash
ddev exec php -r "echo 'hello';"
```
