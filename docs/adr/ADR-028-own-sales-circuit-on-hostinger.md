# ADR-028 — Own sales circuit on Hostinger (Checkout Pro + verified webhook + gated delivery)

## Status
Accepted (2026-11-06). The dev confirmed the option (own circuit, "option B") and decisions D2–D4. D2 means launching without a manual bridge; D3 is the download link limits (30 days / 5 downloads); D4 is `binary_mode` (instant approvals only, no cash payments). Partially supersedes [ADR-025](./ADR-025-product-sales-via-mercadopago.md) (delivery and invoicing) and extends [ADR-027](./ADR-027-product-schema-and-lead-capture.md) (product schema). Living plan, status and decision log: [docs/ventas/README.md](../ventas/README.md).

## Context

ADR-025 chose Mercado Pago "Link de Pago" on two assumptions, both verified false on 2026-11-06:

1. **Link de Pago does not deliver files.** It only collects money (title, price, image, payment methods, installments, expiry). Automatic delivery always requires a system that receives MP's payment notification and sends the file.
2. **Mercado Pago does not issue the seller's invoice.** MP's receipt does not replace the factura; the owner must invoice each sale in ARCA, either by hand or through an invoicing service connected to MP.

Going `live` with only a Link de Pago would mean buyers pay and receive nothing, while [`/gracias`](../../src/pages/gracias.astro) tells them MP is sending the file.

The catalogue is now 3 kits of about 15 files each (PDF, DOCX, editable templates, printables) at AR$ 8.000. ADR-025 set its own Tier-B trigger at "≥3 live products".

Constraints carried over: a single non-technical owner; a single dev; Argentina-first (ARS, local payment methods); Hostinger-only ([ADR-022](./ADR-022-pivot-to-hostinger-only.md)); `npm run dev` stays a single command ([ADR-006](./ADR-006-dx-single-command-dev.md)). New hard requirement from the dev: **nobody can download a kit without a verified payment**, and fraud research and testing are part of the build, not an afterthought ([threat model](../ventas/threat-model.md), [test plan](../ventas/test-plan.md)).

## Decision

Build a minimal sales backend of our own in **PHP on the existing Hostinger plan**, using **Mercado Pago Checkout Pro** for payment.

1. **Checkout.** The buyer types their email twice on our kit page. A same-origin `fetch` to `POST /api/checkout.php` does three things:
   - creates the order, with a random 128-bit `external_reference`;
   - creates the MP preference with the **server-side price** from the catalogue, `binary_mode: true` (D4), cash methods excluded, a 60-minute expiry and `back_urls` → `/gracias`;
   - returns the checkout URL. JS then navigates there, so the current CSP (`form-action 'self'`) stays unchanged.
2. **Webhook** (`/api/webhook.php`):
   - **Where MP sends it (D7):** configured in MP's panel per environment. The test URL points to staging and the production URL to production, and panel-configured notifications carry the `x-signature`. A per-preference `notification_url` is used only if test INT-08 proves those notifications are signed too.
   - Verify `x-signature`: HMAC-SHA256 over `id:<data.id, lowercased>;request-id:<x-request-id>;ts:<ts>;`, omitting absent parts, compared in constant time.
   - Fetch `GET /v1/payments/{id}` with our token. The API is the source of truth, never the notification body.
   - Validate `status: approved` + `status_detail: accredited`, amount and currency (as integer cents), `collector_id`, `live_mode`, and an `external_reference` that belongs to one of our orders.
   - Apply an idempotent compare-and-set state transition, then issue a download token and send the email.
   - Return 401 on a bad signature, 200 on unknown or ignored resources, and 500 when MP's API fails, so MP retries every 15 minutes.
3. **Delivery.**
   - The email comes from `kits@fenia.com.ar` (Hostinger SMTP via vendored PHPMailer) and links to a static download page.
   - The token travels in the URL fragment (`#…`), so it never reaches server logs, and the file streams only on an explicit click (POST). Mail scanners and link previews therefore never consume it.
   - Tokens are 256-bit random values, stored hashed, valid for 30 days and 5 downloads (D3), and revoked on `refunded`, `charged_back` or `in_mediation`.
   - ZIPs live outside `public_html`, with their SHA-256 checksums in server config.
4. **Redundancy.** Four mechanisms cover failures, so a paid buyer never depends on a single channel:
   - `/gracias` asks the server for the order status (payment id + order code) and shows the download button directly.
   - "No me llegó" lets buyers resend on their own, but only to the purchase email.
   - An hourly cron reconciles MP's approved payments against deliveries.
   - A daily report and anomaly alerts reach the dev and the owner, and UptimeRobot watches `/api/health`.
5. **One source of truth for price.** The Astro build emits a catalogue (id, title, price, currency, status) from `src/content/products/*.md`, and PHP reads it from disk. Only `live` kits can be checked out.
6. **Layout and secrets.**
   - Endpoints live under `public/api/` and are copied verbatim to `dist/api/`, as `.htaccess` already is, so `npm run build` is unchanged.
   - Shared code lives in `public/api/_lib/`, denied by `.htaccess` and protected by an include guard in every file.
   - Config (MP token, webhook secret, DB, SMTP), ZIPs and logs live **outside `public_html`**, one set per environment. They are never in git and are out of reach of the deploy FTP account.
   - MySQL is accessed through PDO prepared statements. MP is called through direct REST (cURL); no SDK. Minimum PHP is 8.1, and CI tests 8.1 and 8.3 (D8).
   - **Fail closed:** without its server config, every endpoint answers "unavailable" and delivers nothing. Backend code can therefore reach production before launch with no risk.
   - **The GitHub repo is public.** Security never relies on hiding code. Paid files and secrets never enter the repo: `.gitignore` plus a CI guard that fails on archives or anything shaped like an MP credential.
7. **Environments.** `staging.fenia.com.ar` runs on MP test credentials with its own DB and is deployed from `dev`. Production is deployed from `main`, as today.
8. **Testing is a gate, not a phase.** Every risk in the threat model maps to tests in the test plan:
   - PHPUnit in CI, so no local PHP is needed;
   - a black-box security suite (`npm run test:ventas`) on Node's built-in test runner, with no new dependencies and never chained into `dev`;
   - MP sandbox integration and E2E runs on staging;
   - rehearsed human protocols against fake-receipt scams.

   Launch happens only when the gate in the test plan is fully green.
9. **Kill switch.** A flag in the server config disables checkout without a deploy, for a card-testing attack or an incident.

## Consequences

**Easier:**

- Delivery can be verified end to end, and every failure mode has a detection path: reconciliation, health check, daily report.
- No commission beyond MP's. The infrastructure is the already-prepaid Hostinger plan, and emails go out from FENIA's own domain.
- Buyer data stays with FENIA, which is a prerequisite for a meaningful privacy policy under Ley 25.326.
- Adding a kit stays a content edit: a `.md` file, a ZIP and one config entry.

**Harder / accepted trade-offs:**

- **The site stops being purely static.** There is now a PHP backend, MySQL and a cron to operate, and the dev owns that operation. Runbooks, alerts and the kill switch mitigate it.
- **Real engineering effort**, estimated at several days including tests.
- **Local `npm run dev` cannot run checkout**, because PHP does not run there. The kit page shows a dev-only notice, and checkout is exercised on staging.
- **New secrets to manage** (MP token, webhook secret, SMTP, DB), all outside git, with rotation documented.
- **CI grows**: a PHP lint + PHPUnit job and a staging deploy job (extends [ADR-020](./ADR-020-ci-minimal-github-actions.md)). `.htaccess` gains `/api` rules and blocks dotfiles ([ADR-023](./ADR-023-htaccess-hosting-config.md)).
- **Chargebacks on digital goods are not covered** by MP's seller protection. This residual risk is accepted, and evidence is logged for disputes.
- **Schema migration** ([ADR-027](./ADR-027-product-schema-and-lead-capture.md)): `paymentUrl` is deprecated, and `deliveryMode: "mercadopago-auto"` is replaced by a value describing this circuit. The `live` CTA points to the kit page instead of an external URL.
- **Invoicing remains manual or third-party** until the owner and their accountant decide how to automate it. It is out of the scope of this ADR.

## Alternatives considered

| Option | Why not |
|---|---|
| Argentine digital-product platforms (DropyFile, Pagy, …) connected to MP | Faster, but they are young companies with terms and fees we could not verify. They add a fee per sale, hold the files and buyer data, and their delivery reliability is not observable by us |
| Link de Pago + manual delivery by the owner | Depends on the owner being available. It does not meet "arrives automatically", and manual delivery is the main vector for fake-receipt scams |
| Link de Pago + no-code automation (Make, Zapier) | Puts an external operator in the money path; signature verification and idempotency are hard to guarantee; costs per task |
| Node.js backend on Hostinger | Node availability depends on the plan; PHP is on every shared plan |
| Official MP PHP SDK | A Composer dependency and PHP-version coupling on shared hosting, for three REST calls. The signature check is about 20 lines and unit-tested against vectors |
| SQLite | No setup, but file locking on shared-hosting storage is a risk; MySQL is included in the plan |
| Local PHP + tunnel (ngrok) to test webhooks | Requires PHP locally and works against the single-command DX; staging mirrors production better |
| ZIP as an email attachment | Size limits and spam filters, no revocation, no download evidence |

## Migration path

The frontend depends only on the `/api/*` endpoints and on the content collection. If volume or operating cost later justifies a platform, the kit page CTA can point to it (one field per `.md`), and orders and deliveries can be exported from MySQL. The `.md` files survive intact, as they did from ADR-025 to this ADR.

## Follow-ups

Tracked step by step in [docs/ventas/README.md](../ventas/README.md):

- Phase 1: owner prerequisites (Hostinger, MP application, mailbox, final ZIPs, legal and invoicing).
- Phase 2: staging and CI.
- Phase 3: backend.
- Phase 4: frontend, with a visual proposal first.
- Phase 5: the full test gate.
- Phase 6: launch.
