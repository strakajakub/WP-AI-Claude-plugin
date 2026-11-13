/* WP AI Editor – admin chat and settings page. */
(function () {
	'use strict';

	var config = window.wpaieConfig || {};

	function escHtml(s) {
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	/**
	 * POST to admin-ajax and always resolve to { success, data } – also for network failures,
	 * HTML error pages, gateway timeouts and WordPress' bare "0" / "-1" responses.
	 */
	async function ajax(action, params) {
		var body = new URLSearchParams(Object.assign({ action: action, nonce: config.nonce }, params || {}));
		var res;
		try {
			res = await fetch(config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body
			});
		} catch (err) {
			return fail('Nepodařilo se spojit se serverem webu. Zkontroluj připojení k internetu a zkus to znovu.');
		}

		var text = await res.text();
		try {
			var json = JSON.parse(text);
			if (json && typeof json === 'object' && 'success' in json) {
				return json;
			}
		} catch (e) { /* not JSON – handled below */ }

		var trimmed = text.trim();
		if (trimmed === '-1') {
			return fail('Platnost stránky vypršela. Obnov ji prosím (F5) a zkus to znovu.');
		}
		if (trimmed === '0') {
			return fail('Nejsi přihlášen(a) nebo plugin není aktivní. Obnov stránku a přihlas se.');
		}
		if (res.status === 502 || res.status === 503 || res.status === 504 || res.status === 524) {
			return fail('Server webu nepočkal na odpověď AI (časový limit hostingu, HTTP ' + res.status + '). ' +
				'Zkus jednodušší požadavek nebo nižší „Úroveň přemýšlení“ v Nastavení. Případné provedené změny zůstaly uložené – napiš „pokračuj“.');
		}
		if (res.status >= 500) {
			return fail('Chyba na serveru webu (HTTP ' + res.status + '). Podrobnosti najdeš v PHP error logu (zapni WP_DEBUG_LOG).');
		}
		return fail('Server vrátil neočekávanou odpověď (HTTP ' + res.status + ').');
	}

	function fail(message) {
		return { success: false, data: { message: message, code: 'client' } };
	}

	function errorMessage(data) {
		if (data && typeof data === 'object' && data.message) {
			return data.message;
		}
		return typeof data === 'string' && data ? data : 'Neznámá chyba.';
	}

	// ─── Chat page ───────────────────────────────────────────────────────────

	function initChat() {
		var messagesEl = document.getElementById('wpaie-messages');
		var form = document.getElementById('wpaie-input-row');
		var inputEl = document.getElementById('wpaie-input');
		var sendBtn = document.getElementById('wpaie-send');
		var resetBtn = document.getElementById('wpaie-reset');
		if (!messagesEl || !form || !inputEl || !sendBtn) {
			return;
		}
		var busy = false;

		function scrollBottom() {
			messagesEl.scrollTop = messagesEl.scrollHeight;
		}

		function addBubble(role, html, extraClass) {
			var row = document.createElement('div');
			row.className = 'wpaie-msg wpaie-msg--' + (role === 'user' ? 'user' : 'ai');
			var bubble = document.createElement('div');
			bubble.className = 'wpaie-bubble' + (extraClass ? ' ' + extraClass : '');
			bubble.innerHTML = html;
			row.appendChild(bubble);
			messagesEl.appendChild(row);
			scrollBottom();
			return row;
		}

		function addTyping() {
			return addBubble('ai', '<span></span><span></span><span></span>', 'wpaie-typing');
		}

		function renderStep(data) {
			var html = '';
			(data.tool_calls || []).forEach(function (label) {
				html += '<div class="wpaie-tool-call">' + escHtml(label) + '</div>';
			});
			html += data.reply_html || '';
			if (html) {
				addBubble('ai', html);
			}
		}

		function setBusy(state) {
			busy = state;
			inputEl.disabled = state;
			sendBtn.disabled = state;
			if (!state) {
				inputEl.focus();
			}
		}

		async function send() {
			var text = inputEl.value.trim();
			if (!text || busy) {
				return;
			}
			inputEl.value = '';
			inputEl.style.height = '';
			setBusy(true);
			addBubble('user', escHtml(text));

			var typing = addTyping();
			var res = await ajax('wpaie_chat', { message: text });

			// One request per agent step; keep going while the server reports unfinished work.
			while (res.success) {
				typing.remove();
				renderStep(res.data);
				if (res.data.done) {
					break;
				}
				typing = addTyping();
				res = await ajax('wpaie_continue');
			}

			if (!res.success) {
				typing.remove();
				addBubble('ai', '⚠ ' + escHtml(errorMessage(res.data)), 'wpaie-bubble--error');
			}
			setBusy(false);
		}

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			send();
		});
		inputEl.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
				e.preventDefault();
				send();
			}
		});
		inputEl.addEventListener('input', function () {
			this.style.height = 'auto';
			this.style.height = Math.min(this.scrollHeight, 160) + 'px';
		});

		if (resetBtn) {
			resetBtn.addEventListener('click', async function () {
				if (busy || !window.confirm('Smazat celou historii konverzace a začít nový chat?')) {
					return;
				}
				var res = await ajax('wpaie_reset');
				if (!res.success) {
					addBubble('ai', '⚠ ' + escHtml(errorMessage(res.data)), 'wpaie-bubble--error');
					return;
				}
				window.location.reload();
			});
		}

		scrollBottom();
	}

	// ─── Settings page ───────────────────────────────────────────────────────

	function initSettings() {
		var btn = document.getElementById('wpaie-test');
		var out = document.getElementById('wpaie-test-result');
		if (!btn || !out) {
			return;
		}
		btn.addEventListener('click', async function () {
			btn.disabled = true;
			out.className = '';
			out.textContent = 'Testuji…';
			var res = await ajax('wpaie_test_connection');
			out.className = res.success ? 'is-ok' : 'is-error';
			out.textContent = res.success ? '✔ ' + res.data.message : '✖ ' + errorMessage(res.data);
			btn.disabled = false;
		});
	}

	initChat();
	initSettings();
})();
