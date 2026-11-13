# Changelog

## 3.0.2

Fixes from a review of the whole plugin:
- **Undo order:** a change can only be undone when no newer, not yet undone change touched the same thing (post, CSS, global styles, option, meta, menu item, product). Before, undoing an older change silently wiped newer ones and undoing those later brought the reverted state back.
- **Budget:** every model is priced, including the ones server-side fallbacks route declined requests to (Opus 5, Opus 4.8, Sonnet 5, Fable 5.1, Haiku 4.5). An unknown model is costed at the most expensive known price instead of $0, so it cannot slip past the monthly budget.
- **Menus:** renaming an item with the wrong menu ID is refused instead of moving the item to that menu.
- **Images:** files uploaded to the Files API for a message whose step fails are deleted again instead of being left behind.
- **Lock:** the per-user lock covers the worst case of one step (two model calls and retries), and a request saves the conversation only while it still holds the lock, so two requests can no longer overwrite each other's history.
- **Search & replace:** case-insensitive replacing understands diacritics ("Účet" / "účet"); the 100-post limit counts real matches, not database candidates.
- PHP upload errors (e.g. `upload_max_filesize`) are reported as such, not as a wrong image type.
- The admin bar sends the correct page URL on sites installed in a subdirectory.
- Undoing "Created …" works when the post was already trashed by hand.
- One shared retry policy for streaming and normal API requests.

## 3.0.1

Fixes from a code review:
- **Security:** design tools (global styles, Additional CSS, templates, menus, site identity) are administrator-only again when editors may use the chat. Roles such as WooCommerce's shop_manager have `edit_theme_options` and got them before. Writing Additional CSS also requires `edit_css`.
- **Usage/budget:** monthly usage is stored as atomic counters (one `wp_options` row per value, incremented in SQL) and read from the database, so concurrent users no longer overwrite each other's costs and the budget cannot be exceeded that way. The 3.0.0 usage option is migrated automatically.
- The system prompt describes only the tools of the conversation (an editor is not promised design changes or the admin-only History).
- The date format of the conversation list is translatable.
- Translation tools: `po2mo` skips fuzzy and incomplete entries and no longer crashes on an entry without `msgstr`; `make-pot` also reads `/** translators: */` comments; new `bin/update-po.php` merges the `.pot` into the `.po` files; the tests compare msgids (not just their count) and catch empty plural forms; CI also fails on uncommitted new translation files.
- Admin-only checks use `WPAIE_CAPABILITY` everywhere.

## 3.0.0

### Approvals and undo
- Changes visible on the site immediately wait for the user's approval in the chat, with a preview (content/template diff, CSS, JSON, old → new values). Configurable: live changes only (default), every change, or never.
- History of changes (new **AI Editor → History** page, database table `wpaie_changes`): every change is logged with undo data and can be undone from the chat or the history. Kept for 180 days.
- Tools are defined in a registry with a capability, approval classification and preview per tool; the tool set is fixed per conversation.
- Conversations are stored per conversation (format v3) with automatic migration of the 2.0 history.

### Chat
- Several conversations per user (last 30): switcher in the chat header, new and delete buttons.
- Image attachments (button, paste, drag & drop; up to 5 per message, 5 MB each): saved to the Media Library and sent through the Files API; files are deleted from the Files API together with the conversation.
- "AI Editor" sidebar in the block editor (the chat embedded with the edited post as context) and an admin bar link with the current page as context.

### New tools
- Categories and tags (list, set on a post), excerpts.
- Search across all content and bulk replace (up to 100 posts, one undoable change, always asks in the default approval mode).
- Media Library: list images (also without alt text), let the model look at an image, bulk alt texts, featured images.
- SEO titles and meta descriptions for Yoast SEO and Rank Math (only when active).
- WooCommerce products and variations: names, descriptions, prices, sale prices, stock, status (only when active).
- The system prompt lists only what is available on the site.

### Roles and translation
- Optional access for editors (Settings → Editors) with the tools their role allows; design tools, History and Settings stay admin-only; editors can undo their own changes.
- Complete Czech translation; dependency-free `bin/make-pot.php` and `bin/po2mo.php`; CI checks that translations are up to date.

### Costs and streaming
- Estimated cost per answer and per conversation, monthly usage table in Settings, and a monthly budget that stops the chat when it is used up.
- Streaming ("Live answers", on by default): the answer appears as it is written; the Messages API is streamed over cURL and forwarded to the browser as server-sent events. Falls back to normal requests without cURL.
- `WPAIE_API_BASE` can be overridden in wp-config.php (e.g. for a proxy).

## 2.0.0

### API and models
- Claude API `anthropic-version: 2026-10-21`.
- Models Claude Fable 5.2, Opus 5.5 (default), Sonnet 5.5 and Haiku 5.5. Fable 5.2 gets a 600 s timeout, 32,000 output tokens, refusal fallbacks and a clear error when the organization lacks 30-day data retention. Saved retired models (e.g. `claude-opus-4-8`) are moved to the default automatically.
- Adaptive thinking and a new *Thinking effort* setting (`output_config.effort`). `max_tokens` raised to 16,000 to leave room for thinking.
- History compatible with preserved thinking: append-only, a system prompt frozen for the whole conversation, and old tool results cleared on the API side. Local compaction happens only between messages and removes thinking blocks first.
- `drop_block` and a one-time recovery when a thinking block is invalid.
- Automatic fallback to another model when a safety filter declines (Fable, Opus, Sonnet), plus a clear message when a request is declined.
- Prompt caching.
- Tool errors are returned to the model with `is_error: true`.

### Reliability
- The agent runs one step per request (one AJAX request = one model call), so hosting time limits are not hit. Progress is shown live.
- Automatic retries on 429/5xx/529 and connection errors (respects `retry-after`).
- If an error happens mid-task, completed steps stay saved and "continue" picks up from there.
- A lock prevents the same history from running in two tabs at once.
- A response cut off in the middle of a tool call is never executed.

### Error messages
- Clear messages for an invalid key, no credit, rate limits, overload, timeouts, DNS/SSL problems, an expired page, hosting timeouts (502/504) and more. They include the HTTP status and `request-id`.
- **Test connection** button in Settings.
- Logging to `debug.log` when `WP_DEBUG` is on.

### Security and WordPress
- The API key is no longer printed into the HTML. It can be set with the `WPAIE_API_KEY` constant in wp-config.php. A saved key is validated (`sk-ant-`).
- `edit_post` capability check for content edits; content tools are limited to `post`/`page`.
- Backslashes are now escaped correctly (`wp_slash`) when saving content.
- Global styles are written to the active theme's user Global Styles (previously they could go to another theme or straight into the theme.json file). Lists such as the color palette are replaced as a whole.
- Templates are looked up in the active theme; template parts (header, footer) are supported.
- Uninstalling removes the settings and history.

### Language
- The whole UI, error messages, documentation and system prompt are in English. All UI strings are translatable (`wp-ai-editor` text domain). The assistant replies in the language the user writes in.

### Code
- Split into modules in `includes/`, with CSS and JS in `assets/` (loaded properly via `wp_enqueue_*`).
- Responses are formatted on the server (a single mini-markdown implementation).
- GPL-2.0-or-later license (`LICENSE`, plugin header, included in the ZIP).
- Dependency-free tests (`php tests/run.php`) and GitHub Actions (PHP 8.0–8.4, ZIP build, release).

## 1.3.0
- Original single-file version (Czech UI).
