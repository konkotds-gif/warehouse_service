<?php

namespace Tests\Unit;

use App\Domain\Support\Normalizer;
use App\Models\StorageCell;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NormalizerTest extends TestCase
{
    public function test_phone_is_normalized_to_plus7(): void
    {
        $this->assertSame('+79123456789', Normalizer::phone('8 (912) 345-67-89'));
        $this->assertSame('+79123456789', Normalizer::phone('+7 912 345 67 89'));
        $this->assertSame('+79123456789', Normalizer::phone('9123456789'));
        $this->assertNull(Normalizer::phone(''));
        $this->assertSame('9123456789', Normalizer::phoneDigits('8-912-345-67-89'));
    }

    public function test_plate_latin_lookalikes_become_cyrillic(): void
    {
        $this->assertSame('А123ВС86', Normalizer::plate('a123bc 86'));
        $this->assertSame('А123ВС86', Normalizer::plate('А123-ВС-86'));
        $this->assertSame('Х001ХХ186', Normalizer::plate('x001xx186'));
        $this->assertNull(Normalizer::plate('  '));
    }

    #[DataProvider('sizes')]
    public function test_tire_size_normalization(string $input, string $expected, bool $valid = true): void
    {
        $this->assertSame($expected, Normalizer::tireSize($input));
        $this->assertSame($valid, Normalizer::isValidTireSize($input));
    }

    public static function sizes(): array
    {
        return [
            ['205/55r16 94h', '205/55 R16 94H'],
            ['205/55 R16', '205/55 R16'],
            ['235/45ZR18 98W', '235/45 ZR18 98W'],
            ['195/75 r16c 107/105r', '195/75 R16C 107/105R'],
            ['31x10.5 R15 109Q', '31x10.5 R15 109Q'],
            ['абв', 'АБВ', false],
            ['205/55', '205/55', false],
        ];
    }

    public function test_cell_address_format(): void
    {
        $this->assertTrue(StorageCell::isValidAddress('02_07_15'));
        $this->assertFalse(StorageCell::isValidAddress('2_7_15'));
        $this->assertFalse(StorageCell::isValidAddress('М01'));
        $this->assertSame('02_07_15', StorageCell::makeAddress('02', '7', '15'));
    }
}
