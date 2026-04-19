<?php

namespace Tests\Components;

use FSFramework\Core\Plugins;
use FSFramework\Core\StealthMode;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../base/fs_db2.php';

class StealthModeTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $_GET = [];
        $_COOKIE = [];
        $_SESSION = [];
        $_SERVER['REQUEST_URI'] = '/index.php';
        Plugins::resetRuntimeState();
        $GLOBALS['plugins'] = [];
    }

    public function testHasAccessDeniesWhenSecretIsMissing(): void
    {
        $stealth = new StealthMode($this->createDbStub([
            'stealth_enabled' => '1',
            'stealth_param_name' => 'adminpanel',
        ]));

        $this->assertFalse($stealth->hasAccess());
    }

    public function testIsExemptRouteDelegatesToPluginManagedPublicPrefixes(): void
    {
        Plugins::registerPublicPathPrefixes('OidcProvider', ['/oauth', '/account']);
        $GLOBALS['plugins'] = ['OidcProvider'];

        $stealth = new StealthMode($this->createDbStub());

        $_SERVER['REQUEST_URI'] = '/prefix/oauth/test';
        $this->assertFalse($stealth->isExemptRoute());

        $_SERVER['REQUEST_URI'] = '/oauth';
        $this->assertTrue($stealth->isExemptRoute());

        $_SERVER['REQUEST_URI'] = '/account';
        $this->assertTrue($stealth->isExemptRoute());

        $_SERVER['REQUEST_URI'] = '/.well-known/openid-configuration';
        $this->assertFalse($stealth->isExemptRoute());
    }

    public function testHiddenLoginRequiresSecretParameterOnEachAnonymousRequest(): void
    {
        $_SESSION['stealth_unlocked'] = true;
        $_SERVER['REQUEST_URI'] = '/ventas_clientes';

        $stealth = new StealthMode($this->createDbStub([
            'stealth_param_name' => 'adminpanel',
            'stealth_param_value' => 'secret-token',
        ]));

        $this->assertFalse($stealth->hasAccess());

        $_SERVER['REQUEST_URI'] = '/index.php';
        $this->assertFalse($stealth->hasAccess());

        $_GET = ['page' => 'login', 'adminpanel' => 'secret-token'];
        $_SERVER['REQUEST_URI'] = '/index.php?page=login&adminpanel=secret-token';
        $this->assertTrue($stealth->hasAccess());

        $_GET = ['nlogin' => 'admin', 'adminpanel' => 'secret-token'];
        $_POST = ['user' => 'admin', 'password' => 'pass'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->assertTrue($stealth->hasAccess());

        $_GET = ['nlogin' => 'admin'];
        $this->assertFalse($stealth->hasAccess());
    }

    public function testAccessUrlPointsToHiddenLoginEntry(): void
    {
        if (!defined('FS_BASE_URL')) {
            define('FS_BASE_URL', 'https://oidcprovider.ddev.site');
        }

        $stealth = new StealthMode($this->createDbStub([
            'stealth_param_name' => 'adminpanel',
            'stealth_param_value' => 'secret-token',
        ]));

        $this->assertSame(
            'https://oidcprovider.ddev.site/index.php?adminpanel=secret-token',
            $stealth->getAccessUrl()
        );

        $this->assertSame(
            '/index.php?page=login&adminpanel=secret-token',
            $stealth->getHiddenLoginUrl()
        );
    }

    public function testSaveCustomCssRejectsDangerousRules(): void
    {
        $stealth = new StealthMode($this->createDbStub());

        $this->assertFalse($stealth->saveCustomCss('@import url("https://evil.example/a.css");'));
        $this->assertFalse($stealth->saveCustomCss('.hero { background-image: url("javascript:alert(1)"); }'));
        $this->assertFalse($stealth->saveCustomCss('.hero { width: expression(alert(1)); }'));
    }

    public function testSaveCustomCssPersistsSafeStylesheet(): void
    {
        $db = $this->createDbStub();
        $stealth = new StealthMode($db);

        $saved = $stealth->saveCustomCss('.hero { color: #123456; background-image: url("/img/bg.png"); }');

        $this->assertTrue($saved);
        $this->assertStringContainsString('background-image', $db->lastExecSql);
        $this->assertStringContainsString('varchar', $db->lastExecSql);
    }

    public function testSaveHomepageHtmlRemovesInlineScriptsAndUnsafeUrls(): void
    {
        $db = $this->createDbStub();
        $stealth = new StealthMode($db);

        $saved = $stealth->saveHomepageHtml('<div onclick="alert(1)"><a href="javascript:alert(1)">bad</a><script>alert(1)</script><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script></div>');

        $this->assertTrue($saved);
        $this->assertStringNotContainsString('onclick', $db->lastExecSql);
        $this->assertStringNotContainsString('javascript:alert(1)', $db->lastExecSql);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $db->lastExecSql);
        $this->assertStringContainsString('cdn.jsdelivr.net', $db->lastExecSql);
    }

    /**
     * @return \fs_db2&object{lastExecSql: string}
     */
    private function createDbStub(array $settings = []): \fs_db2
    {
        return new class($settings) extends \fs_db2 {
            private array $settings = [];
            public string $lastExecSql = '';

            public function __construct(array $settings = [])
            {
                $this->settings = $settings;
            }

            public function connected()
            {
                return true;
            }

            public function connect()
            {
                return true;
            }

            public function escape_string($str)
            {
                return addslashes((string) $str);
            }

            /**
             * @return array<int, array<string, string>>
             */
            public function select($sql, $params = [])
            {
                if (str_contains($sql, 'WHERE name IN')) {
                    $rows = [];
                    foreach ((array) $this->settings as $name => $value) {
                        $rows[] = ['name' => $name, 'varchar' => $value];
                    }

                    return $rows;
                }

                if (preg_match("/WHERE name = '([^']+)'/", $sql, $matches) === 1) {
                    $name = stripslashes($matches[1]);
                    return array_key_exists($name, $this->settings) ? [['name' => $name]] : [];
                }

                return [];
            }

            public function exec($sql, $transaction = null, $params = [])
            {
                $this->lastExecSql = $sql;

                if (preg_match("/UPDATE fs_vars SET [`\"]varchar[`\"] = '(.+)' WHERE name = '([^']+)'/", $sql, $matches) === 1) {
                    $this->settings[stripslashes($matches[2])] = stripslashes($matches[1]);
                }

                if (preg_match("/INSERT INTO fs_vars \(name, [`\"]varchar[`\"]\) VALUES \('([^']+)', '(.+)'\)/", $sql, $matches) === 1) {
                    $this->settings[stripslashes($matches[1])] = stripslashes($matches[2]);
                }

                return true;
            }
        };
    }
}
