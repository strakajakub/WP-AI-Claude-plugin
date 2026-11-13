/* WP AI Editor – admin chat and settings page. */
(function () {
	'use strict';

	var config = window.wpaieConfig || {};
	var i18n = config.i18n || {};

	function t(key, number) {
		var text = i18n[key] || key;
		return number === undefined ? text : text.replace('%d', String(number));
	}

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
			return fail(t('networkError'));
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
			return fail(t('expired'));
		}
		if (trimmed === '0') {
			return fail(t('loggedOut'));
		}
		if (res.status === 502 || res.status === 503 || res.status === 504 || res.status === 524) {
			return fail(t('gatewayTimeout', res.status));
		}
		if (res.status >= 500) {
			return fail(t('serverError', res.status));
		}
		return fail(t('unexpected', res.status));
	}

	function fail(message) {
		return { success: false, data: { message: message, code: 'client' } };
	}

	function errorMessage(data) {
		if (data && typeof data === 'object' && data.message) {
			return data.message;
		}
		return typeof data === 'string' && data ? data : t('unknownError');
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
				if (busy || !window.confirm(t('confirmReset'))) {
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
			out.textContent = t('testing');
			var res = await ajax('wpaie_test_connection');
			out.className = res.success ? 'is-ok' : 'is-error';
			out.textContent = res.success ? '✔ ' + res.data.message : '✖ ' + errorMessage(res.data);
			btn.disabled = false;
		});
	}

	initChat();
	initSettings();
})();
