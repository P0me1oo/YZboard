# Node User Sync Reconciliation

YZboard keeps node authentication state in sync with the panel through WebSocket
push events. User edits, bans, plan changes, and traffic exhaustion can trigger
targeted user updates, but a natural subscription expiry is a time-based state
change: the `expired_at` value is already stored in the database and does not
change when the clock passes it.

To prevent online nodes from keeping stale users in memory, YZboard schedules a
periodic user-list reconciliation:

```bash
php artisan node:sync-users
```

The command finds enabled online nodes, recalculates each node's currently
available users with `ServerService::getAvailableUsers()`, and pushes a
`sync.users` event to the node. Expired, banned, or traffic-exhausted users are
not included in that list, so the node replaces stale runtime users with the
panel's current result.

The scheduler runs this command every five minutes. Operators can also run it
manually after changing user eligibility rules or when validating node state.

To target specific nodes:

```bash
php artisan node:sync-users --node=127 --node=131
```

This command does not modify user, order, traffic, or server records. It only
publishes WebSocket sync messages through Redis.
