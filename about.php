<?php include_once __DIR__ . '/lib/toolbox.php'; ?>
<?php // Bootstrap's own classes only, so the page follows FPP's light or dark theme. ?>
<div class="row">
<div class="col-lg-10 col-xl-8">
	<h2>Illumination Toolbox <small class="text-body-secondary">v<?php echo htmlspecialchars(ITB_PLUGIN_VERSION); ?></small></h2>

	<p>
		This plugin links the player to an <a href="https://illuminationtoolbox.com" target="_blank" rel="noopener">Illumination Toolbox</a>
		account and sends a snapshot of how it is configured to <code>api.illuminationtoolbox.com</code>. The xLights AI reads
		that snapshot when you ask it why a prop is dark or what a port is set to; every other tool on the account reads the
		same copy.
	</p>

	<h3 class="fs-5">What is sent</h3>
	<ul>
		<li>System info and status: hostname, IP addresses, FPP version, platform and hardware model, mode, uptime, sensors, and the current warnings.</li>
		<li>Settings, from <code>/api/settings</code>, without FPP's privacy settings.</li>
		<li>Channel outputs and inputs: every <code>co-*.json</code> and <code>ci-*.json</code> config file — E1.31 / ArtNet / DDP universes, pixel strings, other outputs.</li>
		<li>Output processors, pixel overlay models, GPIO and command presets.</li>
		<li>The schedule, playlist names, and the contents of up to 30 playlists.</li>
		<li>Sequence and music file names, and for sequences their size, date, channel count and length.</li>
		<li>Installed plugins, network interfaces, and cape information.</li>
		<li>The controllers this player sends to, and whether each answered a ping when the snapshot was taken.</li>
		<li>The other FPP systems and controllers this player has discovered on your network: name, address, type, firmware and mode.</li>
		<li>When the Player Failover plugin is installed: its status — this player's role and state, the other player's
			name, address and whether it answers, what is playing, and the last ten failover events.</li>
	</ul>
	<p>
		Before it leaves the player, every value whose name looks like a credential — password, secret, token,
		key, PSK — is replaced with <code>[redacted]</code>, FPP's own privacy settings are taken out, and so are
		MAC addresses, serial numbers and hardware uuids. Other plugins' settings files, network interface files
		and backups are not sent at all. Open <a href="/api/plugin/<?php echo htmlspecialchars(ITB_PLUGIN); ?>/preview" target="_blank" rel="noopener">the preview</a>
		to read exactly what a send contains.
	</p>
	<p>
		The toolbox keeps the current snapshot and up to ten earlier ones for each player, and deletes them when you
		remove the player from your account or delete the account.
		<a href="https://www.illuminationtoolbox.com/privacy" target="_blank" rel="noopener">Privacy policy</a>.
	</p>

	<h3 class="fs-5">When it is sent</h3>
	<p>
		Every five minutes the player checks whether anything changed and sends if so — a new controller found on
		the network, or one that stopped answering, counts as a change; at least once an hour regardless; about
		ninety seconds after fppd starts; whenever you press <strong>Send now</strong>; and whenever the toolbox asks
		for a fresh snapshot. Untick <em>Send automatically</em> on the status page to send only by hand and on request.
	</p>
	<p>
		When the Player Failover plugin is installed and given a role, the player also sends its failover status every
		fifteen seconds while it is listening for requests, and straight away when the role or state changes, so Control
		Booth can show which player has the show. Untick <em>Send automatically</em> or the requests box to stop it.
	</p>

	<h3 class="fs-5">Requests from the toolbox</h3>
	<p>
		The player asks the toolbox whether anyone has a request for it — a fresh snapshot, a short RGB chase on a
		channel range so you can see which prop lights, or an fppd restart — and carries it out. A light test is
		refused while a playlist is playing, so it never takes over a show in front of an audience, unless the
		request says to go ahead anyway. A restart request
		does not stop a running show by itself: it raises FPP's own "FPPD Restart Required" banner, and fppd restarts when you press
		<strong>Restart FPPD</strong> there, or when the player next boots. With the Player Failover plugin installed,
		Control Booth can also ask this player to take over the show, hand it over, fail back to the primary, or stop
		holding in standby; the failover plugin does the switch, as if you had pressed its own buttons. The toolbox never connects to the
		player. Untick <em>Let the toolbox ask this player…</em> on the status page to stop listening.
	</p>

	<h3 class="fs-5">Pairing</h3>
	<ol>
		<li>In any toolbox tool, open <strong>Settings</strong> and choose <strong>FPP players</strong>.</li>
		<li>Press <strong>Link a player</strong>. It shows an eight-character code, good for ten minutes.</li>
		<li>On this player, open <strong>Status / Control → Illumination Toolbox</strong>, type the code, tick the box
			to agree to the <a href="<?php echo ITB_TERMS_URL; ?>" target="_blank" rel="noopener">Terms of Service</a>
			and <a href="<?php echo ITB_PRIVACY_URL; ?>" target="_blank" rel="noopener">Privacy Policy</a>, and press
			<strong>Link this player</strong>.</li>
	</ol>
	<p>
		The player keeps a token that uploads this player's snapshot and collects the requests you queue for it,
		nothing else. It is stored in the plugin's own data folder, readable only by FPP, and the player swaps it
		for a fresh one every month, so a linked player stays linked. Removing the player in the toolbox, or
		signing out all devices there, ends it for good; pair again after that. <strong>Unlink</strong> and
		uninstalling the plugin delete it.
	</p>
	<p>
		FPP has no login by default, so anyone on your network who can open this page can unlink the player or
		link it to another account. To prevent that, turn on <strong>UI password</strong> on the <strong>UI</strong> tab of FPP's <strong>Settings</strong> page.
	</p>
	<p>
		What the plugin does is written to <code>plugin-<?php echo htmlspecialchars(ITB_PLUGIN); ?>.log</code> in FPP's
		log viewer: each send, each request it carried out, pairing and unlinking. Never the token or the snapshot itself.
	</p>

	<h3 class="fs-5">Links</h3>
	<ul>
		<li><a href="https://github.com/Illumination-Toolbox/fpp-plugin-IlluminationToolbox" target="_blank" rel="noopener">Source</a></li>
		<li><a href="https://github.com/Illumination-Toolbox/fpp-plugin-IlluminationToolbox/issues" target="_blank" rel="noopener">Report a problem</a></li>
	</ul>
</div>
</div>
