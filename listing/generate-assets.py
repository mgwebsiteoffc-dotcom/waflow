#!/usr/bin/env python3
"""Generates Shopify App Store listing assets at the exact required sizes.
Icon 1200x1200 (no rounded corners, no transparency). Screenshots 1600x900,
app UI only, no browser chrome, each showing a distinct feature."""
import os
from PIL import Image, ImageDraw, ImageFont

OUT = "/home/user/waflow/listing/assets"
os.makedirs(OUT, exist_ok=True)
FD = "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"
FB = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"
FM = "/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf"

INK = "#17152c"; MUTED = "#89899a"; LINE = "#ececf2"; CANVAS = "#f7f7fa"
PURPLE = "#7044e8"; PURPLE_D = "#5830ce"; PURPLE_L = "#f0eaff"
GREEN = "#1ca66a"; GREEN_BG = "#e9f8ef"; AMBER = "#a77b14"; AMBER_BG = "#fff6df"
RED = "#bf6664"; RED_BG = "#fff0ef"; BLUE = "#4778ba"; BLUE_BG = "#eaf2ff"
WA = "#25d366"; WHITE = "#ffffff"

def f(size, bold=False, mono=False):
    return ImageFont.truetype(FM if mono else (FB if bold else FD), size)

def vgrad(w, h, c1, c2):
    im = Image.new("RGB", (w, h), c1)
    d = ImageDraw.Draw(im)
    def rgb(hx): return tuple(int(hx[i:i+2], 16) for i in (1, 3, 5))
    a, b = rgb(c1), rgb(c2)
    for y in range(h):
        t = y / max(1, h - 1)
        d.line([(0, y), (w, y)], fill=tuple(int(a[i] + (b[i]-a[i])*t) for i in range(3)))
    return im

def rr(d, box, r, fill=None, outline=None, width=1):
    d.rounded_rectangle(box, radius=r, fill=fill, outline=outline, width=width)

def txt(d, xy, s, font, fill=INK, anchor=None):
    d.text(xy, s, font=font, fill=fill, anchor=anchor)

def pill(d, x, y, s, bg, fg, size=18):
    ft = f(size, True); w = d.textlength(s, font=ft)
    rr(d, (x, y, x + w + 28, y + 34), 17, bg)
    txt(d, (x + 14, y + 7), s, ft, fg)
    return w + 28

def sidebar(d, active):
    rr(d, (0, 0, 252, 900), 0, WHITE)
    d.line([(252, 0), (252, 900)], fill=LINE, width=2)
    rr(d, (26, 30, 70, 74), 12, PURPLE)
    txt(d, (48, 52), "W", f(26, True), WHITE, "mm")
    txt(d, (82, 34), "Whatify Flow", f(21, True))
    txt(d, (82, 60), "Shopify automation", f(13), MUTED)
    items = [("Overview", "dashboard"), ("Automations", "automations"), ("Orders", "orders"),
             ("Analytics", "analytics"), ("Website widget", "widget"), ("Integrations", "integrations"),
             ("Billing", "billing"), ("Automation health", "health"), ("Webhook events", "webhooks"),
             ("Run history", "runs")]
    y = 120
    for label, key in items:
        if key == active:
            rr(d, (14, y - 8, 238, y + 30), 10, PURPLE_L)
            txt(d, (30, y), label, f(17, True), PURPLE_D)
        else:
            txt(d, (30, y), label, f(17), "#666579")
        y += 52

def topbar(d, title, sub):
    txt(d, (292, 40), title, f(34, True))
    txt(d, (292, 86), sub, f(17), MUTED)

def base(active):
    im = Image.new("RGB", (1600, 900), CANVAS)
    d = ImageDraw.Draw(im)
    sidebar(d, active)
    return im, d

# ---------------------------------------------------------------- app icon
def icon():
    im = vgrad(1200, 1200, "#7b4bf2", "#5830ce")
    d = ImageDraw.Draw(im)
    # white speech bubble, content inside the 750-900px icon grid
    rr(d, (195, 300, 1005, 820), 110, WHITE)
    d.polygon([(420, 800), (420, 880), (540, 800)], fill=WHITE)
    # bold W mark
    txt(d, (600, 560), "W", f(330, True), PURPLE, "mm")
    # green availability badge
    d.ellipse((830, 690, 980, 840), fill=WA)
    txt(d, (905, 767), "✓", f(78, True), WHITE, "mm")
    im.save(os.path.join(OUT, "app-icon.png"), "PNG")

# ------------------------------------------------------------ 1. dashboard
def shot_dashboard():
    im, d = base("dashboard")
    topbar(d, "Overview", "WhatsApp automation performance for demo.myshopify.com")
    stats = [("WhatsApp messages sent", "1,284", "+18% this week", GREEN, GREEN_BG),
             ("COD orders verified", "312", "92% confirmation rate", PURPLE, PURPLE_L),
             ("Opt-in contacts", "2,047", "48 new today", BLUE, BLUE_BG),
             ("Active automations", "4", "all healthy", AMBER, AMBER_BG)]
    x = 292
    for label, val, foot, fg, bg in stats:
        rr(d, (x, 130, x + 300, 286), 14, WHITE, LINE, 2)
        rr(d, (x + 20, 152, x + 66, 198), 10, bg)
        txt(d, (x + 43, 176), "●", f(20, True), fg, "mm")
        pill(d, x + 284 - (d.textlength(foot, f(12, True)) + 26), 158, foot, bg, fg, 12)
        txt(d, (x + 20, 214), val, f(34, True))
        txt(d, (x + 20, 258), label, f(14), MUTED)
        x += 318
    rr(d, (292, 314, 940, 846), 14, WHITE, LINE, 2)
    txt(d, (316, 338), "Automations", f(21, True))
    rows = [("COD order verification", "Order created · WhatsApp confirm + reply tags", "Live", GREEN_BG, GREEN),
            ("Order confirmation", "Sends approved order update template", "Live", GREEN_BG, GREEN),
            ("Abandoned cart recovery", "Checkout inactivity · marketing opt-in gated", "Live", GREEN_BG, GREEN),
            ("Order shipped update", "Fulfillment created · tracking message", "Draft", AMBER_BG, AMBER)]
    y = 392
    for t, s, st, bg, fg in rows:
        d.line([(316, y - 14), (916, y - 14)], fill=LINE, width=1)
        txt(d, (316, y), t, f(17, True))
        txt(d, (316, y + 28), s, f(13), MUTED)
        pill(d, 800, y + 4, st, bg, fg, 14)
        y += 104
    rr(d, (964, 314, 1566, 846), 14, WHITE, LINE, 2)
    txt(d, (988, 338), "Automation health", f(21, True))
    checks = [("Shopify connection", "Authenticated · GraphQL reachable"),
              ("Whatify templates", "6 approved · categories match"),
              ("Scheduler heartbeat", "Running · last check 12s ago"),
              ("Waiting reply runs", "0 overdue timeouts"),
              ("Consent coverage", "All sends opt-in gated"),
              ("Message delivery", "0 failed in 24 hours")]
    y = 400
    for t, s in checks:
        d.ellipse((988, y, 1014, y + 26), fill=GREEN_BG)
        txt(d, (1001, y + 13), "✓", f(15, True), GREEN, "mm")
        txt(d, (1030, y - 2), t, f(16, True))
        txt(d, (1030, y + 24), s, f(13), MUTED)
        y += 68
    im.save(os.path.join(OUT, "01-dashboard-overview.png"), "PNG")

# ------------------------------------------------------------- 2. presets
def shot_presets():
    im, d = base("automations")
    topbar(d, "Template gallery", "Install a ready-made, publish-validated WhatsApp automation in one click")
    cards = [
        ("COD order verification", "ORDERS_CREATE", GREEN,
         ["COD + opt-in conditions", "Confirmation WhatsApp template", "Yes/No reply wait (120 min)", "Auto order tags in Shopify"]),
        ("Order confirmation", "ORDERS_CREATE", PURPLE,
         ["Order number and total", "Approved Utility template", "Runs on every opted-in order", "Zero manual work"]),
        ("Abandoned cart recovery", "CHECKOUTS_CREATE", AMBER,
         ["60-minute inactivity wait", "Marketing opt-in enforced", "Secure recovery link", "One message, no spam"]),
        ("Order shipped update", "FULFILLMENTS_CREATE", BLUE,
         ["Fulfillment trigger", "Tracking details message", "Utility category template", "Customer stays informed"]),
    ]
    pos = [(292, 132), (930, 132), (292, 502), (930, 502)]
    for (title, topic, color, bullets), (x, y) in zip(cards, pos):
        rr(d, (x, y, x + 610, y + 340), 14, WHITE, LINE, 2)
        rr(d, (x + 24, y + 26, x + 72, y + 74), 12, PURPLE_L)
        txt(d, (x + 48, y + 50), "◉", f(24, True), color, "mm")
        txt(d, (x + 90, y + 28), title, f(21, True))
        pill(d, x + 90, y + 62, topic, BLUE_BG, BLUE, 13)
        by = y + 118
        for b in bullets:
            d.ellipse((x + 28, by + 6, x + 38, by + 16), fill=GREEN)
            txt(d, (x + 52, by), b, f(15), "#4c4a5e")
            by += 38
        rr(d, (x + 24, y + 278, x + 220, y + 320), 10, PURPLE)
        txt(d, (x + 122, y + 299), "Install preset", f(15, True), WHITE, "mm")
        txt(d, (x + 240, y + 296), "Maps to your approved templates", f(13), MUTED)
    im.save(os.path.join(OUT, "02-preset-automations.png"), "PNG")

# -------------------------------------------------------------- 3. builder
def shot_builder():
    im, d = base("automations")
    topbar(d, "Workflow builder", "Drag steps, branch on conditions and connect arrows visually")
    # left palette
    rr(d, (292, 130, 492, 846), 12, WHITE, LINE, 2)
    txt(d, (312, 152), "Steps", f(16, True), MUTED)
    palette = [("Trigger", PURPLE), ("Condition", "#ce8446"), ("Send WhatsApp", GREEN),
               ("Wait for reply", "#4c80c3"), ("Delay", "#4c80c3"), ("Add order tag", GREEN),
               ("Notify staff", "#4c80c3"), ("Stop", RED)]
    y = 192
    for label, color in palette:
        rr(d, (310, y, 474, y + 46), 9, WHITE, LINE, 2)
        rr(d, (322, y + 10, 348, y + 36), 7, PURPLE_L)
        txt(d, (335, y + 23), "●", f(14, True), color, "mm")
        txt(d, (360, y + 13), label, f(14, True), "#59586a")
        y += 60
    # canvas
    rr(d, (512, 130, 1232, 846), 12, WHITE)
    for gx in range(540, 1220, 26):
        for gy in range(160, 830, 26):
            d.ellipse((gx, gy, gx + 2, gy + 2), fill="#dedde7")
    nodes = [
        (762, 158, 230, 84, "Order created", "trigger · ORDERS_CREATE", PURPLE, PURPLE_L),
        (762, 286, 230, 84, "Is Cash on Delivery?", "condition · contains", "#ce8446", "#fff2e5"),
        (612, 424, 230, 96, "Send COD confirmation", "template · cod_order_confirmation", GREEN, "#e7f7ee"),
        (922, 424, 230, 96, "Flag for staff review", "add tag · whatify_needs_review", RED, "#fff0ef"),
        (762, 566, 230, 84, "Wait for customer reply", "timeout 120 min · yes/no", "#4c80c3", "#eaf3ff"),
        (762, 698, 230, 78, "Tag order confirmed", "add tag · whatify_cod_confirmed", GREEN, "#e7f7ee"),
    ]
    for x0, y0, w, h, t, s, fg, bg in nodes:
        rr(d, (x0, y0, x0 + w, y0 + h), 11, WHITE, "#e8e6ed", 2)
        rr(d, (x0 + 12, y0 + 12, x0 + 44, y0 + 44), 8, bg)
        txt(d, (x0 + 28, y0 + 28), "●", f(15, True), fg, "mm")
        txt(d, (x0 + 56, y0 + 14), t, f(14, True))
        txt(d, (x0 + 14, y0 + 56), s, f(11), MUTED)
    # connectors with arrowheads
    def arrow(x1, y1, x2, y2, color, label=None, lx=0, ly=0):
        d.line([(x1, y1), (x1, (y1 + y2)//2), (x2, (y1 + y2)//2), (x2, y2)], fill=color, width=3)
        d.polygon([(x2, y2), (x2 - 7, y2 - 12), (x2 + 7, y2 - 12)], fill=color)
        if label:
            ft = f(12, True); w = d.textlength(label, ft)
            rr(d, (lx, ly, lx + w + 18, ly + 26), 13, WHITE, color, 2)
            txt(d, (lx + 9, ly + 5), label, ft, color)
    arrow(877, 242, 877, 286, "#9a97b0")
    arrow(877, 370, 727, 424, GREEN, "yes", 770, 382)
    arrow(877, 370, 1037, 424, RED, "no", 920, 382)
    arrow(727, 520, 877, 566, GREEN)
    arrow(877, 650, 877, 698, "#9a97b0")
    # right config
    rr(d, (1252, 130, 1566, 846), 12, WHITE, LINE, 2)
    txt(d, (1274, 154), "Send WhatsApp", f(18, True))
    txt(d, (1274, 182), "send_template step", f(12), MUTED)
    fields = [("Template name", "cod_order_confirmation"), ("Purpose", "order_update · Utility"),
              ("Sender account", "Whatify Main (+91…210)"), ("Phone", "{{ order.customer.phone }}"),
              ("Body param 1", "{{ order.customer.first_name }}"), ("Body param 2", "{{ order.name }}")]
    y = 228
    for label, val in fields:
        txt(d, (1274, y), label.upper(), f(11, True), MUTED)
        rr(d, (1274, y + 18, 1544, y + 58), 8, "#faf9fd", LINE, 1)
        txt(d, (1288, y + 28), val, f(13), "#49485a")
        y += 74
    rr(d, (1274, y + 6, 1544, y + 52), 9, PURPLE)
    txt(d, (1409, y + 29), "Save step", f(14, True), WHITE, "mm")
    im.save(os.path.join(OUT, "03-workflow-builder.png"), "PNG")

# --------------------------------------------------------------- 4. widget
def shot_widget():
    im, d = base("widget")
    topbar(d, "Website popups & WhatsApp button", "Entry popup, exit-intent capture and floating chat — one snippet")
    # settings panel
    rr(d, (292, 130, 964, 846), 14, WHITE, LINE, 2)
    txt(d, (318, 156), "Entry popup", f(20, True))
    pill(d, 838, 152, "Enabled", GREEN_BG, GREEN, 14)
    rows = [("Popup title", "Get order updates on WhatsApp"),
            ("Show after", "5 seconds on page"),
            ("Show only after this many visits", "2 visits"),
            ("Show on which pages", "Home page only"),
            ("Show again after", "7 days")]
    y = 210
    for label, val in rows:
        txt(d, (318, y), label.upper(), f(11, True), MUTED)
        rr(d, (318, y + 18, 930, y + 62), 8, "#faf9fd", LINE, 1)
        txt(d, (334, y + 30), val, f(14), "#49485a")
        y += 82
    txt(d, (318, y + 2), "POPUP IMAGE", f(11, True), MUTED)
    rr(d, (318, y + 20, 560, y + 116), 10, PURPLE_L)
    rr(d, (340, y + 38, 420, y + 98), 8, "#c9b6f7")
    txt(d, (470, y + 52), "banner-offer.jpg", f(14, True), PURPLE_D)
    txt(d, (470, y + 78), "PNG · 1600 × 900 · 184 KB", f(12), MUTED)
    # storefront preview panel
    rr(d, (988, 130, 1566, 846), 14, WHITE, LINE, 2)
    txt(d, (1014, 156), "Storefront preview", f(20, True))
    rr(d, (1014, 200, 1540, 820), 12, CANVAS, LINE, 1)
    # modal mock inside preview
    mx0, my0, mw, mh = 1086, 250, 382, 470
    rr(d, (mx0 - 14, my0 - 14, mx0 + mw + 14, my0 + mh + 14), 16, "#17152c")
    rr(d, (mx0, my0, mx0 + mw, my0 + mh), 16, WHITE)
    rr(d, (mx0, my0, mx0 + mw, my0 + 96), 16, PURPLE)
    d.rectangle((mx0, my0 + 60, mx0 + mw, my0 + 96), fill=PURPLE)
    txt(d, (mx0 + mw - 26, my0 + 14), "×", f(22, True), WHITE)
    pill(d, mx0 + 24, my0 + 116, "WHATSAPP", GREEN_BG, "#1f8a54", 12)
    txt(d, (mx0 + 24, my0 + 164), "Get order updates", f(20, True))
    txt(d, (mx0 + 24, my0 + 196), "on WhatsApp", f(20, True))
    txt(d, (mx0 + 24, my0 + 240), "Leave your details and we will keep", f(13), MUTED)
    txt(d, (mx0 + 24, my0 + 260), "you updated about every order.", f(13), MUTED)
    rr(d, (mx0 + 24, my0 + 296, mx0 + mw - 24, my0 + 340), 9, "#faf9fd", LINE, 1)
    txt(d, (mx0 + 38, my0 + 308), "Your name", f(13), MUTED)
    rr(d, (mx0 + 24, my0 + 352, mx0 + mw - 24, my0 + 396), 9, "#faf9fd", LINE, 1)
    txt(d, (mx0 + 38, my0 + 364), "WhatsApp number, e.g. +91 …", f(13), MUTED)
    rr(d, (mx0 + 24, my0 + 410, mx0 + mw - 24, my0 + 456), 9, PURPLE)
    txt(d, (mx0 + mw//2, my0 + 433), "Subscribe", f(15, True), WHITE, "mm")
    # floating button
    d.ellipse((1448, 720, 1518, 790), fill=WA)
    txt(d, (1483, 755), "✆", f(38, True), WHITE, "mm")
    im.save(os.path.join(OUT, "04-website-widget.png"), "PNG")

# ---------------------------------------------------------------- 5. orders
def shot_orders():
    im, d = base("orders")
    topbar(d, "COD verification & message log", "Every WhatsApp decision is logged with consent and skip reasons")
    rr(d, (292, 130, 1566, 470), 14, WHITE, LINE, 2)
    txt(d, (316, 154), "Order verifications", f(20, True))
    headers = ["ORDER", "CUSTOMER", "PHONE", "PAYMENT", "CONSENT", "WHATSAPP STATUS", "DECISION"]
    xs = [316, 470, 660, 846, 1046, 1216, 1462]
    for hx, ht in zip(xs, headers):
        txt(d, (hx, 208), ht.upper(), f(11, True), MUTED)
    rows = [
        ("#1042", "Asha Verma", "+91 98765 43210", "Cash on Delivery", "Opted in", "Sent · delivered", "Confirmed", GREEN_BG, GREEN),
        ("#1041", "Ravi Kumar", "+91 98765 11111", "Cash on Delivery", "Opted in", "Awaiting reply", "Pending", AMBER_BG, AMBER),
        ("#1040", "Neha Singh", "+91 98765 22222", "Razorpay (prepaid)", "—", "Not required", "Closed", BLUE_BG, BLUE),
        ("#1039", "Mohit Jain", "+91 98765 33333", "Cash on Delivery", "No opt-in", "Skipped · no opt-in", "Review", RED_BG, RED),
    ]
    y = 248
    for order, name, phone, pay, consent, wa, dec, bg, fg in rows:
        d.line([(316, y - 12), (1542, y - 12)], fill=LINE, width=1)
        txt(d, (316, y), order, f(14, True))
        txt(d, (470, y), name, f(14), "#555466")
        txt(d, (660, y), phone, f(13), MUTED)
        txt(d, (846, y), pay, f(13), "#555466")
        txt(d, (1046, y), consent, f(13), "#555466")
        txt(d, (1216, y), wa, f(13), "#555466")
        pill(d, 1430, y - 6, dec, bg, fg, 13)
        y += 52
    # bottom panels
    rr(d, (292, 494, 916, 846), 14, WHITE, LINE, 2)
    txt(d, (316, 518), "Why messages are skipped", f(19, True))
    skips = [("skipped_no_opt_in", "No active recorded WhatsApp order-update opt-in", "42"),
             ("skipped_not_approved", "Template is not currently approved in Whatify", "7"),
             ("skipped_no_marketing", "No active cart-recovery marketing opt-in", "18"),
             ("skipped_checkout_closed", "Checkout completed or recovery already claimed", "11")]
    y = 568
    for code, meaning, count in skips:
        rr(d, (316, y, 892, y + 54), 9, CANVAS)
        txt(d, (334, y + 8), code, f(13, True), PURPLE_D)
        txt(d, (334, y + 30), meaning, f(12), MUTED)
        txt(d, (862, y + 16), count, f(20, True), INK, "rm")
        y += 64
    rr(d, (940, 494, 1566, 846), 14, WHITE, LINE, 2)
    txt(d, (964, 518), "Compliance guardrails", f(19, True))
    guards = ["Only approved Whatify templates are sent",
              "Utility vs Marketing category re-checked at send time",
              "STOP reply opts out and cancels waiting runs",
              "Phone numbers encrypted at rest",
              "Duplicate protection — never billed or messaged twice",
              "Auto-cancel-order disabled by safety policy",
              "Historical order sync never sends messages"]
    y = 568
    for g in guards:
        d.ellipse((966, y + 4, 990, y + 28), fill=GREEN_BG)
        txt(d, (978, y + 16), "✓", f(13, True), GREEN, "mm")
        txt(d, (1004, y + 6), g, f(14), "#4c4a5e")
        y += 38
    im.save(os.path.join(OUT, "05-orders-compliance.png"), "PNG")

icon()
shot_dashboard()
shot_presets()
shot_builder()
shot_widget()
shot_orders()
for fn in sorted(os.listdir(OUT)):
    with Image.open(os.path.join(OUT, fn)) as im:
        print(fn, im.size, im.mode)
