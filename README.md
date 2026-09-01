# GgSell — ядро магазина цифровых товаров

Бэкенд-ядро площадки цифровых товаров: каталог, заказы, приём платёжных вебхуков
и автоматическая выдача кодов через поставщиков-заглушек.

Задача решена вокруг трёх свойств, которые нельзя получить аккуратностью кода и
приходится закладывать в схему данных: **однократность выдачи под гонками**,
**безопасный повтор после таймаута** и **сходимость денежного журнала**.

Стек: PHP 8.5, Laravel 13, PostgreSQL 17, nginx + php-fpm, очередь на Postgres.

---

## Быстрый старт

```bash
cp .env.example .env                  # уже настроен на docker-сеть
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

API поднимется на `http://localhost:8000`, Postgres — на `localhost:55432`.

Проверка:

```bash
curl -s 'http://localhost:8000/api/v1/products?limit=3'
```

Контейнеры: `web` (nginx), `app` (php-fpm), `worker` (очередь выдачи),
`scheduler` (восстановление и сверка), `postgres`.

---

## API

| Метод | Путь | Назначение |
|---|---|---|
| `GET` | `/api/v1/products` | Витрина с остатками, keyset-пагинация (`type`, `in_stock`, `cursor`, `limit`) |
| `POST` | `/api/v1/orders` | Создать заказ по SKU. Принимает `Idempotency-Key` |
| `GET` | `/api/v1/orders/{order_id}` | Заказ. Код возвращается только в статусе `delivered` |
| `POST` | `/api/v1/webhooks/payment` | Вебхук платёжной системы (контракт из задания) |
| `GET` | `/api/v1/ops/reconciliation` | Отчёт сверки. `200` — сходится, `409` — расхождение |
| `POST` | `/api/v1/ops/orders/{order_id}/redeliver` | Ручное дожатие заказа |
| `POST` | `/api/suppliers/{a\|b}/issue` | Заглушка поставщика (контракт из задания) |
| `GET` | `/api/suppliers/{a\|b}/stock` | Остатки поставщика |

Пример сквозного прохода:

```bash
# 1. Заказ
ORDER=$(curl -s -X POST localhost:8000/api/v1/orders \
  -H 'Content-Type: application/json' \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"sku":"KEY-CS2-PRIME"}' | jq -r .data.order_id)

# 2. Оплата (эмуляция вебхука платёжки)
curl -s -X POST localhost:8000/api/v1/webhooks/payment \
  -H 'Content-Type: application/json' \
  -d "{\"event_id\":\"evt_$RANDOM\",\"order_id\":\"$ORDER\",\"status\":\"paid\",
       \"amount\":1290,\"currency\":\"RUB\",\"created_at\":\"$(date -Iseconds)\"}"

# 3. Результат
sleep 3 && curl -s localhost:8000/api/v1/orders/$ORDER | jq
```

---

## Воспроизведение проверок

### 1. Гонки: 50 параллельных вебхуков по одному заказу

```bash
docker compose exec app php artisan chaos:race --n=50 --mode=distinct
docker compose exec app php artisan chaos:race --n=50 --mode=same
```

`distinct` — пятьдесят **разных** `event_id` по одному заказу: дедуп по
`event_id` их не отсекает, всё ложится на блокировку строки заказа.
`same` — пятьдесят повторов одного события: проверяется сам дедуп.

Команда создаёт заказ, стреляет залпом через `curl_multi` в настоящий HTTP,
дожидается завершения выдачи и проверяет инварианты **по данным**, а не по
ответам:

```
| Выдач по заказу                  | 1         | OK |
| Ключей списано у поставщиков     | 1         | OK |
| Событий "оплачено" применено     | 1         | OK |
| Несходящихся проводок            | 0         | OK |
| Сальдо обязательства (выдан → 0) | 0         | OK |
| Статус заказа                    | delivered | OK |
```

Ненулевой код возврата при любом нарушении.

### 2. Отказ поставщика и фолбэк

```bash
docker compose exec app php artisan chaos:supplier a error   # A отвечает 503
docker compose exec app php artisan chaos:supplier b ok
# создать заказ и оплатить (см. пример выше)
```

Результат — две попытки, выдача одна:

```
 supplier | attempt_no |  status   |  error_reason  | http_status
----------+------------+-----------+----------------+-------------
 a        |          1 | failed    | supplier_error |         503
 b        |          1 | succeeded |                |         200
```

### 3. Ловушка таймаута

```bash
docker compose exec app php artisan chaos:supplier a timeout
# создать заказ и оплатить
```

В режиме `timeout` заглушка **сначала списывает ключ и регистрирует запрос**, и
только потом зависает. То есть поставщик действительно выдал код, а ответ не
дошёл — ровно тот случай, в котором наивный повтор приводит ко второй выдаче.

Результат:

```
 supplier | attempt_no | tries |  status   | latency_ms |               request_id
----------+------------+-------+-----------+------------+----------------------------------------
 a        |          1 |     2 | succeeded |         93 | req_ord_01m1cjbmqs6wdgv9kcb5m3z2nt_a_1

 ключей_списано: 1
```

Две сетевые попытки, **один** `request_id`, **одна** строка попытки, **один**
списанный ключ. Повтор после таймаута — это не новый запрос, а переспрашивание
того же.

Вернуть заглушки к случайному поведению: `php artisan chaos:supplier a random`.

### 4. Пустой остаток

```bash
docker compose exec app php artisan chaos:supplier a out_of_stock
docker compose exec app php artisan chaos:supplier b out_of_stock
```

Заказ переходит в `out_of_stock` — восстановимое состояние, не падение. После
`chaos:supplier a ok` фоновое дожатие доводит его до `delivered` без задвоения.

### 5. Сверка

```bash
docker compose exec app php artisan orders:reconcile --grace=30
curl -s 'localhost:8000/api/v1/ops/reconciliation?grace_seconds=30' | jq
```

---

## Тесты

```bash
docker compose exec app php artisan test                        # всё, 66 тестов
docker compose exec app php artisan test --testsuite=Unit       # машины состояний, журнал
docker compose exec app php artisan test --testsuite=Feature    # критерии 2–6
docker compose exec app php artisan test --testsuite=Integration # критерий 1, нужен поднятый стек
```

Тесты идут против **настоящего PostgreSQL** (`ggsell_test`), а не SQLite: всё,
что здесь проверяется — блокировки строк, `SKIP LOCKED`, поведение при нарушении
ограничений — в SQLite либо отсутствует, либо работает иначе.

Две оговорки, о которых стоит знать заранее:

- Набор `Integration` требует **поднятого стека** и молча пропускается без него
  (с явным предупреждением в выводе). Без `docker compose up` критерий 1 не
  проверяется, хотя прогон остаётся зелёным.
- Этот же набор ходит в **рабочую** базу `ggsell`, а не в тестовую: он
  обстреливает настоящий HTTP-эндпоинт через nginx, а тот работает с рабочей
  базой. Прогон тестов создаёт реальные заказы и списывает ключи из пулов
  заглушек. Это сознательный размен: герметичность против того, чтобы проверка
  гонок действительно проверяла гонки. Вернуть чистое состояние —
  `php artisan migrate:fresh --seed`.

Соответствие критериям приёмки:

| Критерий | Где проверяется |
|---|---|
| 1. 50 параллельных вебхуков → одна выдача | `chaos:race`, `tests/Integration/ParallelWebhookRaceTest` |
| 2. Повтор `event_id` ничего не меняет | `PaymentWebhookTest::повторный_вебхук_...` |
| 3. Вебхук вне порядка / раньше заказа | `PaymentWebhookTest::вебхук_пришедший_раньше_заказа_...`, `...протухшее_событие...` |
| 4. Таймаут поставщика, который выдал код | `TimeoutTrapTest` (3 теста), `SupplierStubContractTest::режим_таймаута_...` |
| 5. A недоступен → фолбэк на B, одна выдача | `SupplierFallbackTest::при_определённом_отказе_...` |
| 6. Пустой остаток, восстановимое состояние | `SupplierFallbackTest::пустой_остаток_...`, `...после_пополнения_...` |

---

## Каталог под нагрузкой

```bash
docker compose exec app php artisan catalog:seed-load --skus=50000 --keys-per-sku=4
```

50 000 SKU и 400 000 ключей. Замеры на этом объёме — в
[NOTES.md](NOTES.md#этап-5-каталог-под-нагрузкой).

Кратко: горячий запрос витрины — **0.69 мс**, `Index Only Scan`,
`Heap Fetches: 0`, 105 буферов. Наивный вариант с `COUNT(*)` по пулу ключей и
`OFFSET` — **164 мс** и 4471 буфер.

Витрина «только в наличии» на распроданном на 95% каталоге: **0.38 мс** против
**3.21 мс** у варианта с фильтром по счётчику в присоединяемой таблице, и
стоимость второго растёт по мере распродажи, а первого — нет.

---

## Полезные команды

```bash
php artisan orders:reconcile [--json] [--grace=60]  # сверка
php artisan orders:resolve-stuck                    # дожать зависшие заказы
php artisan payments:replay                         # применить события без заказа
php artisan stock:refresh                           # обновить проекцию остатков
php artisan chaos:race --n=50 --mode=distinct       # проверка гонок
php artisan chaos:supplier a timeout                # режим заглушки
php artisan catalog:seed-load --skus=50000          # нагрузочный каталог
```

Логи:

```bash
docker compose exec app tail -f storage/logs/payments-$(date +%F).log
docker compose exec app tail -f storage/logs/delivery-$(date +%F).log
```

Структурированный JSON со сквозным `correlation_id`. Вся история заказа —
приём вебхука, применение платежа, работа воркера, обращения к поставщикам —
собирается одним grep по одному значению.

---

## Затраченное время

<!-- ЗАПОЛНИТЬ ПЕРЕД ОТПРАВКОЙ: задание требует этот пункт явно. -->

| Этап | Часы |
|---|---|
| 0. Инфраструктура (Docker, Postgres, схема) | |
| 1–2. Ядро API и exactly-once | |
| 3. Устойчивые интеграции и ловушка таймаута | |
| 4. Сверка, журнал, восстановление | |
| 5. Каталог под нагрузкой | |
| Тесты, ревью, документация | |
| **Итого** | |

## Состязательный ревью

Решение прогнали через независимый разбор в три круга. Первый вскрыл два пути
ко второй выдаче, три способа потерять платёж и несколько эксплуатационных
дефектов. Второй разбирал уже сами исправления — и нашёл в них ещё пять
дефектов, включая один, из-за которого сверка оставалась красной на штатных
данных, и одно «исправление», сломавшее то, что чинило. Третий прошёл по всем
шести критериям приёмки с независимой перепроверкой SQL и нашёл состояние, в
котором оплаченный заказ навсегда оставался без товара и невидим для
собственной сверки.

Всё закрыто регрессионными тестами; для ключевых проверено, что тест краснеет
при возврате бага. Разбор находок — в
[NOTES.md](NOTES.md#состязательный-ревью-и-что-он-нашёл).

## Что дальше

Ключевые решения, их обоснование и план масштабирования — в [NOTES.md](NOTES.md).
