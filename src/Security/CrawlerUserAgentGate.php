<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework originally based on Facturascript 2017
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

namespace FSFramework\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Host-wide block for AI / scraper crawlers, enforced in the request pipeline.
 *
 * Why PHP and not only `.htaccess`: `.htaccess` is inert on nginx and on any
 * Apache whose vhost sets `AllowOverride None`, so a server-level rule makes the
 * protection depend on the deployment's web server. This gate runs from the
 * entry script before routing and before the Kernel boots, so it behaves
 * identically on Apache, nginx and LiteSpeed, and a blocked request costs
 * almost nothing. `.htaccess` stays as an optional edge layer that stops the
 * request before PHP starts; `CrawlerUserAgentGateTest` asserts both lists stay
 * in sync.
 *
 * This is generic host policy, not a plugin concern: it knows nothing about any
 * plugin, and any deployment can opt out.
 *
 * Deliberate exclusions:
 *  - Search engines (Googlebot, Bingbot) are NOT blocked. Public pages may send
 *    `X-Robots-Tag: noindex`, and a crawler must be able to fetch a page to read
 *    that header; otherwise the URL stays indexed without content.
 *  - `/robots.txt` is always reachable. RFC 9309 treats a 4xx robots.txt as
 *    "unavailable", so a blocked crawler would retry forever instead of reading
 *    `Disallow: /` and backing off.
 *  - `Google-Extended` and `Applebot-Extended` are robots.txt-only opt-out
 *    tokens, not User-Agents, so they do not belong in this list.
 *
 * Opt out with `define('FS_BLOCK_AI_CRAWLERS', false);` in `config.php`.
 *
 * Scope and limits, stated so the switch is not oversold:
 *  - The switch removes the HTTP block only. `robots.txt` is a static file and
 *    cannot read a PHP constant, so its crawler groups keep asking compliant
 *    crawlers to leave; edit that file too if you want to stop asking.
 *  - It applies to the public entry points (`index.php`, `api.php`). Direct
 *    plugin scripts that require an authenticated session and a CSRF token
 *    (for example `plugins/system_updater/*`) are out of scope: they are not
 *    crawlable and already reject anonymous requests.
 */
class CrawlerUserAgentGate
{
    private const ROBOTS_PATH = '/robots.txt';

    private const ROBOTS_HEADER = 'noindex, nofollow, noarchive';

    /**
     * Canonical list. Every entry should also appear in the `.htaccess`
     * RewriteCond and in a robots.txt group; the drift guard asserts it.
     *
     * @var list<string>
     */
    private const BLOCKED_TOKENS = [
        'GPTBot',
        'ChatGPT-User',
        'OAI-SearchBot',
        'ClaudeBot',
        'Claude-User',
        'Claude-SearchBot',
        'anthropic-ai',
        'CCBot',
        'PerplexityBot',
        'Perplexity-User',
        'Bytespider',
        'Amazonbot',
        'meta-externalagent',
        'Diffbot',
        'omgili',
        'cohere-ai',
        'Timpibot',
        'ImagesiftBot',
        'PetalBot',
    ];

    /**
     * @return list<string>
     */
    public static function blockedTokens(): array
    {
        return self::BLOCKED_TOKENS;
    }

    public function intercept(Request $request): ?Response
    {
        if (!$this->isEnabled()) {
            return null;
        }

        // A crawler must be able to read the instructions that tell it to stop.
        if ($request->getPathInfo() === self::ROBOTS_PATH) {
            return null;
        }

        $userAgent = trim((string) $request->headers->get('User-Agent', ''));
        if ($userAgent === '' || !$this->isBlocked($userAgent)) {
            return null;
        }

        return new Response(
            "403 Forbidden: automated crawling of this host is not permitted.\n",
            403,
            [
                'Content-Type' => 'text/plain; charset=utf-8',
                'X-Robots-Tag' => self::ROBOTS_HEADER,
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            ]
        );
    }

    public function isBlocked(string $userAgent): bool
    {
        foreach (self::BLOCKED_TOKENS as $token) {
            if (stripos($userAgent, $token) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Defaults to enabled: requiring configuration would reintroduce the very
     * dependency this gate exists to remove.
     */
    protected function isEnabled(): bool
    {
        return !defined('FS_BLOCK_AI_CRAWLERS') || FS_BLOCK_AI_CRAWLERS !== false;
    }
}
