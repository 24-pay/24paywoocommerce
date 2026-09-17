# 24pay Platobná brána pre WooCommerce — Integračný manuál

**Verzia:** 1.1.7
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
- Action Scheduler (súčasť WooCommerce) sa používa pre spracovanie NURL notifikácií na pozadí — netreba nič inštalovať navyše. Ak nie je dostupný, plugin automaticky prejde na synchrónne spracovanie.

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
| EUR RURL   | Návratová URL pre platby v EUR — zákazník je sem presmerovaný po platbe. **Musí byť zaregistrovaná v 24pay.** Predvolené: `{site_url}/24pay-rurl/` |
| CZK RURL   | Návratová URL pre platby v CZK. **Musí byť zaregistrovaná v 24pay.** Predvolené: `{site_url}/24pay-rurl/` |
| PLN RURL   | Návratová URL pre platby v PLN. **Musí byť zaregistrovaná v 24pay.** Predvolené: `{site_url}/24pay-rurl/` |
| HUF RURL   | Návratová URL pre platby v HUF. **Musí byť zaregistrovaná v 24pay.** Predvolené: `{site_url}/24pay-rurl/` |
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

## 6. Spracovanie NURL notifikácií (spoľahlivosť a idempotencia)

Od verzie 1.1.5 bolo spracovanie NURL notifikácií vylepšené tak, aby fungovalo správne bez ohľadu na to, či brána doručuje notifikáciu synchrónne (jedno doručenie hneď po transakcii) alebo asynchrónne (server-server, možný retry, oneskorenie, duplicita alebo doručenie mimo poradia).

### 6.1 Rýchle potvrdenie (koniec pomalých odpovedí)
Plugin predtým notifikáciu kompletne spracoval (vrátane `payment_complete()`, e-mailov objednávky, zmien skladu a hookov tretích strán) ešte pred odoslaním odpovede bráne. Pri vyťaženejších obchodoch to mohlo predĺžiť odpoveď na NURL na desiatky sekúnd, čo môže spôsobiť, že brána vyprší časový limit a notifikáciu zopakuje.

Od verzie 1.1.5 `process_nurl()` synchrónne vykonáva už len rýchle a lacné kroky (overenie podpisu, vyhľadanie objednávky, kontrola duplicity) a odpoveď odosiela okamžite, pričom samotnú aktualizáciu stavu objednávky odovzdáva na spracovanie na pozadí.

Od verzie 1.1.7 je toto spracovanie na pozadí ešte rýchlejšie a spoľahlivejšie: namiesto čakania na to, kým Action Scheduler úlohu „vyzdvihne" cez WP-Cron (čo si vyžaduje samostatný HTTP request a v praxi pridáva niekoľko sekúnd oneskorenia — pri zaťaženom serveri alebo pri blokovaní loopback requestov bezpečnostným pluginom/firewallom aj podstatne viac), plugin odpovie bráne `OK` priamo cez `fastcgi_finish_request()` (na LiteSpeed hostingu cez `litespeed_finish_request()`) — týmto sa HTTP spojenie okamžite uzavrie — a **v tom istom PHP requeste** ihneď pokračuje v spracovaní notifikácie (aktualizácia stavu objednávky, e-maily, sklad). Celý proces (potvrdenie + spracovanie) tak zvyčajne prebehne do necelej sekundy, bez akejkoľvek závislosti na WP-Cron alebo Action Scheduler.

Tieto funkcie (`fastcgi_finish_request`/`litespeed_finish_request`) sú dostupné na prevažnej väčšine moderných hostingov (PHP-FPM, LiteSpeed). Ak nie sú k dispozícii (napr. klasický mod_php), plugin automaticky prejde na pôvodné spracovanie cez Action Scheduler — žiadna funkčnosť sa nestratí, len sa stráca výhoda okamžitého spracovania.

### 6.2 Idempotencia (duplicitné notifikácie)
Každá notifikácia je jednoznačne identifikovaná kombináciou `PspTxnId` + `Result`. Pred jej aplikovaním plugin skontroluje malú históriu uloženú priamo v meta údajoch objednávky (`_24pay_processed_notifications`, max posledných 20 záznamov). Ak bola tá istá notifikácia už raz spracovaná, potvrdí sa (`OK`) bez opätovného spracovania — chráni to pred duplicitným doručením a retry pokusmi brány.

Na toto sa nepoužíva žiadna vlastná databázová tabuľka — plugin využíva výhradne meta údaje objednávky vo WooCommerce (plne kompatibilné s klasickým aj HPOS úložiskom) a natívnu WordPress tabuľku `wp_options` pre zámok na úrovni objednávky (pozri 6.3), takže nie je potrebné nič naviac inštalovať, migrovať ani pri odinštalovaní čistiť.

### 6.3 Ochrana proti súbežnému spracovaniu (zámok na objednávku)
Ak pre tú istú objednávku prídu (takmer) súčasne dve notifikácie, spracuje sa vždy len jedna naraz. Využíva sa na to atomický zámok postavený na WordPress option: `add_option()` sa spolieha na UNIQUE index na `wp_options.option_name`, takže je atomický aj bez externej object cache (Redis/Memcached). „Zaseknutý" zámok (napr. po spadnutom requeste) sa automaticky uvoľní po 20 sekundách. Ak sa notifikácii nepodarí zámok získať, plugin odpovie `FAIL`, aby brána neskôr notifikáciu zopakovala — k strate dát tak nikdy nedôjde.

### 6.4 Ochrana proti poradiu notifikácií (state machine)
Notifikácie majú priradenú „prioritu" podľa toho, aký finálny je ich výsledok:

```
PENDING (1) < AUTHORIZED (2) < FAIL (3) < REVERSAL (4) < OK (5)
```

Ak notifikácia s nižšou prioritou príde až po tej, ktorá už bola s vyššou prioritou spracovaná (napr. oneskorený `PENDING` po už spracovanom `OK`), je ignorovaná a stav objednávky sa **nevráti späť**. Posledný aplikovaný výsledok sa ukladá do meta objednávky `_24pay_last_result`.

### 6.5 Retry v resolveri objednávok (bezpečné pre async doručenie)
`Order_Number_Resolver::resolve()` sa pred zlyhaním pokúsi až 3× (s odstupom 300 ms) v prípade, že notifikácia príde skôr, než je objednávka plne zapísaná/viditeľná v databáze. Pre bežný prípad to nepridáva žiadnu réžiu, keďže objednávka sa nájde hneď na prvý pokus.

### 6.6 Ochrana proti súbehu RURL a NURL („Platba sa spracováva" sa nezasekne)
Zákazníkov prehliadač (RURL, presmerovanie späť z brány) a server-server notifikácia (NURL) môžu bežať ako dva súbežné requesty pre tú istú objednávku. `process_rurl()` z bezpečnostných dôvodov nastavuje/obnovuje príznak „čaká sa na notifikáciu" (`_24pay_awaiting_notification`), aby stránka ďakovnej/detailu objednávky zobrazovala oznam „Platba sa spracováva..." dovtedy, kým NURL notifikácia neprinesie definitívny výsledok.

Od verzie 1.1.7 je toto ošetrené voči dvom typom súbehu:
- `process_rurl()` už tento príznak neobnoví, ak je objednávka už v konečnom neplatenom stave (`failed`/`cancelled`) — teda ak NURL notifikácia s negatívnym výsledkom už bola aplikovaná, opakovaný RURL požiadavok (napr. pri obnovení stránky) príznak znova nezapne.
- `apply_notification_result()` na úplnom konci spracovania notifikácie znova načíta objednávku „na čerstvo" z databázy a príznak zmaže ešte raz. Toto rieši prípad, keď RURL zapíše príznak súbežne s tým, ako NURL request beží — objekt objednávky v NURL requeste mal svoje meta dáta načítané do pamäte skôr, než RURL svoj zápis vykonal, takže pôvodné `delete_meta_data()` volanie o ňom nevedelo a bolo bezúčinné.

Bez tejto opravy mohol príznak (a teda aj nekonečné obnovovanie stránky s oznamom „Platba sa spracováva...") ostať nastavený až `AWAITING_NOTIFICATION_TIMEOUT` (10 minút), aj keď bola objednávka už dávno správne spracovaná.

---

## 7. Mapovanie stavov objednávky

| Výsledok 24pay | Stav objednávky WooCommerce |
|----------------|-----------------------------|
| `OK`           | `processing` / `completed` (cez `payment_complete()`) |
| `PENDING`      | `on-hold` |
| `AUTHORIZED`   | `on-hold` |
| `REVERSAL`     | `refunded` |
| čokoľvek iné   | `failed` |

---

## 8. Podporované pluginy pre číslovanie objednávok

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

## 9. Pridanie podpory pre iné pluginy

Ak váš obchod používa plugin pre číslovanie objednávok, ktorý nie je uvedený v Sekcii 8, môžete pridať podporu bez úpravy kódu pluginu 24pay.

Potrebujete poznať meta kľúč, ktorý váš plugin používa na uloženie vlastného čísla objednávky v databáze. Môžete to zistiť od podpory daného pluginu, alebo spustením debug snippetu nižšie.

### 9.1 Možnosť A — Pridanie meta kľúča cez functions.php

Pridajte nasledujúci kód do súboru `functions.php` vašej témy alebo do vlastného pluginu:

```php
add_filter( '24pay_order_number_meta_keys', function( array $keys ): array {
    $keys[] = '_meta_kluc_vasho_pluginu';  // nahraďte skutočným meta kľúčom
    return $keys;
} );
```

> **Poznámka:** Nahraďte `_meta_kluc_vasho_pluginu` skutočným meta kľúčom vášho pluginu. Pozrite Sekciu 9.3, ako ho nájsť.

### 9.2 Možnosť B — Pridanie cez kompatibilný plugin

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

### 9.3 Ako nájsť meta kľúč vášho pluginu

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

## 10. Kompatibilita s HPOS

Plugin deklaruje kompatibilitu s WooCommerce High-Performance Order Storage (HPOS / custom_order_tables) cez `FeaturesUtil::declare_compatibility()` na hooku `before_woocommerce_init`.

---

## 11. Štruktúra súborov

| Súbor | Trieda | Úloha |
|-------|--------|-------|
| `woo-24pay.php` | `Woo_24pay_Gateway` | Hlavná trieda brány; nastavenia, priebeh platby, RURL/NURL dispatch |
| `woo-24pay-signgenerator.php` | `WOO_24pay_SignGenerator` | SHA1 + AES-256-CBC podpisovanie požiadaviek/odpovedí |
| `woo-24pay-datavalidator.php` | `WOO_24pay_DataValidator` | Validácia FirstName, FamilyName, Email pred odoslaním formulára |
| `woo-24pay-formbuilder.php` | `WOO_24pay_FormBuilder` | Generuje auto-submitujúci HTML formulár so skrytými poľami |
| `woo-24pay-nurlparser.php` | `WOO_24pay_NurlParser` | Parsuje XML notifikáciu z brány cez SimpleXMLElement |
| `woo-24pay-orderresolver.php` | `Order_Number_Resolver` | Prekladá akékoľvek vlastné číslo objednávky na interné WC ID |

---

## 12. Riešenie problémov

### 12.1 Platobná metóda nie je viditeľná pri pokladni
→ Vypni page builder plugin na stránke pokladne (Elementor, Divi a pod.).

### 12.2 Stav objednávky sa neaktualizuje po platbe
→ Skontroluj, že NURL zaregistrovaná v 24pay **presne** zodpovedá nastaveniu NURL (vrátane lomky na konci a schémy http/https).
→ Zapni logy a skontroluj `log.txt` v adresári pluginu.
→ Na serveroch s PHP-FPM alebo LiteSpeed (t. j. takmer všade) sa od verzie 1.1.7 stav objednávky aktualizuje priamo v tom istom requeste ako NURL notifikácia — netreba teda spoliehať sa na WP-Cron. Ak by aj napriek tomu stav neaktualizoval, over v **WooCommerce → Status → Naplánované akcie**, či tam nie je zaseknutá/zlyhaná úloha `woo_24pay_process_notification` (znamenalo by to, že server nepodporuje `fastcgi_finish_request()`/`litespeed_finish_request()` a plugin použil záložné spracovanie cez Action Scheduler) — v tom prípade over, že beží WP-Cron (`DISABLE_WP_CRON` nie je nastavené na `true`, prípadne je nastavený reálny serverový cron volajúci `wp-cron.php`).

### 12.3 Odpoveď na NURL trvá dlho / brána stále opakuje notifikáciu
→ Toto bol známy problém pred verziou 1.1.5, kedy plugin čakal na kompletné spracovanie objednávky (vrátane e-mailov a hookov) pred odoslaním odpovede. Od verzie 1.1.5 sa odpoveď odosiela ihneď po overení notifikácie; samotná aktualizácia beží na pozadí. Od verzie 1.1.7 navyše platí, že na väčšine hostingov (PHP-FPM/LiteSpeed) beží aj samotná aktualizácia stavu objednávky prakticky okamžite (v tom istom requeste), takže by celý proces (potvrdenie + zápis stavu) nemal trvať viac než približne sekundu. Uisti sa, že používaš verziu 1.1.7 alebo novšiu.

### 12.3.1 Oznam „Platba sa spracováva..." sa nezastaví, hoci notifikácia je už spracovaná
→ Toto bol známy race condition medzi RURL (návrat zákazníka z brány) a NURL (server-server notifikácia) opravený vo verzii 1.1.7 — pozri Sekciu 6.6. Uisti sa, že používaš verziu 1.1.7 alebo novšiu. Ak problém pretrváva aj na tejto verzii, zapni logy a skontroluj, či sa v `log.txt` objavuje hláška `Cleared a concurrently re-armed '_24pay_awaiting_notification' flag...` (potvrdzuje, že opravný mechanizmus zabral) — ak sa neobjavuje a príznak napriek tomu nemizne, kontaktuj podporu s priloženým `log.txt`.

### 12.4 Chyba neplatného podpisu na RURL
→ Over, že `Key` (64-znakový hex) a `Mid` presne zodpovedajú hodnotám poskytnutým spoločnosťou 24pay.

### 12.5 Objednávka sa nenájde po platbe (NURL / RURL)
→ Ak používaš plugin pre vlastné číslovanie objednávok, over, že je to jeden z podporovaných pluginov zo sekcie 8.
→ Ak nie, pridaj meta kľúč podľa Sekcie 9.
→ Pre Alg Custom Order Numbers over, že používaš **v1.x alebo v2.x** — oba sú podporované.

### 12.6 Súbor s logmi
→ Nachádza sa na `wp-content/plugins/24paywoocommerce/log.txt`.
→ Aktivuj cez **Nastavenia → Povoliť logy**.
→ **Nikdy tento súbor necommituj do verzionovacieho systému.**

---

## 13. Changelog

### ver 1.1.7 — 2026-09-17
- **Opravené:** spracovanie NURL notifikácie mohlo trvať až ~20 sekúnd, aj keď samotné potvrdenie bráne prišlo do ~500ms. Príčina: skutočná aktualizácia stavu objednávky bežala ako úloha Action Scheduler na pozadí, ktorá závisí od toho, kedy ju „vyzdvihne" WP-Cron (cez samostatný HTTP loopback request) — toto samotné oneskorenie mohlo predstavovať niekoľko sekúnd, a pri vyššom zaťažení servera alebo blokovaní loopback requestov bezpečnostným pluginom/firewallom výrazne viac. `process_nurl()` teraz odpovie bráne `OK` priamo cez `fastcgi_finish_request()` (na LiteSpeed cez `litespeed_finish_request()`), čím sa HTTP spojenie okamžite uzavrie, a **v tom istom PHP requeste** ihneď pokračuje spracovaním notifikácie — bez akejkoľvek závislosti na WP-Cron/Action Scheduler. Na serveroch, kde tieto funkcie nie sú dostupné (napr. klasický mod_php), sa automaticky použije pôvodné spracovanie cez Action Scheduler.
- **Opravené:** oznam „Platba sa spracováva..." mohol zostať zobrazený (a stránka sa donekonečna obnovovala) až 10 minút, aj keď NURL notifikácia bola už dávno kompletne spracovaná a stav objednávky aktualizovaný. Príčina: race condition medzi RURL presmerovaním (prehliadač zákazníka) a NURL notifikáciou (server-server), ktoré môžu bežať ako súbežné requesty pre tú istú objednávku — pozri Sekciu 6.6 pre technické detaily opravy.

### ver 1.1.6 — 2026-09-17
- **Opravené:** oznam „Platba sa spracováva..." sa na stránke prijatej objednávky / detailu objednávky vôbec nezobrazoval. Príčina: trieda `Woo_24pay_Gateway` sa v rámci jedného requestu v skutočnosti vytvára viackrát — raz ju vytvorí samotné WooCommerce (pri načítaní zoznamu dostupných platobných brán) a raz náš vlastný listener zavesený na `init` (ktorý potrebuje inštanciu na detekciu RURL/NURL požiadaviek pri každom requeste). Každá inštancia pri vytvorení znova zaregistrovala tie isté WordPress hooky, takže `woocommerce_before_thankyou` / `woocommerce_thankyou_24pay_gateway` sa spustili dvakrát, čo otvorilo dva vnorené output buffre — druhé volanie `ob_end_clean()` zmazalo oznam, ktorý prvé volanie práve vypísalo. Registrácia hookov je teraz ošetrená tak, aby prebehla len raz za request, bez ohľadu na to, koľkokrát sa trieda vytvorí.

### ver 1.1.5 — 2026-09-16
- NURL notifikácie sa teraz bráne potvrdzujú hneď po overení podpisu; samotná aktualizácia stavu objednávky (payment_complete/e-maily/sklad/hooky) sa presunula na pozadie (Action Scheduler), čím sa predišlo pomalým odpovediam a vypršaniu časového limitu na strane brány. Automatický fallback na synchrónne spracovanie, ak Action Scheduler nie je dostupný.
- Pridaná idempotencia NURL notifikácií: duplicitné/opakované notifikácie (rovnaká kombinácia `PspTxnId` + `Result`) sa detegujú cez meta údaje objednávky (`_24pay_processed_notifications`) a bezpečne ignorujú.
- Pridaný atomický zámok na úrovni objednávky (postavený na WordPress option, bez vlastnej DB tabuľky) na serializáciu súbežných NURL notifikácií pre tú istú objednávku.
- Pridaná ochrana formou state machine (meta objednávky `_24pay_last_result`), ktorá zabraňuje, aby oneskorená/mimo poradia notifikácia vrátila stav objednávky späť.
- Pridaný retry/backoff do `Order_Number_Resolver::resolve()` pre správne spracovanie notifikácií prichádzajúcich skôr, než je objednávka plne zapísaná/viditeľná v databáze.
- Opravená fatálna chyba, ktorá nastala, keď NURL notifikácia odkazovala na objednávku, ktorú sa nepodarilo nájsť.
- Opravený chýbajúci `die()` po neplatnej/zlyhanej NURL odpovedi, ktorý predtým spôsoboval vykreslenie zvyšku stránky za telom odpovede `FAIL`.
- Zmenená viditeľnosť `WOO_24pay_NurlParser::$pspTxnId` z `private` na `public` (potrebné pre vyššie uvedenú logiku idempotencie; property je teraz konzistentná s `$msTxnId` a `$result`).

### ver 1.1.4 — 2026-07-30
- Pridané samostatné RURL nastavenia podľa meny (EUR/CZK/PLN/HUF)
- Pridaný výber RURL podľa meny v `payment_form()` cez `get_rurl_by_currency()`

### ver 1.1.3 — 2026-07-28
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

## 14. História testovania

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
