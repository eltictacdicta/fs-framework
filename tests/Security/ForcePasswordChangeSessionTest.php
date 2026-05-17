<?php

declare(strict_types=1);

namespace Tests\Security;

use FSFramework\DependencyInjection\Container;
use FSFramework\Security\SessionManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;

require_once dirname(__DIR__, 2) . '/model/core/fs_user.php';
require_once dirname(__DIR__, 2) . '/src/DependencyInjection/Container.php';

if (!defined('FS_LAZY_MODELS')) {
    define('FS_LAZY_MODELS', true);
}

require_once dirname(__DIR__, 2) . '/base/fs_controller.php';
require_once dirname(__DIR__, 2) . '/controller/force_password_change.php';

final class ForcePasswordChangeSessionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Container::reset();
        SessionManager::reset();
        $_COOKIE = [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        Container::reset();
        SessionManager::reset();
        $_COOKIE = [];
        $_SESSION = [];

        parent::tearDown();
    }

    public function testGetSessionReusesSessionManagerSessionWhenAvailable(): void
    {
        $manager = SessionManager::getInstance();
        $manager->getSymfonySession()->set('force_password_change_reason', 'shared');

        $controller = (new \ReflectionClass(\force_password_change::class))->newInstanceWithoutConstructor();

        $method = new \ReflectionMethod(\force_password_change::class, 'getSession');
        $method->setAccessible(true);
        $session = $method->invoke($controller);

        $this->assertInstanceOf(Session::class, $session);
        $this->assertSame($manager->getSymfonySession(), $session);
        $this->assertSame('shared', $session->get('force_password_change_reason'));
    }

    public function testCompleteInitialSetupIfPendingMarksFlagCompleted(): void
    {
        $logger = new class {
            public array $errors = [];

            public function error(string $message, array $context = []): void
            {
                $this->errors[] = ['message' => $message, 'context' => $context];
            }
        };
        $fsUser = new class {
            public bool $pendingChecked = false;
            public bool $completed = false;

            public function isInitialSetupPending(): bool
            {
                $this->pendingChecked = true;
                return true;
            }

            public function completeInitialSetup(): bool
            {
                $this->completed = true;
                return true;
            }
        };

        Container::set('logger', $logger);
        Container::set('fs_user', $fsUser);

        $controller = (new \ReflectionClass(\force_password_change::class))->newInstanceWithoutConstructor();

        $method = new \ReflectionMethod(\force_password_change::class, 'completeInitialSetupIfPending');
        $method->setAccessible(true);
        $method->invoke($controller);

        $this->assertTrue($fsUser->pendingChecked);
        $this->assertTrue($fsUser->completed);
        $this->assertSame([], $logger->errors);
    }
}