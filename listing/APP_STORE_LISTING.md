# Whatify Flow — Shopify App Store listing package

Everything below is ready to paste into **Partner Dashboard → Apps → Whatify Flow → Distribution → Shopify App Store**.
Character limits follow Shopify's current listing requirements (verified October 2026). Image files are in
`listing/assets/` at the exact required pixel dimensions.

---

## 1. App identity

| Field | Value | Limit |
| --- | --- | --- |
| App name | `Whatify Flow: WhatsApp CRM` | 26 of 30 chars |
| App handle / URL slug | `whatify-flow` | — |
| Primary language | English | — |
| Primary category | **Marketing** | required |
| Secondary category | **Customer support** | optional |
| Pricing shown on card | `$1 one-time charge · no subscription` | — |

---

## 2. App card subtitle (tagline) — 49 of 62 chars

```
Verify COD orders and recover carts with WhatsApp
```

## 3. App introduction — 88 of 100 chars

```
WhatsApp automations for Shopify stores: COD verification, order updates, cart recovery.
```

## 4. App details — plain text, 496 of 500 chars (no bullets or line breaks)

```
Whatify Flow automates WhatsApp messages for Shopify stores. It verifies cash-on-delivery orders, confirms orders, sends shipping updates, and recovers abandoned checkouts after inactivity. Four ready-made automations install in one click using approved WhatsApp templates, with consent checked before every send, STOP opt-out respected, and each message logged with a skip reason. A visual builder, lead-capture popups, a floating WhatsApp button and analytics are included for one one-time fee.
```

## 5. Feature list — five items, each max 80 chars

1. `COD verification on WhatsApp with yes/no replies and Shopify order tags` (71)
2. `Abandoned cart recovery after inactivity, gated by marketing opt-in` (67)
3. `Order confirmation and shipping update messages from approved templates` (71)
4. `Visual drag-and-drop builder with conditions, delays and reply waits` (68)
5. `Website entry/exit popups and floating WhatsApp button to capture leads` (71)

## 6. App Store search terms — five single-idea terms, no words repeated from the name

```
cod verification, abandoned cart, order tracking, sms alternative, lead capture
```

## 7. Web SEO content (indexed by Google and App Store search)

**Page title — 51 of 60 chars**
```
WhatsApp Automation for Shopify COD & Cart Recovery
```

**Meta description — 151 chars (recommended 140–160)**
```
Automate WhatsApp order confirmations, COD verification, shipping alerts and abandoned cart recovery for Shopify. Consent-safe templates. One-time fee.
```

**Search-friendly URL**
```
https://apps.shopify.com/whatify-flow
```

---

## 8. Full app description (Markdown, 100–2800 chars — paste into the rich-text editor)

```markdown
## WhatsApp automations that pay for themselves on the first COD order

Whatify Flow connects your Shopify store to the WhatsApp Business API through
Whatify, so routine customer conversations happen automatically — while staying
fully compliant with WhatsApp template and consent rules.

### Four automations, ready in one click

- **COD order verification** — when a cash-on-delivery order is created, the
  customer receives a confirmation message. A yes/no reply wait tags the order
  `whatify_cod_confirmed` or flags it for staff review in Shopify automatically.
- **Order confirmation** — every opted-in order triggers an approved Utility
  template with the order number and total.
- **Abandoned cart recovery** — after a 60-minute inactivity window, one
  marketing-template message with a secure checkout link is sent, and only when
  a separate marketing opt-in exists. Completed checkouts are suppressed.
- **Shipping updates** — fulfillment events notify the customer that the order
  is on its way.

### Build anything visually

The drag-and-drop workflow builder supports triggers, conditions, delays,
wait-for-reply branches, WhatsApp template steps, Shopify order tags, staff
notifications and stop nodes. Branch arrows are color-coded and published
versions are immutable, so a live automation never changes underneath a running
order.

### Capture more opted-in contacts from your own website

Add one script snippet to your theme to enable an entry popup, an exit-intent
popup and a floating WhatsApp chat button. Control the delay, visit threshold,
page targeting (home only, all pages or custom paths with wildcards), popup
image and repeat frequency. Every submission is stored as a WhatsApp opt-in
with the contact's name and number.

### Compliance is built in, not bolted on

- Only currently **approved WhatsApp templates** are sent; category (Utility vs
  Marketing) is re-checked at send time.
- **Consent is verified before every message** — no opt-in, no send, with the
  reason logged.
- **STOP replies** instantly opt the number out and cancel its waiting runs.
- Phone numbers are **encrypted at rest**; duplicate protection prevents a
  customer ever being messaged twice for the same step.
- Automatic order cancellation is disabled by safety policy, and historical
  order sync never messages customers.
- GDPR-mandated `customers/data_request`, `customers/redact` and `shop/redact`
  webhooks are implemented.

### Know exactly what is happening

A health page explains every skipped message in plain language, detects a
stopped scheduler, overdue reply waits and template mismatches, while run
history, webhook events, analytics and the order verification table give a full
audit trail.

### Simple, one-time pricing

Pay **$1 USD once** through Shopify's secure billing — no subscription, no
renewals, no per-message markup. Messages are billed by your WhatsApp/Whatify
account under your own rates.
```

---

## 9. Permissions & protected customer data (mandatory approval section)

### 9.1 API access scopes requested

| Scope | Why it is required |
| --- | --- |
| `read_orders` | Read order, payment gateway and customer fields to trigger the four automations and build the COD verification table |
| `write_orders` | Add the `whatify_cod_confirmed` / `whatify_needs_review` order tags that staff and fulfilment teams rely on |

Both scopes are **protected** — submit the protected-customer-data request in
Partner Dashboard with the justification below.

### 9.2 Protected customer data — use disclosure (paste into the compliance form)

**Data types used and purpose**

| Customer data | Purpose |
| --- | --- |
| Customer name | Personalize approved WhatsApp templates and store website popup leads |
| Phone number | Deliver WhatsApp messages; stored encrypted, matched via keyed hash |
| Order data (ID, name, total, currency, payment gateway names, tags) | Trigger order automations, evaluate COD conditions, write verification tags |
| Checkout data (token, line items, totals, recovery URL, note attributes) | Abandoned-checkout eligibility and secure recovery messages |
| Fulfillment data (status, tracking info) | Shipping update messages |
| Consent attributes (`whatsapp_opt_in`, marketing opt-in note attributes/tags) | Prove lawful basis before any message is sent |

**Data the app does NOT collect:** email addresses, payment card details,
customer addresses, or browsing behaviour outside Shopify webhook payloads.

**Storage, retention and deletion**

- Phone numbers are encrypted with Laravel's application-level encryption;
  identity lookups use an HMAC-SHA256 keyed hash.
- Operational data is pruned automatically by the `whatify:prune` scheduled
  command on a rolling retention window.
- `customers/redact` deletes the customer's consents, replies and identifiable
  records; `shop/redact` removes all shop data on uninstall;
  `customers/data_request` produces an export of the customer's held data.

### 9.3 Mandatory compliance webhooks (already declared in `shopify.app.toml`)

`customers/data_request`, `customers/redact`, `shop/redact`, plus
`app/uninstalled`, `orders/create`, `fulfillments/create`, `checkouts/create`
and `checkouts/update` — all delivered to `/api/webhooks/shopify` and HMAC
verified.

---

## 10. Pricing setup (one-time $1 USD)

The charge is implemented with the Shopify Billing API
(`appPurchaseOneTimeCreate`) and verified server-side before the app unlocks.

0. Partner Dashboard → **Distribution → choose Shopify App Store (public)**. The Billing API is rejected for custom/private distribution with *"Apps without a public distribution cannot use the Billing API"*; a draft public listing is sufficient, App Store approval is not required to test.
1. Partner Dashboard → **Pricing → Add plan → One-time charge**.
2. Amount: **1.00 USD**, name: `Whatify Flow — lifetime access`.
3. On the server set:
   `SHOPIFY_BILLING_ENABLED=true`, `SHOPIFY_ONE_TIME_PRICE=1.00`,
   `SHOPIFY_BILLING_CURRENCY=USD`.
4. For review and development stores set `SHOPIFY_BILLING_TEST_MODE=true` so
   the charge is a Shopify **test charge** (no real money). Switch to `false`
   before public launch.
5. Unpaid shops can still open the dashboard, integrations and billing page;
   automations, orders, analytics and the website widget unlock immediately
   after the charge is confirmed ACTIVE.

---

## 11. Media assets — upload mapping

| File (in `listing/assets/`) | Upload slot | Exact size | Format |
| --- | --- | --- | --- |
| `app-icon.png` | App icon | 1200 × 1200 px, square, no rounded corners, no transparency | PNG |
| `01-dashboard-overview.png` | Screenshot 1 | 1600 × 900 px (16:9) | PNG |
| `02-preset-automations.png` | Screenshot 2 | 1600 × 900 px | PNG |
| `03-workflow-builder.png` | Screenshot 3 | 1600 × 900 px | PNG |
| `04-website-widget.png` | Screenshot 4 | 1600 × 900 px | PNG |
| `05-orders-compliance.png` | Screenshot 5 | 1600 × 900 px | PNG |

All five screenshots show the actual app UI only (no browser chrome, no
desktop wallpaper, no logo-only frames) and each shows a distinct feature,
matching Shopify requirements 4.4.4 and 4.4.5.

---

## 12. App setup & reviewer testing instructions (required field)

```
1. Install the app on a Shopify development store. The OAuth flow grants
   read_orders/write_orders scopes and registers the webhook subscriptions.
2. The app opens on the Billing page. The charge is created in TEST mode, so
   approving it makes no real payment; the app unlocks immediately afterward.
3. Open Integrations. A Shopify "Test connection" button verifies the GraphQL
   Admin API without any external account.
4. The Whatify section can be skipped in review: without an API key the app
   remains fully navigable, the health page reports the missing connection,
   and the website widget's lead-capture endpoint works independently.
5. On the Website widget page, save settings and copy the storefront snippet;
   the entry popup, exit-intent popup and floating WhatsApp button render on
   any page where the script is installed.
6. In Automations, open the Template gallery and install the COD verification
   preset, then open it in the visual builder to inspect nodes and branches.
7. Use the "Sync recent orders" button on the Orders page to backfill sample
   orders via GraphQL. Backfill never sends WhatsApp messages.
8. Webhook events, run history and the automation health page show the
   end-to-end audit trail. GDPR compliance endpoints respond to the mandated
   customer/shop redaction and data-request topics.
```

---

## 13. Pre-submission checklist

- [ ] Set distribution to **Shopify App Store (public)** so the Billing API is accepted (draft listing is enough for testing)
- [ ] Replace `client_id = "REPLACE_WITH_SHOPIFY_CLIENT_ID"` in `shopify.app.toml`
- [ ] `shopify app config link` then `shopify app deploy` (registers webhooks)
- [ ] Install on a development store and run the 8 reviewer steps above
- [ ] Public HTTPS `APP_URL` reachable; `php artisan queue:work` and
      `php artisan schedule:work` running
- [ ] Whatify API key connected; Utility and Marketing templates approved
- [ ] Protected customer data request submitted with section 9.2 text
- [ ] Privacy policy URL added to the listing (describe WhatsApp/Whatify data flows)
- [ ] `SHOPIFY_BILLING_TEST_MODE=false` before going live
- [ ] Upload the six assets from `listing/assets/` into the matching slots
