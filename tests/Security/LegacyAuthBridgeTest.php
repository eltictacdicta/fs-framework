<?php
/**
 * Tests para LegacyAuthBridge.
 */

namespace Tests\Security;

use FSFramework\Security\CookieSigner;
use FSFramework\Security\LegacyAuthBridge;
use PHPUnit\Framework\TestCase;

use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

class LegacyAuthBridgeTest extends TestCase
{
    public function testRememberMeSignatureRequiresNonEmptySignature(): void
    {
        $bridge = new LegacyAuthBridge(new Session(new MockArraySessionStorage()));

        $this->assertFalse($this->invokeIsRememberMeSignatureValid($bridge, 'demo', 'logkey', ''));
    }

    public function testRememberMeSignatureAcceptsValidSignature(): void
    {
        $bridge = new LegacyAuthBridge(new Session(new MockArraySessionStorage()));
        $signature = CookieSigner::signRememberMe('demo', 'logkey');

        $this->assertTrue($this->invokeIsRememberMeSignatureValid($bridge, 'demo', 'logkey', $signature));
    }

    private function invokeIsRememberMeSignatureValid(
        LegacyAuthBridge $bridge,
        string $nick,
        string $logkey,
        string $cookieSig
    ): bool {
        $method = new \ReflectionMethod($bridge, 'isRememberMeSignatureValid');
        $method->setAccessible(true);

        return $method->invoke($bridge, $nick, $logkey, $cookieSig);
    }
}