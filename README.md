# Whatify Flow — Laravel + MySQL

A Laravel 12 application for Shopify merchants with the prototype-derived dashboard and visual workflow builder. It supports Shopify OAuth, Whatify API credentials, order/fulfillment and checkout webhooks, delayed abandoned-checkout recovery, separately gated WhatsApp marketing consent, versioned automations, COD review, approved WhatsApp template sends, reply routing, opt-out handling, Shopify order tags, customer-data requests, and execution history.

**Guideline audit date: 27 October 2026.** Code-level changes in this audit include expiring Shopify offline-token acquisition/rotation, Shopify app-configuration webhook declarations (including checkouts and all three mandatory compliance topics), a Shopify-originated install flow with no manual shop-domain form, minimum Shopify scopes and selected webhook fields, separate transactional/marketing opt-in checks, approved-template revalidation, delayed checkout inactivity handling, and STOP/UNSUBSCRIBE handling. This is an implementation audit, not Shopify/Meta approval or a production certification.

Customer/order payloads and provider credentials are encrypted at rest using Laravel's `APP_KEY`. Phone identity hashes are keyed HMACs using `DATA_HASH_KEY` (or `APP_KEY` only as a development fallback). Keep both keys stable and backed up; rotating either key requires a planned data migration.

## Requirements

- PHP 8.2+ with `pdo_mysql`, `mbstring`, `openssl`, `ctype`, `fileinfo`, `tokenizer`, `xml`, `bcmath`, and `pcntl` for workers
- Composer 2 and MySQL 8+
- A stable public HTTPS URL for Shopify OAuth/webhooks
- Shopify app credentials and a Whatify external API key
- A continuously running database queue worker and Laravel scheduler in production (do not use the synchronous queue for Shopify webhooks)

## Run with Docker Compose

```bash
cp .env.example .env
# Set strong unique APP_KEY, DATA_HASH_KEY, DB_PASSWORD and MYSQL_ROOT_PASSWORD values.
# Example key generators:
# php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
# php -r "echo bin2hex(random_bytes(32)).PHP_EOL;"
docker compose up --build
```

The stack runs Laravel/Apache on port `8000`, MySQL, a database queue worker, and the scheduler. For production, set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://waflow.whatify.in`, `SESSION_SECURE_COOKIE=true`, use valid TLS, and set `QUEUE_CONNECTION=database`. For local-only testing, override `APP_URL` with `http://localhost:8000`; Shopify OAuth/webhooks need the public HTTPS origin. The worker and scheduler must remain running. Do not use sample passwords in production.

## Run without Docker

```bash
cp .env.example .env
# Configure MySQL and credentials; generate APP_KEY and set a stable DATA_HASH_KEY.
composer install
php artisan key:generate
php artisan migrate
php artisan serve
# In separate terminals:
php artisan queue:work --tries=5
php artisan schedule:work
```

## Shopify setup and 2026 token requirements

1. Create/link the Shopify app in the Shopify Developer Dashboard. Set the same client ID in `SHOPIFY_API_KEY` and `shopify.app.toml`; keep the client secret only in the Laravel server environment as `SHOPIFY_API_SECRET`.
2. The public URL and callback in `shopify.app.toml` are set to `https://waflow.whatify.in`; set the real Shopify client ID in the TOML and in `SHOPIFY_API_KEY`. The OAuth callback is `https://waflow.whatify.in/integrations/shopify/callback`.
3. Link and deploy the app configuration using the Shopify CLI (`shopify app config link`, then `shopify app deploy`). Keep the TOML webhook declarations deployed; they include `customers/data_request`, `customers/redact`, and `shop/redact`, plus order, fulfillment, checkout create/update, and uninstall webhooks. The Laravel callback does not create duplicate shop-level subscriptions.
4. Install/launch from a Shopify-owned surface. Shopify supplies the `shop` query parameter and the app immediately starts OAuth; there is no manual domain-entry form. The OAuth callback verifies HMAC and one-time session state.
5. The app requests only `read_orders,write_orders` by default. Shopify order/customer fields are protected customer data: request the applicable approval in Shopify and confirm the fields needed for the use case before production.
6. Keep the database queue worker running so webhook endpoints acknowledge quickly and process after receipt. `whatify:refresh-shopify-tokens` is scheduled every 30 minutes and scans for tokens due within 20 minutes; API requests also refresh access tokens shortly before expiry. Re-authorize existing installations that were created without expiring tokens.

New public apps created from 1 April 2026 must use expiring offline access tokens; Shopify extends the requirement to all public apps by 1 January 2027. These access tokens last about one hour and the issued refresh token about 90 days. The OAuth exchange requests `expiring=1`; encrypted access/refresh tokens and expiry times are stored, refresh-token rotation is serialized per shop, and a scheduler scans every 30 minutes for near-expiry credentials. Existing installations with a still-valid non-expiring offline token are migrated in place through Shopify's token-exchange flow; revoked or otherwise unusable credentials require merchant re-authorization.

The app uses Shopify GraphQL Admin API only, version `2026-10`. Webhook delivery verification uses the raw request body and Shopify HMAC; webhook IDs are deduplicated. Webhook payload fields are restricted in `shopify.app.toml` where possible. Customer-data requests create an app-held-data export; customer/shop redaction removes associated local records. The app must still be publicly reachable over HTTPS and configured/deployed correctly for Shopify to deliver these events.

### Nothing is syncing — Shopify Partner/CLI checklist

Orders only appear after Shopify **delivers a webhook** and the queue worker processes it. If the Orders page is empty and **Webhook events** (sidebar) shows no rows, Shopify is not calling the server at all. Work through this exact sequence:

1. **Create the app in the Shopify Partner Dashboard** and copy its client ID and secret into `SHOPIFY_API_KEY` / `SHOPIFY_API_SECRET` on the Laravel server.
2. **Edit `shopify.app.toml`**: replace `client_id = "REPLACE_WITH_SHOPIFY_CLIENT_ID"` and confirm `application_url` and the OAuth redirect match the real public HTTPS origin (the checked-in default is `https://waflow.whatify.in`). Without this step the deploy links nothing and no webhook subscriptions exist — this is the most common reason nothing syncs.
3. **Deploy the configuration**: `shopify app config link` then `shopify app deploy`. The TOML already declares `orders/create`, `fulfillments/create`, `checkouts/create`, `checkouts/update`, `app/uninstalled` and the three mandatory compliance topics, all posting to `/api/webhooks/shopify`.
4. **Install the app on the store** from a Shopify-owned surface (app listing or "Install on development store"). OAuth must complete so an offline token row exists; an unknown shop's webhooks are ignored.
5. **Keep the processes running**: `php artisan queue:work` (webhook processing) and `php artisan schedule:work` (heartbeat, token rotation, stalled-checkout recovery). A received-but-unprocessed webhook shows as *Queued / waiting for worker* on the Webhook events page.
6. **Protected customer data**: order/checkout customer fields (names, phone, addresses) require protected-customer approval in the Partner Dashboard for distribution; on a development store the webhooks still deliver, so use one to verify first.
7. **Verify in-app**: Integrations → **Test connection** runs a live GraphQL `shop` query with the stored token; **Webhook events** proves delivery; Orders → **Sync recent orders** backfills up to 25 existing orders through the GraphQL Admin API (or run `php artisan whatify:sync-orders`). Backfill writes verification/consent rows only and never starts WhatsApp workflows, since webhooks cover future events and historical customers must not be messaged retroactively.

### Distribution and App Store prerequisites not completed in this codebase

The checked-in TOML is configured as `embedded = false`, matching the current server-rendered OAuth/dashboard flow. Shopify App Store distribution expects an embedded merchant experience, current App Bridge/session-token authentication, a complete privacy policy and listing disclosures, and Shopify-compliant billing where the app charges. Those product/release steps are **not implemented or verified** here. Before pursuing App Store distribution, build and test the embedded/session-token experience, decide on Shopify Managed Pricing/Billing API if charging, submit for protected customer data as needed, deploy the TOML and public HTTPS app, and pass Shopify review. A private/custom distribution must still be registered/configured for its chosen distribution method.

## Whatify and WhatsApp setup

1. In **Integrations**, enter the Whatify external API key. The Laravel server verifies it with `GET /whatsapp-accounts` and `GET /templates`, and stores it encrypted. The API key never returns to the browser.
2. Create workflows using currently approved Whatify templates. Before each send, the server checks for the correct active consent purpose and rechecks approval plus category (Utility for order updates, Marketing for cart recovery). The app sends template messages only; it does not send free-form messages.
3. Transactional/order consent uses the existing affirmative `whatsapp_opt_in`, `whatsapp_consent`, or `wa_opt_in` order note attributes, or a merchant-applied `whatsapp_opt_in` tag after valid consent. Marketing consent is a separate record and is never inferred from transactional consent.
4. To enable checkout recovery, configure a Shopify checkout/cart consent control that submits an affirmative `whatsapp_marketing_opt_in` or `whatsapp_cart_recovery_opt_in` note attribute (`yes`, `true`, `1`, `on`, `opted_in`, or `accepted`). Checkout webhooks with no active marketing-purpose consent are not stored for recovery. Ensure consent identifies the business and explicitly covers cart-recovery/marketing messages; this app cannot install or audit the merchant's consent UI.
5. The checkout workflow waits 60 minutes after the most recent checkout update by default (`ABANDONED_CHECKOUT_DELAY_MINUTES`, allowed 10–1440), suppresses completed checkouts, requires an HTTPS Shopify recovery URL, and sends only an approved Whatify template after a final active marketing-consent check. Test the attribute and delivery with a development store before enabling it.
6. Configure Whatify to POST signed inbound reply events to `/api/webhooks/whatify/inbound`, and set `WHATIFY_WEBHOOK_SECRET` only after confirming Whatify's exact event schema and signature contract. `STOP`, `UNSUBSCRIBE`, `OPT OUT`, `END`, and `QUIT` opt the phone out of both consent purposes, scrub pending recovery data, and cancel queued/running/waiting executions. A stale checkout attribute/order tag cannot reverse STOP; re-opt-in requires an explicit opt-in on a checkout/order created afterward. Other replies route to a unique waiting workflow. The Whatify inbound signing/payload format has not been live-verified.
7. Use transactional utility content for order notices only—no upsell or promotional material in order templates. Cart recovery is marketing-purpose and requires a marketing template. Monitor template status, quality, limits, and category; Meta review/re-categorization is not guaranteed.

## Predefined automations and health diagnostics

Merchants do not have to build flows from scratch. **Automations → Templates gallery** (`/automations/presets`) installs predefined, publish-validated workflows. Each card maps its WhatsApp step to one of the shop's currently approved Whatify templates before creation:

| Preset | Trigger | Consent / category | What it does |
| --- | --- | --- | --- |
| COD order verification | `ORDERS_CREATE` | order-update opt-in · Utility | COD + opt-in conditions, confirmation template, reply wait, `whatify_cod_confirmed` / `whatify_needs_review` order tags |
| Order confirmation | `ORDERS_CREATE` | order-update opt-in · Utility | Sends order number and total immediately after every opted-in order |
| Abandoned cart recovery | `CHECKOUTS_CREATE` | separate marketing opt-in · Marketing | Waits the inactivity window, then one approved recovery template with secure recovery link; completed checkouts are suppressed |
| Order shipped / delivery update | `FULFILLMENTS_CREATE` | order-update opt-in · Utility | Loads the linked order from Shopify and notifies the customer on fulfillment |

Installed presets are stamped with `preset_key`; they remain ordinary editable workflows, so every node can be adjusted in the builder before publishing.

**Automations → Automation health** (`/automations/health`) explains why messages are or are not sending. It checks the Shopify connection, the Whatify connection (live, cached for 5 minutes), approved Utility/Marketing templates, every workflow's template mapping (missing name, unapproved, or wrong category), enabled-workflow coverage per trigger, unpublished drafts, scheduler heartbeat, stuck/failed runs, webhook processing errors, stalled or consent-suppressed abandoned checkouts, and a 7-day breakdown of `message_logs` statuses in plain language (for example `skipped_no_marketing` → "no active cart-recovery marketing opt-in"). The dashboard shows a banner linking to the first open issue.

Two scheduled commands keep delayed automations reliable:

- `whatify:health-heartbeat` runs every minute and writes a cache timestamp so the health page can detect a stopped scheduler.
- `whatify:recover-stalled-checkouts` runs every 15 minutes and re-dispatches recovery for `open` abandoned checkouts whose inactivity window has elapsed, covering cases where the originally delayed queue job was lost (worker restart, queue flush). `ProcessAbandonedCheckout` stays idempotent and re-validates consent, completion and eligibility before starting any workflow.

## Workflow model

- Draft workflow graphs are JSON (`nodes` + `edges`); publishing creates an immutable version so in-flight runs use the version that started them.
- Supported nodes: order, fulfillment, or checkout-inactivity trigger; condition; approved WhatsApp template; wait for reply/timeout; delay; add Shopify order tag; staff-review event; stop. Marketing-purpose send nodes require a checkout trigger and independent active marketing consent. Automatic order cancellation is deliberately disabled.
- The default COD flow checks payment method and recorded opt-in before sending. Non-response routes to review; a confirmation only adds an order tag and does not alter financial or fulfillment status.
- The runner has a step limit, retry history, and a guard against resending after an uncertain provider result.

## Data protection and retention

Sensitive credentials, webhook payloads, message request/response details, recovery URLs, customer phone numbers and reply text use Laravel encrypted casts. Checkout webhooks are reduced to encrypted recovery fields after processing; raw webhook bodies are cleared immediately. Checkout records older than 30 days are pruned; Shopify data-request exports include marketing consent and checkout data, while redaction removes it. Processed webhook payloads/privacy exports use a 30-day retention window and ordinary operational records use 90 days. Opt-out records are retained so the app does not message a customer again; they are deleted on relevant Shopify redaction. Adjust retention to applicable law, contracts, and platform policy, and provide your own published privacy policy and support contact.

## Verification and limits

- Automated tests cover the Laravel dashboard/builder paths, workflow versioning, predefined preset installation/publishing, automation health diagnostics, stalled-checkout recovery and the scheduler heartbeat, checkout webhook ingestion, consent-gated recovery, STOP opt-out, and rotating Shopify offline tokens. MySQL, live Shopify OAuth/webhooks, live Whatify requests, Meta opt-in evidence, public HTTPS delivery, and platform reviews require real deployment/accounts and were not verified in this sandbox.
- Staff notification nodes currently create auditable events; email/SMS staff delivery is not implemented. Message-status polling, billing, production alerting, and richer analytics attribution remain product work.
- The endpoint's ability to accept Whatify inbound events depends on Whatify confirming the exact webhook contract.
- Add only approved template names, correct parameter order, and valid sending-account selection to workflows. Do not interpret code-level safeguards as proof that a merchant's consent capture or legal basis is valid.

## Primary guidelines reviewed (as of 27 October 2026)

- [Shopify expiring offline access tokens](https://shopify.dev/changelog/posts/expiring-offline-access-tokens-required-for-public-apps-april-1-2026)
- [Shopify offline access tokens](https://shopify.dev/docs/apps/build/authentication-authorization/access-tokens#offline-access-tokens) and [migration to expiring offline tokens](https://shopify.dev/docs/apps/build/authentication-authorization/migrate-to-expiring-offline-access-tokens)
- [Shopify app configuration](https://shopify.dev/docs/apps/build/cli-for-apps/app-configuration) and [webhook subscriptions](https://shopify.dev/docs/apps/build/webhooks/subscribe)
- [Shopify privacy-law compliance webhooks](https://shopify.dev/docs/apps/build/privacy-law-compliance) and [webhook verification](https://shopify.dev/docs/apps/build/webhooks/verify-deliveries)
- [Shopify protected customer data](https://shopify.dev/docs/apps/launch/protected-customer-data) and [App Store requirements](https://shopify.dev/docs/apps/launch/shopify-app-store/app-store-requirements)
- [Meta WhatsApp platform and opt-in](https://developers.facebook.com/docs/whatsapp/overview), [service windows](https://developers.facebook.com/docs/whatsapp/conversation-types), [template rules](https://developers.facebook.com/docs/whatsapp/message-templates/guidelines/), and [current pricing/window details](https://developers.facebook.com/docs/whatsapp/pricing/)

## Key paths

- `app/Http/Controllers` — app pages, OAuth, order actions, webhooks
- `app/Services/ShopifyAdmin.php` — Shopify GraphQL and rotating tokens
- `app/Services/OrderSync.php` — GraphQL order backfill, connection test, shared order/opt-in/checkout ingest
- `app/Services/WorkflowRunner.php` — workflow and opt-in enforcement
- `app/Jobs` — queued webhooks, workflow runs, and privacy processing
- `database/migrations` — MySQL schema
- `shopify.app.toml` — OAuth scopes and app-specific webhook subscriptions
- `resources/views` and `public/css/app.css` — Blade UI
