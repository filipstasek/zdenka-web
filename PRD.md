# PRD — zdenkacibulova.sk

Dokument na odovzdanie inej AI alebo inému vývojárovi. Popisuje, **čo systém
robí, ako je postavený a prečo je postavený práve tak**. Časť „Vedomé
rozhodnutia" je najdôležitejšia — sú tam veci, ktoré vyzerajú ako chyba, ale
chyba to nie je.

Stav k: **zdenka-theme 3.49.3 · property-pro-plugin 5.71 · zc-newsletter (ZC VIP) 1.24.3
· zc-reviews 1.7.1 · zc-ebook 1.0.7 · zc-2fa 1.1.3 · zc-installer 1.0.5**

> **Zdroj pravdy je ostrý web.** Na webe sa medzičasom robilo aj mimo tohto
> repozitára (premenovanie na VIP, katalógy typov a stavov ponúk). Pred každou
> prácou si nechaj od klienta poslať aktuálne pluginy z webu a porovnaj ich
> s repozitárom (`diff -rq --strip-trailing-cr` – súbory z webu majú CRLF).

---

## 1. Kontext

**Klient:** Mgr. Zdenka Cibuľová, realitná maklérka, Banská Bystrica / Zvolen.
**Web:** https://www.zdenkacibulova.sk — WordPress na bežnom zdieľanom hostingu.
**Zadávateľ vývoja:** Filip Stašek. Zdenka posiela požiadavky cez neho.

**Cieľ:** web, ktorý pôsobí ako práca za 10 000 €, ale maklérka si ho vie
obsluhovať sama bez vývojára. Preto je veľká časť obsahu a nastavení
vytiahnutá do administrácie namiesto toho, aby bola v kóde.

**Jazyk:** všetko po slovensky — texty na webe, v administrácii, e-maily,
**aj komentáre v kóde**. Toto je pevné pravidlo.

---

## 2. Architektúra

Jedna téma a šesť pluginov. Každý je samostatne inštalovateľný `.zip`.
Neexistuje build v zmysle bundlera — PHP a assety idú ako sú, len CSS a JS
majú zmenšené kópie (viď §9).

| Balík | PHP riadkov | Čo rieši |
|---|---|---|
| `zdenka-theme` | 5 835 | vzhľad, šablóny stránok, editovateľné texty, nástroje pre webmastera |
| `property-pro-plugin` | 8 489 | nehnuteľnosti, realitný panel, CRM, zálohy, denník |
| `zc-newsletter` | 3 405 | **„ZC VIP"** – odber ponúk: odberatelia, kategórie, rozposielanie, šablóny e-mailov |
| `zc-reviews` | 1 387 | recenzie; tie z Google sa pridávajú ručne (bez API) |
| `zc-ebook` | 603 | PDF lead-magnet |
| `zc-2fa` | 1 107 | dvojfaktorové prihlásenie |
| `zc-installer` | 197 | nainštaluje všetky priložené balíky naraz (klient ho nepoužíva) |

**Závislosti sú jednosmerné a mäkké.** Pluginy sa navzájom volajú výhradne cez
`function_exists()`. Panel v property-pro má most `pp_nl_*`, ktorý obaľuje
funkcie newslettera — vďaka nemu panel nespadne, keď je newsletter v staršej
verzii, len zobrazí výzvu na aktualizáciu. **Toto pravidlo dodržiavať.**

---

## 3. Dátový model

**Post typy**
- `property` — nehnuteľnosť. Všetky parametre sú post meta s prefixom
  `_property_` (`_property_cena`, `_property_stav`, `_property_plocha`, …).
- `pp_lead` — zachytený dopyt z formulára. Meta `_lead_name`, `_lead_email`,
  `_lead_phone`, `_lead_message`, `_lead_note`.

**Vlastné tabuľky**
- `{prefix}zc_newsletter` — odberatelia. Stĺpce: `id, email, name, interest,
  status ENUM('pending','active','unsubscribed'), token, source,
  subscribed_at, confirmed_at`. `interest` je **zoznam kľúčov oddelený
  čiarkou**, prázdna hodnota = „všetko".
- `{prefix}zc_reviews` — recenzie. Stĺpec `source` = `own|google`. Pri existujúcej
  tabuľke ho dopĺňa `zcr_ensure_source_column()` cez ALTER — `dbDelta` to
  nespraví, lebo schéma používa `CREATE TABLE IF NOT EXISTS`.
- `{prefix}zc_audit` — bezpečnostný denník.

**Roly**
- `realitny_makler` — pracuje v realitnom paneli na frontende. Do wp-adminu sa
  nedostane (presmerovanie), výnimkou je Google Site Kit.
- `nahlad_webu` — klient si pozrie web pred spustením, nič nemôže meniť.

---

## 4. Shortcody

```
[property_grid]  [property_carousel]  [property_filter]  [property_favorites]
[predane_nehnutelnosti]  [porovnanie]  [reality_mesto]  [realitny_panel]
[zc_reviews]  [zc_vip]  [zc_newsletter]  [zc_newsletter_full]  [zc_ebook]
[zc_contact_form]
```

`[zc_reviews]` má režimy `layout="grid|mosaic|carousel|carousel-grid|paged"`.
Podstránka `/referencie` používa **`carousel`** (rozhodnutie klienta).

---

## 5. Administrácia — „Web Zdenky"

Všetko je zlúčené do jednej bubliny v menu wp-adminu, aby maklérka nehľadala
nastavenia po celom WordPresse. Vyžaduje `manage_options`.

| Položka | Čo tam je |
|---|---|
| Prehľad | rozcestník |
| Nehnuteľnosti / Pridať novú | správa ponúk |
| **Možnosti ponúk** | číselníky Stav a Vlastníctvo (§7) |
| Texty stránok | 43+ textov zo šablón, ktoré nejdú cez editor |
| Stránky a údržba | režim údržby, zapínanie stránok |
| Notifikácie | komu chodia dopyty z formulárov |
| VIP (bývalý Newsletter) | odberatelia, kategórie, rozosielanie, história |
| Recenzie | správa recenzií vrátane tých z Google (zdroj „Google") |
| Ebook | lead-magnet |
| Indexovanie | diagnostika presmerovaní pre Search Console |
| Zabezpečenie (2FA) | |
| Denník / Zálohy | bezpečnostný denník, denné zálohy panela |
| Nástroje | WebP, minifikácia, cache |
| Náhľad pre klienta | |

**Realitný panel** je samostatná aplikácia na frontende (`[realitny_panel]`),
bez hlavičky a pätičky témy. Maklérka v ňom robí bežnú dennú prácu: ponuky,
dopyty, newsletter, recenzie, nastavenia. Vo väčšine funkcií duplikuje
wp-admin, ale je pohodlnejší — pri rozporoch je **panel referenčný vzor**.

---

## 6. VIP (newsletter) — model, ktorý treba pochopiť celý

Navonok sa volá **VIP** (stránka `/vip/`, shortcode `[zc_vip]`, plugin „ZC VIP").
Vnútri ostali názvy `zcn_*`, tabuľka `zc_newsletter` aj priečinok `zc-newsletter` –
**nepremenovávať**, prišlo by sa o dáta a o nadväznosť aktualizácií.

Kontaktný formulár s odškrtnutým VIP prihlasuje **vždy na všetky ponuky**;
záujem uvedený vo formulári patrí do CRM, nie do preferencií odberu.

### Kategórie
Definované v `zcn_categories()`, uložené v option `zcn_categories`, editovateľné
v administrácii. Každá má `label`, `group` (nadpis skupiny) a príznak `offer`
(je to kategória nehnuteľností?).

### Pravidlo výberu — dôležité
```
nič neoznačené      → dostáva všetko
označené všetko     → dostáva všetko  (uloží sa ako prázdna hodnota)
označená časť       → dostáva presne to
```
Implementuje `zcn_sanitize_interests()` + `zcn_is_all_interests()`. Je na to
jednotkový test. **Nemeniť bez neho.**

### Prihlásenie — bez potvrdzovacieho kroku
Kto odošle formulár, je **okamžite aktívny** a nastavený na všetko. Platí to
aj pre staré `pending` záznamy a pre tých, čo sa kedysi odhlásili. Token sa
pritom vždy obnoví, aby starý odkaz z e-mailu nikoho nevedel prihlásiť späť.

Uvítací e-mail nič nepotvrdzuje — dáva tlačidlo **Zmeniť si témy**
(`zcn_action=prefs`) a pod ním odkaz na odhlásenie.

> **Riziko, o ktorom klient vie:** bez potvrdenia môže ktokoľvek zadať cudziu
> adresu. Slabší doklad o súhlase podľa GDPR, horšia doručiteľnosť. Bolo to
> vedomé rozhodnutie klienta; `zcn_send_confirmation()` v kóde zostal, keby sa
> to malo vrátiť.

### Odhlásenie cez medzikrok
Odkaz v tele e-mailu vedie na stránku *„Škoda, že odchádzate"* — najprv ponúkne
úpravu tém, až pod tým je vedomé potvrdenie odchodu (POST + nonce).

**Výnimka, ktorú neodstraňovať:** tlačidlo „Unsubscribe", ktoré zobrazuje sám
Gmail/Apple Mail, posiela `POST` s `List-Unsubscribe=One-Click`. Tam sa podľa
RFC 8058 **nesmie nič pýtať** a odhlásenie musí prejsť okamžite. Je to
ošetrené v `confirm.php`.

### Rozosielanie
Cieliť sa dá na: všetkých / len ponuky / vybrané kategórie / **konkrétnych
ľudí** (max 800 adries). Podporuje naplánovanie cez WP-Cron, premenné
`{meno}` a `{email}`, meranie otvorení a klikov.

> Odberatelia newslettera **nikdy nedostávajú správy z kontaktných formulárov**.
> Overené auditom všetkých volaní `wp_mail()`. Toto pravidlo platí naďalej.

---

## 7. Číselníky pri zadávaní ponuky

`property-pro-plugin/includes/options.php`, stránka *Možnosti ponúk* vo wp-admine
aj sekcia v realitnom paneli (`panel_property_options`). Spravujú sa štyri zoznamy:

| Zoznam | Meta | Ukladá sa | Poznámka |
|---|---|---|---|
| Typ ponuky | `_property_typ` | **kľúč** (`predaj`…) | názov + farba štítka, príznak „prenájom" |
| Stav ponuky | `_property_stav_predaja` | **kľúč** (`rezervovane`…) | farba, vlastný názov pri prenájme („Prenajaté") |
| Stav nehnuteľnosti | `_property_stav` | **názov** | novostavba, rekonštrukcia… |
| Vlastníctvo | `_property_vlastnictvo` | **názov** | |

**Typ a stav ponuky** (`pp_catalog_*`, uložené v `pp_catalog_types|statuses`)
majú stabilný kľúč a meniteľný názov – premenovanie nič nerozbije. **Kľúče
`predaj`, `prenajom`, `pozemok`, `rezervovane`, `predane` nemeniť ani nemazať**,
viaže sa na ne filtrovanie a štýly. Farby štítkov idú cez `pp_badge_presets()`.

Pri **Stave nehnuteľnosti a Vlastníctve** sa do meta ukladá **názov, nie kľúč**
(historicky). Z toho plynú tri veci, ktoré sú už vyriešené:

1. Premenovanie položky prepíše aj uložené ponuky (`pp_option_rename`).
2. Skrytá položka sa novým ponukám neponúka, ale ponuka, ktorá ju už má, si ju
   nechá (`pp_option_choices` dopĺňa aktuálnu hodnotu).
3. Hodnoty, ktoré v zozname už nie sú, sa vypíšu s počtom ponúk a dajú sa
   hromadne nahradiť.

> Pôvodne tu stálo, že typ a stav ponuky sa nemajú robiť spravovateľnými.
> Na webe sa to vyriešilo správne – kľúč ostáva, mení sa len názov – takže
> to obmedzenie už neplatí.

---

## 8. Dizajnový systém

### Farby (`main.css`, `:root`)
```
--bg #FBF7EE   --section #F5EEDF   --white #FFFFFF   --border #E0D8CE
--text #2C2825 --muted #746A62     --dark #1C1A18    --dark2 #252220
--accent #B8A47A   --accent-dk #9A8660   --accent-txt #7C5E33   --accent-lt #E8DFD0
```

**Pravidlo, ktoré sa už raz porušilo:** `--accent` a `--accent-dk` sú **plochy
a ikony, nie text**. Na text patrí `--accent-txt`. Zlatá `#9A8660` má na
béžovom pozadí 3,05:1, čo je pod normou. `--muted` je stmavená na `#746A62`
práve preto, aby prešla 4,5:1 aj na najsvetlejšej sekcii.

### Písma — číslice
Playfair Display má predvolene **staroštýlové číslice** („1" po výšku malého
„x", „3" a „4" pod účiaru). Font vie lining figures cez OpenType `lnum`, ale
náš subset ju mal odstrihnutú.

Riešenie: dva drobné súbory `PlayfairDisplay-num.woff2` (2,6 kB) a kurzívny
(2,9 kB) obsahujú **len číslice 0–9**, cmap je premapovaný priamo na `.lf`
glyfy. Zapájajú sa cez `unicode-range: U+0030-0039` a **musia byť deklarované
až za hlavným `@font-face`** — pri zhode rozhoduje posledná deklarácia.

> Predtým to riešil TreeWalker v JS, ktorý po načítaní obaľoval každé číslo do
> `<span>` s bezpätkovým písmom. Je odstránený. **Nevracať.**

### Hero na úvodnej stránke
Fotka je na PC **bez akéhokoľvek prekryvu** — čitateľnosť drží vrstvený tieň
písma (tesná aura + dve mäkšie vrstvy). Na mobile prekryv zostáva, text tam
sedí dole cez fotku.

`text-shadow` je dedičná — preto je vynulovaná pre tlačidlá a vizitku, inak
robila tmavý nápis na zlatom tlačidle špinavým.

---

## 9. Build a vydávanie

**Nie je tu bundler.** Postup je ručný a má svoje overenia:

1. **CSS** — `main.css` → `main.min.css` konzervatívnym skriptom, ktorý
   odstraňuje **len komentáre a biele znaky**. Na konci porovná oba súbory
   znak po znaku po normalizácii; keď sa líšia, spadne. Agresívnejšia
   minifikácia už raz rozbila web.
2. **JS** — `main.js` → `main.min.js` cez `terser` (29,1 → 18,0 kB).
   Po každej minifikácii treba overiť, že sa nestratil žiadny **globálny názov
   volaný z PHP** (`zcToggleFav`, `zcMarkFavs`, `zcRevealImages`,
   `zcUpdateFavBadge` a ďalšie z `onclick`).
3. Oba zmenšené súbory sa dajú vypnúť jedným prepínačom `zc_min_css`
   v *Nástrojoch* — vtedy sa načítajú pôvodné.
4. Zvýšiť verziu v `style.css` **aj** v `functions.php` (je tam v `wp_enqueue_*`
   ako cache-buster), resp. v hlavičke pluginu aj v jeho `define(...)`.
5. Zabaliť: `zip -qr dist/<balík>.zip <balík>`.
6. **Konce riadkov:** v repozitári LF. Súbory z webu prichádzajú s CRLF –
   pri preberaní ich previesť, inak `git diff` ukáže zmenu v každom riadku.
7. **Obnoviť `zc-installer/bundles/`** — inštalátor v sebe nesie kópie
   ostatných zipov a `glob`-uje ich. Keď sa neobnovia, inštalátor vráti web
   o verzie späť. (Toto bol reálny nedorobok, opravený.)

**Git:** vetva `claude/wordpress-realtor-photos-hero-22vh7p`, commit správy
po slovensky, popisujú **príčinu**, nielen zmenu.

---

## 10. Výkon a prístupnosť

**Hero fotka sa prednačítava** (`rel=preload as=image fetchpriority=high`).
Je to CSS pozadie zapísané až v tele stránky, takže o ňom prehliadač inak
zistí neskoro — pritom je to LCP prvok.

> **Pozor pri úpravách:** šablóna používa na rôznych šírkach inú veľkosť
> fotky (mobil portrét, retina dvojnásobný, PC širokú, nad 1441 px väčšiu).
> Podmienky `media` v preloade **musia presne kopírovať šablónu**, inak
> prehliadač stiahne dve fotky namiesto jednej a je to horšie ako bez
> preloadu. Je na to test cez osem kombinácií šírky a hustoty displeja.

Prednačítavajú sa aj `PlayfairDisplay.woff2` a `DMSans.woff2` — obe sú nad
ohybom na každej stránke.

**Kontrasty** sú premerané podľa WCAG, vrátane merania skutočných pixelov pod
textom v hero. Bodky pod carouselmi majú `aria-label`.

---

## 11. Vedomé rozhodnutia — nepovažovať za chyby

| Vec | Prečo |
|---|---|
| **Animácie bežia aj pri systémovom „obmedziť pohyb"** | Pohyby sú drobné (2 px ikony, 7 px šípka). Plošné vypnutie cez `*` navyše zabilo aj hover prechody a web pôsobil mŕtvo. Klient to výslovne chcel. Vypína sa len plynulé rolovanie stránky. |
| **Hero bez prekryvu na PC** | Klient nechce závoj cez fotku. Vie, že tieň nenahrádza meraný kontrast a že kontrola prístupnosti môže hero hlásiť. |
| **Newsletter bez potvrdzovacieho kroku** | Rozhodnutie klienta, riziko popísané v §6. |
| **Ikony sietí v navbare sú zlaté, nie farebné** | Skúšali sa firemné farby sietí, maklérke sa nepáčili. Triedy `zc-soc-{sieť}` v značke ostali, návrat je jeden blok CSS. |
| **Do meta sa ukladá názov, nie kľúč** | Historické, viaže sa na to filtrovanie. Rieši to §7. |
| **Recenzie z Google sú ručné, žiadne Places API** | Places API je dnes len s aktívnym billingom a vracalo najviac 5 recenzií. Recenzia so zdrojom `google` dostane na webe značku „Recenzia z Google". Posledné stiahnuté recenzie sa pri aktualizácii jednorazovo preniesli do databázy a API kľúč sa zmazal. **Napojenie nevracať** bez súhlasu klienta. |
| **Sekcia „Čo ma riadi pri práci" je vypnutá** | Po hero idú rovno nehnuteľnosti. Zapína sa v *Prispôsobiť → Úvodná stránka – sekcie*. |
| **Spracovanie formulárov v administrácii beží na `admin_init`** | Aby sa dalo presmerovať (PRG). Vo vykresľovacej funkcii sú hlavičky už odoslané — preto tam kedysi nefungoval ani CSV export, ani hláška po pridaní kontaktu. |

---

## 12. Otvorené / na klientovi

- **Fotka do sekcie O mne** — pole je pripravené v *Prispôsobiť → Fotky
  maklérky → Fotka do sekcie O mne*, klient ju má nahrať. Je nezávislá od hero.
- **WebP** — v *Nástrojoch* je prepínač „Servírovať WebP" a hromadný prevod.
  Fotky nehnuteľností sú najväčšia položka stránky; odporúčané zapnúť.
- **PageSpeed** — konkrétne prepadnuté audity zatiaľ nie sú k dispozícii
  (report sa nepodarilo načítať). Zvyšné body budú pravdepodobne mimo témy:
  čas odpovede servera, kompresia, cache hlavičky, Site Kit a Analytics.
- **Veľký text v hero** — klient začal pripomienku („čo sa týka toho veľkého
  textu"), ktorá nebola dokončená. Treba sa doptať.
- **Mobilný prekryv v hero** — ostal zapnutý; klient nepovedal, či ho chce
  odstrániť aj tam.

---

## 13. Ako pracovať na tomto projekte

1. **Merať, nie hádať.** Pri kontraste, výkone aj rozložení sa oplatí napísať
   si malý skript alebo vyrenderovať stránku v prehliadači. Viackrát sa tým
   odhalilo, že „oprava" bola v skutočnosti zhoršenie.
2. **Overiť dopad na existujúce dáta.** Zmena číselníka, kategórie alebo
   textu sa takmer vždy dotýka aj toho, čo je už uložené.
3. **Nepresúvať prácu na klienta.** Keď zmena vyžaduje migráciu, spraviť ju
   alebo aspoň ponúknuť tlačidlom v administrácii.
4. **Písať po slovensky** — vrátane komentárov, hlášok a commit správ.
5. **Povedať naplno, čo sa nepodarilo.** Klient radšej počuje „toto som
   nevedel overiť" než tiché mlčanie.
