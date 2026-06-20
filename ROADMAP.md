# Roadmap & design notes

This file tracks what's done, what's intentionally **not** done, and what's planned.
It exists partly to answer a thorough code review the project received — some
suggestions were applied, some were declined on purpose, and the reasoning matters.

## Design philosophy (read this first)

trueCORD's whole reason to exist is **"drop the files on cheap PHP shared hosting,
open the page, done."** No Docker, no Node, no separate database server, no build
step, no background worker process. Several otherwise-reasonable suggestions are
declined below specifically because they would break that promise. If you want a
heavyweight, horizontally-scalable chat server, this isn't trying to be that.

## Done

- **Upload type validation by content.** The server now sniffs the real MIME type
  of every upload from its bytes (`getimagesizefromstring` / `finfo`) instead of
  trusting the client-supplied MIME. Mismatched group (e.g. an executable claiming
  to be `image/jpeg`) is rejected; the file extension follows the detected type.
- **Rate-limiting on write actions.** Beyond the existing login brute-force guard,
  there are now per-user limits on sending messages and DMs, and on creating
  servers and channels (sliding window, stored in a `rate_limits` table).
- **Moderation audit log.** Mute, kick, global ban/unban and global-role changes are
  recorded in a `moderation_log` table (who, whom, action, reason, when). Admins can
  read it via the `get_moderation_log` API action.

## Intentionally not done (and why)

- **Separate CSRF tokens.** Not needed here. Auth uses a bearer token sent in the
  JSON request body (from `localStorage`), not an ambient cookie. CSRF relies on the
  browser auto-attaching credentials cross-site; a body token can't be replayed that
  way. Adding a second token would be cargo-cult security.
- **Switching to MySQL/PostgreSQL.** Declined as a default. SQLite in WAL mode with a
  15s busy timeout handles hundreds of concurrent readers fine for the target use
  case (small/medium communities). A real RDBMS would reintroduce exactly the "run a
  database server" burden the project avoids. If someone wants it, a pluggable PDO
  backend could be added later behind config — but it will never be required.
- **Rewriting polling into WebSockets.** WebSockets (Ratchet/Workerman) need a
  long-running PHP process, which most shared hosts don't allow. SSE has similar
  long-connection problems on cheap hosting. Polling is the deliberate trade-off that
  keeps "upload and go" true. See "Known limitations" below.

## Known limitations

- **Real-time uses polling.** The client periodically polls (`heartbeat`) for new
  messages and presence. This is simple and works everywhere, but it adds latency and
  scales worse than push. Acceptable for the intended community sizes; if you run a
  large/busy instance, this is the first thing you'd want to change (likely by adding
  an optional WebSocket sidecar for hosts that allow long-running processes).
- **SQLite write contention.** Reads scale well; sustained heavy concurrent *writes*
  (very large, very active instances) will eventually serialize. Fine for typical
  self-hosted communities.
- **`index.php` is a single large file.** Front-end HTML, CSS and JS live together in
  `index.php`. It works and ships with zero build step, but it's the main thing that
  makes the codebase look bigger than it is. Splitting it into separate `styles.css`
  and JS modules is a candidate refactor (kept low priority because it's invasive and
  risks regressions for no user-visible gain).

## Planned / candidate features

Roughly in priority order. None of these are commitments.

- Message search.
- Threads / replies as first-class threads.
- Push notifications (in addition to existing in-app notifications).
- A moderation-log viewer in the admin UI (the data and API already exist).
- More granular roles/permissions beyond owner/admin/moderator.
- Read receipts in DMs.
- Slash commands.
- Custom CSS themes via config.
- Optional 2FA (TOTP).
- Optional WebSocket transport for hosts that support a background process.
- DB backup helper script.

## Contributing

PRs that respect the no-build-step / no-extra-services philosophy are very welcome.
If you want to add something that needs a background process or an external service,
make it **optional** and config-gated so the default install stays drop-in.
