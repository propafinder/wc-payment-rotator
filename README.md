# Payment Link Rotator

WooCommerce payment gateway that sends customers to **external payment links** (e.g. **Stripe Payment Links**). Rotates between multiple links, prefills amount and email, and uses a proxy page to hide the referrer.

## Features

- **Stripe (and similar) Payment Links** — Add several payment link URLs; the plugin picks one per order according to the rotation rule.
- **Prefilled amount and email** — Order total (in cents) and customer email are added to the URL as `__prefilled_amount` and `prefilled_email` so the payment page is pre-filled.
- **Link rotation**
  - **Random** — One link chosen at random each time.
  - **Round-robin** — Links used in order, one after another.
  - **Weighted** — Each link has a weight (percentage); selection is random but respects weights.
- **Amount limits per link** — Optional min/max order amount per link so you can route small or large orders to different links.
- **Proxy page** — Customer is sent to your site first (proxy URL), then redirected to the payment link. Referrer is hidden (`Referrer-Policy: no-referrer`).
- **Order number and copy** — Proxy page shows the order number with a “Copy” button; countdown to redirect starts only after the user copies (so they can paste the number on the payment page if needed).
- **Updates from GitHub** — Plugin checks for new versions from this repo and shows “Update now” in **Plugins** when a release is available.

## Requirements

- WordPress 5.6+
- WooCommerce
- PHP 7.4+

## Installation

1. Download the latest release zip from [Releases](https://github.com/propafinder/wc-payment-rotator/releases).
2. In WordPress go to **Plugins → Add New → Upload Plugin**, choose the zip, then **Install** and **Activate**.
3. After activation, go to **Settings → Permalinks** and click **Save** once (to register the proxy URL).
4. Configure under **WooCommerce → Payment Link Rotator**: add your payment links (e.g. `https://buy.stripe.com/...`), set rotation mode, delay, and redirect page text/design.

## Configuration

- **Links** — Add one or more payment link URLs (Stripe or similar). Optionally set min/max order amount and weight (for weighted rotation).
- **Rotation** — Random, round-robin, or weighted.
- **Redirect** — Show a proxy page with order number and countdown, or redirect immediately. Delay (seconds) and all redirect-page texts/colors are configurable.

## Author

Degrees Team — [Repository](https://github.com/propafinder/wc-payment-rotator)

## License

Use as needed for your projects.
