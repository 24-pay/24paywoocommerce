# 24pay Payment Gateway for WooCommerce — Integration Manual

**Version:** 1.1.1
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

| Setting | Description |
|---------|-------------|
| RURL    | Return URL — the customer is redirected here after payment. **Must be registered with 24pay.** Default: `{site_url}/24pay-rurl/` |
| NURL    | Notification URL — 24pay sends a POST XML notification here to update the order status. **Must be registered with 24pay.** Default: `{site_url}/24pay-nurl/` |

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

## 6. Order Status Mapping

| 24pay result  | WooCommerce order status |
|---------------|--------------------------|
| `OK`          | `processing` / `completed` (via `payment_complete()`) |
| `PENDING`     | `on-hold` |
| `AUTHORIZED`  | `on-hold` |
| `REVERSAL`    | `refunded` |
| anything else | `failed` |

---

## 7. Supported Order Number Plugins

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

## 8. Adding Support for Other Plugins

If your store uses an order number plugin not listed in Section 7, you can add support without modifying the 24pay plugin code.

You need to know the meta key your plugin uses to store the custom order number in the database. You can find this from the plugin's support documentation or by running the debug snippet below.

### 8.1 Option A — Add a meta key via functions.php

Add the following code to your theme's `functions.php` or a custom plugin:

```php
add_filter( '24pay_order_number_meta_keys', function( array $keys ): array {
    $keys[] = '_your_plugin_meta_key';  // replace with the actual meta key
    return $keys;
} );
```

> **Note:** Replace `_your_plugin_meta_key` with the actual meta key used by your plugin. See Section 8.3 on how to find it.

### 8.2 Option B — Built-in compatibility from your plugin

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

### 8.3 How to find your plugin's meta key

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

## 9. HPOS Compatibility

The plugin declares compatibility with WooCommerce High-Performance Order Storage (HPOS / custom_order_tables) via `FeaturesUtil::declare_compatibility()` on the `before_woocommerce_init` hook.

---

## 10. File Structure

| File | Class | Role |
|------|-------|------|
| `woo-24pay.php` | `Woo_24pay_Gateway` | Main gateway class; settings, payment flow, RURL/NURL dispatch |
| `woo-24pay-signgenerator.php` | `WOO_24pay_SignGenerator` | SHA1 + AES-256-CBC request/response signing |
| `woo-24pay-datavalidator.php` | `WOO_24pay_DataValidator` | Validates FirstName, FamilyName, Email before form submit |
| `woo-24pay-formbuilder.php` | `WOO_24pay_FormBuilder` | Renders auto-submitting hidden-field HTML form |
| `woo-24pay-nurlparser.php` | `WOO_24pay_NurlParser` | Parses XML notification from gateway via SimpleXMLElement |
| `woo-24pay-orderresolver.php` | `Order_Number_Resolver` | Resolves any custom order number to an internal WC order ID |

---

## 11. Troubleshooting

### 11.1 Payment method not visible at checkout
→ Disable any page builder plugin on the checkout page (Elementor, Divi, etc.).

### 11.2 Order status not updated after payment
→ Check that the NURL registered with 24pay **exactly** matches the NURL setting (including trailing slash and http/https scheme).
→ Enable logs and inspect `log.txt` in the plugin directory.

### 11.3 Invalid sign error on RURL
→ Verify the `Key` (64-char hex) and `Mid` settings match those provided by 24pay exactly.

### 11.4 Order not found after payment (NURL / RURL)
→ If you use a custom order number plugin, confirm it is one of the supported plugins listed in section 7.
→ If not, add your meta key as described in Section 8.
→ For Alg Custom Order Numbers, both **v1.x and v2.x** are supported.

### 11.5 Log file
→ Located at `wp-content/plugins/24paywoocommerce/log.txt`.
→ Enable via **Settings → Enable logs**.
→ **Never commit this file to version control.**

---

## 12. Changelog

### ver 1.1.2 — 2026-06-01
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

## 13. Test History

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
