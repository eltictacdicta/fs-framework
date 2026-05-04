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

    public function testLegacyCookieRestoreRejectedWhenSessionNickDoesNotMatchCookieUser(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('user_nick', 'portal_cliente');
        $bridge = new LegacyAuthBridge($session);

        $user = $this->fakeFsUser('admin', true, 'logkeyval');

        $this->assertFalse($this->invokeIsLegacyUserEligibleForCookieRestore($bridge, $user, 'logkeyval'));
    }

    public function testLegacyCookieRestoreAllowedWhenSessionNickMatchesCookieUser(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('user_nick', 'sameuser');
        $bridge = new LegacyAuthBridge($session);

        $user = $this->fakeFsUser('sameuser', true, 'lk');

        $this->assertTrue($this->invokeIsLegacyUserEligibleForCookieRestore($bridge, $user, 'lk'));
    }

    public function testLegacyCookieRestoreAllowedWhenSessionNickEmpty(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $bridge = new LegacyAuthBridge($session);

        $user = $this->fakeFsUser('admin', true, 'lk2');

        $this->assertTrue($this->invokeIsLegacyUserEligibleForCookieRestore($bridge, $user, 'lk2'));
    }

    /**
     * @return object{nick: string, enabled: bool, log_key: string}
     */
    private function fakeFsUser(string $nick, bool $enabled, string $logKey): object
    {
        return (object) [
            'nick' => $nick,
            'enabled' => $enabled,
            'log_key' => $logKey,
        ];
    }

    private function invokeIsLegacyUserEligibleForCookieRestore(LegacyAuthBridge $bridge, object $user, string $logkey): bool
    {
        $method = new \ReflectionMethod(LegacyAuthBridge::class, 'isLegacyUserEligibleForCookieRestore');
        $method->setAccessible(true);

        return (bool) $method->invoke($bridge, $user, $logkey);
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