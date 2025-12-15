# BlackCat Messaging – Roadmap

## Stage 1 – Foundations ✅
- Composer balík, `MessagingConfig` (env + YAML/PH P), `MessagingManager::boot()` + `LocalEventStore`.
- DTO (`MessageEnvelope`) a kontrakty (`TransportInterface`, `SchedulerInterface`).
- Referenční transporty: In-memory + PostgreSQL LISTEN/NOTIFY (`PostgresTransport` + `PostgresScheduler`).
- ✅ CLI skeleton `bin/messaging` (`publish`, `schedule`, `tail`), config file support.

## Stage 2 – Reliable Delivery & CDC Integration
- At-least-once + idempotence helpers, deduplikace, DLQ storage (Postgres + Redis varianty).
- Outbox bridge pro `blackcat-database` (`EnqueueOnCommit` trait) a `blackcat-database-sync`.
- ✅ DB-backed outbox workery: `event_outbox` → messaging transport + `webhook_outbox` → HTTP dispatch (single source of truth: `blackcat-database/views-library`).
- Scheduler modul s leader election (Postgres advisory locks), catch-up, jitter injection, CLI `schedule:list`.
- TODO Přesunout `PostgresTransport` schema do `blackcat-database` package (bez raw PDO; použít `BlackCat\\Core\\Database` + generated repo).

## Stage 3 – Multi-Protocol Bus
- Kafka/NATS/Redis Streams/AMQP adaptér se sjednocenými capabilities (batch ack, consumer groups).
- Streaming API (`/stream` SSE + WebSocket proxy) – plně kompatibilní s front-end SDK.
- Webhook connector (signed deliveries) + retry/backoff policies sdílené s `blackcat-core/Retry`.

## Stage 4 – Observability & Governance
- Prometheus metrics (`messaging_queue_backlog`, `job_latency`, `consumer_lag`) + OTLP tracing.
- Policy engine (per-tenant quotas, rate-limit, max payload) napojený na `blackcat-auth` claims.
- Audit & replay: `messaging replay` CLI, immutable history do `blackcat-database` (timescale or S3).
- Webhook connectors navázané na virtuální outbox (`blackcat-core`) – notifikace při odeslání.

## Stage 5 – Zero Trust & Edge Federation
- End-to-end šifrované zprávy přes `blackcat-crypto` (AEAD, envelope per recipient).
- Edge gateway (Rust/Go worker) pro IoT/web push s token-bound channely.
- Auto-scaling orchestrator (KEDA/autonomous) + integration s `blackcat-orchestrator` (budoucí repo).
