# Changelog

## 2.0.0

### API a modely
- Claude API `anthropic-version: 2026-10-21`.
- Modely Claude Opus 5.5 (výchozí), Sonnet 5.5 a Haiku 5.5. Uložené vyřazené modely (např. `claude-opus-4-8`) se automaticky převedou na výchozí.
- Adaptivní přemýšlení a nové nastavení *Úroveň přemýšlení* (`output_config.effort`). `max_tokens` zvýšeno na 16 000 kvůli přemýšlení.
- Historie je kompatibilní s preserved thinking: jen se přidává na konec, system prompt se zafixuje na celou konverzaci a staré výsledky nástrojů maže API na své straně. Lokální zkracování probíhá jen mezi zprávami a nejdřív odstraní myšlenkové bloky.
- `drop_block` a jednorázová obnova při neplatném myšlenkovém bloku.
- Automatický fallback na jiný model při odmítnutí bezpečnostním filtrem (Opus, Sonnet) a srozumitelná hláška při odmítnutí.
- Prompt caching.
- Chyby nástrojů se modelu vracejí s `is_error: true`.

### Spolehlivost
- Agent běží krok za krokem (jeden AJAX požadavek = jedno volání modelu), takže nenarazí na časové limity hostingu. Průběh se zobrazuje živě.
- Automatické opakování při 429/5xx/529 a chybách spojení (respektuje `retry-after`).
- Při chybě uprostřed úkolu zůstanou provedené kroky uložené a „pokračuj“ naváže.
- Zámek proti souběžnému běhu ze dvou záložek.
- Odpověď zkrácená uprostřed volání nástroje se nikdy nespustí.

### Chybové hlášky
- České hlášky pro neplatný klíč, došlý kredit, rate limit, přetížení, timeout, DNS/SSL problémy, vypršelou relaci, timeout hostingu (502/504) a další. Obsahují HTTP kód a `request-id`.
- Tlačítko **Otestovat připojení** v Nastavení.
- Logování do `debug.log` při zapnutém `WP_DEBUG`.

### Bezpečnost a WordPress
- API klíč se už nevypisuje do HTML. Lze ho nastavit konstantou `WPAIE_API_KEY` ve wp-config.php. Ukládaný klíč se kontroluje (`sk-ant-`).
- Kontrola `edit_post` u úprav obsahu, nástroje jen pro `post`/`page`.
- Správné escapování zpětných lomítek (`wp_slash`) při ukládání obsahu.
- Globální styly se zapisují do uživatelských Globálních stylů aktivního tématu (dřív mohly jít do jiného tématu nebo přímo do souboru theme.json). Seznamy (např. paleta) se nahrazují celé.
- Šablony se hledají v aktivním tématu, podpora template parts (header, footer).
- Odinstalace smaže nastavení i historii.

### Kód
- Rozdělení do modulů v `includes/`, CSS a JS v `assets/` (správně načítané přes `wp_enqueue_*`).
- Odpovědi se formátují na serveru (jedna implementace mini-markdownu).
- Licence GPL-2.0-or-later (`LICENSE`, hlavička pluginu, součást ZIPu).
- Testy bez závislostí (`php tests/run.php`) a GitHub Actions (PHP 8.0–8.4, sestavení ZIPu, release).

## 1.3.0
- Původní verze (jeden soubor).
