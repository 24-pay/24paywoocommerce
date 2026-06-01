== 24pay Payment Gateway for WooCommerce ==

Version: 1.1.2
License: MIT
Author: 24pay (https://www.24-pay.sk)
Tested: WC 10.8.1 / WP 7.0

---

== Description ==

Integrates the 24pay payment gateway (https://www.24-pay.eu) into WooCommerce.
Supports card payments, bank transfers, and the "pay later" method.

---

== Requirements ==

- WordPress 5.0+
- WooCommerce 3.5+
- PHP 7.2+ (PHP 8.x supported)
- OpenSSL extension (for AES-256-CBC signing)
- A valid 24pay merchant contract (Mid, Key, EshopId)

---

== Installation ==

1. Upload the plugin folder `24paywoocommerce/` to `wp-content/plugins/`.
2. In WordPress admin go to Plugins → Activate "Woocommerce 24pay Payment gateway".
3. Go to WooCommerce → Settings → Payments → 24pay_gateway → Manage.

---

== Configuration ==

=== Basic ===

| Setting        | Description |
|----------------|-------------|
| Enable/Disable | Enable the gateway so it appears at checkout. |
| Title          | Payment method name shown to the customer at checkout. Default: `24-pay | Platobná brána` |
| Description    | Short description shown below the title at checkout. |

=== Credentials (provided by 24pay after signing the contract) ===

| Setting   | Description |
|-----------|-------------|
| Mid       | Merchant ID. Also used as the AES-256-CBC IV seed. Example: `demoOMED` |
| EshopId   | E-shop identifier. Example: `11111111` |
| Key       | 64-character hex string used as the AES-256-CBC encryption key. Example: `1234567812345678...` (64 chars) |

=== URLs ===

| Setting | Description |
|---------|-------------|
| RURL    | Return URL — the customer is redirected here after payment. **Must be registered with 24pay.** Default: `{site_url}/24pay-rurl/` |
| NURL    | Notification URL — 24pay sends a POST XML notification here to update the order status. **Must be registered with 24pay.** Default: `{site_url}/24pay-nurl/` |

> ⚠️ The RURL and NURL values set here **must exactly match** the URLs registered in the 24pay merchant portal.
> The plugin does NOT use WordPress rewrite rules — it matches the raw request URI directly.

=== Test Mode ===

| Setting   | Description |
|-----------|-------------|
| Test mode | When checked, payments are sent to `https://test.24-pay.eu/pay_gate/paygt` instead of the live gateway. **Disable before going live!** |

=== Optional Settings ===

| Setting               | Description |
|-----------------------|-------------|
| Notify Email          | Extra email address to receive payment notifications. Leave empty to disable. |
| Notify client by email | Send payment status email to the customer. |
| Save transaction email | Send an offline payment link if no response or payment is declined. |
| Language              | Gateway display language. Set to `automatically` to detect from the WooCommerce order locale. Supported: sk, cs, en, de, fr, it, pl, hu, es, ro, sl. |
| Include cart & shipping | Send cart contents as base64-encoded JSON (`Cart` field). **Required only for the "pay later" method.** |
| Enable logs           | Append debug entries to `log.txt` in the plugin directory. Never commit this file. |

---

== Payment Flow ==

1. Customer places order → WooCommerce creates the order.
2. `process_payment()` redirects to the WooCommerce order-pay page.
3. `payment_form()` builds a hidden-field HTML form and auto-submits it to 24pay.
4. Customer completes payment on the 24pay gateway.
5. **NURL** (POST): 24pay sends an XML notification → plugin updates order status.
6. **RURL** (GET): Customer is redirected back → plugin verifies the sign and redirects to the thank-you page.

---

== Order Status Mapping ==

| 24pay result | WooCommerce order status |
|--------------|--------------------------|
| `OK`         | `processing` / `completed` (via `payment_complete()`) |
| `PENDING`    | `on-hold` |
| `AUTHORIZED` | `on-hold` |
| `REVERSAL`   | `refunded` |
| anything else | `failed` |

---

== Supported Order Number Plugins ==

The plugin automatically detects and supports these third-party order number plugins:

| Plugin | Detection method |
|--------|-----------------|
| Custom Order Numbers for WooCommerce (Alg) — **v2.x** | `apply_filters('alg_wc_custom_order_numbers_get_order_id_by_order_number')` |
| Sequential Order Numbers for WooCommerce (free) | `wc_sequential_order_numbers()->find_order_by_order_number()` |
| Sequential Order Numbers Pro | `wc_seq_order_number_pro()->find_order_by_order_number()` |
| YITH Sequential Order Numbers | `ywson_get_order_id_by_order_number()` |

If no plugin is detected, the resolver falls back to:
1. Searching by known meta keys (`_alg_wc_custom_order_number`, `_order_number`, etc.)
2. Treating the value as a direct WooCommerce order ID

> ℹ️ Alg Custom Order Numbers v1.x used `Alg_WC_Custom_Order_Numbers_Core::add_order_number_to_tracking()`.
> **v2.x changed to a filter-based API.** This plugin supports v2.x only. If you are still on v1.x, do not upgrade Alg until you update this plugin.

---

== HPOS Compatibility ==

The plugin declares compatibility with WooCommerce High-Performance Order Storage (custom_order_tables) via `FeaturesUtil::declare_compatibility()`.

---

== Troubleshooting ==

**Payment method not visible at checkout**
→ Disable any page builder plugin on the checkout page (Elementor, Divi, etc.).

**Order status not updated after payment**
→ Check that the NURL registered with 24pay exactly matches the NURL setting (including trailing slash and http/https).
→ Enable logs and inspect `log.txt` in the plugin directory.

**Invalid sign error on RURL**
→ Verify the `Key` and `Mid` settings match those provided by 24pay exactly.

**Order not found after payment (NURL/RURL)**
→ If you use a custom order number plugin, confirm it is one of the supported plugins listed above.
→ For Alg Custom Order Numbers, confirm you are running v2.x.

**Log file**
→ Located at `wp-content/plugins/24paywoocommerce/log.txt`. Enable via Settings → Enable logs.
→ Never commit this file to version control.

---

== File Structure ==

| File | Class | Role |
|------|-------|------|
| `woo-24pay.php` | `Woo_24pay_Gateway` | Main gateway class; settings, payment flow, RURL/NURL dispatch |
| `woo-24pay-signgenerator.php` | `WOO_24pay_SignGenerator` | SHA1 + AES-256-CBC request/response signing |
| `woo-24pay-datavalidator.php` | `WOO_24pay_DataValidator` | Validates FirstName, FamilyName, Email before form submit |
| `woo-24pay-formbuilder.php` | `WOO_24pay_FormBuilder` | Renders auto-submitting hidden-field HTML form |
| `woo-24pay-nurlparser.php` | `WOO_24pay_NurlParser` | Parses XML notification from gateway via SimpleXMLElement |
| `woo-24pay-orderresolver.php` | `Order_Number_Resolver` | Resolves any custom order number to an internal WC order ID |

---

== Changelog ==

= ver 1.1.2 = [01.06.2026]
= ver 1.1.1 = [05.09.2025]
- HPOS (High-Performance Order Storage) compatibility declared
- Alg Custom Order Numbers updated to v2.x filter-based API
- Added Order_Number_Resolver with meta-key fallback and WP object cache
- Added REVERSAL order status support
- Added cart JSON (base64) support for pay later method
- Added language auto-detection
- Added Save Transaction Email option

= ver 1.1.0 = [02.11.2022]
= ver 1.0.1 = [08.10.2021]
= ver 1.0.0 = [21.11.2018]

---

== Test History ==

= Last Tested [WC 10.8.1 WP 7.0]
= Recently Tested [WC 10.1.2 WP 6.8.3]
= Recently Tested [WC 8.6.1 WP 6.4.3]
= Recently Tested [WC 8.0.3 WP 6.3.0]
= Recently Tested [WC 7.6.1 WP 6.2.0]
= Recently Tested [WC 7.0.1 WP 6.1.0]
= Recently Tested [WC 6.1.1 WP 5.8.1]
= Recently Tested [WC 5.7.1 WP 5.8.1]
= Recently Tested [WC 5.6.0 WP 5.8.0]
= Recently Tested [WC 5.2.2 WP 5.7.1]
= Recently Tested [WC 4.8.0 WP 5.6.2]
= Recently Tested [WC 4.7.1 WP 5.3.3]
= Recently Tested [WC 4.5.1 WP 5.3.3]
= Recently Tested [WC 4.0.1 WP 5.3.2]
= Recently Tested [WC 3.8.1 WP 5.3.0]
= Recently Tested [WC 3.7.0 WP 5.2.3]
= Recently Tested [WC 3.6.5 WP 5.2.3]
= Recently Tested [WC 3.6.4 WP 5.2.1]
= Recently Tested [WC 3.6.2 WP 5.1.1]
= Recently Tested [WC 3.5.3 WP 5.0.2]

---

24-pay s.r.o. provides modules for easy integration with the payment gateway.
Modules are tested on clean CMS installations. The company reserves the right to
decline support for issues caused by conflicts with additionally installed plugins.
For specific customisations (client notifications, invoice generation, etc.)
consult your developer.
