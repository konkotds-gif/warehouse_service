<?php

return [
    // Цифр в номере договора: 000123
    'contract_digits' => (int) env('STORAGE_CONTRACT_DIGITS', 6),

    // Сколько часов данные о долге из 1С считаются свежими
    'debt_fresh_hours' => (int) env('STORAGE_DEBT_FRESH_HOURS', 24),

    // Блокировать выдачу при долге (план, раздел 4)
    'block_issue_on_debt' => (bool) env('STORAGE_BLOCK_ISSUE_ON_DEBT', true),

    'sms_driver' => env('SMS_DRIVER', 'log'),

    'onec' => [
        // Событие без подтверждения дольше N минут — уведомление администратору
        'ack_alert_minutes' => (int) env('ONEC_ACK_ALERT_MINUTES', 30),
        'alert_email' => env('ONEC_ALERT_EMAIL'),
        // Хранить записи integration_inbox, дней
        'inbox_retention_days' => 90,
    ],
];
