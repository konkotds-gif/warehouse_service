<?php

namespace App\Http\Api;

use SimpleXMLElement;
use XMLWriter;

/**
 * XML для 1С в режиме совместимости 8.2 (ЧтениеXML/ЗаписьXML): сериализация ответов и разбор тел запросов.
 */
final class Xml
{
    private const SINGULAR = [
        'events' => 'event', 'sets' => 'set', 'clients' => 'client', 'contracts' => 'contract',
        'vehicles' => 'vehicle', 'garage_sets' => 'garage_set', 'movements' => 'movement', 'items' => 'item',
        'errors' => 'error', 'warnings' => 'warning', 'payments' => 'payment', 'lines' => 'line',
    ];

    public static function encode(mixed $data, string $root = 'response'): string
    {
        $w = new XMLWriter;
        $w->openMemory();
        $w->setIndent(true);
        $w->startDocument('1.0', 'UTF-8');
        self::write($w, $root, $data);
        $w->endDocument();

        return $w->outputMemory();
    }

    private static function write(XMLWriter $w, string $name, mixed $value): void
    {
        $name = self::safeName($name);
        $w->startElement($name);
        if (is_array($value)) {
            // Служебный ключ «@attributes» — атрибуты элемента (например, у event)
            foreach ($value['@attributes'] ?? [] as $attr => $attrValue) {
                $w->writeAttribute(self::safeName((string) $attr), self::scalar($attrValue));
            }
            unset($value['@attributes']);
            if (array_is_list($value)) {
                $child = self::SINGULAR[$name] ?? 'item';
                foreach ($value as $item) {
                    self::write($w, $child, $item);
                }
            } else {
                foreach ($value as $k => $v) {
                    self::write($w, (string) $k, $v);
                }
            }
        } elseif ($value !== null) {
            $w->text(self::scalar($value));
        }
        $w->endElement();
    }

    private static function scalar(mixed $v): string
    {
        return match (true) {
            is_bool($v) => $v ? 'true' : 'false',
            is_float($v) => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') ?: '0',
            $v instanceof \BackedEnum => (string) $v->value,
            $v instanceof \DateTimeInterface => $v->format(DATE_ATOM),
            default => (string) $v,
        };
    }

    private static function safeName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $name) ?? 'item';

        return preg_match('/^[A-Za-z_]/', $name) ? $name : 'n'.$name;
    }

    /** Разбор XML-тела запроса в массив (атрибуты и дочерние элементы — в одном уровне). */
    public static function decode(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        $el = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_use_internal_errors($prev);
        if ($el === false) {
            throw new \InvalidArgumentException('Некорректный XML');
        }

        $out = self::toArray($el);

        return is_array($out) ? $out : [];
    }

    private static function toArray(SimpleXMLElement $el): array|string|null
    {
        $out = [];
        foreach ($el->attributes() as $k => $v) {
            $out[$k] = self::cast((string) $v);
        }
        $children = [];
        foreach ($el->children() as $child) {
            $children[$child->getName()][] = self::toArray($child);
        }
        foreach ($children as $name => $items) {
            // Повторяющиеся элементы и обёртки-списки (<sets><set/></sets>) → список
            $out[$name] = count($items) > 1 ? $items : $items[0];
        }
        if ($out === []) {
            $text = trim((string) $el);

            return $text === '' ? null : self::cast($text);
        }
        foreach ($out as $name => $value) {
            if (isset(self::SINGULAR[$name]) && is_array($value) && array_keys($value) === [self::SINGULAR[$name]]) {
                $inner = $value[self::SINGULAR[$name]];
                $out[$name] = is_array($inner) && array_is_list($inner) ? $inner : [$inner];
            }
        }

        return $out;
    }

    private static function cast(string $v): string|bool
    {
        return match (strtolower($v)) {
            'true' => true,
            'false' => false,
            default => $v,
        };
    }
}
