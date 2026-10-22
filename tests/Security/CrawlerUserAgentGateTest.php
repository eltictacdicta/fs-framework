<?php

declare(strict_types=1);

namespace Tests\Security;

use FSFramework\Security\CrawlerUserAgentGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Host-wide crawler block enforced in the request pipeline.
 *
 * `.htaccess` only exists on Apache with AllowOverride; on nginx it is inert.
 * The policy therefore lives in PHP, and `.htaccess` becomes an optional edge
 * layer. This suite pins the policy, the opt-out, the wiring in the entry
 * script, and the agreement between the three places that name the crawlers.
 */
#[CoversClass(CrawlerUserAgentGate::class)]
final class CrawlerUserAgentGateTest extends TestCase
{
    private function webRoot(): string
    {
        return rtrim((string) FS_FOLDER, '/');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function blockedUserAgents(): array
    {
        return [
            'GPTBot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.4; +https://openai.com/gptbot)'],
            'GPTBot lowercase' => ['gptbot/1.2'],
            'ChatGPT-User' => ['Mozilla/5.0; compatible; ChatGPT-User/1.0; +https://openai.com/bot'],
            'CCBot' => ['CCBot/2.0 (https://commoncrawl.org/faq/)'],
            'ClaudeBot' => ['Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)'],
            'PerplexityBot' => ['Mozilla/5.0 (compatible; PerplexityBot/1.0)'],
            'Bytespider' => ['Mozilla/5.0 (compatible; Bytespider; spider-feedback@bytedance.com)'],
            'meta-externalagent' => ['meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function allowedUserAgents(): array
    {
        return [
            'Chrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36'],
            'Firefox' => ['Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0'],
            'Safari iOS' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1'],
            // Search engines must keep fetching: a crawler has to reach a page
            // to read its X-Robots-Tag noindex.
            'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
            'Bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'],
            // Server-to-server integration that must never be blocked.
            'WordPress plugin' => ['WordPress/7.1.3; https://infrico.com/en/'],
            'curl' => ['curl/8.5.0'],
            'empty' => [''],
        ];
    }

    #[DataProvider('blockedUserAgents')]
    public function testBlockedCrawlersGet403(string $userAgent): void
    {
        $request = Request::create('/oauth/login', 'GET');
        $request->headers->set('User-Agent', $userAgent);

        $response = (new CrawlerUserAgentGate())->intercept($request);

        self::assertNotNull($response, "{$userAgent} must be blocked");
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('noindex, nofollow, noarchive', $response->headers->get('X-Robots-Tag'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    #[DataProvider('allowedUserAgents')]
    public function testLegitimateClientsPassThrough(string $userAgent): void
    {
        $request = Request::create('/oauth/login', 'GET');
        $request->headers->set('User-Agent', $userAgent);

        self::assertNull((new CrawlerUserAgentGate())->intercept($request));
    }

    public function testBlockAppliesToEveryPathNotJustOauth(): void
    {
        foreach (['/', '/account', '/index.php?page=admin_home', '/oauth/authorize'] as $path) {
            $request = Request::create($path, 'GET');
            $request->headers->set('User-Agent', 'GPTBot/1.4');

            $response = (new CrawlerUserAgentGate())->intercept($request);

            self::assertNotNull($response, "{$path} must be blocked for crawlers");
            self::assertSame(403, $response->getStatusCode());
        }
    }

    /**
     * RFC 9309: a 4xx robots.txt means "unavailable", so a compliant crawler
     * would retry forever instead of reading "Disallow: /".
     */
    public function testRobotsTxtStaysReachableForBlockedCrawlers(): void
    {
        $request = Request::create('/robots.txt', 'GET');
        $request->headers->set('User-Agent', 'GPTBot/1.4');

        self::assertNull((new CrawlerUserAgentGate())->intercept($request));
    }

    public function testGateCanBeOptedOutByConfiguration(): void
    {
        $gate = new class extends CrawlerUserAgentGate {
            protected function isEnabled(): bool
            {
                return false;
            }
        };

        $request = Request::create('/oauth/login', 'GET');
        $request->headers->set('User-Agent', 'GPTBot/1.4');

        self::assertNull($gate->intercept($request));
    }

    /**
     * The entry script is the only universal hook: every dynamic request goes
     * through it, on every web server. It must invoke this core gate before the
     * Kernel boots, and it must not reach into a plugin to do it.
     */
    public function testEntryScriptInvokesTheCoreGateBeforeBootingTheKernel(): void
    {
        $source = (string) file_get_contents($this->webRoot() . '/index.php');

        $gateAt = strpos($source, '\FSFramework\Security\CrawlerUserAgentGate');
        $bootAt = strpos($source, 'Kernel::boot()');

        self::assertNotFalse($gateAt, 'index.php no longer invokes the crawler gate');
        self::assertNotFalse($bootAt, 'index.php no longer boots the Kernel');
        self::assertLessThan(
            $bootAt,
            $gateAt,
            'the crawler gate must run before Kernel::boot() (otherwise blocked crawlers pay the whole boot)'
        );
    }

    public function testEntryScriptDoesNotReferencePluginCodeForThisPolicy(): void
    {
        $source = (string) file_get_contents($this->webRoot() . '/index.php');

        self::assertStringNotContainsString(
            'Plugins\\OidcProvider',
            $source,
            'the public entry script must not depend on a private plugin namespace'
        );
    }

    public function testApiEntryScriptAppliesTheSamePolicy(): void
    {
        $source = (string) file_get_contents($this->webRoot() . '/api.php');

        self::assertStringContainsString(
            '\FSFramework\Security\CrawlerUserAgentGate',
            $source,
            'the REST entry point is a separate PHP entry and must apply the same gate'
        );
    }

    /**
     * The policy must not be duplicated as a server rule. A RewriteCond cannot
     * see FS_BLOCK_AI_CRAWLERS, so an edge rule would keep returning 403 after
     * the operator opted out — a documented switch that does not work.
     */
    public function testNoServerLevelCrawlerRuleDuplicatesThePolicy(): void
    {
        $htaccess = (string) file_get_contents($this->webRoot() . '/.htaccess');

        self::assertDoesNotMatchRegularExpression(
            '/RewriteCond %\{HTTP_USER_AGENT\}/',
            $htaccess,
            '.htaccess must not re-implement the crawler policy: it cannot honour FS_BLOCK_AI_CRAWLERS'
        );
    }

    public function testEveryBlockedCrawlerGroupDisallowsTheWholeHost(): void
    {
        $robots = (string) file_get_contents($this->webRoot() . '/robots.txt');

        foreach (CrawlerUserAgentGate::blockedTokens() as $token) {
            self::assertMatchesRegularExpression(
                '/^User-agent:\s*' . preg_quote($token, '/') . '\s*\r?\nDisallow:\s*\/\s*$/mi',
                $robots,
                "the robots.txt group for {$token} must start with 'Disallow: /' immediately after its User-agent line"
            );
        }
    }

    /**
     * A group whose User-agent line is separated from its rules by a blank line
     * has no rules at all: a strict RFC 9309 parser reads it as "allow all".
     */
    public function testWildcardGroupIsNotBlankLineSeparatedFromItsRules(): void
    {
        $robots = (string) file_get_contents($this->webRoot() . '/robots.txt');

        self::assertMatchesRegularExpression(
            '/^User-agent:\s*\*\s*\r?\n(?:Disallow|Allow):/mi',
            $robots,
            'the User-agent: * group must be followed immediately by its first rule'
        );
    }
}
