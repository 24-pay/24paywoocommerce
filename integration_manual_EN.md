# 24pay Payment Gateway for WooCommerce — Integration Manual

**Version:** 1.1.7
**License:** MIT
**Author:** 24pay (https://www.24-pay.sk)
**Last tested:** WC 10.8.1 / WP 7.0

---

## 1. Description

Integrates the 24pay payment gateway (https://www.24-pay.eu) into WooCommerce.
Supports card payments, bank transfers, and the "pay later" method.

---

## 2. Requirements

- WordPress 5.0+
- WooCommerce 3.5+
- PHP 7.2+ (PHP 8.x supported)
- OpenSSL extension (for AES-256-CBC signing)
- A valid 24pay merchant contract (Mid, Key, EshopId)
- Action Scheduler (bundled with WooCommerce) is used for background NURL processing — no separate installation required. If unavailable, the plugin falls back to synchronous processing automatically.

---

## 3. Installation

1. Upload the plugin folder `24paywoocommerce/` to `wp-content/plugins/`.
2. In WordPress admin go to **Plugins → Activate** "Woocommerce 24pay Payment gateway".
3. Go to **WooCommerce → Settings → Payments → 24pay_gateway → Manage**.

---

## 4. Configuration

### 4.1 Basic

| Setting        | Description |
|----------------|-------------|
| Enable/Disable | Enable the gateway so it appears at checkout. |
| Title          | Payment method name shown to the customer at checkout. Default: `24-pay | Platobná brána` |
| Description    | Short description shown below the title at checkout. |

### 4.2 Credentials

Provided by 24pay after signing the merchant contract (delivered via SMS).

| Setting  | Description |
|----------|-------------|
| Mid      | Merchant ID. Also used as the AES-256-CBC IV seed. Example: `demoOMED` |
| EshopId  | E-shop identifier. Example: `11111111` |
| Key      | 64-character hex string used as the AES-256-CBC encryption key. Example: `1234567812345678...` (64 chars) |

### 4.3 URLs

| Setting  | Description |
|----------|-------------|
| EUR RURL | Return URL for EUR payments — customer is redirected here after payment. **Must be registered with 24pay.** Default: `{site_url}/24pay-rurl/` |
| CZK RURL | Return URL for CZK payments. **Must be registered with 24pay.** Default: `{site_url}/24pay-rurl/` |
| PLN RURL | Return URL for PLN payments. **Must be registered with 24pay.** Default: `{site_url}/24pay-rurl/` |
| HUF RURL | Return URL for HUF payments. **Must be registered with 24pay.** Default: `{site_url}/24pay-rurl/` |
| NURL     | Notification URL — 24pay sends a POST XML notification here to update the order status. **Must be registered with 24pay.** Default: `{site_url}/24pay-nurl/` |

> ⚠️ The RURL and NURL values **must exactly match** the URLs registered in the 24pay merchant portal, including the trailing slash and the http/https scheme.
> The plugin does **not** use WordPress rewrite rules — it matches the raw request URI directly.

### 4.4 Test Mode

| Setting   | Description |
|-----------|-------------|
| Test mode | When checked, payments are sent to `https://test.24-pay.eu/pay_gate/paygt` instead of the live gateway. **Disable before going live!** |

### 4.5 Optional Settings

| Setting                | Description |
|------------------------|-------------|
| Notify Email           | Extra email address to receive payment notifications. Leave empty to disable. |
| Notify client by email | Send payment status email to the customer. |
| Save transaction email | Send an offline payment link if no response or payment is declined. |
| Language               | Gateway display language. Set to `automatically` to detect from the WooCommerce order locale. Supported: `sk`, `cs`, `en`, `de`, `fr`, `it`, `pl`, `hu`, `es`, `ro`, `sl`. |
| Include cart & shipping | Send cart contents as base64-encoded JSON (`Cart` field). **Required only for the "pay later" method.** |
| Enable logs            | Append debug entries to `log.txt` in the plugin directory. **Never commit this file.** |

---

## 5. Payment Flow

1. Customer places order → WooCommerce creates the order.
2. `process_payment()` redirects to the WooCommerce order-pay page.
3. `payment_form()` builds a hidden-field HTML form and auto-submits it to 24pay.
4. Customer completes payment on the 24pay gateway.
5. **NURL** (POST): 24pay sends an XML notification → plugin updates order status.
6. **RURL** (GET): Customer is redirected back → plugin verifies the sign and redirects to the thank-you page.

```
Checkout → process_payment() → WC order-pay page
         → payment_form() → FormBuilder → auto-submit POST → 24pay gateway
                                                             ↕
                                          RURL (GET redirect back to shop)
                                          NURL (POST XML notification → order status update)
```

---

## 6. NURL Notification Processing (Reliability & Idempotency)

Since version 1.1.5, NURL notification handling has been hardened to work correctly regardless of whether the gateway delivers the notification synchronously (a single delivery right after the transaction) or asynchronously (server-to-server, possibly retried, delayed, duplicated, or delivered out of order).

### 6.1 Fast acknowledgement (no more slow responses)
Previously, the plugin fully processed the notification (including `payment_complete()`, order emails, stock changes and any third-party hooks) before responding to the gateway. On busy stores this could make the NURL response take many seconds, which can cause the gateway to time out and retry.

Since version 1.1.5, `process_nurl()` only performs fast, cheap steps synchronously (signature validation, order lookup, duplicate check) and responds immediately, handing off the actual order status update to a background job.

As of version 1.1.7, that background processing is faster and more reliable still: instead of waiting for Action Scheduler to "pick up" the job via WP-Cron (which requires a separate HTTP request and, in practice, adds a delay of several seconds - much more under load or when a security plugin/firewall throttles loopback requests), the plugin now responds `OK` to the gateway directly via `fastcgi_finish_request()` (or `litespeed_finish_request()` on LiteSpeed hosting) - which closes the HTTP connection immediately - and then continues processing the notification (order status update, emails, stock) **in that very same PHP request**. The entire flow (acknowledgement + processing) therefore typically completes in well under a second, with no dependency on WP-Cron or Action Scheduler at all.

These functions (`fastcgi_finish_request`/`litespeed_finish_request`) are available on the vast majority of modern hosting environments (PHP-FPM, LiteSpeed). If unavailable (e.g. classic mod_php), the plugin automatically falls back to the previous Action Scheduler based background processing - no functionality is lost, only the immediate-processing benefit.

### 6.2 Idempotency (duplicate notifications)
Every notification is uniquely identified by its `PspTxnId` + `Result`. Before applying it, the plugin checks a small history stored in the order's own metadata (`_24pay_processed_notifications`, capped at the last 20 entries). If the exact same notification was already applied, it is acknowledged (`OK`) without being processed again — this protects against duplicate deliveries and gateway retries.

No custom database table is used for this — it relies entirely on WooCommerce order meta (fully compatible with both legacy post-based storage and HPOS) and WordPress's own `wp_options` table for the per-order lock (see 6.3), so there is nothing extra to install, migrate, or clean up on uninstall.

### 6.3 Concurrency protection (per-order lock)
If two notifications for the same order arrive at (nearly) the same time, only one is processed at a time. This uses an atomic WordPress option-based lock: `add_option()` relies on the UNIQUE index on `wp_options.option_name`, so it is atomic even without an external object cache (Redis/Memcached). A stale lock (e.g. left behind by a crashed request) is automatically reclaimed after 20 seconds. If a notification cannot acquire the lock, the plugin responds `FAIL` so the gateway retries later — this never causes data loss.

### 6.4 Out-of-order protection (state machine)
Notifications carry a "priority" based on how final their result is:

```
PENDING (1) < AUTHORIZED (2) < FAIL (3) < REVERSAL (4) < OK (5)
```

If a notification with a lower priority arrives after one with a higher priority was already applied (e.g. a delayed `PENDING` arriving after `OK` was already processed), it is ignored and the order status is **not** moved backwards. The last applied result is stored in order meta `_24pay_last_result`.

### 6.5 Order resolver retry (async-safe lookups)
`Order_Number_Resolver::resolve()` retries up to 3 times (300 ms apart) before giving up, in case a notification arrives before the order is fully committed/visible in the database. This adds no overhead for the normal case, since the order is found on the first attempt.

### 6.6 RURL/NURL race protection ("payment is being processed" no longer gets stuck)
The customer's browser (RURL, redirect back from the gateway) and the server-to-server notification (NURL) can run as two concurrent requests for the same order. For safety, `process_rurl()` sets/refreshes an "awaiting notification" flag (`_24pay_awaiting_notification`) so the order-received/view-order pages keep showing "payment is being processed..." until the NURL notification delivers a definitive result.

As of version 1.1.7, this is hardened against two kinds of race:
- `process_rurl()` no longer re-arms this flag once the order has already reached a terminal non-paid status (`failed`/`cancelled`) - i.e. once a NURL notification with a negative result has already been applied, a repeat RURL hit (e.g. from a page refresh) will not turn the flag back on.
- `apply_notification_result()` now performs one final, fresh re-check at the very end of processing and clears the flag again if needed. This handles the case where a concurrent RURL request wrote the flag while the NURL request was still executing - the order object in the NURL request had already loaded its metadata into memory before RURL's write happened, so the original `delete_meta_data()` call had nothing to act on and was a no-op for that entry.

Without this fix, the flag (and therefore the endless "payment is being processed..." auto-refresh loop) could remain set for up to `AWAITING_NOTIFICATION_TIMEOUT` (10 minutes) even though the order had long since been correctly processed.

---

## 7. Order Status Mapping

| 24pay result  | WooCommerce order status |
|---------------|--------------------------|
| `OK`          | `processing` / `completed` (via `payment_complete()`) |
| `PENDING`     | `on-hold` |
| `AUTHORIZED`  | `on-hold` |
| `REVERSAL`    | `refunded` |
| anything else | `failed` |

---

## 8. Supported Order Number Plugins

The plugin automatically detects and supports these third-party order number plugins:

| Plugin | Version | Detection method |
|--------|---------|-----------------|
| Custom Order Numbers for WooCommerce (Alg) | **v1.x** | `Alg_WC_Custom_Order_Numbers_Core::add_order_number_to_tracking()` |
| Custom Order Numbers for WooCommerce (Alg) | **v2.x** | `apply_filters('alg_wc_custom_order_numbers_get_order_id_by_order_number')` |
| Sequential Order Numbers for WooCommerce (free) | any | `wc_sequential_order_numbers()->find_order_by_order_number()` |
| Sequential Order Numbers Pro | any | `wc_seq_order_number_pro()->find_order_by_order_number()` |
| YITH Sequential Order Numbers | any | `ywson_get_order_id_by_order_number()` |

Both Alg v1.x and v2.x are supported simultaneously — the resolver tries v1.x first (class exists check), then v2.x (filter). This means upgrading from Alg v1.x to v2.x does not require any changes to this plugin.

If no plugin is detected, the resolver (`Order_Number_Resolver`) falls back to:

1. Searching by known order meta keys:
   - `_alg_wc_custom_order_number`
   - `_order_number`
   - `_ywson_order_number`
   - `_wcj_order_number`
   - `_wc_order_number`
   - `_order_number_formatted`
2. Treating the value as a direct WooCommerce order ID.

---

## 9. Adding Support for Other Plugins

If your store uses an order number plugin not listed in Section 8, you can add support without modifying the 24pay plugin code.

You need to know the meta key your plugin uses to store the custom order number in the database. You can find this from the plugin's support documentation or by running the debug snippet below.

### 9.1 Option A — Add a meta key via functions.php

Add the following code to your theme's `functions.php` or a custom plugin:

```php
add_filter( '24pay_order_number_meta_keys', function( array $keys ): array {
    $keys[] = '_your_plugin_meta_key';  // replace with the actual meta key
    return $keys;
} );
```

> **Note:** Replace `_your_plugin_meta_key` with the actual meta key used by your plugin. See Section 9.3 on how to find it.

### 9.2 Option B — Built-in compatibility from your plugin

If you are a plugin developer and want to ship built-in compatibility with 24pay, add the following to your plugin:

```php
class My_Plugin_24pay_Compat {

    public static function init(): void {
        // Register only if the 24pay plugin is active
        if ( defined( 'PLUGIN_PATH_24PAY' ) ) {
            add_filter(
                '24pay_order_number_meta_keys',
                [ self::class, 'add_meta_key' ]
            );
        }
    }

    public static function add_meta_key( array $keys ): array {
        $keys[] = '_my_plugin_order_number';
        return $keys;
    }
}

add_action( 'plugins_loaded', [ 'My_Plugin_24pay_Compat', 'init' ] );
```

### 9.3 How to find your plugin's meta key

If you don't know which meta key your plugin uses, add this temporary debug snippet to `functions.php` and open any order in the WooCommerce admin:

```php
add_action( 'woocommerce_order_details_after_order_table', function( $order ) {
    if ( ! current_user_can( 'manage_woocommerce' ) ) return;
    foreach ( $order->get_meta_data() as $meta ) {
        $data = $meta->get_data();
        if ( str_starts_with( $data['key'], '_' ) ) {
            echo '<p style="font-size:11px;color:#999">'
               . esc_html( $data['key'] ) . ' => '
               . esc_html( $data['value'] ) . '</p>';
        }
    }
} );
```

Look for the meta key that contains your custom order number. Once found, use it in Option A and then remove this debug code.

---

## 10. HPOS Compatibility

The plugin declares compatibility with WooCommerce High-Performance Order Storage (HPOS / custom_order_tables) via `FeaturesUtil::declare_compatibility()` on the `before_woocommerce_init` hook.

---

## 11. File Structure

| File | Class | Role |
|------|-------|------|
| `woo-24pay.php` | `Woo_24pay_Gateway` | Main gateway class; settings, payment flow, RURL/NURL dispatch |
| `woo-24pay-signgenerator.php` | `WOO_24pay_SignGenerator` | SHA1 + AES-256-CBC request/response signing |
| `woo-24pay-datavalidator.php` | `WOO_24pay_DataValidator` | Validates FirstName, FamilyName, Email before form submit |
| `woo-24pay-formbuilder.php` | `WOO_24pay_FormBuilder` | Renders auto-submitting hidden-field HTML form |
| `woo-24pay-nurlparser.php` | `WOO_24pay_NurlParser` | Parses XML notification from gateway via SimpleXMLElement |
| `woo-24pay-orderresolver.php` | `Order_Number_Resolver` | Resolves any custom order number to an internal WC order ID |

---

## 12. Troubleshooting

### 12.1 Payment method not visible at checkout
→ Disable any page builder plugin on the checkout page (Elementor, Divi, etc.).

### 12.2 Order status not updated after payment
→ Check that the NURL registered with 24pay **exactly** matches the NURL setting (including trailing slash and http/https scheme).
→ Enable logs and inspect `log.txt` in the plugin directory.
→ On servers running PHP-FPM or LiteSpeed (i.e. almost everywhere), as of v1.1.7 the order status is updated directly within the same request as the NURL notification - there is no dependency on WP-Cron at all. If it still doesn't update, check **WooCommerce → Status → Scheduled Actions** for a stuck/failed `woo_24pay_process_notification` action (this would mean the server doesn't support `fastcgi_finish_request()`/`litespeed_finish_request()` and the plugin fell back to Action Scheduler) - in that case, verify WP-Cron is running (`DISABLE_WP_CRON` not set to `true`, or a real server cron is configured to call `wp-cron.php`).

### 12.3 NURL response takes long / gateway keeps retrying
→ This was a known issue prior to v1.1.5, where the plugin waited for the full order update (including emails and hooks) before responding. As of v1.1.5, the response is sent immediately after validating the notification; the actual update runs in the background. As of v1.1.7, on most hosting (PHP-FPM/LiteSpeed) the actual order status update itself also runs practically instantly (within the same request), so the entire flow (acknowledgement + status update) should take well under a second. Make sure you are running v1.1.7 or later.

### 12.3.1 "Payment is being processed..." never stops, even though the notification was already processed
→ This was a known race condition between RURL (customer returning from the gateway) and NURL (server-to-server notification), fixed in v1.1.7 - see Section 6.6. Make sure you are running v1.1.7 or later. If the issue persists on this version, enable logs and check whether `log.txt` contains the line `Cleared a concurrently re-armed '_24pay_awaiting_notification' flag...` (confirms the fix engaged) - if it doesn't appear and the flag still won't clear, contact support with your `log.txt` attached.

### 12.4 Invalid sign error on RURL
→ Verify the `Key` (64-char hex) and `Mid` settings match those provided by 24pay exactly.

### 12.5 Order not found after payment (NURL / RURL)
→ If you use a custom order number plugin, confirm it is one of the supported plugins listed in section 8.
→ If not, add your meta key as described in Section 9.
→ For Alg Custom Order Numbers, both **v1.x and v2.x** are supported.

### 12.6 Log file
→ Located at `wp-content/plugins/24paywoocommerce/log.txt`.
→ Enable via **Settings → Enable logs**.
→ **Never commit this file to version control.**

---

## 13. Changelog

### ver 1.1.7 — 2026-09-17
- **Fixed:** NURL notification processing could take up to ~20 seconds even though the gateway itself received its acknowledgement in ~500ms. Root cause: the actual order status update ran as an Action Scheduler background job, which depends on WP-Cron "picking it up" via a separate HTTP loopback request - this pickup delay alone could add several seconds, and much more under load or when a security plugin/firewall throttles loopback requests. `process_nurl()` now responds `OK` to the gateway directly via `fastcgi_finish_request()` (or `litespeed_finish_request()` on LiteSpeed), closing the HTTP connection immediately, and then continues processing the notification **in that same PHP request** - with no dependency on WP-Cron/Action Scheduler at all. On servers where these functions are unavailable (e.g. classic mod_php), the plugin automatically falls back to the previous Action Scheduler based processing.
- **Fixed:** the "payment is being processed..." notice could remain shown (with the page auto-refreshing indefinitely) for up to 10 minutes even though the NURL notification had already been fully processed and the order status updated long ago. Root cause: a race condition between the RURL redirect (customer's browser) and the NURL notification (server-to-server), which can run as concurrent requests for the same order - see Section 6.6 for the technical details of the fix.

### ver 1.1.6 — 2026-09-17
- **Fixed:** the "payment is being processed..." notice on the order-received/view-order pages was never actually showing up. Root cause: `Woo_24pay_Gateway` is instantiated more than once per request in practice - once by WooCommerce itself (when it loads the list of available payment gateways) and once more by this plugin's own `init`-hooked listener (which needs an instance to detect RURL/NURL requests on every request). Each instantiation's constructor re-registered the very same WordPress hooks, so `woocommerce_before_thankyou` / `woocommerce_thankyou_24pay_gateway` fired twice, opening two nested output buffers - the second `ob_end_clean()` call discarded the notice the first call had just printed. Hook registration is now guarded to happen only once per request, no matter how many times the class gets instantiated.

### ver 1.1.5 — 2026-09-16
- NURL notifications are now acknowledged to the gateway immediately after signature validation; the actual order status update (payment_complete/emails/stock/hooks) is moved to a background job (Action Scheduler), preventing slow responses and gateway timeouts. Automatic fallback to synchronous processing if Action Scheduler is unavailable.
- Added idempotency for NURL notifications: duplicate/retried notifications (same `PspTxnId` + `Result`) are detected via order meta (`_24pay_processed_notifications`) and safely ignored.
- Added an atomic per-order lock (WordPress option-based, no custom DB table) to serialize concurrent NURL notifications for the same order.
- Added a state-machine guard (order meta `_24pay_last_result`) that prevents an out-of-order/delayed notification from moving the order status backwards.
- Added retry/backoff to `Order_Number_Resolver::resolve()` to correctly handle notifications that arrive before the order is fully committed/visible in the database.
- Fixed a fatal error that occurred when a NURL notification referenced an order that could not be resolved.
- Fixed a missing `die()` after an invalid/failed NURL response, which previously caused the rest of the page to be rendered after the `FAIL` response body.
- `WOO_24pay_NurlParser::$pspTxnId` visibility changed from `private` to `public` (required for the idempotency logic above; the property is now consistent with `$msTxnId` and `$result`).

### ver 1.1.4 — 2026-07-30
- Added dedicated RURL settings per currency (EUR/CZK/PLN/HUF)
- Added currency-based RURL selection in `payment_form()` via `get_rurl_by_currency()`

### ver 1.1.3 — 2026-07-28
- Alg Custom Order Numbers updated to v2.x filter-based API (removed deprecated `Alg_WC_Custom_Order_Numbers_Core`)
- Added `Order_Number_Resolver` class with meta-key fallback and WP object cache (TTL 300 s)

### ver 1.1.1 — 2025-09-05
- HPOS (High-Performance Order Storage) compatibility declared

- Added `REVERSAL` → `refunded` order status support
- Added cart JSON (base64) support for the pay later method
- Added language auto-detection from WooCommerce order locale
- Added Save Transaction Email option

### ver 1.1.0 — 2022-11-02
### ver 1.0.1 — 2021-10-08
### ver 1.0.0 — 2018-11-21

---

## 14. Test History

| WooCommerce | WordPress |
|-------------|-----------|
| 10.8.1      | 7.0       |
| 10.1.2      | 6.8.3     |
| 8.6.1       | 6.4.3     |
| 8.0.3       | 6.3.0     |
| 7.6.1       | 6.2.0     |
| 7.0.1       | 6.1.0     |
| 6.1.1       | 5.8.1     |
| 5.7.1       | 5.8.1     |
| 5.6.0       | 5.8.0     |
| 5.2.2       | 5.7.1     |
| 4.8.0       | 5.6.2     |
| 4.7.1       | 5.3.3     |
| 4.5.1       | 5.3.3     |
| 4.0.1       | 5.3.2     |
| 3.8.1       | 5.3.0     |
| 3.7.0       | 5.2.3     |
| 3.6.5       | 5.2.3     |
| 3.6.4       | 5.2.1     |
| 3.6.2       | 5.1.1     |
| 3.5.3       | 5.0.2     |

---

*24-pay s.r.o. provides modules for easy integration with the payment gateway.
Modules are tested on clean CMS installations. The company reserves the right to
decline support for issues caused by conflicts with additionally installed plugins.
For specific customisations (client notifications, invoice generation, etc.)
consult your developer.*
