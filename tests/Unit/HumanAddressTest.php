<?php

namespace Tests\Unit;

use App\Models\WheelSet;
use PHPUnit\Framework\TestCase;

class HumanAddressTest extends TestCase
{
    public function test_service_addresses_are_shown_in_plain_russian(): void
    {
        $this->assertSame('у клиента', WheelSet::humanAddress('ISSUED'));
        $this->assertSame('списан', WheelSet::humanAddress('DISPOSED'));
        $this->assertSame('в пути · П00012', WheelSet::humanAddress('TR-П00012'));
        $this->assertSame('03_01_02', WheelSet::humanAddress('03_01_02'));
        $this->assertSame('М01', WheelSet::humanAddress('М01'));
        $this->assertNull(WheelSet::humanAddress(null));
    }
}
