# WhatsApp templates for Whatify Flow

The automations send templates **by exact name** and pass variables in a fixed
order. Create these templates in the Whatify dashboard with the exact names and
variable counts below, or update the automation step's template name to match
yours.

## How to check approval inside the app

1. **Integrations page → "Approved templates"** — fetched live from Whatify on
   every page load. Only templates with status `approved` appear, with name,
   category and language. The badge shows the approved count.
2. **Automation health page** — two checks:
   - *Approved Whatify templates available* (needs at least one utility and one
     marketing template).
   - *Workflow template mappings valid* — every automation step points at an
     approved template of the right category.
3. **Message status** — if a template is not approved at send time the run is
   logged as `skipped_not_approved` with reason "Template is not currently
   approved in Whatify."

---

## 1. COD order confirmation (create this first)

- **Template name:** `cod_order_confirmation` (exact, lowercase + underscores)
- **Category:** Utility
- **Language:** English
- **Variables:** exactly 3, in this order
  1. customer first name
  2. order name (e.g. `#1001`)
  3. order total (Shopify sends the numeric amount, e.g. `1499.00`)

**Header (text):** Order confirmation

**Body:**

```
Hi {{1}}, thank you for shopping with us. We have received your Cash on Delivery order {{2}}. Amount payable on delivery: {{3}}. To confirm your order, reply CONFIRM. If you did not place this order, reply CANCEL. Orders without a reply are sent for staff review.
```

**Footer (optional):** Whatify Flow order updates

**Sample values to enter during submission:**

| Variable | Sample |
|---|---|
| {{1}} | Rahul |
| {{2}} | #1001 |
| {{3}} | 1499.00 |

The automation waits 120 minutes for a reply: `yes`/`confirm` tags the order
`whatify_cod_confirmed`; `no`/`cancel` or no reply tags it
`whatify_needs_review` for staff.

---

## 2. Order confirmation (all orders)

- **Name:** `order_confirmation` · **Category:** Utility · **3 variables:**
  first name, order name, total

**Body:**

```
Hi {{1}}, your order {{2}} has been placed successfully and is being prepared. Order total: {{3}}. We will message you when it ships. Thank you for shopping with us.
```

## 3. Order shipped / delivery update

- **Name:** `order_shipped` · **Category:** Utility · **2 variables:**
  first name, order name

**Body:**

```
Hi {{1}}, good news — your order {{2}} has been shipped and is on its way. Keep your phone reachable for the delivery update. Thank you for shopping with us.
```

## 4. Abandoned cart recovery

- **Name:** `cart_abandonment_reminder` · **Category:** Marketing ·
  **4 variables:** first name, item summary, shop name, recovery URL

**Body:**

```
Hi {{1}}, you left {{2}} behind in your cart at {{3}}. Complete your checkout here: {{4}}. Reply STOP to opt out of cart reminders.
```

The recovery URL variable requires the shop domain to be verified in Meta
Business Manager (Whatify support can confirm the verified-domain setup).
Marketing templates should always include the opt-out line shown above.

---

## Approval rules (why WhatsApp rejects templates)

- **No promotional wording in utility templates** — words like sale, discount,
  offer, coupon, "buy now" get utility templates rejected. Put those only in
  marketing templates.
- Variable order and count must match exactly; every variable needs a realistic
  sample value at submission.
- No URLs or phone numbers inside variables (except the verified-domain
  marketing URL above); no emojis in variable positions.
- Avoid ALL CAPS, repeated punctuation (!!!), and aggressive wording.
- Names may contain only lowercase letters, numbers and underscores.
- After editing a rejected template, WhatsApp reviews it again as a new
  submission — approval usually takes a few minutes to a few hours, up to 24h.
