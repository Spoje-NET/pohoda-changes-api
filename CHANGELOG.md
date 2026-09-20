# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Added

- MSSQL poll mode (`POLL_MODE=mssql`): discover changes via `DatSave` using `spojenet/pohoda-sql`, load documents only for those IDs from mServer
- `Agenda::idsChangedSince()` / `maxChangedAt()` in pohoda-sql
- Per-unit `poll_mode` and expanded `unit-add` MSSQL options
- `POLL_MSSQL_SEED_ONLY` / `POLL_MSSQL_LIMIT` controls

## [0.1.0] - 2026-09-20

### Added

- Initial release of Pohoda Changes API
- Multi accounting-unit registry keyed by IČO + accounting year + mServer URL
- mServer `lastChanges` poller with full document download into `record_cache`
- MultiFlexi-compatible `changes_cache` events
- Cache HTTP API with JSON, YAML and XML (Stormware schemas via pohodaser)
- Outbound webhook fan-out with evidence/operation/IČO filters
- Debian packaging, cron poller and Apache config

