# Mail plugins — the Email Log family

Source audit (2026-07-09) behind the `family: mail` surfaces. None of these
plugins ship public REST for their logs, so every adapter is the read-only
shim pattern from shim-tutorial.md: prefix-scoped prepared SELECTs, `{items,
total}`, serialized blobs mined with regexes and never unserialized.

## Shipped adapters

| Plugin | Storage | Time column | Notes |
|---|---|---|---|
| Gravity SMTP | `{prefix}gravitysmtp_events` | UTC datetime | Deep adapter (v0.12.0 + v0.13.0 + v0.16 bulk delete): log + enriched detail through their models, Settings view mapped from their `settings_fields()` schema (all 21 connectors), Suppressions view, **Debug log** view (first `views[]` consumer), status card with a 14-day sent/failed **chart** (first status-card chart consumer), send-a-test, single/bulk **Delete** through `Event_Model::delete` (`DELETE_EMAIL_LOG`), granular `gravitysmtp_*` caps; Resend mirrors their own endpoint (allowlisted Recipient unserialize, original headers/attachments) with the regex path as fallback |
| FluentSMTP | `{prefix}fsmpt_email_logs` | `current_time('mysql')` — site-LOCAL, emit raw | Full log, free. `to`/`headers` serialized; statuses sent/failed; Resend via `wp_mail`; **search** on to/from/subject (mirrors `Logger::$searchables`) and single/bulk **delete** via `Logger::delete(ids)` with prefix-scoped SQL fallback. **Settings tab** (v0.18.0): default/fallback connection, log_emails, retention days, simulate_emails through `Settings::getMisc()/updateMiscSettings()` (their controller's exact write; simulation locked when the `FLUENTMAIL_SIMULATE_EMAILS` constant forces it; connection wizard stays their app). A common ACTIVE provider in local testing (local SMTP sink + seeded sent rows) |
| Post SMTP | `{prefix}post_smtp_logs` (+ `post_smtp_logmeta`) | `time` BIGINT = `current_time('timestamp')` — WP-LOCAL epoch, shift by gmt_offset (the Aryo trap) | All-longtext columns. `success` = `''`/`'1'` when delivered, error text otherwise. `session_transcript` deliberately NOT exposed (can carry AUTH exchanges) |
| WP Mail SMTP (free) | `{prefix}wpmailsmtp_debug_events` | `CURRENT_TIMESTAMP` — DB clock, UTC in practice, emit ISO Z | Free stores NO full email log (Pro's `wpmailsmtp_emails_log`). Debug events answer "did my mail fail and why": event_type 0 = error, 1 = debug. `initiator` is `{"file","line"}` JSON → basename:line. Table exists only after the plugin's admin-context migration |
| WP Mail Logging | `{prefix}wpml_mails` | `current_time('mysql')` — site-LOCAL, emit raw | Log-only (no SMTP). Resend through its own DI resender; delete is prefix-scoped. Shipped v0.12.0 cycle; often the ACTIVE resident alongside Gravity SMTP when SMTP plugins are rotated |

## Conventions

- One SMTP provider stays ACTIVE at a time in multi-provider testing
  (FluentSMTP or Gravity SMTP); the others are installed-but-deactivated so
  the sidebar shows one **Email** entry (same pattern as the redirects and
  activity-log families). WP Mail Logging coexists because it is log-only.
- Resend re-sends through `wp_mail()` (or the plugin's own resender), so
  the CURRENT mailer handles it and logs it as a fresh entry.
- SMTP plugins fight over `phpmailer_init` — never activate two at once
  expecting both to log.

## Later

- **WP Mail SMTP Pro / Easy WP SMTP Pro** full email logs
  (`wpmailsmtp_emails_log`) when a license is available for fixtures.
- **GoSMTP / SureMails / Site Mailer** (free-tier logs; ranked in
  `docs/plugin-support.md` wave 3).
- **Mail Bank** if demand shows up (same family slot).
