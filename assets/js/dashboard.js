/**
 * Elementor Application Vetting dashboard.
 * Vanilla JS, no dependencies. All text is inserted with textContent / DOM
 * nodes, never as HTML, except the email body the administrator is writing.
 */
(function () {
	'use strict';

	var cfg = window.EAV_CONFIG;
	var app = document.getElementById('eav-app');
	if (!cfg || !app) {
		return;
	}
	var t = cfg.i18n;

	var layer = document.getElementById('eav-layer');
	var toast = document.getElementById('eav-toast');
	var panel = layer.querySelector('.eav-panel');
	var content = document.getElementById('eav-panel-content');
	var grid = document.getElementById('eav-grid');
	var moreBtn = document.getElementById('eav-load-more');
	var subtitle = document.getElementById('eav-subtitle');

	// Move the overlay and toast to <body> so theme containers cannot clip them.
	document.body.appendChild(layer);
	document.body.appendChild(toast);

	var state = {
		status: 'pending',
		page: 1,
		counts: {},
		requestId: 0,
		detail: null,
		opener: null,
		view: 'closed',
		compose: null
	};

	/* ---------------------------------------------------------------- helpers */

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (key) {
			var value = attrs[key];
			if (value === null || value === undefined || value === false) {
				return;
			}
			if (key === 'class') {
				node.className = value;
			} else if (key === 'text') {
				node.textContent = value;
			} else {
				node.setAttribute(key, value === true ? '' : value);
			}
		});
		(children || []).forEach(function (child) {
			if (child === null || child === undefined) {
				return;
			}
			node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
		});
		return node;
	}

	function icon(className) {
		return className ? el('i', { 'class': className + ' eav-icon', 'aria-hidden': 'true' }) : null;
	}

	function button(className, label, onClick, iconClass) {
		var node = el('button', { type: 'button', 'class': className }, [
			icon(iconClass),
			el('span', { 'class': 'eav-btn__label', text: label })
		]);
		if (onClick) {
			node.addEventListener('click', onClick);
		}
		return node;
	}

	function closeButton(extraClass) {
		var node = button('eav-link-btn' + (extraClass ? ' ' + extraClass : ''), t.close, null, cfg.icons.close);
		node.setAttribute('data-eav-close', '');
		return node;
	}

	function escapeHtml(value) {
		return String(value).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function api(action, data) {
		var body = new FormData();
		body.append('action', 'eav_' + action);
		body.append('nonce', cfg.nonce);
		Object.keys(data || {}).forEach(function (key) {
			body.append(key, data[key]);
		});

		return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (res) {
				return res.json().catch(function () {
					return null;
				});
			})
			.then(function (json) {
				if (!json || !json.success) {
					var message = json && json.data && json.data.message ? json.data.message : t.genericError;
					throw new Error(message);
				}
				return json.data;
			}, function () {
				throw new Error(t.networkError);
			});
	}

	var toastTimer;
	function showToast(message, isError) {
		toast.textContent = message;
		toast.classList.toggle('is-error', !!isError);
		toast.classList.add('is-visible');
		clearTimeout(toastTimer);
		toastTimer = setTimeout(function () {
			toast.classList.remove('is-visible');
		}, 7000);
	}

	var URL_RE = /(https?:\/\/[^\s,]+[^\s,.;:!?)\]])/g;

	function valueNodes(value) {
		if (!value) {
			return [el('span', { 'class': 'eav-empty', text: t.notProvided })];
		}
		// split() with a capture group puts the matched URLs at odd indexes.
		return value.split(URL_RE).map(function (part, i) {
			if (i % 2 === 1) {
				return el('a', { href: part, target: '_blank', rel: 'noopener noreferrer', text: part });
			}
			return document.createTextNode(part);
		});
	}

	/* ------------------------------------------------------------------- list */

	function updateCounts() {
		app.querySelectorAll('[data-count]').forEach(function (node) {
			var count = state.counts[node.getAttribute('data-count')];
			node.textContent = typeof count === 'number' ? String(count) : '';
		});
		var pending = state.counts.pending;
		if (typeof pending === 'number') {
			subtitle.textContent = pending === 0 ? t.pendingNone : (pending === 1 ? t.pendingOne : t.pendingMany.replace('%d', pending));
		}
	}

	function emptyState() {
		var copy = t.empty[state.status] || t.empty.pending;
		return el('div', { 'class': 'eav-state' }, [
			el('strong', { text: copy.title }),
			el('span', { text: copy.text })
		]);
	}

	function renderCard(item) {
		var meta = el('span', { 'class': 'eav-card__meta' }, [
			el('span', null, [t.submitted + ' ', el('time', { datetime: item.date_iso, text: item.date })])
		]);
		if (item.status !== 'pending' && item.decided) {
			meta.appendChild(el('span', { text: item.status_label + ' ' + item.decided }));
		}

		var card = el('button', { type: 'button', 'class': 'eav-card eav-card--' + item.status }, [
			el('span', { 'class': 'eav-card__name', text: item.name }),
			el('span', { 'class': 'eav-card__email', text: item.email || t.noEmail }),
			meta
		]);
		card.addEventListener('click', function () {
			openApplication(item.id, card);
		});
		return card;
	}

	function loadList(reset) {
		var id = ++state.requestId;

		if (reset) {
			state.page = 1;
			grid.textContent = '';
			grid.appendChild(el('p', { 'class': 'eav-state', text: t.loading }));
		}
		moreBtn.hidden = true;

		return api('list', { status: state.status, page: state.page }).then(function (data) {
			if (id !== state.requestId) {
				return;
			}
			state.counts = data.counts;
			if (reset) {
				grid.textContent = '';
			}
			data.items.forEach(function (item) {
				grid.appendChild(renderCard(item));
			});
			if (reset && !data.items.length) {
				grid.appendChild(emptyState());
			}
			moreBtn.hidden = !data.has_more;
			updateCounts();
		}).catch(function (error) {
			if (id !== state.requestId) {
				return;
			}
			if (reset) {
				grid.textContent = '';
			}
			grid.appendChild(el('p', { 'class': 'eav-state eav-state--error', role: 'alert', text: error.message }));
		});
	}

	app.querySelectorAll('.eav-tab').forEach(function (tab) {
		tab.addEventListener('click', function () {
			var status = tab.getAttribute('data-status');
			if (status === state.status) {
				return;
			}
			state.status = status;
			app.querySelectorAll('.eav-tab').forEach(function (other) {
				other.setAttribute('aria-current', other === tab ? 'true' : 'false');
			});
			loadList(true);
		});
	});

	moreBtn.addEventListener('click', function () {
		state.page += 1;
		loadList(false);
	});

	/* ------------------------------------------------------------------ panel */

	function showLayer() {
		layer.classList.add('is-open');
		document.documentElement.classList.add('eav-lock');
	}

	function hideLayer() {
		layer.classList.remove('is-open');
		document.documentElement.classList.remove('eav-lock');
		state.view = 'closed';
		state.detail = null;
		state.compose = null;
		if (state.opener && document.body.contains(state.opener)) {
			state.opener.focus();
		}
		state.opener = null;
	}

	function composeIsDirty() {
		var c = state.compose;
		return !!c && (c.subjectEl.value !== c.subject || c.editorEl.innerHTML !== c.html);
	}

	function confirmDiscard() {
		return !composeIsDirty() || window.confirm(t.discardConfirm);
	}

	function requestClose() {
		if (state.view === 'compose' && !confirmDiscard()) {
			return;
		}
		hideLayer();
	}

	layer.addEventListener('click', function (event) {
		if (event.target.closest('[data-eav-close]')) {
			requestClose();
		}
	});

	document.addEventListener('keydown', function (event) {
		if (!layer.classList.contains('is-open')) {
			return;
		}
		if (event.key === 'Escape') {
			event.preventDefault();
			requestClose();
			return;
		}
		if (event.key === 'Tab') {
			var focusable = Array.prototype.filter.call(
				panel.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), [contenteditable="true"]'),
				function (node) {
					return node.offsetParent !== null;
				}
			);
			if (!focusable.length) {
				event.preventDefault();
				panel.focus();
				return;
			}
			var first = focusable[0];
			var last = focusable[focusable.length - 1];
			if (event.shiftKey && (document.activeElement === first || document.activeElement === panel)) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && document.activeElement === last) {
				event.preventDefault();
				first.focus();
			}
		}
	});

	function openApplication(id, opener) {
		state.opener = opener;
		state.view = 'loading';
		content.textContent = '';
		content.appendChild(el('div', { 'class': 'eav-panel__head' }, [
			el('div', { 'class': 'eav-panel__headrow' }, [el('span'), closeButton()]),
			el('p', { 'class': 'eav-panel__meta', text: t.loading })
		]));
		showLayer();
		panel.focus();

		api('get', { id: id }).then(function (data) {
			state.detail = data;
			renderDetail(data);
		}).catch(function (error) {
			content.textContent = '';
			content.appendChild(el('div', { 'class': 'eav-panel__head' }, [
				el('div', { 'class': 'eav-panel__headrow' }, [el('span'), closeButton()]),
				el('p', { 'class': 'eav-state eav-state--error', role: 'alert', text: error.message })
			]));
		});
	}

	function renderDetail(data) {
		state.view = 'detail';
		state.compose = null;
		content.textContent = '';

		var title = el('h3', { id: 'eav-panel-title', 'class': 'eav-panel__title', tabindex: '-1', text: data.name });

		var head = el('div', { 'class': 'eav-panel__head' }, [
			el('div', { 'class': 'eav-panel__headrow' }, [
				el('span', { 'class': 'eav-badge eav-badge--' + data.status, text: data.status_label }),
				closeButton()
			]),
			title,
			el('p', { 'class': 'eav-panel__meta' }, [
				data.email ? el('a', { href: 'mailto:' + data.email, text: data.email }) : el('span', { text: t.noEmail }),
				el('span', { text: t.submitted + ' ' + data.date_full })
			])
		]);

		var list = el('dl', { 'class': 'eav-fields' });
		data.fields.forEach(function (field) {
			list.appendChild(el('div', { 'class': 'eav-field' }, [
				el('dt', { text: field.label }),
				el('dd', null, valueNodes(field.value))
			]));
		});

		var foot;
		if (data.status === 'pending') {
			var hasEmail = !!data.email;
			var rejectBtn = button('eav-btn eav-btn--reject', t.reject, function () {
				openCompose('rejected');
			});
			var approveBtn = button('eav-btn eav-btn--approve', t.approve, function () {
				openCompose('approved');
			});
			rejectBtn.disabled = approveBtn.disabled = !hasEmail;
			foot = el('div', { 'class': 'eav-panel__foot' }, [
				hasEmail ? null : el('p', { 'class': 'eav-panel__note', text: t.noEmailNote }),
				rejectBtn,
				approveBtn
			]);
		} else {
			foot = el('div', { 'class': 'eav-panel__foot eav-panel__foot--info' }, [
				el('p', { text: data.decision ? data.decision.summary : data.status_label }),
				data.decision && data.decision.subject ? el('p', { text: t.subjectSent + ' ' + data.decision.subject }) : null
			]);
		}

		content.appendChild(head);
		content.appendChild(el('div', { 'class': 'eav-panel__scroll' }, [list]));
		content.appendChild(foot);
		title.focus();
	}

	/* --------------------------------------------------------------- composer */

	function openCompose(decision) {
		var data = state.detail;
		var approve = decision === 'approved';
		var tpl = approve ? data.compose.approve : data.compose.reject;

		state.view = 'compose';
		content.textContent = '';

		var savedRange = null;

		var bar = el('div', { 'class': 'eav-compose__bar eav-compose__bar--' + decision }, [
			el('h3', { id: 'eav-panel-title', 'class': 'eav-compose__title', text: approve ? t.composeApprove : t.composeReject }),
			el('span', { 'class': 'eav-compose__actions' }, [
				button('eav-link-btn eav-link-btn--light', t.back, function () {
					if (confirmDiscard()) {
						renderDetail(state.detail);
					}
				}),
				closeButton('eav-link-btn--light')
			])
		]);

		var toInput = el('input', { type: 'email', id: 'eav-to', 'class': 'eav-input', value: data.email, readonly: true, 'aria-readonly': 'true' });
		var subjectInput = el('input', { type: 'text', id: 'eav-subject', 'class': 'eav-input', value: tpl.subject, maxlength: '200', autocomplete: 'off' });

		var editor = el('div', {
			id: 'eav-editor',
			'class': 'eav-editor',
			contenteditable: 'true',
			role: 'textbox',
			'aria-multiline': 'true',
			'aria-label': t.messageLabel,
			spellcheck: 'true'
		});
		// Starter text is sanitised on the server and applicant values are escaped there.
		editor.innerHTML = tpl.body;

		editor.addEventListener('paste', function (event) {
			event.preventDefault();
			var clip = event.clipboardData || window.clipboardData;
			document.execCommand('insertText', false, clip.getData('text/plain'));
		});

		/* Link bar */
		var linkInput = el('input', { type: 'url', 'class': 'eav-input', placeholder: 'https://', 'aria-label': t.linkAddress });
		var linkBar = el('div', { 'class': 'eav-linkbar', hidden: true }, [
			linkInput,
			button('eav-btn eav-btn--small eav-btn--primary', t.linkApply, applyLink),
			button('eav-btn eav-btn--small eav-btn--quiet', t.linkRemove, removeLink)
		]);

		function saveSelection() {
			var sel = window.getSelection();
			if (sel.rangeCount && editor.contains(sel.anchorNode)) {
				return sel.getRangeAt(0).cloneRange();
			}
			return null;
		}

		function restoreSelection(range) {
			editor.focus();
			if (range) {
				var sel = window.getSelection();
				sel.removeAllRanges();
				sel.addRange(range);
			}
		}

		function applyLink() {
			var url = linkInput.value.trim();
			if (!url) {
				return;
			}
			if (!/^(https?:\/\/|mailto:)/i.test(url)) {
				url = 'https://' + url;
			}
			restoreSelection(savedRange);
			if (savedRange && !savedRange.collapsed) {
				document.execCommand('createLink', false, url);
			} else {
				document.execCommand('insertHTML', false, '<a href="' + escapeHtml(url) + '">' + escapeHtml(url) + '</a>');
			}
			linkBar.hidden = true;
			linkInput.value = '';
			editor.focus();
		}

		function removeLink() {
			restoreSelection(savedRange);
			document.execCommand('unlink', false, null);
			linkBar.hidden = true;
			linkInput.value = '';
			editor.focus();
		}

		linkInput.addEventListener('keydown', function (event) {
			if (event.key === 'Enter') {
				event.preventDefault();
				applyLink();
			}
		});

		/* Toolbar */
		var tools = [
			['bold', 'bold', 'eav-tool--bold'],
			['italic', 'italic', 'eav-tool--italic'],
			['underline', 'underline', 'eav-tool--underline'],
			['bullets', 'insertUnorderedList', ''],
			['numbers', 'insertOrderedList', ''],
			['link', 'link', ''],
			['clear', 'removeFormat', '']
		];

		var toolbar = el('div', { 'class': 'eav-toolbar', role: 'toolbar', 'aria-label': t.toolbarLabel });
		tools.forEach(function (tool) {
			var node = el('button', { type: 'button', 'class': 'eav-tool ' + tool[2], text: t.tools[tool[0]] });
			// Keep the editor selection when the button is pressed.
			node.addEventListener('mousedown', function (event) {
				event.preventDefault();
			});
			node.addEventListener('click', function () {
				if (tool[1] === 'link') {
					savedRange = saveSelection();
					linkBar.hidden = !linkBar.hidden;
					if (!linkBar.hidden) {
						linkInput.focus();
					}
					return;
				}
				editor.focus();
				document.execCommand(tool[1], false, null);
			});
			toolbar.appendChild(node);
		});

		/* Footer */
		var copyBox = el('input', { type: 'checkbox', id: 'eav-copy' });
		var errorBox = el('p', { 'class': 'eav-error', role: 'alert', hidden: true });

		var sendBtn = button('eav-btn ' + (approve ? 'eav-btn--approve' : 'eav-btn--reject'), approve ? t.sendApprove : t.sendReject, send, cfg.icons.send);
		var sendLabel = sendBtn.querySelector('.eav-btn__label');
		var sendText = sendLabel.textContent;

		var foot = el('div', { 'class': 'eav-panel__foot' }, [
			el('label', { 'class': 'eav-check', 'for': 'eav-copy' }, [copyBox, t.copyMe]),
			el('span', { 'class': 'eav-compose__buttons' }, [sendBtn])
		]);

		function fail(message, field) {
			errorBox.textContent = message;
			errorBox.hidden = false;
			if (field) {
				field.focus();
			}
		}

		function send() {
			var subject = subjectInput.value.trim();
			errorBox.hidden = true;

			if (!subject) {
				fail(t.subjectRequired, subjectInput);
				return;
			}
			if (!editor.textContent.trim()) {
				fail(t.bodyRequired, editor);
				return;
			}

			sendBtn.disabled = true;
			sendLabel.textContent = t.sending;

			api('decide', {
				id: data.id,
				decision: decision,
				subject: subject,
				body: editor.innerHTML,
				copy: copyBox.checked ? '1' : ''
			}).then(function (result) {
				state.counts = result.counts;
				hideLayer();
				showToast(result.message, false);
				return loadList(true);
			}).catch(function (error) {
				sendBtn.disabled = false;
				sendLabel.textContent = sendText;
				fail(error.message, null);
			});
		}

		content.appendChild(el('div', { 'class': 'eav-compose' }, [
			bar,
			el('div', { 'class': 'eav-row eav-row--locked' }, [el('label', { 'for': 'eav-to', text: t.to }), toInput]),
			el('div', { 'class': 'eav-row' }, [el('label', { 'for': 'eav-subject', text: t.subject }), subjectInput]),
			toolbar,
			linkBar,
			editor,
			errorBox,
			foot
		]));

		state.compose = {
			subjectEl: subjectInput,
			editorEl: editor,
			subject: subjectInput.value,
			html: editor.innerHTML
		};

		// Put the caret at the end of the starter text, ready to type.
		editor.focus();
		var range = document.createRange();
		range.selectNodeContents(editor);
		range.collapse(false);
		var selection = window.getSelection();
		selection.removeAllRanges();
		selection.addRange(range);
	}

	/* ------------------------------------------------------------------- init */

	loadList(true);
})();
