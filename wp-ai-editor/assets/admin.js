/* WP AI Editor – admin chat, history and settings pages. */
(function () {
	'use strict';

	var config = window.wpaieConfig || {};
	var i18n = config.i18n || {};

	function t(key, value) {
		var text = i18n[key] || key;
		return value === undefined ? text : text.replace(/%[ds]/, String(value));
	}

	function escHtml(s) {
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
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

	/**
	 * POST to admin-ajax and always resolve to { success, data } – also for network failures,
	 * HTML error pages, gateway timeouts and WordPress' bare "0" / "-1" responses.
	 *
	 * Chat steps may answer with a text/event-stream: "text" and "status" events are passed to
	 * handlers.onText / handlers.onStatus, and the final "result" / "error" event is returned.
	 */
	async function call(action, params, handlers) {
		handlers = handlers || {};
		var fields = Object.assign({ action: action, nonce: config.nonce, conversation: config.conversation || '' }, params || {});
		var body;
		if (fields.files && fields.files.length) {
			body = new FormData();
			Object.keys(fields).forEach(function (key) {
				if (key === 'files') {
					fields.files.forEach(function (file) { body.append('files[]', file); });
				} else {
					body.append(key, fields[key]);
				}
			});
		} else {
			delete fields.files;
			body = new URLSearchParams(fields);
		}

		var res;
		try {
			res = await fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body });
		} catch (err) {
			return fail(t('networkError'));
		}

		var type = res.headers.get('Content-Type') || '';
		if (type.indexOf('text/event-stream') !== -1 && res.body) {
			return readStream(res, handlers);
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
		if ([502, 503, 504, 524].indexOf(res.status) !== -1) {
			return fail(t('gatewayTimeout', res.status));
		}
		if (res.status >= 500) {
			return fail(t('serverError', res.status));
		}
		return fail(t('unexpected', res.status));
	}

	async function readStream(res, handlers) {
		var reader = res.body.getReader();
		var decoder = new TextDecoder();
		var buffer = '';
		var final = null;

		function handleEvent(raw) {
			var event = 'message';
			var data = '';
			raw.split('\n').forEach(function (line) {
				if (line.indexOf('event:') === 0) {
					event = line.slice(6).trim();
				} else if (line.indexOf('data:') === 0) {
					data += line.slice(5).trim();
				}
			});
			if (!data) {
				return;
			}
			var payload;
			try {
				payload = JSON.parse(data);
			} catch (e) {
				return;
			}
			if (event === 'text' && handlers.onText) {
				handlers.onText(payload.delta || '');
			} else if (event === 'status' && handlers.onStatus) {
				handlers.onStatus(payload.label || '');
			} else if (event === 'result') {
				final = { success: true, data: payload };
			} else if (event === 'error') {
				final = { success: false, data: payload };
			}
		}

		try {
			for (;;) {
				var chunk = await reader.read();
				if (chunk.done) {
					break;
				}
				buffer += decoder.decode(chunk.value, { stream: true }).replace(/\r\n/g, '\n');
				var index;
				while ((index = buffer.indexOf('\n\n')) !== -1) {
					handleEvent(buffer.slice(0, index));
					buffer = buffer.slice(index + 2);
				}
			}
		} catch (e) { /* connection dropped – handled below */ }

		if (buffer.trim()) {
			handleEvent(buffer);
		}
		return final || fail(t('interrupted'));
	}

	// ─── Undo buttons (chat + history) ───────────────────────────────────────

	document.addEventListener('click', async function (e) {
		var btn = e.target.closest && e.target.closest('.wpaie-undo');
		if (!btn || btn.disabled) {
			return;
		}
		if (!window.confirm(t('confirmUndo'))) {
			return;
		}
		btn.disabled = true;
		var res = await call('wpaie_undo', { change: btn.getAttribute('data-change') });
		if (res.success) {
			var done = document.createElement('em');
			done.textContent = t('undone');
			btn.replaceWith(done);
		} else {
			btn.disabled = false;
			window.alert(errorMessage(res.data));
		}
	});

	// ─── Chat page ───────────────────────────────────────────────────────────

	function initChat() {
		var messagesEl = document.getElementById('wpaie-messages');
		var form = document.getElementById('wpaie-input-row');
		var inputEl = document.getElementById('wpaie-input');
		var sendBtn = document.getElementById('wpaie-send');
		if (!messagesEl || !form || !inputEl || !sendBtn) {
			return;
		}
		var busy = false;
		var hooks = window.wpaieChatHooks = window.wpaieChatHooks || {};

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

		function changesHtml(changes) {
			return (changes || []).map(function (c) {
				return '<div class="wpaie-change"><span>' + escHtml(c.label) + '</span> <button type="button" class="button-link wpaie-undo" data-change="' +
					Number(c.id) + '">' + escHtml(t('undo')) + '</button></div>';
			}).join('');
		}

		function renderStep(data, liveRow) {
			var html = '';
			(data.tool_calls || []).forEach(function (label) {
				html += '<div class="wpaie-tool-call">' + escHtml(label) + '</div>';
			});
			html += changesHtml(data.changes);
			html += data.reply_html || '';
			if (liveRow) {
				if (html) {
					liveRow.querySelector('.wpaie-bubble').innerHTML = html;
					liveRow.querySelector('.wpaie-bubble').classList.remove('wpaie-streaming');
				} else {
					liveRow.remove();
				}
			} else if (html) {
				addBubble('ai', html);
			}
		}

		/**
		 * Show the approval card and resolve with true (apply) or false (reject).
		 */
		function askApproval(bubble) {
			return new Promise(function (resolve) {
				var actions = bubble.querySelector('.wpaie-approval-actions');
				function decide(approved) {
					actions.innerHTML = '<em>' + escHtml(approved ? t('approved') : t('rejected')) + '</em>';
					resolve(approved);
				}
				bubble.querySelector('.wpaie-approve').addEventListener('click', function () { decide(true); });
				bubble.querySelector('.wpaie-reject').addEventListener('click', function () { decide(false); });
				scrollBottom();
			});
		}

		function approvalCard(approvals) {
			var html = '<p class="wpaie-approval-title">' + escHtml(t('approvalTitle')) + '</p>';
			(approvals || []).forEach(function (card) {
				html += '<div class="wpaie-approval-item"><div class="wpaie-tool-call">' + escHtml(card.label) + '</div>' +
					(card.live ? '<span class="wpaie-live-badge">' + escHtml(t('liveBadge')) + '</span>' : '') +
					(card.preview || '') + '</div>';
			});
			html += '<div class="wpaie-approval-actions"><button type="button" class="button button-primary wpaie-approve">' + escHtml(t('approve')) +
				'</button> <button type="button" class="button wpaie-reject">' + escHtml(t('reject')) + '</button></div>';
			return addBubble('ai', html, 'wpaie-approval').querySelector('.wpaie-bubble');
		}

		function setBusy(state) {
			busy = state;
			inputEl.disabled = state;
			sendBtn.disabled = state;
			if (!state) {
				inputEl.focus();
			}
		}

		/**
		 * Streaming handlers: show text as it arrives in a live bubble, tool labels as chips.
		 */
		function liveHandlers(ctx) {
			return {
				onText: function (delta) {
					if (ctx.typing) {
						ctx.typing.remove();
						ctx.typing = null;
					}
					if (!ctx.live) {
						ctx.live = addBubble('ai', '', 'wpaie-streaming');
						ctx.liveText = '';
					}
					ctx.liveText += delta;
					ctx.live.querySelector('.wpaie-bubble').textContent = ctx.liveText;
					scrollBottom();
				},
				onStatus: function (label) {
					if (ctx.typing) {
						ctx.typing.querySelector('.wpaie-bubble').innerHTML = '<div class="wpaie-tool-call">' + escHtml(label) + '</div>';
					} else {
						ctx.typing = addBubble('ai', '<div class="wpaie-tool-call">' + escHtml(label) + '</div>', 'wpaie-typing-status');
					}
				}
			};
		}

		/**
		 * Run a turn: the first request, then "continue" / approval rounds until done.
		 */
		async function runTurn(action, params, approvalBubble) {
			setBusy(true);
			var ctx = { typing: null, live: null };
			var turnCost = 0;
			var res;

			if (approvalBubble) {
				var approved = await askApproval(approvalBubble);
				ctx.typing = addTyping();
				res = await call('wpaie_decide', { approve: approved ? '1' : '0', stream: config.stream ? '1' : '0' }, liveHandlers(ctx));
			} else {
				ctx.typing = addTyping();
				res = await call(action, Object.assign({ stream: config.stream ? '1' : '0' }, params), liveHandlers(ctx));
			}

			for (;;) {
				if (ctx.typing) {
					ctx.typing.remove();
					ctx.typing = null;
				}
				if (!res.success) {
					if (ctx.live) {
						ctx.live.remove();
					}
					addBubble('ai', '⚠ ' + escHtml(errorMessage(res.data)), 'wpaie-bubble--error');
					break;
				}

				var d = res.data;
				if (d.conversation) {
					config.conversation = d.conversation;
				}
				if (hooks.onStep) {
					hooks.onStep(d);
				}
				var costEl = document.getElementById('wpaie-cost');
				if (costEl && d.conversation_cost > 0) {
					costEl.textContent = '$' + Number(d.conversation_cost).toFixed(4);
				}
				renderStep(d, ctx.live);
				ctx.live = null;
				turnCost += Number(d.cost || 0);

				if (d.done) {
					if (turnCost > 0) {
						addBubble('ai', escHtml(t('turnCost', turnCost.toFixed(4))), 'wpaie-cost');
					}
					break;
				}

				var next;
				if (d.awaiting) {
					var ok = await askApproval(approvalCard(d.approvals));
					next = ['wpaie_decide', { approve: ok ? '1' : '0' }];
				} else {
					next = ['wpaie_continue', {}];
				}
				ctx.typing = addTyping();
				res = await call(next[0], Object.assign({ stream: config.stream ? '1' : '0' }, next[1]), liveHandlers(ctx));
			}
			setBusy(false);
		}

		async function send() {
			var text = inputEl.value.trim();
			if (!text || busy) {
				return;
			}
			setBusy(true);
			var extra = hooks.beforeSend ? await hooks.beforeSend() : { params: {}, html: '' };
			if (extra === false) {
				setBusy(false);
				return;
			}
			inputEl.value = '';
			inputEl.style.height = '';
			addBubble('user', (extra.html || '') + escHtml(text));
			await runTurn('wpaie_chat', Object.assign({ message: text }, extra.params || {}));
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

		var newBtn = document.getElementById('wpaie-new');
		if (newBtn) {
			newBtn.addEventListener('click', async function () {
				if (busy || !window.confirm(t('confirmNew'))) {
					return;
				}
				var res = await call('wpaie_new_conversation');
				if (res.success) {
					window.location.reload();
				} else {
					addBubble('ai', '⚠ ' + escHtml(errorMessage(res.data)), 'wpaie-bubble--error');
				}
			});
		}

		// A change still waiting for approval from an earlier visit.
		var restored = messagesEl.querySelector('.wpaie-approval[data-restored]');
		if (restored) {
			setBusy(true);
			runTurn(null, null, restored);
		}

		hooks.addBubble = addBubble;
		hooks.isBusy = function () { return busy; };
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
			var res = await call('wpaie_test_connection');
			out.className = res.success ? 'is-ok' : 'is-error';
			out.textContent = res.success ? '✔ ' + res.data.message : '✖ ' + errorMessage(res.data);
			btn.disabled = false;
		});
	}

	// ─── Conversations, images, context ──────────────────────────────────────

	function initExtras() {
		var hooks = window.wpaieChatHooks;
		if (!hooks || !document.getElementById('wpaie-messages')) {
			return;
		}

		// Conversation switcher and delete.
		var select = document.getElementById('wpaie-conversations');
		if (select) {
			select.addEventListener('change', async function () {
				if (hooks.isBusy()) {
					select.value = config.conversation || '';
					return;
				}
				var res = select.value
					? await call('wpaie_switch_conversation', { conversation: select.value })
					: await call('wpaie_new_conversation');
				if (res.success) {
					window.location.reload();
				} else {
					hooks.addBubble('ai', '⚠ ' + escHtml(errorMessage(res.data)), 'wpaie-bubble--error');
				}
			});
		}
		var del = document.getElementById('wpaie-delete');
		if (del) {
			del.addEventListener('click', async function () {
				if (hooks.isBusy() || !window.confirm(t('confirmDelete'))) {
					return;
				}
				var res = await call('wpaie_delete_conversation');
				if (res.success) {
					window.location.reload();
				} else {
					hooks.addBubble('ai', '⚠ ' + escHtml(errorMessage(res.data)), 'wpaie-bubble--error');
				}
			});
		}

		// Image attachments: button, paste, drag & drop.
		var pending = [];
		var strip = document.getElementById('wpaie-attachments');
		var fileInput = document.getElementById('wpaie-file');
		var attachBtn = document.getElementById('wpaie-attach');
		var maxImages = config.maxImages || 5;

		function renderStrip() {
			if (!strip) {
				return;
			}
			strip.hidden = pending.length === 0;
			strip.innerHTML = '';
			pending.forEach(function (item, index) {
				var wrap = document.createElement('span');
				wrap.className = 'wpaie-attachment';
				var img = document.createElement('img');
				img.src = item.preview;
				img.alt = '';
				var rm = document.createElement('button');
				rm.type = 'button';
				rm.textContent = '×';
				rm.title = t('remove');
				rm.addEventListener('click', function () {
					URL.revokeObjectURL(item.preview);
					pending.splice(index, 1);
					renderStrip();
				});
				wrap.appendChild(img);
				wrap.appendChild(rm);
				strip.appendChild(wrap);
			});
		}

		function addFiles(files) {
			Array.prototype.forEach.call(files, function (file) {
				if (!/^image\/(jpeg|png|gif|webp)$/.test(file.type)) {
					return;
				}
				if (pending.length >= maxImages) {
					window.alert(t('tooManyImgs'));
					return;
				}
				pending.push({ file: file, preview: URL.createObjectURL(file) });
			});
			renderStrip();
		}

		if (attachBtn && fileInput) {
			attachBtn.addEventListener('click', function () { fileInput.click(); });
			fileInput.addEventListener('change', function () {
				addFiles(fileInput.files);
				fileInput.value = '';
			});
			var input = document.getElementById('wpaie-input');
			input.addEventListener('paste', function (e) {
				var files = e.clipboardData && e.clipboardData.files;
				if (files && files.length) {
					e.preventDefault();
					addFiles(files);
				}
			});
			var chat = document.getElementById('wpaie-chat');
			chat.addEventListener('dragover', function (e) { e.preventDefault(); });
			chat.addEventListener('drop', function (e) {
				if (e.dataTransfer && e.dataTransfer.files.length) {
					e.preventDefault();
					addFiles(e.dataTransfer.files);
				}
			});
		}

		var contextSent = false;

		// Before a message is sent: upload the images, add the page context (first message only).
		hooks.beforeSend = async function () {
			var params = {};
			var html = '';
			if (!contextSent && config.context) {
				if (config.context.post) {
					params.context_post = String(config.context.post);
				}
				if (config.context.url) {
					params.context_url = config.context.url;
				}
			}
			if (pending.length) {
				var ids = [];
				var note = hooks.addBubble('ai', escHtml(t('uploading')), 'wpaie-typing-status');
				for (var i = 0; i < pending.length; i++) {
					var res = await call('wpaie_upload_image', { files: [pending[i].file] });
					if (!res.success) {
						note.remove();
						hooks.addBubble('ai', '⚠ ' + escHtml(errorMessage(res.data)), 'wpaie-bubble--error');
						return false;
					}
					ids.push(res.data.id);
					html += '<img class="wpaie-msg-image" src="' + escHtml(res.data.thumb || pending[i].preview) + '" alt="" />';
				}
				note.remove();
				params.images = ids.join(',');
				pending.forEach(function (item) { URL.revokeObjectURL(item.preview); });
				pending = [];
				renderStrip();
			}
			contextSent = true;
			return { params: params, html: html };
		};
	}

	window.wpaieCall = call;
	initChat();
	initExtras();
	initSettings();
})();
