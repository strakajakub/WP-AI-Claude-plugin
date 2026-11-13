# WP AI Editor — Claude asistent pro WordPress

WordPress plugin, který do administrace přidá chat s AI asistentem (Claude od Anthropic). Asistent umí na pokyn v češtině číst a upravovat obsah webu: stránky a příspěvky, globální styly, šablony, menu, identitu webu a Doplňkový CSS.

> „Změň barvu primárního tlačítka na #e63946“ · „Napiš koncept stránky O nás“ · „Přejmenuj v hlavním menu položku Blog na Články“

## Co umí

| Oblast | Čtení | Úpravy | Projeví se |
|---|---|---|---|
| Stránky a příspěvky | seznam, vyhledávání, obsah | úprava, vytvoření | **jako koncept** (publikace jen na výslovné přání) |
| Globální styly (theme.json) | styly tématu i uživatelské úpravy | sloučení do `settings` / `styles` | ihned (vratné v Revizích stylů) |
| Block templates a template parts (FSE) | seznam, obsah | uložení uživatelské kopie | ihned (vratné v editoru webu) |
| Navigační menu | menu a položky | přidání a přejmenování položky | ihned |
| Identita webu | název, podtitulek, logo, záhlaví | změna / odebrání | ihned |
| Doplňkový CSS | aktuální CSS | doplnění / přepsání | ihned (vratné v revizích Customizeru) |

Asistent při zahájení konverzace zjistí, jestli web používá block (FSE) nebo klasické téma, a podle toho navrhuje řešení. Nemůže upravovat PHP soubory, instalovat pluginy a témata ani měnit nastavení Customizeru mimo identitu webu a CSS.

## Požadavky

- WordPress 6.0+
- PHP 8.0+ (s rozšířeními `curl`/`openssl` a `mbstring`)
- API klíč Anthropic ([Claude Console → API Keys](https://platform.claude.com/settings/keys)) s kreditem
- Uživatel s rolí administrátora (oprávnění `manage_options`)

## Instalace

1. Stáhni `wp-ai-editor.zip` z [Releases](https://github.com/strakajakub/WP-AI-Claude-plugin/releases) (nebo si ZIP vytvoř ze složky [`wp-ai-editor/`](wp-ai-editor), viz [Vývoj](#vývoj)).
2. V administraci: **Pluginy → Instalace pluginů → Nahrát plugin**, vyber ZIP a aktivuj.
3. Otevři **AI Editor → Nastavení**, vlož API klíč, ulož a klikni na **Otestovat připojení**.

### API klíč ve wp-config.php (doporučeno)

Bezpečnější než ukládat klíč do databáze:

```php
define( 'WPAIE_API_KEY', 'sk-ant-…' );
```

Konstanta má přednost před klíčem uloženým v Nastavení.

## Nastavení

| Volba | Výchozí | Popis |
|---|---|---|
| Model | Claude Opus 5.5 | Opus 5.5 (nejschopnější), Sonnet 5.5 (rychlejší, levnější), Haiku 5.5 (nejrychlejší, nejlevnější) |
| Úroveň přemýšlení | Střední | Nízká / Střední / Vysoká / Velmi vysoká. Vyšší úroveň je pečlivější, ale pomalejší a dražší. |

Seznam modelů lze rozšířit filtrem:

```php
add_filter( 'wpaie_models', function ( $models ) {
	$models['claude-fable-5-2'] = 'Claude Fable 5.2';
	return $models;
} );
```

## Jak to funguje

- **Claude Messages API** (`anthropic-version: 2026-10-21`) voláno přes WordPress HTTP API (`wp_remote_request`). Oficiální PHP SDK se záměrně nepoužívá: plugin distribuovaný jako ZIP by musel přibalit Composer závislosti, které se snadno střetnou s jinými pluginy.
- **Adaptivní přemýšlení** (u Opus 5.5 vždy zapnuté), jeho hloubku řídí volba *Úroveň přemýšlení* (`output_config.effort`).
- **Krok za krokem:** každý AJAX požadavek provede jedno volání modelu a případné nástroje. Prohlížeč pak pokračuje dalším krokem. Žádný požadavek tak nemusí přežít celou smyčku agenta, na kterou by hosting uplatnil časový limit. Mezivýsledky (použité nástroje) se zobrazují průběžně. Maximum je 15 kroků na zprávu.
- **Historie** se ukládá pro každého uživatele zvlášť (user meta `wpaie_history`) a jen se přidává na konec. System prompt se zafixuje při zahájení konverzace. Je to nutné, protože Opus 5.5 váže své „myšlenkové“ bloky na přesnou podobu předchozí konverzace. Úprava starších zpráv by je zneplatnila a u nových účtů API vrací chybu 400. Staré výsledky nástrojů proto maže API na své straně (`context_management`). Lokální historie se zkracuje jen mezi zprávami a jen když přeroste 400 kB.
- **Volitelné beta funkce:** serverové mazání starých výsledků nástrojů, `drop_block` pro neplatné myšlenkové bloky a automatický **fallback na jiný model**, když bezpečnostní filtr žádost odmítne (u Opus a Sonnet). Pokud by je API odmítlo, plugin se na den přepne do režimu bez nich a pokračuje.
- **Prompt caching** (`cache_control`) zlevňuje opakované posílání historie mezi kroky.

## Chybové hlášky a řešení problémů

Chyby se zobrazují česky přímo v chatu, včetně HTTP kódu a `request-id` pro dohledání u Anthropic.

| Hláška | Co dělat |
|---|---|
| Neplatný API klíč | Zkontroluj klíč v Nastavení, případně vytvoř nový. |
| Na účtu Anthropic došel kredit | Dobij kredit v Claude Console → Billing. |
| Překročen limit požadavků / API je přetížené | Plugin to sám zkusí znovu (až 2×). Pokud chyba trvá, počkej chvíli. |
| Anthropic API neodpovědělo do 240 s | Rozděl úkol, sniž úroveň přemýšlení nebo zvol rychlejší model. |
| Server webu nepočkal na odpověď AI (HTTP 502/504) | Časový limit hostingu (proxy/nginx) je kratší než doba odpovědi modelu. Zvyš `proxy_read_timeout` / `max_execution_time`, nebo sniž úroveň přemýšlení. |
| Platnost stránky vypršela | Obnov stránku (F5). |
| Předchozí požadavek ještě běží | Chat je otevřený v jiné záložce. Počkej, až doběhne. |
| Model žádost odmítl (bezpečnostní filtr) | Přeformuluj zadání nebo zvol jiný model. |

Pokud chyba nastane uprostřed úkolu, změny z předchozích kroků zůstanou uložené. Napiš „pokračuj“ a asistent naváže.

Podrobný log: v `wp-config.php` zapni `WP_DEBUG` a `WP_DEBUG_LOG`. Plugin zapisuje do `wp-content/debug.log` řádky `[WP AI Editor] …` (nikdy ne API klíč).

## Bezpečnost

- Chat a všechny AJAX akce jsou dostupné jen administrátorům a chráněné nonce.
- Úpravy obsahu se ukládají jako **koncepty**. Změny stylů, šablon, menu, identity a CSS jsou živé ihned, ale dají se vrátit přes revize WordPressu.
- API klíč se nikdy nevypisuje do HTML (formulář ukazuje jen maskovanou podobu).
- Zprávy a obsah webu odesílané modelu zpracovává Anthropic. Viz [zásady Anthropic](https://www.anthropic.com/legal).

## Odinstalace

Smazáním pluginu v administraci (Pluginy → Smazat) se odstraní nastavení i historie konverzací všech uživatelů (`uninstall.php`).

## Vývoj

```
wp-ai-editor/              # samotný plugin (obsah ZIPu)
├── wp-ai-editor.php       # hlavička pluginu, konstanty, načtení modulů
├── uninstall.php
├── includes/
│   ├── helpers.php        # pomocné funkce, mini-markdown
│   ├── settings.php       # nastavení (klíč, model, effort)
│   ├── api.php            # klient Messages API, retry, překlad chyb
│   ├── site-context.php   # detekce tématu, system prompt
│   ├── tools.php          # definice a implementace nástrojů
│   ├── conversation.php   # ukládání a zkracování historie
│   ├── agent.php          # jeden krok agenta
│   ├── ajax.php           # AJAX endpointy
│   └── admin.php          # stránky v administraci
└── assets/                # admin.css, admin.js
tests/                     # testy bez závislostí (stuby WordPressu)
```

Testy a kontrola syntaxe:

```bash
php tests/run.php
find wp-ai-editor -name '*.php' -exec php -l {} \;
```

Sestavení ZIPu pro instalaci:

```bash
zip -r wp-ai-editor.zip wp-ai-editor          # Linux/macOS
```
```powershell
Compress-Archive -Path wp-ai-editor -DestinationPath wp-ai-editor.zip -Force   # Windows
```

GitHub Actions spouští testy na PHP 8.0–8.4, ke každému běhu přikládá ZIP jako artefakt a po pushnutí tagu `v*` (např. `v2.0.0`) vytvoří Release se ZIPem.

## Changelog

Viz [CHANGELOG.md](CHANGELOG.md).

## Licence

Copyright © 2026 Jakub Straka. Licence [GPL-2.0-or-later](LICENSE), stejně jako WordPress. Plugin můžeš volně používat, upravovat i šířit. Upravené verze, které šíříš dál, musí zůstat pod stejnou licencí.
