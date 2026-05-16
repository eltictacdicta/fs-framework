<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FSFramework\Core;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * HTML sanitizer that removes inline scripts, event handlers, and unsafe elements.
 * Allows scripts from known CDN hosts (Bootstrap, jQuery, etc.)
 */
class HtmlSanitizer
{
    private const ALLOWED_SCRIPT_HOSTS = [
        'ajax.googleapis.com',
        'cdn.jsdelivr.net',
        'cdnjs.cloudflare.com',
        'code.jquery.com',
        'unpkg.com',
    ];

    private const ALLOWED_SCRIPT_ATTRIBUTES = [
        'async',
        'crossorigin',
        'defer',
        'integrity',
        'referrerpolicy',
        'src',
    ];

    private const BLOCKED_HTML_TAGS = [
        'base',
        'embed',
        'form',
        'iframe',
        'input',
        'link',
        'meta',
        'object',
        'textarea',
    ];

    private const URL_HTML_ATTRIBUTES = [
        'action',
        'formaction',
        'href',
        'poster',
        'src',
    ];

    /**
     * Sanitiza HTML eliminando scripts inline y event handlers.
     * Permite scripts de CDN conocidos (Bootstrap, jQuery, etc.)
     */
    public function sanitizeHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $wrappedHtml = '<!DOCTYPE html><html><body><div id="stealth-root">' . $html . '</div></body></html>';

        $previousUseErrors = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        $xpath = new DOMXPath($document);
        $rootNode = $xpath->query('//*[@id="stealth-root"]')->item(0);
        if (!$rootNode instanceof DOMElement) {
            return '';
        }

        foreach ($this->collectElements($rootNode) as $element) {
            $tagName = strtolower($element->tagName);

            if ($tagName === 'script') {
                if (!$this->sanitizeScriptElement($element)) {
                    $element->parentNode?->removeChild($element);
                }
                continue;
            }

            if (in_array($tagName, self::BLOCKED_HTML_TAGS, true)) {
                $element->parentNode?->removeChild($element);
                continue;
            }

            $this->sanitizeHtmlAttributes($element);
        }

        return $this->getInnerHtml($rootNode);
    }

    /**
     * Envuelve el contenido del body en un documento HTML5 completo con Bootstrap 5.
     */
    public function wrapInDocument(string $bodyContent, string $pageTitle, string $customCss = ''): string
    {
        $title = htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8');
        $cssBlock = '';
        if (!empty($customCss)) {
            $cssBlock = "\n    <style>\n" . $customCss . "\n    </style>";
        }

        return '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . $title . '</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand-primary: #2DBCB6;
            --brand-dark: #1a2332;
            --brand-darker: #111927;
            --brand-accent: #24d4ad;
            --brand-light: #f0fdfb;
        }
        body { font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; color: #333; }
        .st-navbar { background: var(--brand-dark); padding: .8rem 0; }
        .st-navbar .navbar-brand { color: var(--brand-primary); font-weight: 700; font-size: 1.4rem; }
        .st-navbar .navbar-brand:hover { color: var(--brand-accent); }
        .st-hero {
            background: linear-gradient(135deg, var(--brand-dark) 0%, #1e3a4f 50%, #1a4a45 100%);
            color: #fff; padding: 100px 20px 80px; text-align: center; position: relative; overflow: hidden;
        }
        .st-hero::before {
            content: ""; position: absolute; top: -50%; right: -20%; width: 600px; height: 600px;
            background: radial-gradient(circle, rgba(45,188,182,.15) 0%, transparent 70%); border-radius: 50%;
        }
        .st-hero::after {
            content: ""; position: absolute; bottom: -30%; left: -10%; width: 500px; height: 500px;
            background: radial-gradient(circle, rgba(36,212,173,.1) 0%, transparent 70%); border-radius: 50%;
        }
        .st-hero .container { position: relative; z-index: 2; }
        .st-hero .brand-logo { width: 80px; height: 80px; margin: 0 auto 1.5rem; }
        .st-hero .brand-logo img { max-width: 100%; height: auto; }
        .st-hero h1 { font-size: 2.8rem; font-weight: 700; margin-bottom: .75rem; }
        .st-hero p.lead { font-size: 1.2rem; opacity: .85; max-width: 550px; margin: 0 auto 2rem; font-weight: 300; }
        .st-btn-primary {
            background: var(--brand-primary); border: none; color: #fff; padding: .7rem 2rem;
            border-radius: 8px; font-weight: 600; font-size: 1rem; transition: all .2s;
        }
        .st-btn-primary:hover { background: var(--brand-accent); color: #fff; transform: translateY(-1px); box-shadow: 0 6px 20px rgba(45,188,182,.3); }
        .st-btn-outline {
            background: transparent; border: 2px solid rgba(255,255,255,.3); color: #fff; padding: .65rem 2rem;
            border-radius: 8px; font-weight: 500; font-size: 1rem; transition: all .2s;
        }
        .st-btn-outline:hover { border-color: var(--brand-primary); color: var(--brand-primary); }
        .st-section { padding: 80px 0; }
        .st-section-title { font-weight: 700; font-size: 2rem; margin-bottom: .5rem; color: var(--brand-dark); }
        .st-section-subtitle { color: #6c757d; font-size: 1.1rem; margin-bottom: 3rem; }
        .st-card {
            background: #fff; border: 1px solid #e8f4f3; border-radius: 16px; padding: 2rem;
            height: 100%; transition: all .25s;
        }
        .st-card:hover { transform: translateY(-4px); box-shadow: 0 12px 40px rgba(45,188,182,.1); border-color: var(--brand-primary); }
        .st-card-icon {
            width: 56px; height: 56px; border-radius: 12px; background: var(--brand-light);
            display: inline-flex; align-items: center; justify-content: center; font-size: 24px; margin-bottom: 1.2rem;
            color: var(--brand-primary);
        }
        .st-card h4 { font-weight: 600; font-size: 1.15rem; margin-bottom: .5rem; color: var(--brand-dark); }
        .st-card p { color: #6c757d; font-size: .95rem; line-height: 1.6; margin: 0; }
        .st-cta {
            background: linear-gradient(135deg, var(--brand-dark) 0%, #1e3a4f 100%);
            color: #fff; padding: 60px 0; text-align: center;
        }
        .st-cta h3 { font-weight: 700; font-size: 1.8rem; margin-bottom: .5rem; }
        .st-cta p { opacity: .8; margin-bottom: 1.5rem; font-size: 1.05rem; }
        .st-footer { background: var(--brand-darker); color: rgba(255,255,255,.5); padding: 2rem 0; font-size: .85rem; }
        .st-footer a { color: var(--brand-primary); text-decoration: none; }
        .st-footer a:hover { color: var(--brand-accent); }
    </style>' . $cssBlock . '
</head>
<body>
' . $bodyContent . '
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>';
    }

    public function getDefaultBodyContent(): string
    {
        $year = date('Y');
        return '
    <nav class="navbar navbar-expand-lg st-navbar">
        <div class="container">
            <a class="navbar-brand" href="#">
                <!-- Reemplaza con tu logo: <img src="tu-logo.png" height="36" alt="Logo"> -->
                Tu Empresa
            </a>
        </div>
    </nav>

    <section class="st-hero">
        <div class="container">
            <div class="brand-logo">
                <!-- Reemplaza con tu logo: <img src="tu-logo-blanco.png" alt="Logo"> -->
            </div>
            <h1>Bienvenido a nuestra plataforma</h1>
            <p class="lead">Soluciones integrales para la gesti&oacute;n empresarial moderna, r&aacute;pida y segura.</p>
            <a href="#servicios" class="btn st-btn-primary me-2">Descubrir m&aacute;s</a>
            <a href="#contacto" class="btn st-btn-outline">Contacto</a>
        </div>
    </section>

    <section id="servicios" class="st-section">
        <div class="container">
            <div class="text-center">
                <h2 class="st-section-title">Nuestros Servicios</h2>
                <p class="st-section-subtitle">Todo lo que necesitas en un solo lugar</p>
            </div>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="st-card">
                        <div class="st-card-icon">&#9889;</div>
                        <h4>R&aacute;pido</h4>
                        <p>Rendimiento optimizado para que tu negocio opere sin esperas ni interrupciones.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="st-card">
                        <div class="st-card-icon">&#128274;</div>
                        <h4>Seguro</h4>
                        <p>Tus datos protegidos con los m&aacute;s altos est&aacute;ndares de encriptaci&oacute;n y privacidad.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="st-card">
                        <div class="st-card-icon">&#10024;</div>
                        <h4>Intuitivo</h4>
                        <p>Interfaz sencilla y moderna dise&ntilde;ada para que empieces a trabajar desde el primer d&iacute;a.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="st-cta" id="contacto">
        <div class="container">
            <h3>&iquest;Listo para empezar?</h3>
            <p>Cont&aacute;ctanos y descubre c&oacute;mo podemos ayudarte</p>
            <a href="mailto:info@tuempresa.com" class="btn st-btn-primary">Contactar</a>
        </div>
    </section>

    <footer class="st-footer">
        <div class="container text-center">
            <p class="mb-0">&copy; ' . $year . ' Tu Empresa &mdash; Todos los derechos reservados.</p>
        </div>
    </footer>';
    }

    /**
     * @return list<DOMElement>
     */
    private function collectElements(DOMNode $root): array
    {
        $elements = [];
        foreach ($root->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            $elements[] = $child;
            foreach ($this->collectElements($child) as $nested) {
                $elements[] = $nested;
            }
        }

        return $elements;
    }

    private function sanitizeScriptElement(DOMElement $element): bool
    {
        $src = trim($element->getAttribute('src'));
        if ($src === '' || trim($element->textContent) !== '') {
            return false;
        }

        if (!$this->isAllowedScriptSource($src)) {
            return false;
        }

        $attributesToRemove = [];
        foreach ($element->attributes as $attribute) {
            $attributeName = strtolower($attribute->nodeName);
            if (!in_array($attributeName, self::ALLOWED_SCRIPT_ATTRIBUTES, true)) {
                $attributesToRemove[] = $attribute->nodeName;
            }
        }

        foreach ($attributesToRemove as $attributeName) {
            $element->removeAttribute($attributeName);
        }

        return true;
    }

    private function isAllowedScriptSource(string $src): bool
    {
        $scheme = strtolower((string) parse_url($src, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($src, PHP_URL_HOST));

        return $scheme === 'https' && in_array($host, self::ALLOWED_SCRIPT_HOSTS, true);
    }

    private function sanitizeHtmlAttributes(DOMElement $element): void
    {
        $attributesToRemove = [];

        foreach ($element->attributes as $attribute) {
            $attributeName = strtolower($attribute->nodeName);
            $attributeValue = $attribute->nodeValue ?? '';

            if (str_starts_with($attributeName, 'on') || $attributeName === 'style' || $attributeName === 'srcdoc') {
                $attributesToRemove[] = $attribute->nodeName;
                continue;
            }

            if (in_array($attributeName, self::URL_HTML_ATTRIBUTES, true) && !$this->isSafeHtmlUrl($attributeValue)) {
                $attributesToRemove[] = $attribute->nodeName;
            }
        }

        foreach ($attributesToRemove as $attributeName) {
            $element->removeAttribute($attributeName);
        }
    }

    private function isSafeHtmlUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '#')) {
            return true;
        }

        $decodedUrl = strtolower(rawurldecode($url));
        if (str_starts_with($decodedUrl, 'javascript:')
            || str_starts_with($decodedUrl, 'data:')
            || str_starts_with($decodedUrl, 'vbscript:')) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        if ($scheme === null) {
            return $host === null;
        }

        return in_array(strtolower($scheme), ['http', 'https', 'mailto', 'tel'], true);
    }

    private function getInnerHtml(DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $childNode) {
            $html .= $node->ownerDocument?->saveHTML($childNode) ?? '';
        }

        return $html;
    }
}
