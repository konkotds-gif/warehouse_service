# API для 1С:УТ 10.3

Платформа — REST-сервер, 1С — клиент: 1С сама опрашивает платформу и отправляет ей данные.
Полная интерактивная схема со всеми полями — `/docs/api` (Scramble, вход под администратором).

## Подключение

- Базовый адрес: `https://<домен>/api/v1/`
- Ключ: заголовок `X-Api-Key: <ключ>`. Ключ выпускается командой `php artisan onec:key "1С УТ" --ips=<IP 1С>`
  или в интерфейсе «Администрирование → Интеграция». В БД хранится только хэш. Если задан список IP, запросы с других адресов получают `403`.
- Формат: `Accept: application/json` или `Accept: application/xml` (для 1С 8.2 удобнее XML).
  Тело POST/PUT — JSON или XML (`Content-Type: application/xml`, корневой элемент любой).
- Идемпотентность: на каждый POST/PUT передавайте `Idempotency-Key: <уникальная строка, например GUID документа>`.
  Повтор с тем же ключом и тем же телом вернёт сохранённый ответ; тот же ключ с другим телом — `409`.
- Лимит: 600 запросов в минуту на ключ.

Коды ответов: `200/201` — успех; `400` — ошибка в данных (поле `errors`); `401` — нет/неверный ключ;
`403` — IP не разрешён; `404` — не найдено; `409` — конфликт (идемпотентность или бизнес-правило); `422` — нарушение бизнес-правила.

## Очередь событий (платформа → 1С)

### `GET integration/events?after={id}&limit=100`

1С хранит у себя последний обработанный `id` и запрашивает события после него (курсор, без смещений).
`limit` — до 500. Параметр `undelivered=1` вернёт только неподтверждённые.

```json
{
  "events": [
    {"id": 41, "type": "contract.created", "created_at": "2026-11-01T10:30:00+05:00",
     "delivered": false, "data": { "contract_number": "000123", "...": "..." }}
  ],
  "last_id": 41,
  "has_more": false
}
```

В XML атрибуты события (`id`, `type`, `created_at`) — атрибутами элемента `<event>`, данные — дочерними элементами.

| Тип | Когда | Что сделать в 1С |
|---|---|---|
| `client.created` | В магазине заведён новый клиент | Создать контрагента, в ack вернуть `client_onec_guid` |
| `contract.created` | Принят комплект, создан договор | Создать реализацию услуги хранения, в ack вернуть `onec_doc_guid` |
| `contract.extended` | Оформлена пролонгация договора (документ ПР00001) | Документ продления (реализация услуги), в ack вернуть `onec_doc_guid` — он привяжется к документу пролонгации |
| `contract.closed` | Все комплекты выданы | Закрыть договор |
| `set.issued` | Комплект выдан клиенту | Если `overdue` не пуст — начислить плату за хранение после срока |
| `set.overdue` | Срок хранения истёк | Начисление/уведомление |
| `transfer.completed` | Перемещение принято полностью | Перемещение между складами 1С |

Данные договора (`contract.*`): `contract_number`, `contract_id`, `status`, `client_id`, `client_onec_guid`,
`client_name`, `client_phone`, `vehicle{plate,make,model}`, `store_code`, `store_onec_guid`, `service_code`,
`service_onec_guid`, `accepted_at`, `storage_until`, `months`, `paid_until`, `amount`, `onec_doc_guid`,
`sets[]{qr_code, qty, tire_size, with_rims, condition, season, status, address, service_code, service_onec_guid, price, amount}`.

Стоимость считается по прайсу диаметров: у каждого комплекта своя строка услуги — `service_code` (код номенклатуры 1С,
например `ЦБ000335` для R16), `price` (цена за 1 колесо в месяц), `qty` (колёс) и `amount = price × qty × months`.
Для документа реализации в 1С — одна строка на комплект: номенклатура `service_code`, количество `qty × months`, цена `price`.
`contract.extended` дополнительно содержит `extended_months`, `extra_amount`, реквизиты документа пролонгации
`extension{id, number, seq, date, months, period_from, previous_until, storage_until, amount}` (`number` — например `ПР00001`,
`seq` — какое по счёту продление договора, `storage_until` — новая дата окончания хранения) и
`extension_lines[]{qr_code, service_code, service_onec_guid, diameter, qty, months, price, amount}` по текущему прайсу.
GUID документа 1С из ack для `contract.extended` записывается в документ пролонгации, а не в договор.

`set.issued` содержит `overdue{service_code, days, rate, per, amount}` (или `null`): плата за дни после окончания срока
и бесплатного периода. `per` = `contract` (ставка за договор в день) или `wheel` (за колесо в день). Код услуги и условия
задаются в «Тарифы → Условия хранения».

Данные перемещения: `transfer_number`, `status`, `from_code`, `from_onec_guid`, `to_code`, `to_onec_guid`,
`shipped_at`, `received_at`, `sets[]{qr_code, scanned_out, scanned_in, ...}`.

### `POST integration/ack`

Подтверждение одного события или пакета:

```json
{"event_id": 41, "onec_doc_guid": "6f1c...", "note": "Реализация №123"}
{"events": [{"event_id": 41, "onec_doc_guid": "..."}, {"event_id": 42, "client_onec_guid": "..."}]}
```

Ответ: `{"results": [{"event_id": 41, "status": "ok"}]}` (`not_found` для неизвестных id).
Если событие не подтверждено дольше 30 минут, администратор получает предупреждение.

## Справочники (1С → платформа)

| Метод | Тело | Назначение |
|---|---|---|
| `PUT clients/{guid}` | `full_name`, `phone`, `email`, `consent_sms`, `client_id`? | Создать/обновить контрагента. `client_id` — связать с клиентом, созданным в магазине |
| `PUT services/{guid}` | `code`, `name`, `price`, `period` (season/month), `months`, `is_active`, `diameter_from`, `diameter_to` | Услуга хранения 1С. Ищется по guid, затем по коду: так 1С обновляет цену строки прайса (ЦБ…); диаметры, если не переданы, не меняются |
| `PUT locations/{guid}` | `code`, `name`, `type` (store/warehouse), `address` | Магазин или склад (код склада — 2 цифры, магазина — `М` + цифры) |

## Запросы из 1С

| Метод | Ответ |
|---|---|
| `GET clients/search?phone=&plate=&onec_guid=` | Клиенты с договорами (нужен хотя бы один параметр) |
| `GET clients/{id или guid}/garage` | Машины клиента, закреплённые комплекты, где лежат |
| `GET contracts/{номер}` | Договор, комплекты, статусы, адреса, `paid_until`, `debt`, `extensions[]` — пролонгации с `onec_doc_guid` |
| `GET sets/{qr}` | Комплект по QR бирки, текущий адрес и история движений |
| `GET stock?location=02&status=placed&limit=1000` | Остатки на хранении |

## Деньги (1С → платформа)

### `POST integration/payments`

```json
{"contract": "000123", "amount": 4800, "paid_until": "2027-05-01",
 "onec_doc_guid": "…", "paid_at": "2026-11-01T12:00:00"}
```

Повтор с тем же `onec_doc_guid` не создаёт второй платёж (ответ `200` вместо `201`).

### `PUT contracts/{номер}/debt`

`{"debt": 1200}` — текущий долг по договору. Пока долг больше нуля, выдача комплекта заблокирована.
Если данные о долге старше 24 часов, продавец видит предупреждение. Передавайте `0`, когда долг погашен.

## Статусы

Комплект: `accepted` (в буфере магазина), `in_transit`, `received` (на складе, не размещён), `placed` (в ячейке),
`ordered` (заказан к выдаче), `ready` (готов к выдаче), `issued`, `disposed`, `lost`.
Договор: `open`, `overdue`, `closed`.
Адрес комплекта (`address`): `XX_YY_ZZ` — ячейка, `TR-П00012` — в пути, `М01` — буфер магазина, `02 (приёмка)` — на складе, не размещён, `ISSUED` — выдан, `DISPOSED` — утилизирован.

## Порядок внедрения в 1С

1. Регламентное задание каждые 1–5 минут: `GET integration/events?after=<последний id>`, обработка, `POST integration/ack`.
2. Выгрузка справочников (`PUT clients/services/locations`) при изменении в 1С.
3. При проведении оплаты — `POST integration/payments`; при изменении долга — `PUT contracts/{номер}/debt`.
