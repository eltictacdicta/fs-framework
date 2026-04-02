<?php
/**
 * Tests para el registro de uso legacy.
 */

namespace Tests\LegacySupport;

use FSFramework\Plugins\legacy_support\LegacyUsageTracker;
use PHPUnit\Framework\TestCase;

class LegacyUsageTrackerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once FS_FOLDER . '/plugins/legacy_support/LegacyUsageTracker.php';
    }

    protected function setUp(): void
    {
        LegacyUsageTracker::reset();
    }

    protected function tearDown(): void
    {
        LegacyUsageTracker::reset();
    }

    public function testResetClearsStoredUsageHistory(): void
    {
        LegacyUsageTracker::incrementLegacyRoute('admin_users', 'legacy_controller');
        LegacyUsageTracker::incrementLegacyComponent('legacy.template_loader', '__construct');

        $summary = LegacyUsageTracker::getSummary();
        $this->assertSame(1, $summary['totals']['route_hits']);
        $this->assertSame(1, $summary['totals']['component_hits']);
        $this->assertSame(1, $summary['totals']['unique_routes']);
        $this->assertSame(1, $summary['totals']['unique_components']);

        $this->assertTrue(LegacyUsageTracker::reset());

        $summary = LegacyUsageTracker::getSummary();
        $this->assertNull($summary['updated_at']);
        $this->assertSame(0, $summary['totals']['route_hits']);
        $this->assertSame(0, $summary['totals']['component_hits']);
        $this->assertSame(0, $summary['totals']['unique_routes']);
        $this->assertSame(0, $summary['totals']['unique_components']);
        $this->assertSame([], $summary['top_routes']);
        $this->assertSame([], $summary['top_components']);
    }
}