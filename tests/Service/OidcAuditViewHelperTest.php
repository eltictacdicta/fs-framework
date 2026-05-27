<?php

declare(strict_types=1);

namespace FSFramework\Plugins\OidcProvider\Tests\Service;

use FSFramework\Plugins\OidcProvider\Service\OidcAuditViewHelper;
use PHPUnit\Framework\TestCase;

final class OidcAuditViewHelperTest extends TestCase
{
    public function testEventLabelMapsKnownEvents(): void
    {
        $this->assertSame('Autorización concedida', OidcAuditViewHelper::eventLabel('authorization_granted'));
        $this->assertSame('Token refrescado', OidcAuditViewHelper::eventLabel('token_refreshed'));
        $this->assertSame('Error de validación', OidcAuditViewHelper::eventLabel('validation_error'));
    }

    public function testEventBadgeClassGroupsByOutcome(): void
    {
        $this->assertSame('label-success', OidcAuditViewHelper::eventBadgeClass('authorization_granted'));
        $this->assertSame('label-danger', OidcAuditViewHelper::eventBadgeClass('validation_error'));
        $this->assertSame('label-default', OidcAuditViewHelper::eventBadgeClass('admin_token_revoked'));
    }

    public function testIsUsageEventExcludesAdminAndSupportEvents(): void
    {
        $this->assertTrue(OidcAuditViewHelper::isUsageEvent('authorization_granted'));
        $this->assertTrue(OidcAuditViewHelper::isUsageEvent('token_issued'));
        $this->assertFalse(OidcAuditViewHelper::isUsageEvent('admin_token_revoked'));
        $this->assertFalse(OidcAuditViewHelper::isUsageEvent('password_changed'));
        $this->assertFalse(OidcAuditViewHelper::isUsageEvent('support_requested'));
        $this->assertFalse(OidcAuditViewHelper::isUsageEvent('jwk_key_rotated'));
    }
}
