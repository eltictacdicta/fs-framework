# Extending the admin `<head>` from a plugin

The global `<head>` is rendered by `themes/AdminLTE/view/header.html.twig`.
Core ships only first-party assets there. Third-party libraries that a plugin
needs are registered **by the plugin**, not by core.

## Why core no longer ships Chart.js

Chart.js (and bootstrap-select's scripts) used to be loaded from public CDNs as
plain `<script src="...">` tags inside `<head>`. Those tags are **parser-blocking**:
the HTML parser stops at each one and waits for the response.

When the CDN was slow or unreachable, parsing stalled before `<body>` was built.
The server had already returned `200`, but the admin UI never rendered — the
browser kept showing the previous page. Every admin page hung, which showed up in
E2E as:

```
waiting for getByRole('link', { name: 'User Image admin' })
Error: element(s) not found
```

The same HTML contained that link; it just never got parsed. Charts are not worth
a render-blocking third-party dependency, so the Chart.js tag was removed from core.

**Rule: never add a parser-blocking third-party `<script>` to the global header.**

## Hooks available in the header

| Global / slot | Rendered as | Use for |
| --- | --- | --- |
| `head_extra_css` | `<link rel="stylesheet">`, root-relative | plugin stylesheets |
| `head_extra_js` | `<script src>`, **synchronous**, root-relative | legacy assets that depend on load order (loads before `base.js`) |
| `head_extra_js_defer` | `<script src defer>`, root-relative path or absolute URL | non-critical libraries: charts, editors, maps |
| `fsc.extensions` with `type = 'head'` | raw HTML, verbatim | legacy escape hatch, see below |

`fsc.extensions` is fed from the legacy `fs_extensions2` DB table, not from plugin
code. It still works, but prefer the Twig globals for new plugins.

## Registering assets from a plugin

Listen to `TwigInitEvent` in the plugin `Init.php` — the same pattern used by
`plugins/legacy_support/Init.php`:

```php
<?php

declare(strict_types=1);

namespace FSFramework\Plugins\MyPlugin;

use FSFramework\Event\FSEventDispatcher;
use FSFramework\Event\TwigInitEvent;

class Init
{
    public function init(): void
    {
        $dispatcher = FSEventDispatcher::getInstance();

        $dispatcher->addListener(TwigInitEvent::NAME, function (TwigInitEvent $event): void {
            $twig = $event->getTwig();

            // Append, never replace: another plugin may have registered assets first.
            $existing = $twig->getGlobals()['head_extra_js_defer'] ?? [];

            $twig->addGlobal('head_extra_js_defer', [
                ...$existing,
                'plugins/MyPlugin/view/js/chart.umd.min.js',
            ]);
        });
    }
}
```

The hook is a single global, so registering it replaces it. Always read the
current value and prepend/append your assets, or the last plugin to run silently
drops the assets of the others.

## Restoring Chart.js

### Self-hosted (recommended)

No external dependency, no CDN outage, CSP already allows `'self'`:

```php
// Copy the library into the plugin: plugins/MyPlugin/view/js/chart.umd.min.js
$twig->addGlobal('head_extra_js_defer', [
    'plugins/MyPlugin/view/js/chart.umd.min.js',
]);
```

### From a CDN

`head_extra_js_defer` accepts absolute URLs. `script-src` already allows
`https://cdnjs.cloudflare.com` and `https://cdn.jsdelivr.net`:

```php
$twig->addGlobal('head_extra_js_defer', [
    'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.8.0/Chart.js',
]);
```

A different CDN requires adding its origin to `SCRIPT_SRC_DIRECTIVE` in
`src/Security/SecurityHeaders.php`.

### Using it

Because the tag is deferred, `Chart` is available by `DOMContentLoaded`. Do **not**
call `new Chart(...)` from an inline script that runs during parsing — wrap it:

```html
<script {{ csp_nonce_attr() }}>
  document.addEventListener('DOMContentLoaded', function () {
    new Chart(document.getElementById('sales'), { /* ... */ });
  });
</script>
```

The CSP nonce is mandatory: `script-src` has no `'unsafe-inline'`, so an inline
`<script>` without `csp_nonce_attr()` is blocked by the browser.

## bootstrap-select

bootstrap-select stays in core but its `<script>` tags are now `defer`-red. Its
only in-repo consumer is `view/js/ajax-loader.js`, which already guards the call
(`if ($.fn.selectpicker)`), so an unavailable library degrades to plain `<select>`
instead of throwing. If a plugin needs it and core ever drops it, register it with
`head_extra_css` + `head_extra_js_defer` the same way.

## Where the hooks live

- `themes/AdminLTE/view/header.html.twig` — `head_extra_css`, `head_extra_js`,
  `head_extra_js_defer` loops and the `fsc.extensions` head slot.
- `src/Event/TwigInitEvent.php` — event contract.
- `src/Security/SecurityHeaders.php` — CSP `script-src` / `style-src` allowlist.
- `plugins/legacy_support/Init.php` — reference implementation of a plugin
  registering head assets.

## Contract tests

`tests/Core/FsDatepickerMigrationContractTest.php` pins the shape of these hooks
(the `head_extra_css` / `head_extra_js` loops and their ordering before `base.js`).
Update it if you rename or reorder them.
