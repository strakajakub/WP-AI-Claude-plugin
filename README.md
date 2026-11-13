# WP AI Editor — Claude assistant for WordPress

A WordPress plugin that adds an AI assistant chat (Claude by Anthropic) to the admin. Tell it what you want in plain language and it reads and edits the site for you: pages and posts, global styles, templates, menus, site identity and Additional CSS. It replies in the language you write in.

> "Change the primary button color to #e63946" · "Write a draft of an About us page" · "Rename the Blog item in the main menu to Articles"

## Features

| Area | Read | Edit | Takes effect |
|---|---|---|---|
| Pages and posts | list, search, content | update, create | **as a draft** (published only when you explicitly ask) |
| Global styles (theme.json) | theme styles and user customizations | merge into `settings` / `styles` | immediately (revertible in Styles → Revisions) |
| Block templates and template parts (FSE) | list, content | save a user copy | immediately (revertible in the Site Editor) |
| Navigation menus | menus and items | add and rename items | immediately |
| Site identity | title, tagline, logo, header image | change / remove | immediately |
| Additional CSS | current CSS | append / replace | immediately (revertible in Customizer revisions) |

### Approvals and undo

- **Ask before changes:** before a change that is visible on the site immediately (styles, CSS, templates, menus, site identity, publishing), the chat shows a card with a preview (a diff of the content or template, the CSS or JSON to be applied, a table of old and new values) and waits for **Apply** or **Reject**. In Settings you can switch this to every change, including drafts, or turn it off.
- **History of changes:** every change the assistant makes is logged with what is needed to restore the previous state. You can undo it right from the chat (the *Undo* link under the change) or from **AI Editor → History**. The log is kept for 180 days.

When a conversation starts, the assistant checks whether the site uses a block (FSE) or a classic theme and suggests solutions that fit. It cannot edit PHP files, install plugins or themes, or change Customizer settings other than site identity and CSS.

## Requirements

- WordPress 6.0+
- PHP 8.0+ (with the `curl`/`openssl` and `mbstring` extensions)
- An Anthropic API key ([Claude Console → API Keys](https://platform.claude.com/settings/keys)) with credit
- A user with the administrator role (`manage_options` capability)

## Installation

1. Download `wp-ai-editor.zip` from [Releases](https://github.com/strakajakub/WP-AI-Claude-plugin/releases), or build the ZIP from the [`wp-ai-editor/`](wp-ai-editor) folder (see [Development](#development)).
2. In the admin go to **Plugins → Add New → Upload Plugin**, choose the ZIP and activate it.
3. Open **AI Editor → Settings**, paste the API key, save, and click **Test connection**.

### API key in wp-config.php (recommended)

Safer than storing the key in the database:

```php
define( 'WPAIE_API_KEY', 'sk-ant-…' );
```

The constant takes precedence over a key saved in Settings.

## Settings

| Option | Default | Description |
|---|---|---|
| Model | Claude Opus 5.5 | Fable 5.2 (most capable; slowest and most expensive), Opus 5.5 (best balance), Sonnet 5.5 (faster, cheaper), Haiku 5.5 (fastest, cheapest) |
| Thinking effort | Medium | Low / Medium / High / Extra high. Higher effort is more careful but slower and more expensive. |
| Ask before changes | Live changes | Live changes only / every change including drafts / never. |

### Claude Fable 5.2

Fable 5.2 is Anthropic's most capable model, for the hardest tasks. Before choosing it, keep in mind:

- **Price:** $8 / $40 per million input / output tokens, about twice as much as Opus 5.5.
- **Speed:** one answer can take several minutes. The plugin waits up to 600 s for it (240 s for other models) and gives it more room for output. If your hosting cuts requests off sooner (nginx/proxy limits), you will see a 502/504 error. Raise the limit, or use Opus 5.5.
- **Thinking effort:** start with High.
- **Data retention:** your Anthropic organization must have 30-day data retention. Organizations with zero data retention get an error.

### Adding models

You can add other models with a filter:

```php
add_filter( 'wpaie_models', function ( $models ) {
	$models['claude-sonnet-5'] = 'Claude Sonnet 5';
	return $models;
} );
```

## How it works

- **Claude Messages API** (`anthropic-version: 2026-10-21`), called through the WordPress HTTP API (`wp_remote_request`). The official PHP SDK is not used on purpose: a plugin distributed as a ZIP would have to bundle Composer dependencies, which easily clash with other plugins.
- **Adaptive thinking** (always on for Opus 5.5). The *Thinking effort* setting (`output_config.effort`) controls how deep it goes.
- **One step per request:** each AJAX request runs one model call plus any tools, and then the browser asks for the next step. No single request has to survive the whole agent loop, so hosting time limits are not hit. Tool calls are shown live as they happen. A message gets at most 15 steps.
- **History** is stored per user (user meta `wpaie_history`) and is only ever appended to. The system prompt is frozen when the conversation starts. This matters because Opus 5.5 binds its thinking blocks to the exact earlier conversation: editing earlier messages invalidates them, and for new accounts the API then returns a 400 error. Old tool results are therefore cleared on the API side (`context_management`). The local history is shrunk only between messages, and only when it grows past 400 kB.
- **Optional beta features:** server-side clearing of old tool results, `drop_block` for invalid thinking blocks, and an automatic **fallback to another model** when a safety filter declines a request (Fable, Opus and Sonnet). If the API rejects them, the plugin switches to running without them for a day and carries on.
- **Prompt caching** (`cache_control`) makes resending the history between steps cheaper.

## Error messages and troubleshooting

Errors are shown right in the chat, with the HTTP status and the `request-id` so Anthropic can look them up.

| Message | What to do |
|---|---|
| Invalid API key | Check the key in Settings, or create a new one. |
| Your Anthropic account is out of credit | Top up in the Claude Console → Billing. |
| Rate limit exceeded / API overloaded | The plugin retries automatically (up to 2 times). If it persists, wait a moment. |
| The Anthropic API did not respond within 240 s | Split the task, lower the thinking effort, or choose a faster model. |
| The website server stopped waiting for the AI (HTTP 502/504) | The hosting time limit (proxy/nginx) is shorter than the model's response time. Raise `proxy_read_timeout` / `max_execution_time`, or lower the thinking effort. |
| This page has expired | Reload the page (F5). |
| A previous request is still running | The chat is open in another tab. Wait for it to finish. |
| The model declined this request (safety filter) | Rephrase the request or choose another model. |
| This model requires 30-day data retention | Your organization uses zero data retention, so Fable 5.2 is not available. Choose Opus 5.5. |

If an error happens in the middle of a task, the changes from earlier steps stay saved. Write "continue" and the assistant picks up where it left off.

Detailed log: enable `WP_DEBUG` and `WP_DEBUG_LOG` in `wp-config.php`. The plugin writes `[WP AI Editor] …` lines to `wp-content/debug.log`. The API key is never logged.

## Security

- The chat and all AJAX actions are available to administrators only and are protected by a nonce.
- Content edits are saved as **drafts**. Changes to styles, templates, menus, identity and CSS are live immediately, but can be reverted through WordPress revisions.
- The API key is never printed into the HTML (the form shows only a masked version).
- Messages and site content sent to the model are processed by Anthropic. See [Anthropic's policies](https://www.anthropic.com/legal).

## Translation

All user-facing strings use the `wp-ai-editor` text domain, so the admin UI can be translated (`.po`/`.mo` files in `wp-ai-editor/languages/`). The assistant itself replies in whatever language you write in.

## Uninstall

Deleting the plugin in the admin (Plugins → Delete) removes its settings, every user's conversations and the history of changes (`uninstall.php`).

## Development

```
wp-ai-editor/              # the plugin itself (contents of the ZIP)
├── wp-ai-editor.php       # plugin header, constants, module loading
├── uninstall.php
├── includes/
│   ├── helpers.php        # helpers, mini-markdown
│   ├── settings.php       # settings (key, model, effort)
│   ├── api.php            # Messages API client, retries, error mapping
│   ├── site-context.php   # theme detection, system prompt
│   ├── tools/             # tool registry and tools (content, design, …)
│   ├── changes.php        # change log and undo
│   ├── conversation.php   # history storage and compaction
│   ├── agent.php          # a single agent step
│   ├── ajax.php           # AJAX endpoints
│   └── admin.php          # admin screens
└── assets/                # admin.css, admin.js
tests/                     # dependency-free tests (WordPress stubs)
```

Tests and syntax check:

```bash
php tests/run.php
find wp-ai-editor -name '*.php' -exec php -l {} \;
```

Building the installable ZIP:

```bash
zip -r wp-ai-editor.zip wp-ai-editor          # Linux/macOS
```
```powershell
Compress-Archive -Path wp-ai-editor -DestinationPath wp-ai-editor.zip -Force   # Windows
```

GitHub Actions runs the tests on PHP 8.0–8.4 and attaches the ZIP to every run as an artifact. Pushing a `v*` tag (e.g. `v2.0.0`) creates a Release with the ZIP.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

Copyright © 2026 Jakub Straka. Licensed under [GPL-2.0-or-later](LICENSE), like WordPress itself. You may use, modify and redistribute the plugin freely; modified versions you distribute must stay under the same license.
