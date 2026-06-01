# 24pay Platobná brána pre WooCommerce — Integračný manuál

**Verzia:** 1.1.2
**Licencia:** MIT
**Autor:** 24pay (https://www.24-pay.sk)
**Posledné testovanie:** WC 10.8.1 / WP 7.0

---

## 1. Popis

Plugin integruje platobnú bránu 24pay (https://www.24-pay.eu) do WooCommerce.
Podporuje platby kartou, bankový prevod a metódu „Na splátky".

---

## 2. Požiadavky

- WordPress 5.0+
- WooCommerce 3.5+
- PHP 7.2+ (PHP 8.x podporovaný)
- Rozšírenie OpenSSL (pre AES-256-CBC podpisovanie)
- Platná zmluva s 24pay (Mid, Key, EshopId)

---

## 3. Inštalácia

1. Nahraj priečinok `24paywoocommerce/` do `wp-content/plugins/`.
2. V administrácii WordPressu prejdi na **Pluginy → Aktivovať** „Woocommerce 24pay Payment gateway".
3. Prejdi na **WooCommerce → Nastavenia → Platby → 24pay_gateway → Spravovať**.

---

## 4. Konfigurácia

### 4.1 Základné nastavenia

| Nastavenie     | Popis |
|----------------|-------|
| Povoliť/Zakázať | Povolí bránu, aby sa zobrazovala pri pokladni. |
| Názov          | Názov platobnej metódy zobrazený zákazníkovi pri pokladni. Predvolené: `24-pay | Platobná brána` |
| Popis          | Krátky popis zobrazený pod názvom pri pokladni. |

### 4.2 Prihlasovacie údaje

Poskytnuté spoločnosťou 24pay po podpísaní obchodnej zmluvy (doručené SMS).

| Nastavenie | Popis |
|------------|-------|
| Mid        | Identifikátor obchodníka (Merchant ID). Používa sa aj ako seed pre AES-256-CBC IV. Príklad: `demoOMED` |
| EshopId    | Identifikátor e-shopu. Príklad: `11111111` |
| Key        | 64-znakový hex reťazec použitý ako AES-256-CBC šifrovací kľúč. Príklad: `1234567812345678...` (64 znakov) |

### 4.3 URL adresy

| Nastavenie | Popis |
|------------|-------|
| RURL       | Návratová URL — zákazník je sem presmerovaný po platbe. **Musí byť zaregistrovaná v 24pay.** Predvolené: `{site_url}/24pay-rurl/` |
| NURL       | Notifikačná URL — 24pay sem posiela POST XML notifikáciu na aktualizáciu stavu objednávky. **Musí byť zaregistrovaná v 24pay.** Predvolené: `{site_url}/24pay-nurl/` |

> ⚠️ Hodnoty RURL a NURL **musia presne zodpovedať** URL adresám zaregistrovaným v portáli 24pay, vrátane lomky na konci a schémy http/https.
> Plugin **nepoužíva** WordPress rewrite pravidlá — porovnáva priamo surové request URI.

### 4.4 Testovací režim

| Nastavenie      | Popis |
|-----------------|-------|
| Testovací režim | Keď je zaškrtnuté, platby sú odosielané na `https://test.24-pay.eu/pay_gate/paygt` namiesto ostrej brány. **Pred spustením do produkcie vypnúť!** |

### 4.5 Voliteľné nastavenia

| Nastavenie                  | Popis                                                                                                                                                                                    |
|-----------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Notifikačný e-mail          | Dodatočná e-mailová adresa pre príjem platobných notifikácií. Ponechaj prázdne ak nechceš používať.                                                                                      |
| Notifikovať zákazníka e-mailom | Odošle zákazníkovi e-mail so stavom platby.                                                                                                                                              |
| Uložiť transakčný e-mail    | Odošle odkaz na offline platbu ak nedôjde k odpovedi alebo platba bude zamietnutá.                                                                                                       |
| Jazyk                       | Jazyk zobrazenia platobnej brány. Nastav `automaticky` pre detekciu z lokalizácie objednávky WooCommerce. Podporované: `sk`, `cs`, `en`, `de`, `fr`, `it`, `pl`, `hu`, `es`, `ro`, `sl`. |
| Zahrnúť košík a dopravu     | Odošle obsah košíka ako base64-kódovaný JSON (pole `Cart`). **Vyžadované len pre metódu „Na splátky".**                                                                                  |
| Povoliť logy                | Zapisuje ladiace záznamy do `log.txt` v adresári pluginu. **Nikdy tento súbor necommituj do repozitára.**                                                                                |

---

## 5. Priebeh platby

1. Zákazník odošle objednávku → WooCommerce vytvorí objednávku.
2. `process_payment()` presmeruje na WooCommerce stránku platby za objednávku.
3. `payment_form()` zostaví HTML formulár so skrytými poľami a automaticky ho odošle do 24pay.
4. Zákazník dokončí platbu na bráne 24pay.
5. **NURL** (POST): 24pay odošle XML notifikáciu → plugin aktualizuje stav objednávky.
6. **RURL** (GET): Zákazník je presmerovaný späť → plugin overí podpis a presmeruje na ďakovnú stránku.

```
Pokladňa → process_payment() → WC stránka platby
         → payment_form() → FormBuilder → auto-submit POST → brána 24pay
                                                             ↕
                                          RURL (GET presmerovanie späť do e-shopu)
                                          NURL (POST XML notifikácia → aktualizácia stavu objednávky)
```

---

## 6. Mapovanie stavov objednávky

| Výsledok 24pay | Stav objednávky WooCommerce |
|----------------|-----------------------------|
| `OK`           | `processing` / `completed` (cez `payment_complete()`) |
| `PENDING`      | `on-hold` |
| `AUTHORIZED`   | `on-hold` |
| `REVERSAL`     | `refunded` |
| čokoľvek iné   | `failed` |

---

## 7. Podporované pluginy pre číslovanie objednávok

Plugin automaticky detekuje a podporuje tieto pluginy pre vlastné číslovanie objednávok:

| Plugin | Verzia | Metóda detekcie |
|--------|--------|-----------------|
| Custom Order Numbers for WooCommerce (Alg) | **v1.x** | `Alg_WC_Custom_Order_Numbers_Core::add_order_number_to_tracking()` |
| Custom Order Numbers for WooCommerce (Alg) | **v2.x** | `apply_filters('alg_wc_custom_order_numbers_get_order_id_by_order_number')` |
| Sequential Order Numbers for WooCommerce (free) | ľubovoľná | `wc_sequential_order_numbers()->find_order_by_order_number()` |
| Sequential Order Numbers Pro | ľubovoľná | `wc_seq_order_number_pro()->find_order_by_order_number()` |
| YITH Sequential Order Numbers | ľubovoľná | `ywson_get_order_id_by_order_number()` |

Alg v1.x aj v2.x sú podporované súčasne — resolver skúša najprv v1.x (kontrola existencie triedy), potom v2.x (filter API). Upgrade z Alg v1.x na v2.x teda **nevyžaduje žiadne zmeny v tomto plugine**.

Ak žiadny plugin nie je detekovaný, resolver (`Order_Number_Resolver`) použije záložné riešenia:

1. Vyhľadávanie podľa známych meta kľúčov objednávky:
   - `_alg_wc_custom_order_number`
   - `_order_number`
   - `_ywson_order_number`
   - `_wcj_order_number`
   - `_wc_order_number`
   - `_order_number_formatted`
2. Spracovanie hodnoty ako priameho WooCommerce ID objednávky.

---

## 8. Pridanie podpory pre iné pluginy

Ak váš obchod používa plugin pre číslovanie objednávok, ktorý nie je uvedený v Sekcii 7, môžete pridať podporu bez úpravy kódu pluginu 24pay.

Potrebujete poznať meta kľúč, ktorý váš plugin používa na uloženie vlastného čísla objednávky v databáze. Môžete to zistiť od podpory daného pluginu, alebo spustením debug snippetu nižšie.

### 8.1 Možnosť A — Pridanie meta kľúča cez functions.php

Pridajte nasledujúci kód do súboru `functions.php` vašej témy alebo do vlastného pluginu:

```php
add_filter( '24pay_order_number_meta_keys', function( array $keys ): array {
    $keys[] = '_meta_kluc_vasho_pluginu';  // nahraďte skutočným meta kľúčom
    return $keys;
} );
```

> **Poznámka:** Nahraďte `_meta_kluc_vasho_pluginu` skutočným meta kľúčom vášho pluginu. Pozrite Sekciu 8.3, ako ho nájsť.

### 8.2 Možnosť B — Pridanie cez kompatibilný plugin

Ak ste vývojár pluginu a chcete dodať vstavanú kompatibilitu s 24pay, pridajte do svojho pluginu nasledujúce:

```php
class My_Plugin_24pay_Compat {

    public static function init(): void {
        // Registruj kompatibilitu len ak je aktívny plugin 24pay
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

### 8.3 Ako nájsť meta kľúč vášho pluginu

Ak neviete, aký meta kľúč váš plugin používa, pridajte tento dočasný debug snippet do `functions.php` a otvorte ľubovoľnú objednávku v administrácii WooCommerce:

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

Hľadajte meta kľúč, ktorý obsahuje vaše vlastné číslo objednávky. Po nájdení ho použite v Možnosti A a potom tento debug kód odstráňte.

---

## 9. Kompatibilita s HPOS

Plugin deklaruje kompatibilitu s WooCommerce High-Performance Order Storage (HPOS / custom_order_tables) cez `FeaturesUtil::declare_compatibility()` na hooku `before_woocommerce_init`.

---

## 10. Štruktúra súborov

| Súbor | Trieda | Úloha |
|-------|--------|-------|
| `woo-24pay.php` | `Woo_24pay_Gateway` | Hlavná trieda brány; nastavenia, priebeh platby, RURL/NURL dispatch |
| `woo-24pay-signgenerator.php` | `WOO_24pay_SignGenerator` | SHA1 + AES-256-CBC podpisovanie požiadaviek/odpovedí |
| `woo-24pay-datavalidator.php` | `WOO_24pay_DataValidator` | Validácia FirstName, FamilyName, Email pred odoslaním formulára |
| `woo-24pay-formbuilder.php` | `WOO_24pay_FormBuilder` | Generuje auto-submitujúci HTML formulár so skrytými poľami |
| `woo-24pay-nurlparser.php` | `WOO_24pay_NurlParser` | Parsuje XML notifikáciu z brány cez SimpleXMLElement |
| `woo-24pay-orderresolver.php` | `Order_Number_Resolver` | Prekladá akékoľvek vlastné číslo objednávky na interné WC ID |

---

## 11. Riešenie problémov

### 11.1 Platobná metóda nie je viditeľná pri pokladni
→ Vypni page builder plugin na stránke pokladne (Elementor, Divi a pod.).

### 11.2 Stav objednávky sa neaktualizuje po platbe
→ Skontroluj, že NURL zaregistrovaná v 24pay **presne** zodpovedá nastaveniu NURL (vrátane lomky na konci a schémy http/https).
→ Zapni logy a skontroluj `log.txt` v adresári pluginu.

### 11.3 Chyba neplatného podpisu na RURL
→ Over, že `Key` (64-znakový hex) a `Mid` presne zodpovedajú hodnotám poskytnutým spoločnosťou 24pay.

### 11.4 Objednávka sa nenájde po platbe (NURL / RURL)
→ Ak používaš plugin pre vlastné číslovanie objednávok, over, že je to jeden z podporovaných pluginov zo sekcie 7.
→ Ak nie, pridaj meta kľúč podľa Sekcie 8.
→ Pre Alg Custom Order Numbers over, že používaš **v1.x alebo v2.x** — oba sú podporované.

### 11.5 Súbor s logmi
→ Nachádza sa na `wp-content/plugins/24paywoocommerce/log.txt`.
→ Aktivuj cez **Nastavenia → Povoliť logy**.
→ **Nikdy tento súbor necommituj do verzionovacieho systému.**

---

## 12. Changelog

### ver 1.1.2 — 2026-06-01
- Alg Custom Order Numbers aktualizovaný na v2.x filter API; zachovaná spätná kompatibilita s v1.x
- Pridaná trieda `Order_Number_Resolver` s meta-key fallbackom a WP object cache (TTL 300 s)

### ver 1.1.1 — 2025-09-05
- Deklarovaná kompatibilita s HPOS (High-Performance Order Storage)
- Pridaná podpora stavu `REVERSAL` → `refunded`
- Pridaná podpora odoslania obsahu košíka ako JSON (base64) pre metódu „zaplať neskôr"
- Pridaná auto-detekcia jazyka z lokalizácie objednávky WooCommerce
- Pridaná možnosť Save Transaction Email

### ver 1.1.0 — 2022-11-02
### ver 1.0.1 — 2021-10-08
### ver 1.0.0 — 2018-11-21

---

## 13. História testovania

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

*Spoločnosť 24-pay s.r.o. poskytuje moduly pre jednoduchú implementáciu komunikácie s platobnou bránou.
Moduly boli testované na čistej inštalácii daného CMS systému. Spoločnosť si vyhradzuje právo odmietnuť
podporu pri problémoch spôsobených kolíziami s dodatočne nainštalovanými pluginmi.
Špecifické úpravy (notifikovanie klientov, generovanie faktúr a pod.) konzultuj so svojím developerom.*
