# Shopify App Store listing draft — Whatify Flow

**Status:** Draft only; do not submit until the App Store readiness blockers at the end are addressed. This draft describes the current Laravel app and avoids claiming unimplemented features.

## Listing fields — suggested copy

### App name
**Whatify Flow**

The name is 12 characters and matches the `name` in `shopify.app.toml`. Confirm in Shopify's Developer Dashboard/App Store search that it is distinct and not confusingly similar to another listing before submission.

### App card subtitle
**Automate COD checks and order updates with WhatsApp workflows**

### App introduction (100-character limit)
**Organize COD checks and WhatsApp order messages with consent-aware workflows.**

### App details (500-character limit)
**Create Shopify order, fulfillment, and checkout-inactivity workflows; review cash-on-delivery orders; and send approved WhatsApp templates through a connected Whatify account. Checkout-recovery marketing requires a separate active marketing opt-in and uses Shopify's recovery URL. Route replies when Whatify inbound events are enabled, add Shopify order tags, and review workflow history. Requires a Whatify API account and approved templates. The app does not automatically cancel orders.**

### Feature list (each under 80 characters)
- Trigger workflows for orders, fulfillments, or inactive checkouts.
- Review COD orders and route non-responses to a review step.
- Send approved WhatsApp templates only to opted-in customers.
- Route customer replies when Whatify inbound webhooks are enabled.
- Add Shopify order tags and review workflow execution history.

### Recommended category and discovery terms
- **Suggested primary category:** Orders and shipping → Orders → Other. Confirm the available category in the current Partner Dashboard before selecting it.
- **Suggested search terms (up to five):** COD confirmation; WhatsApp order updates; order automation; cash on delivery; workflow automation.
- **Integrations to disclose:** Shopify, Whatify, WhatsApp Business messaging through the merchant's Whatify account.
- **Sales channel:** This is a regular Shopify admin app, not a Shopify Sales Channel.
- **Online Store requirement:** Not required by the current app functionality; confirm this choice in the submission form.
- **Languages:** English only, unless and until other merchant-facing UI is fully translated.
- **Geography:** Do not claim global availability without checking Whatify/Meta sender availability and applicable WhatsApp rules. Select only supported markets.

### Pricing — decision required
Choose the actual plan before submission; do not copy a price into the listing until it is approved internally.

- Select **Free to install**, **Recurring charge**, or another permitted Shopify pricing option based on the real offer.
- If Whatify/WhatsApp usage or provider fees are billed separately, describe them accurately in the designated “additional charges” field and confirm the arrangement is permitted by Shopify.
- Any charge for Whatify Flow itself must use Shopify Managed Pricing or the Shopify Billing API for App Store distribution. Billing is not implemented in the current codebase.
- Do not put prices, discounts, reviews, or guarantees in the subtitle, listing images, or description.

## Shopify configuration and access

| Item | Value / action |
|---|---|
| App URL | `https://waflow.whatify.in` |
| OAuth callback | `https://waflow.whatify.in/integrations/shopify/callback` |
| Shopify webhook endpoint | `https://waflow.whatify.in/api/webhooks/shopify` |
| Whatify inbound webhook endpoint | `https://waflow.whatify.in/api/webhooks/whatify/inbound` |
| Shopify API version | `2026-10` (already set in the app config) |
| Requested scopes | `read_orders,write_orders` |
| Distribution config | `shopify.app.toml`; its `client_id` still needs the real Shopify app client ID |
| App config webhook topics | `orders/create`, `fulfillments/create`, `checkouts/create`, `checkouts/update`, `app/uninstalled`, and the three compliance topics below |
| Required compliance topics | `customers/data_request`, `customers/redact`, `shop/redact` |

**Why these scopes:** `read_orders` supports new-order processing and fetching an order for fulfillment workflows. `write_orders` is used to add confirmation/review tags. The current app does not request `read_customers`, `read_all_orders`, or product scopes. Customer name, phone, and ID are nevertheless protected customer data; request the appropriate Shopify approval and clearly explain why those fields are needed. Scopes alone do not grant protected-data approval.

## Assets and submission fields to prepare

- **Feature media:** A truthful 2–3 minute product video or a feature image. Shopify's guidance specifies 1600×900 for feature images; provide accurate alt text. Do not show pricing or Shopify's logo as your app branding.
- **Screenshots:** Prepare 3–6 distinct, current 1600×900 screenshots showing the actual deployed UI (dashboard, workflow builder, integrations, order review, execution history). Crop browser chrome and remove all customer data/API keys. Do not use mock screens that imply features not present.
- **Demo store:** Create a dedicated development store and use it for the listing/review demo. Do not use a merchant's live store or customer data.
- **Review screencast:** Show installation from Shopify, permissions, Whatify connection, an approved template, a consented test order, the resulting workflow, and a reply/review outcome. Use English narration or subtitles and test credentials that remain active for the review.
- **Support contact:** Add a monitored support email and support URL. These were not supplied, so they are intentionally left blank here.
- **Privacy policy and terms:** Publish a real privacy policy and terms page, then enter their live URLs in Shopify's submission form. The current app does not yet have public `/privacy` or `/terms` pages; do not submit guessed URLs.
- **Pricing details:** State all plan limits, free-trial length, renewal/cancellation terms, and any permitted third-party charges exactly as offered.
- **App listing name:** Keep it consistent with `Whatify Flow` across the Developer Dashboard, app config, listing, and in-app UI.

## Reviewer setup and test instructions — fill with real test credentials

1. Install from the provided Shopify test store using the Shopify-hosted install flow. The app should request only `read_orders` and `write_orders`.
2. Connect a test Whatify API key and select a currently approved transactional template. Do not include customer PII in the recording.
3. For transactional order messages, use a test order with valid recorded order-update consent (`whatsapp_opt_in`, `whatsapp_consent`, or `wa_opt_in` note attribute, or a merchant-applied `whatsapp_opt_in` tag after consent was collected). For cart recovery, configure an affirmative `whatsapp_marketing_opt_in` or `whatsapp_cart_recovery_opt_in` checkout note attribute and test only with explicit marketing-purpose consent.
4. Confirm the workflow sends only an approved template, records the run, and routes the simulated reply. Whatify must enable and confirm the inbound webhook contract for reply testing.
5. Test an opted-in checkout, wait for the configured inactivity delay, and confirm the approved Marketing template uses Shopify's recovery URL. Complete a checkout before the delay in a separate test and confirm no reminder is sent.
6. Test an order and a checkout without their respective consent, and confirm no message is sent. Test STOP/UNSUBSCRIBE and confirm both purposes are suppressed.
7. Confirm order confirmation/review tags are visible in Shopify. The current app intentionally does not cancel orders automatically and does not send staff email/SMS notifications.

Provide test-store URL, reviewer instructions, and credentials only in Shopify's secure submission fields, not in this document or public listing copy.

## App Store readiness blockers

1. **Embedded app:** The current `shopify.app.toml` uses `embedded = false` and the Laravel UI is not yet an embedded Shopify Admin app using current App Bridge and session-token authentication. Shopify App Store requirements call for an embedded experience and session-token authentication. This is a release blocker for public App Store submission.
2. **Billing:** No Shopify app billing is implemented. If the app charges, integrate Shopify Managed Pricing or the Billing API and test plan changes/cancellation.
3. **Privacy/public pages:** Publish and test the privacy policy, terms, support contact, deletion/export explanations, and listing disclosures.
4. **Protected customer data:** Submit the required Shopify request/approval for customer/order fields and verify access on the target app/distribution type.
5. **Live configuration:** Set the real `client_id`, app secret, allowed callback URL, public HTTPS/TLS, deployed app config/webhooks, MySQL, queue worker, and scheduler. DNS/TLS and live webhook delivery at `waflow.whatify.in` have not been verified from this workspace.
6. **Whatify/Meta:** Verify Whatify's inbound signature/payload contract, real approved templates/sender account, evidence of user opt-in, opt-out handling, and all applicable Meta/WhatsApp account requirements.
7. **Review quality:** The actual demo store, assets, test credentials, screencast, category, geographic eligibility, uniqueness check, pricing, and Shopify review are still outstanding.

## Official references reviewed

- [Shopify App Store requirements](https://shopify.dev/docs/apps/launch/shopify-app-store/app-store-requirements)
- [Shopify App Store listing best practices](https://shopify.dev/docs/apps/launch/shopify-app-store/best-practices)
- [Shopify access scopes](https://shopify.dev/docs/api/usage/access-scopes)
- [Shopify protected customer data](https://shopify.dev/docs/apps/launch/protected-customer-data)
- [Shopify privacy compliance webhooks](https://shopify.dev/docs/apps/build/compliance/privacy-law-compliance)
- [Shopify expiring offline token requirement](https://shopify.dev/changelog/posts/expiring-offline-access-tokens-required-for-public-apps-april-1-2026)
- [Meta WhatsApp opt-in and templates](https://developers.facebook.com/docs/whatsapp/overview)
