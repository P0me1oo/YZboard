# Changelog

## 1.0.1 - 2026-07-06

- Added `node:sync-users` to periodically reconcile online node user lists with
  the panel's current eligibility rules.
- Scheduled node user reconciliation every five minutes so naturally expired
  users are removed from node runtime authentication tables without requiring a
  node restart.
