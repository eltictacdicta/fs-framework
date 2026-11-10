<?php

declare(strict_types=1);

/**
 * Stand-in for the legacy fs_extension model, loaded only inside the separate
 * processes of LegacyExtensionsLazyLoadTest: it counts the queries.
 */
class fs_extension
{
    /** @return list<object> */
    public function all(): array
    {
        \Tests\Event\LegacyExtensionsLazyLoadTest::$queries++;

        return \Tests\Event\LegacyExtensionsLazyLoadTest::$rows;
    }
}
