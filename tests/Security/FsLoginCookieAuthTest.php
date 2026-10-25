<?php

declare(strict_types=1);

namespace Tests\Security;

use FSFramework\Security\SessionManager;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/base/fs_core_log.php';
require_once dirname(__DIR__, 2) . '/base/fs_cache.php';
require_once dirname(__DIR__, 2) . '/base/fs_functions.php';
require_once dirname(__DIR__, 2) . '/model/core/fs_user.php';
require_once dirname(__DIR__, 2) . '/base/fs_login.php';

final class FsLoginCookieAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        SessionManager::reset();
        $_COOKIE = [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        SessionManager::reset();
        $_COOKIE = [];
        $_SESSION = [];

        parent::tearDown();
    }

    /**
     * RMR-06: el contrato público no cambia.
     *
     * Los 20 ficheros que referencian `base/fs_login.php` deben seguir
     * compilando: `log_out()` mantiene su firma pública, la revocación sigue
     * siendo privada y la API pública del puente sigue expuesta igual.
     */
    public function testPublicSignatureContractIsUnchanged(): void
    {
        $logOut = new \ReflectionMethod(\fs_login::class, 'log_out');
        $this->assertTrue($logOut->isPublic());
        $this->assertSame(1, $logOut->getNumberOfParameters());
        $this->assertTrue($logOut->getParameters()[0]->isOptional());

        $revoke = new \ReflectionMethod(\fs_login::class, 'revokeCurrentRememberMeToken');
        $this->assertTrue($revoke->isPrivate());
        $this->assertSame(0, $revoke->getNumberOfParameters());

        $bridge = new \ReflectionClass(\FSFramework\Security\LegacyAuthBridge::class);
        foreach (['revokeCurrentLegacyLogin', 'clearLegacyCookies', 'issueLegacyCookies'] as $name) {
            $this->assertTrue($bridge->getMethod($name)->isPublic(), $name . ' sigue siendo público');
        }

        $this->assertSame(0, $bridge->getMethod('revokeCurrentLegacyLogin')->getNumberOfParameters());
    }

    /**
     * RMR-01: la regla de revocación tiene una sola copia, la del puente.
     * `fs_login` no puede volver a resolver la cuenta por su cuenta: si lo
     * hiciera, el `user_model` inyectado aquí se consultaría con el nick de la
     * cookie sin firmar.
     */
    public function testLogoutDoesNotResolveTheAccountThroughItsOwnUserModel(): void
    {
        $_COOKIE['user'] = 'named-account';
        $_COOKIE['logkey'] = 'named-account-key';

        $login = new \fs_login();
        $userModel = new class {
            /** @var list<string> */
            public array $lookups = [];

            public function get(string $nick): mixed
            {
                $this->lookups[] = $nick;

                return null;
            }

            public function clean_cache(bool $force = false): void
            {
            }
        };

        $this->setPrivateProperty($login, 'user_model', $userModel);
        $this->setPrivateProperty($login, 'core_log', new class {
            public function new_error(string $message): void
            {
            }

            public function new_message(string $message): void
            {
            }

            public function set_user_nick($user): void
            {
            }

            public function save(string $message, string $channel = '', bool $important = false): void
            {
            }
        });
        $this->setPrivateProperty($login, 'cache', new class {
            public function clean(): void
            {
            }
        });

        $login->log_out(TRUE);

        $this->assertSame([], $userModel->lookups, 'La revocación no se resuelve con el modelo propio de fs_login');
    }

    public function testDisabledRememberedUserReturnsFalseExplicitly(): void
    {
        $_COOKIE['user'] = 'disabled-user';
        $_COOKIE['logkey'] = 'remember-me-key';

        $login = new \fs_login();
        $this->setPrivateProperty($login, 'user_model', new class {
            public function get(string $nick): object
            {
                return (object) ['nick' => $nick, 'enabled' => false];
            }

            public function clean_cache(bool $force): void
            {
                // No-op intencional: el test no usa caché de usuarios.
            }
        });
        $this->setPrivateProperty($login, 'core_log', new class {
            public function new_error(string $message): void
            {
                // No-op intencional: el test no valida mensajes de log.
            }

            public function set_user_nick($user): void
            {
                // No-op intencional: stub de fs_core_log.
            }

            public function save(string $message, string $channel = '', bool $important = false): void
            {
                // No-op intencional: stub de fs_core_log.
            }
        });
        $this->setPrivateProperty($login, 'cache', new class {
            public function clean(): void
            {
                // No-op intencional: stub de fs_cache.
            }
        });

        $controllerUser = (object) ['logged_on' => true];
        $method = new \ReflectionMethod(\fs_login::class, 'log_in_cookie');
        $method->setAccessible(true);

        $result = $method->invokeArgs($login, [&$controllerUser]);

        $this->assertFalse($result);
    }

    private function setPrivateProperty(object $instance, string $propertyName, object $value): void
    {
        $property = new \ReflectionProperty($instance, $propertyName);
        $property->setAccessible(true);
        $property->setValue($instance, $value);
    }
}