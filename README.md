# BlackCat Messaging

Distribuovaný messaging a orchestrace pro celý BlackCat ekosystém. Cílem repozitáře je oddělit komponenty, které v `blackcat-core/src/Messaging`, `JobQueue.php` a `Cron` zajišťují event-driven spolupráci, a nabídnout je jako modulární, rozšiřitelný engine použitelný napříč microservices, edge workerem i front-end notifikacemi.

## Stage 1 – Deliverables ✅

- **Config loader** – `MessagingConfig::fromFile()` a `config/example.messaging.php` (PostgreSQL transport + scheduler, `storage_dir` pro lokální event log). CLI může použít `BLACKCAT_MESSAGING_CONFIG_FILE=...`.
- **MessagingManager** – sjednocená fasáda, zapisuje do transportu/scheduleru + `LocalEventStore` (tail CLI).
- **Reference transporty** – `InMemory` a `PostgresTransport` (LISTEN/NOTIFY-ready) + `PostgresScheduler`.
- **CLI** – `publish`, `schedule`, `tail` + integrace na config file. Vše navazuje na `blackcat-orchestrator` (manifesty generuje `blackcat-data`).
- **Telemetry** – lokální NDJSON pro dev, připraveno na Prometheus counters ve Stage 2.

## Klíčové vlastnosti (cílový stav)

- Unifikované rozhraní (`MessagingManager`) pro publish/schedule/listen.
- Pluggable transporty (PostgreSQL hotovo, Stage 2 přidá Redis/Kafka).
- Outbox + CDC ready – napojeno na `blackcat-database-sync`.
- Scheduler & Cron – Postgres job queue, Stage 2 leader election.
- Dev tooling – CLI + event tail, Stage 3 dashboard/WebSocket.
- Bezpečnost – standardizované `MessageEnvelope`, HMAC přes `blackcat-crypto`.

## Struktura repo

```
blackcat-messaging/
├── src/
│   ├── MessagingManager.php   # fasáda
│   ├── Transport/             # jednotlivé adaptéry
│   ├── Scheduler/             # cron & delay queue
│   ├── Contracts/             # DTO, interface
│   └── Support/               # serializace, metriky
├── docs/ROADMAP.md
├── README.md
├── composer.json
└── tests/
```

## Integrace

- **blackcat-database-sync** – outbox pluginy mohou pushovat CDC změny přímo do MessagingManageru, čímž se sjednotí streaming.
- **blackcat-auth** – audit hooky mohou emitovat security events; messaging poskytne SSE feed pro FE.
- **blackcat-crypto** – podepisování zpráv a šifrování payloadů (např. tajné klíče, KMS instrukce).

## Rychlý start

```
composer install

export BLACKCAT_MESSAGING_CONFIG_FILE=blackcat-messaging/config/example.messaging.php

# publish event from CLI
php bin/messaging publish auth.login '{"tenant":"eu-1","user":123}'

# schedule task
php bin/messaging schedule cleanup '+15 minutes' '{"tenant":"eu-1"}'

# tail local dev events
php bin/messaging tail
```
```

V applikačním kódu:

```php
use BlackCat\Messaging\MessagingManager;
use BlackCat\Messaging\Config\MessagingConfig;

$manager = MessagingManager::boot(MessagingConfig::fromEnv());
$manager->publish('billing.invoice_created', ['invoiceId' => 123, 'tenant' => 'eu-1']);
$manager->schedule('daily-report', '+15 minutes', ['tenant' => 'eu-1']);
```

Podrobné milníky najdeš v `docs/ROADMAP.md`.
