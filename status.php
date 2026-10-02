<?php
/**
 * Status / Control → Illumination Toolbox.
 *
 * Pair this player with a toolbox account, see when it last sent its
 * configuration, send it now, decide whether the toolbox may ask things of
 * it, or stop. All of the work is behind the plugin's own API (api.php);
 * this page is the form in front of it.
 */
include_once __DIR__ . '/lib/toolbox.php';
$itbApi = '/api/plugin/' . ITB_PLUGIN;
?>
<?php // FPP 10 is Bootstrap 5.3 with a light and a dark theme. Everything below
// is Bootstrap's own cards, forms and text classes so the page follows the
// theme the operator picked; there is no CSS of the plugin's own. ?>
<div class="row">
<div class="col-lg-10 col-xl-8">
	<p class="text-body-secondary mb-4">
		Send this player's configuration to your Illumination Toolbox account, so the xLights AI can see your
		outputs, pixel strings, schedule, warnings and whether your controllers answer when you ask it for help.
		Every toolbox tool reads the same copy. Passwords, keys and tokens, FPP's privacy settings, and hardware
		serial numbers and MAC addresses are removed on this player before anything is sent.
	</p>

	<section class="card mb-3" id="itb-link">
		<div class="card-body">
			<h2 class="card-title fs-5">Account</h2>
			<div id="itb-link-body"><span class="text-body-secondary">Checking…</span></div>
		</div>
	</section>

	<section class="card mb-3 d-none" id="itb-sync">
		<div class="card-body">
			<h2 class="card-title fs-5">Sending</h2>
			<dl class="row mb-3">
				<dt class="col-sm-4 col-md-3">Last sent</dt><dd class="col-sm-8 col-md-9" id="itb-last-sent">—</dd>
				<dt class="col-sm-4 col-md-3">Result</dt><dd class="col-sm-8 col-md-9 mb-0" id="itb-last-result">—</dd>
			</dl>
			<div class="form-check form-switch mb-3">
				<input class="form-check-input" type="checkbox" role="switch" id="itb-auto">
				<label class="form-check-label" for="itb-auto">
					Send automatically
					<span class="d-block small text-body-secondary">Every five minutes when something changed, at least hourly, and when fppd starts.</span>
				</label>
			</div>
			<p id="itb-auto-msg" class="text-danger text-break small mb-2" role="alert"></p>
			<div class="d-flex flex-wrap align-items-center gap-3">
				<button type="button" class="btn btn-primary" id="itb-sync-now">Send now</button>
				<a class="small" href="<?php echo htmlspecialchars($itbApi); ?>/preview" target="_blank" rel="noopener">See exactly what is sent</a>
			</div>
		</div>
	</section>

	<section class="card mb-3 d-none" id="itb-remote">
		<div class="card-body">
			<h2 class="card-title fs-5">Requests from the toolbox</h2>
			<dl class="row mb-3">
				<dt class="col-sm-4 col-md-3">Remote requests</dt><dd class="col-sm-8 col-md-9" id="itb-remote-state">—</dd>
				<dt class="col-sm-4 col-md-3">Last request</dt><dd class="col-sm-8 col-md-9 mb-0" id="itb-last-request">—</dd>
			</dl>
			<div class="form-check form-switch mb-2">
				<input class="form-check-input" type="checkbox" role="switch" id="itb-allow-remote">
				<label class="form-check-label" for="itb-allow-remote">Let the toolbox ask this player for a fresh snapshot, a light test, or to flag fppd for a restart</label>
			</div>
			<p id="itb-remote-msg" class="text-danger text-break small mb-2" role="alert"></p>
			<p class="small text-body-secondary mb-0">
				The player asks the toolbox whether anything is waiting; the toolbox never connects to the player.
				A request that is not picked up within ten minutes lapses. A restart request does not stop a running show by itself:
				it raises FPP's own "FPPD Restart Required" banner, and fppd restarts when you press
				<strong>Restart FPPD</strong> there, or when the player next boots.
			</p>
		</div>
	</section>

</div>
</div>

<script>
(function () {
	var API = <?php echo json_encode($itbApi); ?>;
	var state = null;
	var TRIGGERS = {
		timer: 'hourly check', change: 'a change was found', start: 'fppd started',
		manual: 'Send now', pair: 'pairing', request: 'the toolbox asked'
	};

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
	// The term column of every key/value list, so they all line up.
	function dt(text) { return '<dt class="col-sm-4 col-md-3">' + esc(text) + '</dt>'; }
	// A <dd> keeps its grid columns while its colour changes with the state it shows.
	function tone(node, classes) {
		var last = node.classList.contains('mb-0') ? ' mb-0' : '';
		node.className = 'col-sm-8 col-md-9' + last + (classes ? ' ' + classes : '');
	}
	// Every POST carries a JSON body, even an empty one: the plugin's API
	// refuses a POST without Content-Type: application/json (see api.php).
	function call(method, path, body) {
		var post = method !== 'GET';
		return fetch(API + path, {
			method: method,
			headers: post ? { 'Content-Type': 'application/json' } : {},
			body: post ? JSON.stringify(body || {}) : undefined
		}).then(function (r) { return r.json(); });
	}

	// FPP's own toast when the page has it, and the line under the switch either way.
	function fail(lineId, text) {
		el(lineId).textContent = text;
		if (window.jQuery && jQuery.jGrowl) jQuery.jGrowl(text, { themeState: 'danger' });
	}

	function render() {
		var s = state;
		var box = el('itb-link-body');
		if (!s) { box.innerHTML = '<span class="text-body-secondary">Checking…</span>'; return; }

		if (!s.linked) {
			box.innerHTML =
				'<p>Not linked. In any Illumination Toolbox tool, open <strong>Settings</strong>, find <strong>FPP players</strong>, ' +
				'press <strong>Link a player</strong>, and type the code it shows here. Codes last ten minutes.</p>' +
				'<div class="d-flex flex-wrap gap-2">' +
				'<label class="visually-hidden" for="itb-code">Pairing code</label>' +
				'<input type="text" id="itb-code" class="form-control font-monospace text-uppercase w-auto" size="11" placeholder="XXXX-XXXX" autocomplete="off" spellcheck="false" maxlength="9">' +
				'<button type="button" class="btn btn-primary" id="itb-pair">Link this player</button>' +
				'</div>' +
				'<p id="itb-pair-msg" class="text-danger text-break small mt-2 mb-0" role="alert"></p>';
			el('itb-pair').addEventListener('click', pair);
			el('itb-code').addEventListener('keydown', function (e) { if (e.key === 'Enter') pair(); });
			el('itb-sync').classList.add('d-none');
			el('itb-remote').classList.add('d-none');
		} else {
			var link = s.link;
			var linkLine = !link ? '' :
				link.ok
					? '<span class="text-success">Link confirmed by the toolbox' +
						(link.lastReceivedUtc ? ' · last received ' + when(link.lastReceivedUtc) : ' · nothing received yet') + '</span>'
					: '<span class="text-danger text-break">' + esc(link.error || 'The toolbox did not confirm the link.') + '</span>';
			box.innerHTML =
				'<dl class="row mb-3">' +
				dt('Linked to') + '<dd class="col-sm-8 col-md-9">@' + esc(s.username) + '</dd>' +
				dt('This player') + '<dd class="col-sm-8 col-md-9">' + esc(s.hostname) + ' <span class="text-body-secondary small">' + esc(s.deviceId) + '</span></dd>' +
				dt('Token') + '<dd class="col-sm-8 col-md-9">good until ' + when(s.tokenExpiresUtc) + '</dd>' +
				dt('Status') + '<dd class="col-sm-8 col-md-9 mb-0">' + (linkLine || '<span class="text-body-secondary">—</span>') + '</dd>' +
				'</dl>' +
				'<div class="d-flex flex-wrap align-items-center gap-3">' +
				'<button type="button" class="btn btn-outline-danger" id="itb-unlink">Unlink</button>' +
				'<span class="small text-body-secondary">Stops sending. To delete what the toolbox already holds, remove this player under FPP players in the toolbox.</span></div>' +
				'<p id="itb-unlink-msg" class="text-danger text-break small mt-2 mb-0" role="alert"></p>';
			el('itb-unlink').addEventListener('click', unlinkPlayer);

			el('itb-sync').classList.remove('d-none');
			var why = s.lastTrigger && TRIGGERS[s.lastTrigger] ? ' <span class="text-body-secondary">(' + esc(TRIGGERS[s.lastTrigger]) + ')</span>' : '';
			el('itb-last-sent').innerHTML = when(s.lastSyncUtc) + (s.lastSyncUtc ? why : '');
			var res = el('itb-last-result');
			if (s.lastSyncResult === 'ok') { tone(res, 'text-success'); res.textContent = 'Sent'; }
			else if (s.lastSyncResult === 'error') { tone(res, 'text-danger text-break'); res.textContent = s.lastSyncError || 'Failed'; }
			else { tone(res, 'text-body-secondary'); res.textContent = 'Not sent yet'; }
			el('itb-auto').checked = !!s.autoSync;

			el('itb-remote').classList.remove('d-none');
			var remote = el('itb-remote-state');
			if (s.allowRemote) { tone(remote, 'text-success'); remote.textContent = 'listening'; }
			else { tone(remote, 'text-body-secondary'); remote.textContent = 'off'; }
			var req = el('itb-last-request');
			if (s.lastCommandType) {
				var failed = /^failed:/.test(s.lastCommandResult || '');
				tone(req, failed ? 'text-danger text-break' : '');
				req.textContent = s.lastCommandType + ' · ' + when(s.lastCommandUtc) + ' — ' + (s.lastCommandResult || 'done');
			} else {
				tone(req, 'text-body-secondary');
				req.textContent = 'None yet';
			}
			el('itb-allow-remote').checked = !!s.allowRemote;
		}
	}

	function load(check) {
		return call('GET', '/status' + (check ? '?check=1' : '')).then(function (s) { state = s; render(); })
			.catch(function () { el('itb-link-body').innerHTML = '<span class="text-danger">Could not reach this player\'s plugin API.</span>'; });
	}

	function pair() {
		var code = el('itb-code').value;
		var btn = el('itb-pair');
		var msg = el('itb-pair-msg');
		btn.disabled = true; msg.textContent = '';
		call('POST', '/pair', { code: code }).then(function (r) {
			if (!r.ok) { msg.textContent = r.error || 'Pairing failed.'; btn.disabled = false; return; }
			return load(true);
		}).catch(function () { msg.textContent = 'Pairing failed.'; btn.disabled = false; });
	}

	function unlinkPlayer() {
		if (!confirm('Stop sending this player\'s configuration to the toolbox?')) return;
		call('POST', '/unlink').then(function () { return load(false); })
			.catch(function () { fail('itb-unlink-msg', 'Could not unlink: this player\'s plugin API did not answer.'); });
	}

	el('itb-sync-now').addEventListener('click', function () {
		var btn = el('itb-sync-now');
		btn.disabled = true; btn.textContent = 'Sending…';
		call('POST', '/sync').then(function (r) {
			btn.disabled = false; btn.textContent = 'Send now';
			return load(true);
		}).catch(function () { btn.disabled = false; btn.textContent = 'Send now'; });
	});

	// A switch that could not be saved goes back to where it was, and says so,
	// rather than showing a setting the player does not have.
	function toggle(boxId, lineId, key) {
		var box = el(boxId);
		box.addEventListener('change', function () {
			var wanted = box.checked;
			var body = {};
			body[key] = wanted;
			el(lineId).textContent = '';
			call('POST', '/settings', body).then(function (s) {
				if (!s || !s.ok) throw new Error(s && s.error ? s.error : 'not saved');
				state = Object.assign({}, state, s); render();
			}).catch(function (e) {
				box.checked = !wanted;
				fail(lineId, 'Could not save that setting' + (e && e.message && e.message !== 'not saved' ? ': ' + e.message : '.'));
			});
		});
	}
	toggle('itb-auto', 'itb-auto-msg', 'autoSync');
	toggle('itb-allow-remote', 'itb-remote-msg', 'allowRemote');

	load(true);
})();
</script>
