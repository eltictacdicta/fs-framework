<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace Tests\Core;

use FSFramework\Core\CssSanitizer;
use FSFramework\Core\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Hardening of the sanitizers used by the stealth page and of the fatal error
 * renderer.
 *
 * Both sanitizers allowed a payload that broke out of their own context:
 * a `</style>` inside a CSS string closed the <style> element and turned the
 * rest into live HTML, and `xlink:href` was not in the URL attribute allowlist,
 * so a `javascript:` SVG link survived.
 */
final class ContentSanitizerHardeningTest extends TestCase
{
    public function testCssValueCannotCloseTheStyleElement(): void
    {
        $css = 'a{content:"</style><img src=x onerror=alert(1)>"}';

        $result = (new CssSanitizer())->sanitizeCss($css);

        $this->assertTrue(
            $result === null || !str_contains(strtolower((string) $result), '</style'),
            'A CSS value must never be able to emit a closing </style> tag. Got: ' . var_export($result, true)
        );
    }

    public function testCssUppercaseStyleCloseIsAlsoRejected(): void
    {
        $css = 'a{content:"</STYLE><img src=x onerror=alert(1)>"}';

        $result = (new CssSanitizer())->sanitizeCss($css);

        $this->assertTrue(
            $result === null || !str_contains(strtolower((string) $result), '</style'),
            'Case must not bypass the </style> guard. Got: ' . var_export($result, true)
        );
    }

    public function testLegitimateCssStillPasses(): void
    {
        $result = (new CssSanitizer())->sanitizeCss('a{color:#fff;margin:0 auto}');

        $this->assertNotNull($result, 'Plain CSS must keep working.');
        $this->assertStringContainsString('color', (string) $result);
    }

    public function testSvgJavascriptHrefIsStripped(): void
    {
        $html = (new HtmlSanitizer())->sanitizeHtml(
            '<svg><a xlink:href="javascript:alert(1)">x</a></svg>'
        );

        $this->assertStringNotContainsString(
            'javascript:',
            strtolower($html),
            'xlink:href must go through the same URL scheme check as href.'
        );
    }

    public function testSvgDataHrefIsStripped(): void
    {
        $html = (new HtmlSanitizer())->sanitizeHtml(
            '<svg><a xlink:href="data:text/html;base64,PHNjcmlwdD4=">x</a></svg>'
        );

        $this->assertStringNotContainsString('data:text/html', strtolower($html));
    }

    /**
     * SMIL animation can assign `href` at runtime, after the attribute filter
     * has already run: `<set attributeName="href" to="javascript:...">`.
     */
    public function testSvgSetCannotAssignJavascriptHref(): void
    {
        $html = (new HtmlSanitizer())->sanitizeHtml(
            '<svg><a><set attributeName="href" to="javascript:alert(1)"/><text>x</text></a></svg>'
        );

        $lower = strtolower($html);
        $this->assertStringNotContainsString('javascript:', $lower);
        $this->assertStringNotContainsString('<set', $lower);
    }

    public function testSvgAnimateCannotAssignJavascriptHref(): void
    {
        $html = (new HtmlSanitizer())->sanitizeHtml(
            '<svg><a><animate attributeName="href" values="javascript:alert(1)"/><text>x</text></a></svg>'
        );

        $lower = strtolower($html);
        $this->assertStringNotContainsString('javascript:', $lower);
        $this->assertStringNotContainsString('<animate', $lower);
    }

    /**
     * A crafted selector can carry markup too, and selectors were not passed
     * through the declaration-value token check.
     */
    public function testCssSelectorCannotCloseTheStyleElement(): void
    {
        $css = 'a[title="</style><svg onload=alert(1)>"]{color:red}';

        $result = (new CssSanitizer())->sanitizeCss($css);

        $this->assertTrue(
            $result === null || !str_contains(strtolower((string) $result), '</style'),
            'No rendered selector may close the <style> element. Got: ' . var_export($result, true)
        );
    }

    /**
     * The guard must target the HTML end tag, not any `<`: a lone `<` is valid
     * CSS content and rejecting it would discard the whole stylesheet.
     */
    public function testLessThanInContentIsStillAllowed(): void
    {
        $result = (new CssSanitizer())->sanitizeCss('a::before{content:"<"}');

        $this->assertNotNull($result, 'A legitimate `<` in a CSS string must not be rejected.');
    }
}
