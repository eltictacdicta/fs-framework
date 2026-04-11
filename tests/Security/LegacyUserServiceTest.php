<?php
/**
 * Tests para LegacyUserService.
 */

namespace Tests\Security;

use FSFramework\Security\LegacyUserService;
use PHPUnit\Framework\TestCase;

class LegacyUserServiceTest extends TestCase
{
    public function testCanAccessReturnsFalseForNullUser(): void
    {
        $service = new LegacyUserService();

        $this->assertFalse($service->canAccess(null, 'admin_home'));
    }

    public function testCanAccessUsesLegacyPermissionMethodForRegularUser(): void
    {
        $service = new LegacyUserService();
        $user = new class() {
            public bool $admin = false;

            public function have_access_to(string $pageName): bool
            {
                return $pageName === 'ventas_clientes';
            }
        };

        $this->assertTrue($service->canAccess($user, 'ventas_clientes'));
        $this->assertFalse($service->canAccess($user, 'admin_home'));
    }

    public function testCanDeleteReturnsFalseForNullUser(): void
    {
        $service = new LegacyUserService();

        $this->assertFalse($service->canDelete(null, 'admin_home'));
    }

    public function testCanDeleteFallsBackToAdminFlag(): void
    {
        $service = new LegacyUserService();
        $adminUser = (object) ['admin' => true];
        $regularUser = (object) ['admin' => false];

        $this->assertTrue($service->canDelete($adminUser, 'admin_home'));
        $this->assertFalse($service->canDelete($regularUser, 'admin_home'));
    }
}
