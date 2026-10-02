<?php include_once __DIR__ . '/lib/toolbox.php'; ?>
<div style="max-width: 720px">
	<h2>Illumination Toolbox <small style="color:#666">v<?php echo htmlspecialchars(ITB_PLUGIN_VERSION); ?></small></h2>

	<p>
		This plugin links the player to an <a href="https://illuminationtoolbox.com" target="_blank" rel="noopener">Illumination Toolbox</a>
		account and sends a snapshot of how it is configured. The xLights AI reads that snapshot when you ask it
		why a prop is dark or what a port is set to; every other tool on the account reads the same copy.
	</p>

	<h3>What is sent</h3>
	<ul>
		<li>System info and status: version, platform, mode, IP addresses, uptime, sensors, and the current warnings.</li>
		<li>Settings, from <code>/api/settings</code>.</li>
		<li>Channel outputs and inputs: every <code>co-*.json</code> and <code>ci-*.json</code> config file — E1.31 / ArtNet / DDP universes, pixel strings, other outputs.</li>
		<li>Output processors, pixel overlay models, GPIO and command presets.</li>
		<li>The schedule, playlist names, and the contents of up to 30 playlists.</li>
		<li>Installed plugins, network interfaces, and cape information.</li>
	</ul>
	<p>
		Before it leaves the player, every value whose name looks like a credential — password, secret, token,
		key, PSK — is replaced with <code>[redacted]</code>. Other plugins' settings files, network interface files
		and backups are not sent at all. Open <a href="/api/plugin/<?php echo htmlspecialchars(ITB_PLUGIN); ?>/preview" target="_blank" rel="noopener">the preview</a>
		to read exactly what a send contains.
	</p>

	<h3>When it is sent</h3>
	<p>
		Every five minutes the player checks whether anything changed and sends if so; at least once an hour
		regardless; about ninety seconds after fppd starts; whenever you press <b>Send now</b>; and whenever
		the toolbox asks for a fresh snapshot. Untick <i>Send automatically</i> on the status page to send only
		by hand and on request.
	</p>

	<h3>Requests from the toolbox</h3>
	<p>
		The player asks the toolbox whether anyone has a request for it — a fresh snapshot, a short RGB chase
		on a channel range so you can see which prop lights, or an fppd restart — and carries it out. The
		toolbox never connects to the player. Untick <i>Let the toolbox ask this player…</i> on the status
		page to stop listening.
	</p>

	<h3>Pairing</h3>
	<ol>
		<li>In any toolbox tool, open the account menu and choose <b>FPP players</b>.</li>
		<li>Press <b>Link a player</b>. It shows an eight-character code, good for ten minutes.</li>
		<li>On this player, open <b>Status / Control → Illumination Toolbox</b>, type the code, and press <b>Link this player</b>.</li>
	</ol>
	<p>
		The player keeps a token that can upload its own snapshot and nothing else. It lasts a year; pair
		again when it lapses, or any time you want a fresh one.
	</p>

	<h3>Links</h3>
	<ul>
		<li><a href="https://github.com/Illumination-Toolbox/fpp-plugin-IlluminationToolbox" target="_blank" rel="noopener">Source</a></li>
		<li><a href="https://github.com/Illumination-Toolbox/fpp-plugin-IlluminationToolbox/issues" target="_blank" rel="noopener">Report a problem</a></li>
	</ul>
</div>
