<?php
/**
 * Status / Control → Illumination Toolbox.
 *
 * Pair this player with a toolbox account, see when it last sent its
 * configuration, send it now, or stop. All of the work is behind the plugin's
 * own API (api.php); this page is the form in front of it.
 */
include_once __DIR__ . '/lib/toolbox.php';
$itbApi = '/api/plugin/' . ITB_PLUGIN;
?>
<style>
.itb { max-width: 720px; }
.itb h2 { margin-top: 0; }
.itb-card { border: 1px solid #ccc; border-radius: 6px; padding: 14px 16px; margin: 0 0 14px; background: #fff; }
.itb-card h3 { margin: 0 0 8px; font-size: 1.05em; }
.itb-muted { color: #666; }
.itb-ok { color: #1a7f37; }
.itb-bad { color: #b3261e; }
.itb-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin: 8px 0; }
.itb-code { font-family: monospace; font-size: 1.4em; letter-spacing: 0.15em; text-transform: uppercase; width: 11ch; }
.itb-kv { margin: 0; }
.itb-kv dt { font-weight: 600; float: left; clear: left; width: 9em; }
.itb-kv dd { margin: 0 0 4px 9.5em; min-height: 1.2em; }
.itb details summary { cursor: pointer; }
.itb-error { white-space: pre-wrap; }
.itb button[disabled] { opacity: .6; }
</style>

<div class="itb">
	<h2>Illumination Toolbox</h2>
	<p class="itb-muted">
		Send this player's configuration to your Illumination Toolbox account, so the xLights AI can see your
		outputs, pixel strings, schedule and warnings when you ask it for help. Every toolbox tool reads the same copy.
		Passwords, keys and tokens are removed on this player before anything is sent.
	</p>

	<div class="itb-card" id="itb-link">
		<h3>Account</h3>
		<div id="itb-link-body"><span class="itb-muted">Checking…</span></div>
	</div>

	<div class="itb-card" id="itb-sync" style="display:none">
		<h3>Sending</h3>
		<dl class="itb-kv">
			<dt>Last sent</dt><dd id="itb-last-sent">—</dd>
			<dt>Result</dt><dd id="itb-last-result">—</dd>
		</dl>
		<div class="itb-row">
			<button type="button" class="buttons" id="itb-sync-now">Send now</button>
			<label><input type="checkbox" id="itb-auto"> Send automatically (every hour, and when fppd starts)</label>
		</div>
		<p class="itb-muted">
			<a href="<?php echo htmlspecialchars($itbApi); ?>/preview" target="_blank" rel="noopener">See exactly what is sent</a>
			— it opens as JSON in a new tab.
		</p>
	</div>

	<details class="itb-card">
		<summary>Advanced</summary>
		<div class="itb-row">
			<label for="itb-api">Toolbox API</label>
			<input type="text" id="itb-api" size="60">
			<button type="button" class="buttons" id="itb-api-save">Save</button>
		</div>
		<p class="itb-muted">Leave this alone unless you run your own toolbox. Blank restores the default.</p>
	</details>
</div>

<script>
(function () {
	var API = <?php echo json_encode($itbApi); ?>;
	var state = null;

	function el(id) { return document.getElementById(id); }
	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function when(iso) {
		if (!iso) return '—';
		var d = new Date(iso);
		return isNaN(d.getTime()) ? esc(iso) : d.toLocaleString();
	}
	function call(method, path, body) {
		return fetch(API + path, {
			method: method,
			headers: body ? { 'Content-Type': 'application/json' } : {},
			body: body ? JSON.stringify(body) : undefined
		}).then(function (r) { return r.json(); });
	}

	function render() {
		var s = state;
		var box = el('itb-link-body');
		if (!s) { box.innerHTML = '<span class="itb-muted">Checking…</span>'; return; }

		if (!s.linked) {
			box.innerHTML =
				'<p>Not linked. In any Illumination Toolbox tool, open the account menu, choose <b>FPP players</b>, ' +
				'press <b>Link a player</b>, and type the code it shows here. Codes last ten minutes.</p>' +
				'<div class="itb-row">' +
				'<input type="text" id="itb-code" class="itb-code" placeholder="XXXX-XXXX" autocomplete="off" spellcheck="false" maxlength="9">' +
				'<button type="button" class="buttons" id="itb-pair">Link this player</button>' +
				'</div>' +
				'<p id="itb-pair-msg" class="itb-bad itb-error"></p>';
			el('itb-pair').addEventListener('click', pair);
			el('itb-code').addEventListener('keydown', function (e) { if (e.key === 'Enter') pair(); });
			el('itb-sync').style.display = 'none';
		} else {
			var link = s.link;
			var linkLine = !link ? '' :
				link.ok
					? '<span class="itb-ok">Link confirmed by the toolbox' +
						(link.lastReceivedUtc ? ' · last received ' + when(link.lastReceivedUtc) : ' · nothing received yet') + '</span>'
					: '<span class="itb-bad">' + esc(link.error || 'The toolbox did not confirm the link.') + '</span>';
			box.innerHTML =
				'<dl class="itb-kv">' +
				'<dt>Linked to</dt><dd>@' + esc(s.username) + '</dd>' +
				'<dt>This player</dt><dd>' + esc(s.hostname) + ' <span class="itb-muted">(' + esc(s.deviceId) + ')</span></dd>' +
				'<dt>Token</dt><dd>good until ' + when(s.tokenExpiresUtc) + '</dd>' +
				'<dt>Status</dt><dd>' + (linkLine || '<span class="itb-muted">—</span>') + '</dd>' +
				'</dl>' +
				'<div class="itb-row"><button type="button" class="buttons" id="itb-unlink">Unlink</button>' +
				'<span class="itb-muted">Stops sending. To delete what the toolbox already holds, remove this player from FPP players in the toolbox.</span></div>';
			el('itb-unlink').addEventListener('click', unlink);

			el('itb-sync').style.display = '';
			el('itb-last-sent').textContent = when(s.lastSyncUtc);
			var res = el('itb-last-result');
			if (s.lastSyncResult === 'ok') { res.className = 'itb-ok'; res.textContent = 'Sent'; }
			else if (s.lastSyncResult === 'error') { res.className = 'itb-bad itb-error'; res.textContent = s.lastSyncError || 'Failed'; }
			else { res.className = 'itb-muted'; res.textContent = 'Not sent yet'; }
			el('itb-auto').checked = !!s.autoSync;
		}
		el('itb-api').value = s.apiBaseUrl || '';
	}

	function load(check) {
		return call('GET', '/status' + (check ? '?check=1' : '')).then(function (s) { state = s; render(); })
			.catch(function () { el('itb-link-body').innerHTML = '<span class="itb-bad">Could not reach this player\'s plugin API.</span>'; });
	}

	function pair() {
		var code = el('itb-code').value;
		var btn = el('itb-pair');
		var msg = el('itb-pair-msg');
		btn.disabled = true; msg.textContent = '';
		call('POST', '/pair', { code: code, apiBaseUrl: el('itb-api').value }).then(function (r) {
			if (!r.ok) { msg.textContent = r.error || 'Pairing failed.'; btn.disabled = false; return; }
			return load(true);
		}).catch(function () { msg.textContent = 'Pairing failed.'; btn.disabled = false; });
	}

	function unlink() {
		if (!confirm('Stop sending this player\'s configuration to the toolbox?')) return;
		call('POST', '/unlink').then(function () { return load(false); });
	}

	el('itb-sync-now').addEventListener('click', function () {
		var btn = el('itb-sync-now');
		btn.disabled = true; btn.textContent = 'Sending…';
		call('POST', '/sync').then(function (r) {
			btn.disabled = false; btn.textContent = 'Send now';
			return load(true);
		}).catch(function () { btn.disabled = false; btn.textContent = 'Send now'; });
	});

	el('itb-auto').addEventListener('change', function () {
		call('POST', '/settings', { autoSync: el('itb-auto').checked }).then(function (s) { state = Object.assign({}, state, s); render(); });
	});

	el('itb-api-save').addEventListener('click', function () {
		call('POST', '/settings', { apiBaseUrl: el('itb-api').value }).then(function (s) {
			if (!s.ok) { alert(s.error || 'Could not save.'); return; }
			state = Object.assign({}, state, s); render();
		});
	});

	load(true);
})();
</script>
