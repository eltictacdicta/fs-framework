<?php
/**
 * This file is part of FSFramework
 */

namespace Tests\Core;

use FSFramework\Database\TypeNormalizer;
use PHPUnit\Framework\TestCase;

class TypeNormalizerTest extends TestCase
{
    public function testNormalizeDefaultPreservesSequenceMarkerForSerialColumns(): void
    {
        $this->assertSame(
            "nextval('fs_logs_id_seq'::regclass)",
            TypeNormalizer::normalizeDefault("nextval('fs_logs_id_seq'::regclass)", 'SERIAL')
        );
    }

    public function testNormalizeDefaultKeepsCurrentTimestampLiteralForNonTemporalColumns(): void
    {
        $this->assertSame("'CURRENT_TIMESTAMP'", TypeNormalizer::normalizeDefault('CURRENT_TIMESTAMP', 'VARCHAR(255)'));
    }

    public function testNormalizeDefaultMapsTemporalKeywordForDateColumns(): void
    {
        $this->assertSame('CURRENT_DATE', TypeNormalizer::normalizeDefault('CURRENT_TIMESTAMP', 'DATE'));
    }
}
