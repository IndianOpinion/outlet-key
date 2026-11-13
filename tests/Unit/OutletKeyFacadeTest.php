<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey\Tests\Unit;

use IndianOpinion\OutletKey\OutletKey;
use IndianOpinion\OutletKey\OutletMap;
use PHPUnit\Framework\TestCase;

final class OutletKeyFacadeTest extends TestCase
{
    public function testFacadeUsesTheBundledMap(): void
    {
        self::assertSame('economictimes.indiatimes.com', OutletKey::outlet('m.economictimes.com'));
        self::assertSame('scmp.com/x', OutletKey::article('https://amp.scmp.com/x/amp?utm_source=y'));
        self::assertSame(OutletMap::bundled()->version(), OutletKey::mapVersion());
        self::assertSame(OutletKey::mapVersion(), OutletKey::explain('x.com')->mapVersion());
        self::assertSame(1, OutletKey::SCHEME_VERSION);
    }
}
