<?php

declare(strict_types=1);

namespace FSFramework\Controller;

/**
 * HTMX-first fragment controller base (HCS-13, HCS-14, HCS-15, CRD-01).
 *
 * Extends \fs_controller (NOT final — legacy + PSR-4 children).
 * Provides buildFragment() as a pure unit-testable core, plus render* methods
 * that emit responses via template=false + echo — zero index.php changes.
 */
class HtmxCrudController extends \fs_controller
{
    protected HtmxCrudConfig $crud;

    /**
     * Fluent config root for the CRUD view.
     */
    protected function crud(string $view): HtmxCrudConfig
    {
        if (!isset($this->crud)) {
            $this->crud = new HtmxCrudConfig();
        }
        return $this->crud;
    }

    /**
     * Returns true when the request carries the HX-Request header.
     * Callers use this to decide between fragment and full-page (PRG) paths.
     */
    protected function requireHtmx(): bool
    {
        return $this->isHtmxRequest();
    }

    /**
     * URL for the no-htmx PRG fallback redirect.
     * Subclasses override to append persistent list params (e.g. codtarifa).
     */
    protected function listUrl(): string
    {
        return $this->url();
    }

    /**
     * Render a Twig partial via Html::render() — the seam for testing.
     * Subclasses or tests override this to inject fixture HTML.
     */
    protected function renderPartial(string $partial, array $params = []): string
    {
        return \FSFramework\Core\Html::render($partial, ['fsc' => $this] + $params);
    }

    /**
     * Pure fragment builder — no echo, no template mutation.
     *
     * Returns ['status' => int, 'html' => string, 'headers' => array].
     * Unit-testable without Twig, DB, or framework boot.
     */
    protected function buildFragment(string $html, array $options = []): array
    {
        $status = $options['status'] ?? 200;
        $flash = $options['flash'] ?? null;
        $oobFlash = $options['oobFlash'] ?? false;

        $headers = [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'X-FS-Duration' => $this->duration(),
            'X-FS-Queries' => (string) $this->selects(),
            'X-FS-Transactions' => (string) $this->transactions(),
        ];

        // HX-Trigger — ONE header carries fs:flash plus optional extra events
        // (e.g. fs:modal-close). Avoids a second header() call after output.
        $events = $options['events'] ?? [];
        $trigger = [];
        if ($flash !== null) {
            $cleaned = $this->cleanFlashPayload($flash);
            $hasContent = !empty($cleaned['errors']) || !empty($cleaned['messages']) || !empty($cleaned['advices']);
            if ($hasContent) {
                $trigger['fs:flash'] = $cleaned;
            }
        }
        foreach ($events as $name => $detail) {
            $trigger[$name] = $detail;
        }
        if (!empty($trigger)) {
            $headers['HX-Trigger'] = json_encode($trigger, JSON_UNESCAPED_UNICODE);
        }

        // OOB flash block — opt-in, <template>-wrapped, only with non-empty payload
        $oobBlock = '';
        if ($oobFlash && isset($flash)) {
            $cleaned = $this->cleanFlashPayload($flash);
            $hasContent = !empty($cleaned['errors']) || !empty($cleaned['messages']) || !empty($cleaned['advices']);
            if ($hasContent) {
                $alerts = '';
                foreach ($cleaned['errors'] as $err) {
                    $alerts .= '<div class="alert alert-danger">' . $err . '</div>';
                }
                foreach ($cleaned['messages'] as $msg) {
                    $alerts .= '<div class="alert alert-success">' . $msg . '</div>';
                }
                foreach ($cleaned['advices'] as $adv) {
                    $alerts .= '<div class="alert alert-info">' . $adv . '</div>';
                }
                $oobBlock = '<template><div id="fs-htmx-flash" hx-swap-oob="true">' . $alerts . '</div></template>';
            }
        }

        return [
            'status' => $status,
            'html' => $html . $oobBlock,
            'headers' => $headers,
        ];
    }

    /**
     * Render a fragment response — emit via template=false + echo.
     */
    protected function renderFragment(string $partial, array $params = [], array $options = []): void
    {
        $html = $this->renderPartial($partial, $params);
        $result = $this->buildFragment($html, $options);
        $this->emit($result);
    }

    /**
     * Render a single row fragment (for toggle actions).
     */
    protected function renderRowFragment(object $row, array $params = [], array $options = []): void
    {
        $partial = $this->crud->getRowPartial() ?? '';
        $html = $this->renderPartial($partial, ['row' => $row] + $params);
        $result = $this->buildFragment($html, $options + [
            'flash' => $this->flashPayload(),
        ]);
        $this->emit($result);
    }

    /**
     * Render a tbody fragment (for structural changes: save, add, delete, reorder).
     */
    protected function renderTbodyFragment(array $rows, array $options = []): void
    {
        $partial = $this->crud->getRowPartial() ?? '';
        $html = '';
        foreach ($rows as $row) {
            $html .= $this->renderPartial($partial, ['row' => $row]);
        }
        $result = $this->buildFragment($html, $options + [
            'flash' => $this->flashPayload(),
        ]);
        $this->emit($result);
    }

    /**
     * Emit a 204 response with flash headers only — htmx swaps nothing.
     */
    protected function noContentWithFlash(): void
    {
        $result = $this->buildFragment('', ['status' => 204]);
        $flash = $this->flashPayload();
        $hasContent = !empty($flash['errors']) || !empty($flash['messages']) || !empty($flash['advices']);
        if ($hasContent) {
            $result['headers']['HX-Trigger'] = json_encode(
                ['fs:flash' => $flash],
                JSON_UNESCAPED_UNICODE
            );
        }
        $this->emit($result);
    }

    /**
     * Build flash payload exclusively from fs_core_log channels.
     * Returns the standardized shape consumed by HX-Trigger.
     */
    protected function flashPayload(): array
    {
        return [
            'errors' => $this->get_errors(),
            'messages' => $this->get_messages(),
            'advices' => $this->get_advices(),
        ];
    }

    /**
     * Emit the response — template=false + echo.
     * Protected to allow test subclasses to capture the result.
     */
    protected function emit(array $result): void
    {
        $this->template = false;

        foreach ($result['headers'] as $name => $value) {
            header($name . ': ' . $value);
        }

        http_response_code($result['status']);
        echo $result['html'];
    }

    /**
     * Strip CRLF sequences from all flash payload channels.
     */
    private function cleanFlashPayload(array $flash): array
    {
        $strip = fn(string $m): string => str_replace(["\r\n", "\r", "\n"], '', $m);
        return [
            'errors' => array_map($strip, $flash['errors'] ?? []),
            'messages' => array_map($strip, $flash['messages'] ?? []),
            'advices' => array_map($strip, $flash['advices'] ?? []),
        ];
    }
}
