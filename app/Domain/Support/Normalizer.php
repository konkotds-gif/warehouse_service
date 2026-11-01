<?php

namespace App\Domain\Support;

/**
 * Нормализация телефонов, госномеров и размеров шин (техдок, раздел 3.2).
 */
final class Normalizer
{
    /** Латинские буквы, похожие на кириллические буквы госномера. */
    private const PLATE_MAP = [
        'A' => 'А', 'B' => 'В', 'E' => 'Е', 'K' => 'К', 'M' => 'М', 'H' => 'Н',
        'O' => 'О', 'P' => 'Р', 'C' => 'С', 'T' => 'Т', 'Y' => 'У', 'X' => 'Х',
    ];

    /** Телефон в формат +7XXXXXXXXXX; null — если номер не распознан. */
    public static function phone(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) === 11 && ($digits[0] === '8' || $digits[0] === '7')) {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) !== 10) {
            return '+'.$digits; // иностранный или неполный номер — сохраняем как есть
        }

        return '+7'.$digits;
    }

    /** Часть телефона для поиска (последние цифры). */
    public static function phoneDigits(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) === 11 && ($digits[0] === '8' || $digits[0] === '7')) {
            $digits = substr($digits, 1);
        }

        return $digits;
    }

    /** Госномер: верхний регистр, без пробелов и дефисов, латиница → кириллица. */
    public static function plate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = mb_strtoupper(preg_replace('/[\s\-_]+/u', '', $value) ?? '');
        if ($v === '') {
            return null;
        }

        return strtr($v, self::PLATE_MAP);
    }

    /**
     * Полный размер шины: '205/55r16 94h' → '205/55 R16 94H'.
     * Допускаются форматы 205/55 R16 [94H], 235/45 ZR18 98W, 195/75 R16C 107/105R, 31x10.5 R15 109Q.
     */
    public static function tireSize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = mb_strtoupper(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
        $v = str_replace(['Х', 'X '], ['X', 'X'], $v);
        if (preg_match('/^(\d{3})\s*\/\s*(\d{2})\s*(Z?R)\s*(\d{2}(?:\.5)?C?)(?:\s*(\d{2,3}(?:\/\d{2,3})?)\s*([A-Z]))?$/', $v, $m)) {
            $s = "{$m[1]}/{$m[2]} {$m[3]}{$m[4]}";
            if (! empty($m[5])) {
                $s .= " {$m[5]}{$m[6]}";
            }

            return $s;
        }
        if (preg_match('/^(\d{2})\s*X\s*(\d{1,2}(?:\.\d{1,2})?)\s*(R)\s*(\d{2})(?:\s*(\d{2,3})\s*([A-Z]))?$/', $v, $m)) {
            $s = "{$m[1]}x{$m[2]} R{$m[4]}";
            if (! empty($m[5])) {
                $s .= " {$m[5]}{$m[6]}";
            }

            return $s;
        }

        return $v;
    }

    public static function isValidTireSize(string $value): bool
    {
        $n = self::tireSize($value);

        return (bool) preg_match('/^(\d{3}\/\d{2} Z?R\d{2}(\.5)?C?|\d{2}x\d{1,2}(\.\d{1,2})? R\d{2})( \d{2,3}(\/\d{2,3})?[A-Z])?$/', (string) $n);
    }
}
