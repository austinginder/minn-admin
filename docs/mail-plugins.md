# Mail plugins — the Email Log family

Source audit (2026-07-09) behind the `family: mail` surfaces. None of these
plugins ship public REST for their logs, so every adapter is the read-only
shim pattern from extension-api.md: prefix-scoped prepared SELECTs, `{items,
total}`, serialized blobs mined with regexes and never unserialized.

## Shipped adapters

| Plugin | Storage | Time column | Notes |
|---|---|---|---|
| Gravity SMTP | `{prefix}gravitysmtp_events` | UTC datetime | Deep adapter (v0.12.0): log + enriched detail through their models, Settings view mapped from their `settings_fields()` schema (all 21 connectors), Suppressions view, send-a-test, granular `gravitysmtp_*` caps; Resend mirrors their own endpoint (allowlisted Recipient unserialize, original headers/attachments) with the regex path as fallback |
| FluentSMTP | `{prefix}fsmpt_email_logs` | `current_time('mysql')` — site-LOCAL, emit raw | Full log, free. `to`/`headers` serialized; statuses sent/failed; Resend via `wp_mail`. The dev site's ACTIVE provider (Mailpit connection, seeded sent rows) |
| Post SMTP | `{prefix}post_smtp_logs` (+ `post_smtp_logmeta`) | `time` BIGINT = `current_time('timestamp')` — WP-LOCAL epoch, shift by gmt_offset (the Aryo trap) | All-longtext columns. `success` = `''`/`'1'` when delivered, error text otherwise. `session_transcript` deliberately NOT exposed (can carry AUTH exchanges) |
| WP Mail SMTP (free) | `{prefix}wpmailsmtp_debug_events` | `CURRENT_TIMESTAMP` — DB clock, UTC in practice, emit ISO Z | Free stores NO full email log (Pro's `wpmailsmtp_emails_log`). Debug events answer "did my mail fail and why": event_type 0 = error, 1 = debug. `initiator` is `{"file","line"}` JSON → basename:line. Table exists only after the plugin's admin-context migration |

## Conventions

- One provider stays ACTIVE on the dev site (FluentSMTP); the others are
  installed-but-deactivated so the sidebar shows one **Email Log** entry
  (same pattern as the redirects and activity-log families).
- Resend re-sends through `wp_mail()`, so the CURRENT mailer handles it and
  logs it as a fresh entry.
- SMTP plugins fight over `phpmailer_init` — never activate two at once
  expecting both to log.

## Later

- **WP Mail SMTP Pro / Easy WP SMTP Pro** full email logs
  (`wpmailsmtp_emails_log`) when a license is available for fixtures.
- **Mail Bank / WP Mail Logging** (log-only plugins, no SMTP) would slot into
  the same family if demand shows up.
