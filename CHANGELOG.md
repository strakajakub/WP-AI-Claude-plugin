# Changelog

## Unreleased

### Approvals and undo
- Changes visible on the site immediately wait for the user's approval in the chat, with a preview (content/template diff, CSS, JSON, old → new values). Configurable: live changes only (default), every change, or never.
- History of changes (new **AI Editor → History** page, database table `wpaie_changes`): every change is logged with undo data and can be undone from the chat or the history. Kept for 180 days.
- Tools are defined in a registry with a capability, approval classification and preview per tool; the tool set is fixed per conversation.
- Conversations are stored per conversation (format v3) with automatic migration of the 2.0 history.

### Chat
- Several conversations per user (last 30): switcher in the chat header, new and delete buttons.
- Image attachments (button, paste, drag & drop; up to 5 per message, 5 MB each): saved to the Media Library and sent through the Files API; files are deleted from the Files API together with the conversation.
- "AI Editor" sidebar in the block editor (the chat embedded with the edited post as context) and an admin bar link with the current page as context.

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
