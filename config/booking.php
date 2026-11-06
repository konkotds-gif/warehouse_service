<?php

/*
 * Интеграция с онлайн-записью (zapis.diskimira.com): когда колёса с хранения могут быть в магазине.
 * Подвоз со склада — на следующий день, первая машина с колёсами приезжает к 12:00 (решение 2026-11-06).
 */
return [
    'delivery_days' => (int) env('BOOKING_DELIVERY_DAYS', 1),
    'delivery_time' => env('BOOKING_DELIVERY_TIME', '12:00'),
    // Предупреждать, если до записи меньше этого срока, но всё равно заказывать
    'lookup_limit' => (int) env('BOOKING_LOOKUP_LIMIT', 30),
];
