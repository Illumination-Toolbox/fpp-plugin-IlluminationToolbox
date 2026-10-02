<?php
/**
 * Illumination Toolbox plugin — the part that does the work.
 *
 * Shared by the status page, the plugin's own API endpoints, and the timer
 * and poll loop that run behind them. Four jobs:
 *
 *   1. Pair. Trade a code the person read off the toolbox for a device token,
 *      and keep it in this plugin's own data folder, readable by nobody else.
 *   2. Snapshot. Ask this player, through its own local REST API, for
 *      everything that describes how it is set up, and redact anything that
 *      looks like a secret, and anything that identifies the hardware itself,
 *      before it goes anywhere.
 *   3. Send. PUT the snapshot to the toolbox, which holds it for every tool on
 *      the account — and remember what went, so the five-minute timer can
 *      tell whether anything changed and stay quiet when nothing did.
 *   4. Answer. Ask the toolbox whether someone has asked this player for
 *      something — a fresh snapshot, a light test, an fppd restart — do it,
 *      and report back.
 *
 * Nothing here reads fppd's internal port or touches FPP's files under the
 * media directory; the only files it writes are its own, in
 * plugindata/fpp-plugin-IlluminationToolbox/, and its one log. Everything
 * else goes through http://127.0.0.1/api, which is what the plugin
 * guidelines ask for and what keeps this working across FPP versions and
 * platforms.
 *
 * Requires FPP's common.php to be loaded first, for ReadSettingFromFile,
 * WriteSettingToFile and $settings. plugin.php and api/index.php both do that.
 */

if (!defined('ITB_PLUGIN')) {
	define('ITB_PLUGIN', 'fpp-plugin-IlluminationToolbox');
	define('ITB_PLUGIN_VERSION', '1.2.0');
	define('ITB_SCHEMA', 'illumination-toolbox.fpp-snapshot/1');
	define('ITB_DEFAULT_API', 'https://api.illuminationtoolbox.com');
	define('ITB_LOCAL_API', 'http://127.0.0.1');
	/** One config file bigger than this is skipped; the toolbox caps the whole snapshot at 4 MB. */
	define('ITB_MAX_CONFIG_FILE_BYTES', 512000);
	define('ITB_SNAPSHOT_BUDGET_BYTES', 2500000);
	/** Where 1.1 kept what was last sent, beside the settings file. Deleted on sight now. */
	define('ITB_OLD_STATE_FILE', 'plugin.' . ITB_PLUGIN . '.state.json');
	/** The timer resends an unchanged snapshot once this much time has passed, so a quiet player is still heard from hourly. */
	define('ITB_RESEND_AFTER_SECONDS', 55 * 60);
	define('ITB_MAX_FILES', 300);
	define('ITB_MAX_SEQUENCE_META', 40);
	/** Controllers: at most this many discovered systems, and this many output addresses pinged. */
	define('ITB_MAX_CONTROLLERS', 64);
	/** A skip, or a poll that could not reach the toolbox, is logged at most this often per reason. */
	define('ITB_LOG_QUIET_SECONDS', 3600);
}

// ── Settings ────────────────────────────────────────────────────────────────
// FPP keeps these in config/plugin.fpp-plugin-IlluminationToolbox as
// key = "value" lines: the switches, who the player is linked to, and what
// happened last. Not the device token — config/ is copied into FPP's backups
// and crash reports, so the token lives in plugindata/ instead (below).

function itb_setting($name, $default = '')
{
	$value = ReadSettingFromFile($name, ITB_PLUGIN);
	if ($value === false || $value === null || $value === '')
		return $default;
	return $value;
}

function itb_set($name, $value)
{
	// The settings file is one value per line inside double quotes; a value
	// carrying either would corrupt every setting after it.
	$clean = str_replace(array("\r", "\n", '"'), array(' ', ' ', "'"), (string) $value);
	WriteSettingToFile($name, $clean, ITB_PLUGIN);
}

function itb_api_base()
{
	return rtrim(itb_setting('apiBaseUrl', ITB_DEFAULT_API), '/');
}

function itb_now()
{
	return gmdate('Y-m-d\TH:i:s\Z');
}

// ── The plugin's own data ───────────────────────────────────────────────────
// Everything the plugin keeps that is not a setting a person would change
// lives in <mediadir>/plugindata/fpp-plugin-IlluminationToolbox/: the device
// token, and the bookkeeping about the last send. FPP's backups and crash
// reports copy config/ and never plugindata/, which is why the token is
// here (guidelines §14.11). The folder is 0700 and each file 0600, owned by
// whoever wrote it — the web server's user, since everything here runs
// behind Apache. Unlink deletes the token; uninstall deletes the folder.

/**
 * FPP's media directory, the way common.php works it out; the usual default
 * when $settings is not around (a script run outside the web server).
 */
function itb_media_dir()
{
	global $settings;
	if (isset($settings['mediaDirectory']) && is_string($settings['mediaDirectory']) && $settings['mediaDirectory'] !== '')
		return rtrim($settings['mediaDirectory'], '/');
	return '/home/fpp/media';
}

function itb_data_dir()
{
	return itb_media_dir() . '/plugindata/' . ITB_PLUGIN;
}

/** The data folder, made on first use with nobody else able to look in. False if it cannot be made. */
function itb_ensure_data_dir()
{
	$dir = itb_data_dir();
	if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir))
		return false;
	@chmod($dir, 0700);
	return $dir;
}

/**
 * One of the plugin's files, written whole through a temporary file, mode
 * 0600 from the moment it exists — so a poll and a timer run landing at the
 * same moment cannot leave half a file for the next one to read, and the
 * token is never readable by anyone else even for an instant.
 */
function itb_write_private($name, $content)
{
	$dir = itb_ensure_data_dir();
	if ($dir === false)
		return false;
	$path = $dir . '/' . $name;
	$tmp = $path . '.tmp';
	$old = umask(0077);
	$written = @file_put_contents($tmp, $content);
	umask($old);
	if ($written === false)
		return false;
	@chmod($tmp, 0600);
	return @rename($tmp, $path);
}

function itb_read_private($name)
{
	$path = itb_data_dir() . '/' . $name;
	return is_file($path) ? @file_get_contents($path) : false;
}

function itb_delete_private($name)
{
	@unlink(itb_data_dir() . '/' . $name);
}

// ── Device token ────────────────────────────────────────────────────────────
// The one credential the plugin holds. 1.1 kept it in the settings file as
// deviceToken; the first read after an upgrade moves it here and blanks the
// old setting, and leaves it where it was if the move fails.

function itb_token()
{
	$stored = itb_read_private('token');
	if ($stored !== false && trim($stored) !== '')
		return trim($stored);

	$old = itb_setting('deviceToken');
	if ($old === '')
		return '';
	if (itb_write_private('token', $old))
		itb_set('deviceToken', '');
	return $old;
}

function itb_set_token($token)
{
	return itb_write_private('token', (string) $token);
}

function itb_forget_token()
{
	itb_delete_private('token');
	if (itb_setting('deviceToken') !== '')
		itb_set('deviceToken', '');
}

// ── State file ──────────────────────────────────────────────────────────────
// What the plugin remembers about its last send — the fingerprint and one
// hash per section — is bookkeeping, not a setting, and goes in state.json
// in the data folder. 1.1 kept it beside the settings file; that copy is
// deleted rather than carried over, and the next timer run simply sends.

function itb_drop_old_state()
{
	$old = itb_media_dir() . '/config/' . ITB_OLD_STATE_FILE;
	if (is_file($old))
		@unlink($old);
	if (is_file($old . '.tmp'))
		@unlink($old . '.tmp');
}

/** What the last successful send left behind, or an empty array if nothing did. */
function itb_read_state()
{
	itb_drop_old_state();
	$json = itb_read_private('state.json');
	if ($json === false)
		return array();
	$decoded = json_decode((string) $json, true);
	return is_array($decoded) ? $decoded : array();
}

function itb_write_state($state)
{
	$json = itb_encode($state);
	return $json !== false && itb_write_private('state.json', $json);
}

function itb_clear_state()
{
	itb_delete_private('state.json');
	itb_drop_old_state();
}

// ── Log ─────────────────────────────────────────────────────────────────────
// One file, plugin-fpp-plugin-IlluminationToolbox.log in FPP's log folder,
// so it shows in FPP's log viewer and support zip; FPP rotates it, so the
// plugin never does (guidelines §1). One line per send, per request carried
// out, per pairing and unlink. A skip, or a poll that could not reach the
// toolbox, repeats every few minutes, so each reason is written at most once
// an hour. Never the token, never the snapshot.

function itb_log_path()
{
	global $settings;
	$dir = isset($settings['logDirectory']) && is_string($settings['logDirectory']) && $settings['logDirectory'] !== ''
		? rtrim($settings['logDirectory'], '/')
		: itb_media_dir() . '/logs';
	return $dir . '/plugin-' . ITB_PLUGIN . '.log';
}

function itb_log($message)
{
	$line = itb_now() . ' ' . str_replace(array("\r", "\n"), ' ', (string) $message) . "\n";
	@file_put_contents(itb_log_path(), $line, FILE_APPEND | LOCK_EX);
}

/** itb_log, but quiet if the same reason was already logged within the hour. */
function itb_log_quietly($reason, $message)
{
	$seen = json_decode((string) itb_read_private('quiet.json'), true);
	if (!is_array($seen))
		$seen = array();
	$last = isset($seen[$reason]) && is_numeric($seen[$reason]) ? (int) $seen[$reason] : 0;
	if (time() - $last < ITB_LOG_QUIET_SECONDS)
		return;
	$seen[$reason] = time();
	itb_write_private('quiet.json', itb_encode($seen));
	itb_log($message);
}

// ── HTTP ────────────────────────────────────────────────────────────────────

/**
 * One request. Returns array(status, body, curlError). Status 0 means the
 * request never got an answer, and curlError says why.
 */
function itb_http($method, $url, $body = null, $headers = array(), $timeout = 15)
{
	$ch = curl_init($url);
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CUSTOMREQUEST => $method,
		CURLOPT_CONNECTTIMEOUT => 5,
		CURLOPT_TIMEOUT => $timeout,
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_HTTPHEADER => array_merge(array('Accept: application/json', 'User-Agent: ' . ITB_PLUGIN . '/' . ITB_PLUGIN_VERSION), $headers),
	));
	if ($body !== null)
		curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

	$out = curl_exec($ch);
	$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$err = curl_error($ch);
	// A no-op since PHP 8, and deprecated from 8.5 (FPP 10 on macOS runs 8.5).
	if (PHP_VERSION_ID < 80000)
		curl_close($ch);

	return array($status, $out === false ? '' : $out, $err);
}

/** GET from this player's own API. */
function itb_local($path)
{
	return itb_http('GET', ITB_LOCAL_API . $path);
}

/** POST JSON to this player's own API. Same return shape as itb_local. */
function itb_local_post($path, $data)
{
	return itb_http('POST', ITB_LOCAL_API . $path, itb_encode($data), array('Content-Type: application/json'));
}

/**
 * GET and decode from this player's own API. Null on any failure, with the
 * reason appended to $errors so the snapshot can say what it is missing
 * rather than silently having a hole.
 */
function itb_local_json($path, &$errors)
{
	list($status, $out, $err) = itb_local($path);
	if ($status !== 200) {
		$errors[] = $path . ': ' . ($status ? 'HTTP ' . $status : ($err ?: 'no answer'));
		return null;
	}
	$decoded = json_decode($out, true);
	if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
		$errors[] = $path . ': not JSON';
		return null;
	}
	return $decoded;
}

// ── Redaction ───────────────────────────────────────────────────────────────

/**
 * Anything whose key looks like a credential is replaced before it leaves
 * the player. Applied to the whole snapshot, recursively, so a password in a
 * setting, a Wi-Fi PSK in a network block, or an API key inside another
 * plugin's config all get the same treatment. Better to lose a harmless
 * setting whose name happens to match than to ship one that matters.
 *
 * At least as wide as the redactor FPP runs over its own crash reports
 * (guidelines §14.4): password, passwd, passphrase, passcode, pwd, psk,
 * secret, token or credential anywhere in the key; pass, key, auth or pat
 * when not followed by a lower-case letter — so apikey, mailpass and
 * authToken are caught and keyframe is not. That lookahead is
 * case-sensitive, as FPP's is, hence the (?i:...) group rather than a /i on
 * the whole pattern.
 */
function itb_is_secret_key($key)
{
	if (!is_string($key))
		return false;
	return preg_match('/password|passwd|passphrase|passcode|pwd|psk|secret|token|credential|apikey|api_key|privatekey|private_key|authorization|cookie/i', $key) === 1
		|| preg_match('/(?i:pass|key|auth|pat)(?![a-z])/', $key) === 1;
}

function itb_redact($value)
{
	if (!is_array($value))
		return $value;

	$out = array();
	foreach ($value as $key => $item) {
		if (itb_is_secret_key($key) && !is_array($item))
			$out[$key] = '[redacted]';
		else
			$out[$key] = itb_redact($item);
	}
	return $out;
}

/**
 * FPP's privacy settings: the operator's answers to FPP about their own data
 * (guidelines §14.9). Not the plugin's business, so they are taken out of
 * the settings before anything else looks at them, wherever in the answer
 * they sit — as a key, and as a name in a settings group's list.
 */
function itb_privacy_setting_keys()
{
	return array('statsPublish', 'statsPublishUrl', 'ShareCrashData', 'FetchVendorLogos',
		'SendVendorSerial', 'SendVendorLogos', 'privacyConsent', 'LegalJurisdiction');
}

function itb_drop_privacy_settings($value)
{
	if (!is_array($value))
		return $value;
	$drop = itb_privacy_setting_keys();
	$isList = array_keys($value) === range(0, count($value) - 1);
	$out = array();
	foreach ($value as $key => $item) {
		if ($isList ? is_string($item) && in_array($item, $drop, true) : is_string($key) && in_array($key, $drop, true))
			continue;
		$item = itb_drop_privacy_settings($item);
		if ($isList)
			$out[] = $item;
		else
			$out[$key] = $item;
	}
	return $out;
}

// ── Hardware identifiers ────────────────────────────────────────────────────
// A MAC address, a CPU or cape serial number, a machine or FPP uuid: each
// names this one piece of hardware for good, which the toolbox has no use
// for (guidelines §14.1). They are removed, not redacted, wherever they turn
// up in the snapshot — /api/network/interface carries MACs, /api/system/info
// a uuid, /api/cape a serial. MACs are recognised by their shape, whatever
// the key (ip's "address" inside a link block is one); serials and uuids by
// their key, and only when the value is a string or a long number, so a
// switch called serialSomething that is 0 or 1 survives.

function itb_is_mac($value)
{
	return is_string($value) && preg_match('/^\s*[0-9A-Fa-f]{2}([:-])(?:[0-9A-Fa-f]{2}\1){4}[0-9A-Fa-f]{2}\s*$/', $value) === 1;
}

function itb_is_identifier_key($key)
{
	return is_string($key) && preg_match('/serial|uuid|cpuid|hwid|machine.?id/i', $key) === 1;
}

function itb_strip_identifiers($value)
{
	if (!is_array($value))
		return $value;

	$isList = array_keys($value) === range(0, count($value) - 1);
	$out = array();
	foreach ($value as $key => $item) {
		if (itb_is_mac($item))
			continue;
		if (itb_is_identifier_key($key) && !is_array($item)
			&& ((is_string($item) && trim($item) !== '') || (is_numeric($item) && strlen((string) $item) >= 6)))
			continue;
		$item = itb_strip_identifiers($item);
		if ($isList)
			$out[] = $item;
		else
			$out[$key] = $item;
	}
	return $out;
}

// ── Identity ────────────────────────────────────────────────────────────────

/**
 * What this player calls itself to the toolbox, chosen once at pairing. A
 * hash of FPP's uuid when it reports one — that survives a hostname change —
 * otherwise of the hostname, so the toolbox can tell this player from the
 * next without ever being given the uuid itself. A player paired by 1.1
 * keeps the id it has (the raw uuid or hostname then): its token was issued
 * for that id, and changing it would orphan the player on the account.
 */
function itb_device_id($info)
{
	$raw = '';
	if (is_array($info)) {
		foreach (array('uuid', 'UUID', 'HostName') as $key) {
			if (!empty($info[$key]) && is_string($info[$key])) {
				$raw = $info[$key];
				break;
			}
		}
	}
	if ($raw === '')
		$raw = gethostname() ?: 'fpp';

	return substr(hash('sha256', 'itb:' . $raw), 0, 16);
}

function itb_hostname($info)
{
	if (is_array($info) && !empty($info['HostName']) && is_string($info['HostName']))
		return $info['HostName'];
	return gethostname() ?: 'fpp';
}

// ── Snapshot ────────────────────────────────────────────────────────────────

/**
 * Which files under the config directory travel. Channel outputs and inputs,
 * the pixel overlay models, the schedule, output processors, GPIO and command
 * presets — the things a person asks about when something is not lighting up.
 *
 * Not: other plugins' settings files (they hold their own secrets, and ours
 * holds the device token), network interface files (Wi-Fi keys), backups,
 * and anything in a subdirectory.
 */
function itb_wanted_config($name)
{
	if (!is_string($name) || $name === '' || strpos($name, '/') !== false || strpos($name, '\\') !== false)
		return false;
	if (preg_match('/^plugin\./', $name) || preg_match('/^interface\./', $name) || preg_match('/\.bak$/i', $name))
		return false;
	return preg_match(
		'/^((co|ci)-[A-Za-z0-9_]+\.json|channeloutputs\.json|model-overlays\.json|schedule\.json|outputProcessors\.json|virtualdisplaymap|channelmemorymaps|gpio\.json|commandPresets\.json|proxies)$/',
		$name) === 1;
}

/**
 * A file list from /api/files/..., which answers {files:[{name, mtime,
 * sizeHuman, ...}]} on current FPP and a bare array on some older builds.
 * Null when the call failed (already noted in $errors).
 */
function itb_file_list($path, &$errors)
{
	$list = itb_local_json($path, $errors);
	if ($list === null)
		return null;
	if (is_array($list) && isset($list['files']) && is_array($list['files']))
		return $list['files'];
	return is_array($list) ? $list : array();
}

function itb_file_name($entry)
{
	if (is_string($entry))
		return $entry;
	if (is_array($entry) && isset($entry['name']) && is_string($entry['name']))
		return $entry['name'];
	return '';
}

/**
 * The files a playlist can point at: sequences, with their size, mtime and
 * (for the first forty) channel count and length; and music, by name. With
 * this the toolbox can say "that sequence is not on the player" instead of
 * guessing, and compare a sequence's channel count with the outputs.
 *
 * Every piece is optional. A call that fails is noted in $errors and its key
 * is left out or left partial; a missing list never stops the snapshot.
 */
function itb_capture_files(&$errors)
{
	$files = array();

	$sequences = itb_file_list('/api/files/sequences', $errors);
	if ($sequences !== null) {
		$kept = array();
		foreach ($sequences as $entry) {
			if (count($kept) >= ITB_MAX_FILES) {
				$errors[] = '/api/files/sequences: only the first ' . ITB_MAX_FILES . ' sequences were captured';
				break;
			}
			$name = itb_file_name($entry);
			if ($name === '')
				continue;
			$item = array('name' => $name);
			if (is_array($entry)) {
				// Bytes when FPP gives them; the human-readable figure is better than nothing.
				if (isset($entry['sizeBytes']) && is_numeric($entry['sizeBytes']))
					$item['size'] = (int) $entry['sizeBytes'];
				elseif (isset($entry['size']) && is_numeric($entry['size']))
					$item['size'] = (int) $entry['size'];
				elseif (isset($entry['sizeHuman']) && is_string($entry['sizeHuman']))
					$item['size'] = $entry['sizeHuman'];
				if (isset($entry['mtime']) && (is_string($entry['mtime']) || is_numeric($entry['mtime'])))
					$item['mtime'] = (string) $entry['mtime'];
			}
			$kept[] = $item;
		}
		$files['sequences'] = $kept;

		// One call per sequence, so only the first forty, and stop early if
		// the endpoint itself is the problem rather than one odd file.
		$meta = array();
		$count = 0;
		$failures = 0;
		foreach ($kept as $item) {
			if (++$count > ITB_MAX_SEQUENCE_META)
				break;
			$m = itb_local_json('/api/sequence/' . rawurlencode($item['name']) . '/meta', $errors);
			if (!is_array($m)) {
				if (++$failures >= 3) {
					$errors[] = '/api/sequence/*/meta: gave up after three failures';
					break;
				}
				continue;
			}
			$meta[$item['name']] = array(
				'channelCount' => isset($m['ChannelCount']) && is_numeric($m['ChannelCount']) ? (int) $m['ChannelCount'] : null,
				'frames' => isset($m['NumFrames']) && is_numeric($m['NumFrames']) ? (int) $m['NumFrames'] : null,
				'stepTime' => isset($m['StepTime']) && is_numeric($m['StepTime']) ? (int) $m['StepTime'] : null,
			);
		}
		$files['sequenceMeta'] = $meta;
	}

	$music = itb_file_list('/api/files/music', $errors);
	if ($music !== null) {
		$names = array();
		foreach ($music as $entry) {
			if (count($names) >= ITB_MAX_FILES) {
				$errors[] = '/api/files/music: only the first ' . ITB_MAX_FILES . ' files were captured';
				break;
			}
			$name = itb_file_name($entry);
			if ($name !== '')
				$names[] = $name;
		}
		$files['music'] = $names;
	}

	return $files;
}

// ── Controllers ─────────────────────────────────────────────────────────────
// "Is the controller even on?" is the first question about a dark prop, and
// the player is the one machine that can answer it from inside the show
// network. Two lists: the FPP systems this player has heard on the network
// (fppd's MultiSync discovery — Falcons, other FPP players, ESPixelSticks),
// and every address this player's E1.31 / ArtNet / DDP outputs send to, each
// pinged once.

/** A dotted IPv4 address a player could ping: not multicast, not broadcast, not 0.0.0.0. */
function itb_unicast_ipv4($address)
{
	if (!is_string($address) || !preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/', trim($address), $m))
		return false;
	for ($i = 1; $i <= 4; $i++) {
		if ((int) $m[$i] > 255)
			return false;
	}
	$first = (int) $m[1];
	$last = (int) $m[4];
	if ($first === 0 || ($first >= 224 && $first <= 239) || $last === 255)
		return false;
	return trim($address);
}

/** FPP's universe type number, in words: what the toolbox shows next to the address. */
function itb_universe_protocol($type)
{
	if (!is_numeric($type))
		return null;
	switch ((int) $type) {
		case 0: case 1: return 'e131';
		case 2: case 3: case 9: return 'artnet';
		case 4: case 5: return 'ddp';
		case 6: case 7: return 'kinet';
	}
	return null;
}

/**
 * The systems fppd has discovered, each cut down to what a person would use
 * to recognise it: name, address, what it is, its firmware and mode, the
 * channels it says it handles, when it was last heard, and whether it is
 * this player. Not its uuid, serial or MAC (see itb_strip_identifiers).
 */
function itb_multisync_systems($answer, &$errors)
{
	$list = is_array($answer) && isset($answer['systems']) && is_array($answer['systems']) ? $answer['systems'] : $answer;
	if (!is_array($list))
		return array();

	$systems = array();
	foreach ($list as $sys) {
		if (!is_array($sys))
			continue;
		if (count($systems) >= ITB_MAX_CONTROLLERS) {
			$errors[] = '/api/fppd/multiSyncSystems: only the first ' . ITB_MAX_CONTROLLERS . ' systems were captured';
			break;
		}
		$text = function ($key) use ($sys) {
			return isset($sys[$key]) && (is_string($sys[$key]) || is_numeric($sys[$key])) ? (string) $sys[$key] : null;
		};
		$systems[] = array(
			'hostname' => $text('hostname'),
			'address' => $text('address'),
			'type' => $text('type'),
			'model' => $text('model'),
			'version' => $text('version'),
			'mode' => $text('fppModeString'),
			'channelRanges' => $text('channelRanges'),
			'lastSeen' => $text('lastSeenStr'),
			'local' => !empty($sys['local']),
		);
	}
	usort($systems, function ($a, $b) {
		return strcmp((string) $a['address'] . ' ' . (string) $a['hostname'], (string) $b['address'] . ' ' . (string) $b['hostname']);
	});
	return $systems;
}

/**
 * One entry per address this player's network outputs send to, from
 * co-universes.json as already captured: the protocols and universe ids
 * that go there, and whether FPP is asked to monitor any of them. Outputs
 * and universes switched off are left out — they send nothing.
 */
function itb_output_targets($universesFile, &$errors)
{
	$targets = array();
	if (!is_array($universesFile) || !isset($universesFile['channelOutputs']) || !is_array($universesFile['channelOutputs']))
		return $targets;

	foreach ($universesFile['channelOutputs'] as $output) {
		if (!is_array($output) || (isset($output['enabled']) && !$output['enabled']))
			continue;
		if (!isset($output['universes']) || !is_array($output['universes']))
			continue;
		foreach ($output['universes'] as $u) {
			if (!is_array($u) || (isset($u['active']) && !$u['active']))
				continue;
			$address = itb_unicast_ipv4(isset($u['address']) ? $u['address'] : '');
			if ($address === false)
				continue;
			if (!isset($targets[$address])) {
				if (count($targets) >= ITB_MAX_CONTROLLERS) {
					$errors[] = 'controllers: only the first ' . ITB_MAX_CONTROLLERS . ' output addresses were checked';
					break 2;
				}
				$targets[$address] = array('address' => $address, 'protocols' => array(), 'universes' => array(), 'monitored' => false);
			}
			$protocol = itb_universe_protocol(isset($u['type']) ? $u['type'] : null);
			if ($protocol !== null && !in_array($protocol, $targets[$address]['protocols'], true))
				$targets[$address]['protocols'][] = $protocol;
			if (isset($u['id']) && is_numeric($u['id']) && !in_array((int) $u['id'], $targets[$address]['universes'], true))
				$targets[$address]['universes'][] = (int) $u['id'];
			if (!empty($u['monitor']))
				$targets[$address]['monitored'] = true;
		}
	}
	ksort($targets, SORT_STRING);
	foreach ($targets as &$t)
		sort($t['universes']);
	unset($t);
	return array_values($targets);
}

/**
 * Pings every address once, all at the same time, in one shell: one second
 * to answer each, so the whole check takes about a second however many
 * controllers there are. Returns address => milliseconds, or false for one
 * that did not answer; null when there is no ping to run.
 *
 * The addresses are dotted IPv4 already (itb_unicast_ipv4), and are quoted
 * anyway. On Linux, -W 1 gives each one second to answer; macOS's -W is in
 * milliseconds and still waits a second more after it, so there -t 1 caps
 * the whole ping at one second instead.
 */
function itb_ping_all($addresses)
{
	if (!$addresses)
		return array();
	$ping = trim((string) @shell_exec('command -v ping 2>/dev/null'));
	if ($ping === '')
		return null;

	$wait = PHP_OS_FAMILY === 'Darwin' ? '-t 1' : '-W 1';
	$script = '';
	foreach ($addresses as $address) {
		$a = escapeshellarg($address);
		$script .= '( out=$(' . escapeshellarg($ping) . ' -c 1 ' . $wait . ' ' . $a . ' 2>/dev/null); rc=$?; '
			. 't=$(printf "%s" "$out" | sed -n "s/.*time[=<] *\([0-9.]*\).*/\1/p" | head -n 1); '
			. 'echo ' . $a . ' "$rc" "$t" ) &' . "\n";
	}
	$script .= "wait\n";

	$out = array();
	exec('/bin/sh -c ' . escapeshellarg($script) . ' 2>/dev/null', $out);

	$results = array_fill_keys($addresses, false);
	foreach ($out as $line) {
		$parts = preg_split('/\s+/', trim($line));
		if (count($parts) < 2 || !array_key_exists($parts[0], $results))
			continue;
		if ($parts[1] === '0')
			$results[$parts[0]] = isset($parts[2]) && is_numeric($parts[2]) ? round((float) $parts[2], 1) : 0.0;
	}
	return $results;
}

/**
 * The controllers block of the snapshot. Every part is optional: a call
 * that fails is noted in $errors, and the rest still goes.
 */
function itb_capture_controllers($universesFile, &$errors)
{
	$answer = itb_local_json('/api/fppd/multiSyncSystems', $errors);
	$systems = $answer === null ? array() : itb_multisync_systems($answer, $errors);

	$targets = itb_output_targets($universesFile, $errors);
	$addresses = array();
	foreach ($targets as $t)
		$addresses[] = $t['address'];

	$checked = itb_now();
	$pings = itb_ping_all($addresses);
	if ($pings === null && $addresses)
		$errors[] = 'controllers: ping is not available on this player, so reachability was not checked';

	foreach ($targets as &$t) {
		if ($pings === null) {
			$t['reachable'] = null;
			$t['ms'] = null;
		} else {
			$ms = $pings[$t['address']];
			$t['reachable'] = $ms !== false;
			$t['ms'] = $ms === false ? null : $ms;
		}
	}
	unset($t);

	return array('systems' => $systems, 'reachability' => $targets, 'checkedUtc' => $checked);
}

/**
 * The controllers block without what moves on its own: when each system was
 * last heard, how long each ping took, and when the check ran. What is left
 * — which systems exist and which controllers answer — is a change worth a
 * send; ping jitter is not.
 */
function itb_controllers_stable($controllers)
{
	if (!is_array($controllers))
		return $controllers;
	unset($controllers['checkedUtc']);
	foreach (array('systems' => 'lastSeen', 'reachability' => 'ms') as $list => $volatile) {
		if (isset($controllers[$list]) && is_array($controllers[$list])) {
			foreach ($controllers[$list] as $i => $entry) {
				if (is_array($entry))
					unset($controllers[$list][$i][$volatile]);
			}
		}
	}
	return $controllers;
}

/**
 * Everything the toolbox should know about this player, as one document.
 *
 * Shape (the toolbox checks only that system.info is present; the tools read
 * the rest each in their own way):
 *
 *   schema, capturedUtc, plugin{name,version}, device{id,hostname}
 *   trigger, changed[], fingerprint   why it was sent and what moved — see itb_prepare()
 *   system{info,status}      /api/system/info, /api/system/status
 *   settings                 /api/settings, without FPP's privacy settings
 *   network                  /api/network/interface, without MAC addresses
 *   cape                     /api/cape (null when there is none)
 *   ports                    /api/fppd/ports (port/eFuse status, where the cape reports it)
 *   outputProcessors         /api/channel/output/processors
 *   schedule                 /api/schedule
 *   playlists{names,items}   /api/playlists and each /api/playlist/:name
 *   plugins                  /api/plugin
 *   files{sequences,sequenceMeta,music}   /api/files/sequences, /api/sequence/:name/meta, /api/files/music
 *   configFileNames          every file /api/configfile lists
 *   configFiles{name:body}   the ones itb_wanted_config() keeps, parsed when they are JSON
 *   controllers{systems[],reachability[],checkedUtc}
 *                            /api/fppd/multiSyncSystems, and a ping of every address in
 *                            co-universes.json — see itb_capture_controllers()
 *   errors[]                 what could not be captured, and why
 *
 * The whole document then loses every hardware identifier
 * (itb_strip_identifiers) and every credential-shaped value (itb_redact).
 */
function itb_build_snapshot()
{
	$errors = array();

	$info = itb_local_json('/api/system/info', $errors);
	$status = itb_local_json('/api/system/status', $errors);
	// Taken out before anything else sees them; see itb_drop_privacy_settings.
	$settings = itb_drop_privacy_settings(itb_local_json('/api/settings', $errors));
	$network = itb_local_json('/api/network/interface', $errors);
	$cape = itb_local_json('/api/cape', $errors);
	$ports = itb_local_json('/api/fppd/ports', $errors);
	$processors = itb_local_json('/api/channel/output/processors', $errors);
	$schedule = itb_local_json('/api/schedule', $errors);
	$plugins = itb_local_json('/api/plugin', $errors);
	$playlistNames = itb_local_json('/api/playlists', $errors);

	$playlists = array();
	if (is_array($playlistNames)) {
		$count = 0;
		foreach ($playlistNames as $name) {
			if (!is_string($name) || $name === '')
				continue;
			if (++$count > 30) {
				$errors[] = '/api/playlist: only the first 30 playlists were captured';
				break;
			}
			$playlist = itb_local_json('/api/playlist/' . rawurlencode($name), $errors);
			if ($playlist !== null)
				$playlists[$name] = $playlist;
		}
	}

	$files = itb_capture_files($errors);

	$names = array();
	$list = itb_local_json('/api/configfile', $errors);
	if (is_array($list)) {
		$entries = $list;
		foreach (array('ConfigFiles', 'configFiles', 'files') as $key) {
			if (isset($list[$key]) && is_array($list[$key])) {
				$entries = $list[$key];
				break;
			}
		}
		foreach ($entries as $entry) {
			if (is_string($entry))
				$names[] = $entry;
			elseif (is_array($entry) && isset($entry['name']) && is_string($entry['name']))
				$names[] = $entry['name'];
		}
	}

	$configFiles = array();
	$budget = ITB_SNAPSHOT_BUDGET_BYTES;
	foreach ($names as $name) {
		if (!itb_wanted_config($name))
			continue;
		list($code, $out, $err) = itb_local('/api/configfile/' . rawurlencode($name));
		if ($code !== 200) {
			$errors[] = 'configfile/' . $name . ': ' . ($code ? 'HTTP ' . $code : ($err ?: 'no answer'));
			continue;
		}
		if (strlen($out) > ITB_MAX_CONFIG_FILE_BYTES) {
			$errors[] = 'configfile/' . $name . ': skipped, larger than 500 KB';
			continue;
		}
		$budget -= strlen($out);
		if ($budget < 0) {
			$errors[] = 'configfile/' . $name . ': skipped, snapshot size budget spent';
			continue;
		}
		$decoded = json_decode($out, true);
		$configFiles[$name] = ($decoded === null && json_last_error() !== JSON_ERROR_NONE) ? $out : $decoded;
	}

	$controllers = itb_capture_controllers(isset($configFiles['co-universes.json']) ? $configFiles['co-universes.json'] : null, $errors);

	$snapshot = array(
		'schema' => ITB_SCHEMA,
		'capturedUtc' => itb_now(),
		'plugin' => array('name' => ITB_PLUGIN, 'version' => ITB_PLUGIN_VERSION),
		'device' => array(
			// The id the player was paired under; before pairing (the preview), the one it would get.
			'id' => itb_setting('deviceId', itb_device_id($info)),
			'hostname' => itb_hostname($info),
		),
		'system' => array('info' => $info, 'status' => $status),
		'settings' => $settings,
		'network' => $network,
		'cape' => $cape,
		'ports' => $ports,
		'outputProcessors' => $processors,
		'schedule' => $schedule,
		'playlists' => array('names' => $playlistNames, 'items' => $playlists),
		'plugins' => $plugins,
		'files' => $files,
		'configFileNames' => $names,
		'configFiles' => $configFiles,
		'controllers' => $controllers,
		'errors' => $errors,
	);

	return itb_redact(itb_strip_identifiers($snapshot));
}

function itb_encode($snapshot)
{
	$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
	if (defined('JSON_INVALID_UTF8_SUBSTITUTE'))
		$flags |= JSON_INVALID_UTF8_SUBSTITUTE;
	return json_encode($snapshot, $flags);
}

// ── Change detection ────────────────────────────────────────────────────────
// The timer runs every five minutes, but a player whose configuration has
// not moved should not send the same two megabytes twelve times an hour. So
// every snapshot gets a fingerprint — a hash of the parts that mean
// something — and a hash per section, kept in the state file after each
// send. The next run compares, and either stays quiet or says which
// sections moved. The toolbox computes its own stable hash as well; these
// two need not agree, only each be consistent with itself.

/** First 16 hex characters of sha256 over the JSON form. Short, but plenty to tell two snapshots apart. */
function itb_hash($value)
{
	$json = itb_encode($value);
	return substr(hash('sha256', $json === false ? '' : $json), 0, 16);
}

function itb_status_warnings($snapshot)
{
	if (isset($snapshot['system']['status']['warnings']) && is_array($snapshot['system']['status']['warnings']))
		return array_values($snapshot['system']['status']['warnings']);
	return array();
}

function itb_status_fppd($snapshot)
{
	if (isset($snapshot['system']['status']['fppd']) && is_string($snapshot['system']['status']['fppd']))
		return $snapshot['system']['status']['fppd'];
	return null;
}

/**
 * The snapshot with the volatile parts removed, hashed. Time of capture, the
 * bookkeeping keys themselves, the live status block (uptime, sensors, what
 * is playing), port and eFuse readings and the CPU/memory figures all move
 * from one minute to the next without anyone touching the player. The
 * warnings and whether fppd is running are the two live facts that are worth
 * a send, so they come back in under their own keys. The controllers block
 * goes in without its timings (itb_controllers_stable).
 */
function itb_fingerprint($snapshot)
{
	$stable = $snapshot;
	foreach (array('capturedUtc', 'trigger', 'changed', 'fingerprint', 'ports') as $key)
		unset($stable[$key]);
	if (isset($stable['system']) && is_array($stable['system'])) {
		unset($stable['system']['status']);
		if (isset($stable['system']['info']) && is_array($stable['system']['info']))
			unset($stable['system']['info']['Utilization']);
	}
	if (isset($stable['controllers']))
		$stable['controllers'] = itb_controllers_stable($stable['controllers']);
	$stable['_warnings'] = itb_status_warnings($snapshot);
	$stable['_fppd'] = itb_status_fppd($snapshot);
	return itb_hash($stable);
}

/**
 * One hash per section the toolbox digests, so "changed" can name what moved
 * rather than only that something did. Each config file is its own section,
 * named configFiles.<file>, because "co-universes.json changed" is the
 * answer a person actually wants.
 */
function itb_section_hashes($snapshot)
{
	$sections = array();
	foreach (array('settings', 'network', 'cape', 'outputProcessors', 'schedule', 'playlists', 'plugins') as $key)
		$sections[$key] = itb_hash(isset($snapshot[$key]) ? $snapshot[$key] : null);
	$sections['warnings'] = itb_hash(itb_status_warnings($snapshot));
	$sections['fppd'] = itb_hash(itb_status_fppd($snapshot));
	$sections['files'] = itb_hash(isset($snapshot['files']) ? $snapshot['files'] : null);
	$sections['controllers'] = itb_hash(itb_controllers_stable(isset($snapshot['controllers']) ? $snapshot['controllers'] : null));
	if (isset($snapshot['configFiles']) && is_array($snapshot['configFiles'])) {
		foreach ($snapshot['configFiles'] as $name => $body)
			$sections['configFiles.' . $name] = itb_hash($body);
	}
	return $sections;
}

/**
 * Which sections differ from the last snapshot sent. Empty when nothing was
 * sent before: there is nothing to compare against, and the toolbox treats
 * a first snapshot as new anyway. A config file that disappeared counts as
 * changed too.
 */
function itb_changed_sections($sections, $state)
{
	if (!isset($state['sections']) || !is_array($state['sections']))
		return array();
	$previous = $state['sections'];
	$changed = array();
	foreach ($sections as $name => $hash) {
		if (!isset($previous[$name]) || $previous[$name] !== $hash)
			$changed[] = $name;
	}
	foreach ($previous as $name => $hash) {
		if (!isset($sections[$name]))
			$changed[] = $name;
	}
	return $changed;
}

/**
 * A snapshot with its bookkeeping filled in: why it is being sent, which
 * sections moved since the last send, and its fingerprint. The three keys
 * sit just after capturedUtc so a person reading the preview sees them
 * first. Returns array(snapshot, sectionHashes, state) — the hashes go into
 * the state file once the send succeeds, and the caller may want the old
 * state to decide whether to send at all.
 */
function itb_prepare($trigger)
{
	$raw = itb_build_snapshot();
	$state = itb_read_state();
	$sections = itb_section_hashes($raw);
	$fingerprint = itb_fingerprint($raw);
	$changed = itb_changed_sections($sections, $state);

	$snapshot = array();
	foreach ($raw as $key => $value) {
		$snapshot[$key] = $value;
		if ($key === 'capturedUtc') {
			$snapshot['trigger'] = $trigger;
			$snapshot['changed'] = $changed;
			$snapshot['fingerprint'] = $fingerprint;
		}
	}
	return array($snapshot, $sections, $state);
}

// ── Talking to the toolbox ──────────────────────────────────────────────────

function itb_toolbox_error($status, $out, $err, $fallback)
{
	if ($status === 0)
		return 'Could not reach the toolbox: ' . ($err ?: 'no answer');
	$body = json_decode($out, true);
	if (is_array($body) && !empty($body['message']) && is_string($body['message']))
		return $body['message'];
	return $fallback . ' (HTTP ' . $status . ')';
}

/**
 * Trades a pairing code for a device token and remembers it. Whatever the
 * plugin remembered about a previous link — last send, last request, what
 * was last sent — is cleared, since it was about another account.
 *
 * The toolbox address is the one in the settings file (the default unless
 * someone running their own toolbox edited it there by hand); nothing a
 * request carries can change where the token goes.
 */
function itb_pair($code)
{
	$api = itb_api_base();
	if (!preg_match('#^https?://[^\s/]+#', $api))
		return array('ok' => false, 'error' => 'The toolbox address must start with https://.');

	$code = trim((string) $code);
	if ($code === '')
		return array('ok' => false, 'error' => 'Enter the code shown in the toolbox.');

	$errors = array();
	$info = itb_local_json('/api/system/info', $errors);
	// Pairing again while still linked keeps the id the player already has,
	// so it stays the same player on the account.
	$deviceId = itb_token() !== '' && itb_setting('deviceId') !== '' ? itb_setting('deviceId') : itb_device_id($info);
	$hostname = itb_hostname($info);

	$body = itb_encode(array('code' => $code, 'deviceId' => $deviceId, 'hostname' => $hostname));
	list($status, $out, $err) = itb_http('POST', $api . '/api/fpp/pair/claim', $body,
		array('Content-Type: application/json'), 30);

	if ($status !== 200) {
		$message = itb_toolbox_error($status, $out, $err, 'The toolbox refused the code');
		itb_log('pairing failed: ' . $message);
		return array('ok' => false, 'error' => $message);
	}

	$data = json_decode($out, true);
	if (!is_array($data) || empty($data['token']) || !is_string($data['token']) || empty($data['username'])) {
		itb_log('pairing failed: the toolbox answered without a token');
		return array('ok' => false, 'error' => 'The toolbox answered, but not with a token. Try again.');
	}
	if (!itb_set_token($data['token'])) {
		itb_log('pairing failed: could not write ' . itb_data_dir() . '/token');
		return array('ok' => false, 'error' => 'Paired, but this player could not store its token in ' . itb_data_dir() . '. Try again.');
	}

	itb_set('apiBaseUrl', $api);
	if (itb_setting('deviceToken') !== '')
		itb_set('deviceToken', '');
	itb_set('deviceId', $deviceId);
	itb_set('username', $data['username']);
	itb_set('tokenExpiresUtc', isset($data['expiresUtc']) ? $data['expiresUtc'] : '');
	foreach (array('lastSyncUtc', 'lastSyncResult', 'lastSyncError', 'lastTrigger', 'lastCommandUtc', 'lastCommandType', 'lastCommandResult') as $key)
		itb_set($key, '');
	itb_clear_state();
	itb_log('paired with @' . $data['username'] . ' as ' . $deviceId);

	return array('ok' => true, 'username' => $data['username'], 'deviceId' => $deviceId, 'hostname' => $hostname);
}

/** Forgets the token and what was last sent. What the toolbox already holds stays until removed there. */
function itb_unlink()
{
	itb_forget_token();
	foreach (array('deviceId', 'username', 'tokenExpiresUtc', 'lastSyncUtc', 'lastSyncResult', 'lastSyncError',
		'lastTrigger', 'lastCommandUtc', 'lastCommandType', 'lastCommandResult') as $key)
		itb_set($key, '');
	itb_clear_state();
	itb_log('unlinked; the device token was deleted');
	return array('ok' => true);
}

/**
 * Builds a snapshot and sends it, unless there is no reason to.
 *
 * $auto is true when the timer or fppd's start hook asked, in which case the
 * "Send automatically" switch is honoured; a person pressing the button, the
 * pairing step and a request from the toolbox always send.
 *
 * $trigger says why: timer, start, manual, pair or request. The timer is
 * the special case. It runs every five minutes, and when the fingerprint
 * matches the last snapshot sent and that was under an hour ago, nothing
 * goes out and nothing is recorded — the five-minute check found nothing
 * new. When the fingerprint differs, the send is reported as a "change" so
 * the toolbox keeps the previous snapshot as history.
 */
function itb_sync($auto = false, $trigger = 'manual')
{
	if ($auto && itb_setting('autoSync', '1') !== '1') {
		itb_log_quietly('sync-off', 'not sending (' . $trigger . '): Send automatically is off');
		return array('ok' => false, 'skipped' => true, 'error' => 'Auto-sync is off.');
	}

	$token = itb_token();
	$deviceId = itb_setting('deviceId');
	if ($token === '' || $deviceId === '') {
		itb_log_quietly('sync-unlinked', 'not sending (' . $trigger . '): this player is not linked');
		return array('ok' => false, 'error' => 'This player is not linked to a toolbox account yet.');
	}

	list($snapshot, $sections, $state) = itb_prepare($trigger);

	if ($trigger === 'timer') {
		$sameAsLast = isset($state['fingerprint']) && $state['fingerprint'] === $snapshot['fingerprint'];
		if ($sameAsLast) {
			$sentAt = isset($state['sentUtc']) && is_string($state['sentUtc']) ? strtotime($state['sentUtc']) : false;
			if ($sentAt !== false && time() - $sentAt < ITB_RESEND_AFTER_SECONDS) {
				itb_log_quietly('sync-unchanged', 'not sending (timer): nothing changed since the last send');
				return array('ok' => true, 'skipped' => true, 'reason' => 'unchanged', 'fingerprint' => $snapshot['fingerprint']);
			}
		} else {
			$snapshot['trigger'] = 'change';
		}
	}
	$trigger = $snapshot['trigger'];

	$json = itb_encode($snapshot);
	if ($json === false) {
		itb_log('send (' . $trigger . ') failed: could not encode the snapshot: ' . json_last_error_msg());
		return itb_record_sync(false, 'Could not encode the snapshot: ' . json_last_error_msg(), null, $trigger);
	}

	list($status, $out, $err) = itb_http('PUT', itb_api_base() . '/api/fpp/devices/' . rawurlencode($deviceId), $json,
		array('Content-Type: application/json', 'Authorization: Bearer ' . $token), 90);

	if ($status === 200) {
		$entry = json_decode($out, true);
		$sentUtc = itb_now();
		$stateSaved = itb_write_state(array(
			'fingerprint' => $snapshot['fingerprint'],
			'sections' => $sections,
			'sentUtc' => $sentUtc,
		));
		itb_record_sync(true, '', null, $trigger);
		itb_log('sent (' . $trigger . '), ' . strlen($json) . ' bytes' . ($snapshot['changed'] ? ', changed: ' . implode(', ', $snapshot['changed']) : ''));
		return array(
			'ok' => true,
			'sentUtc' => $sentUtc,
			'trigger' => $trigger,
			'changed' => $snapshot['changed'],
			'fingerprint' => $snapshot['fingerprint'],
			'bytes' => strlen($json),
			'stateSaved' => $stateSaved,
			'entry' => is_array($entry) ? $entry : null,
			'captureErrors' => $snapshot['errors'],
		);
	}

	if ($status === 401)
		$message = 'The toolbox no longer accepts this player\'s token. Pair again.';
	else
		$message = itb_toolbox_error($status, $out, $err, 'The toolbox refused the snapshot');

	itb_log('send (' . $trigger . ') failed, ' . strlen($json) . ' bytes: ' . $message);
	return itb_record_sync(false, $message, $status, $trigger);
}

function itb_record_sync($ok, $message, $status = null, $trigger = null)
{
	itb_set('lastSyncUtc', itb_now());
	itb_set('lastSyncResult', $ok ? 'ok' : 'error');
	itb_set('lastSyncError', $ok ? '' : substr($message, 0, 300));
	if ($trigger !== null)
		itb_set('lastTrigger', $trigger);
	return $ok
		? array('ok' => true)
		: array('ok' => false, 'error' => $message, 'httpStatus' => $status);
}

// ── Requests from the toolbox ───────────────────────────────────────────────
// The toolbox cannot reach a player behind someone's router, so the player
// asks. scripts/poll.sh calls itb_poll() in a loop; each call holds one
// request open at the toolbox for up to twenty seconds, which is how a
// "Request snapshot" button on the website turns into a send here within
// seconds without the toolbox ever opening a connection inward. The token
// that uploads snapshots is the same one that claims requests; the toolbox
// only hands a player its own.

function itb_commands_url($deviceId, $suffix)
{
	return itb_api_base() . '/api/fpp/devices/' . rawurlencode($deviceId) . '/commands' . $suffix;
}

/**
 * One round: claim whatever is waiting, run each request, report each
 * result. Skipped — and poll.sh backs off — when the player is not linked,
 * the switch is off, or the token is no longer accepted.
 */
function itb_poll()
{
	// The claim waits up to twenty seconds and a snapshot request can take
	// a minute to upload; neither should be cut short by the web server's
	// script limit.
	@set_time_limit(0);

	$token = itb_token();
	$deviceId = itb_setting('deviceId');
	if ($token === '' || $deviceId === '')
		return array('ok' => false, 'skipped' => true, 'error' => 'This player is not linked to a toolbox account yet.');
	if (itb_setting('allowRemote', '1') !== '1')
		return array('ok' => false, 'skipped' => true, 'error' => 'Remote requests are off.');

	$auth = array('Content-Type: application/json', 'Authorization: Bearer ' . $token);
	// An empty string rather than null, so curl sends a proper POST with Content-Length: 0.
	list($status, $out, $err) = itb_http('POST', itb_commands_url($deviceId, '/claim?wait=20'), '', $auth, 35);

	if ($status === 401) {
		$message = 'The toolbox no longer accepts this player\'s token. Pair again.';
		itb_record_command('claim', false, $message);
		itb_log_quietly('poll-401', 'listening for requests: ' . $message);
		return array('ok' => false, 'skipped' => true, 'error' => $message, 'httpStatus' => 401);
	}
	if ($status !== 200) {
		$message = itb_toolbox_error($status, $out, $err, 'The toolbox did not answer the poll');
		itb_log_quietly('poll-error', 'listening for requests: ' . $message);
		return array('ok' => false, 'error' => $message, 'httpStatus' => $status);
	}

	$claim = json_decode($out, true);
	$commands = is_array($claim) && isset($claim['commands']) && is_array($claim['commands']) ? $claim['commands'] : array();

	$ran = 0;
	foreach ($commands as $cmd) {
		if (!is_array($cmd) || !isset($cmd['id']) || !is_string($cmd['id']) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $cmd['id']))
			continue;
		$type = isset($cmd['type']) && is_string($cmd['type']) ? $cmd['type'] : '';
		itb_log('request ' . $cmd['id'] . ': ' . ($type !== '' ? $type : '(no type)'));
		$result = itb_run_command($cmd);
		itb_record_command($type, $result['ok'], $result['message']);
		itb_log('request ' . $cmd['id'] . ' ' . ($result['ok'] ? 'done' : 'failed') . ': ' . $result['message']);
		itb_http('POST', itb_commands_url($deviceId, '/' . rawurlencode($cmd['id']) . '/result'),
			itb_encode(array('ok' => $result['ok'], 'message' => $result['message'])), $auth, 30);
		$ran++;
	}

	return array('ok' => true, 'ran' => $ran);
}

/** What the status page shows under "Last request". */
function itb_record_command($type, $ok, $message)
{
	itb_set('lastCommandUtc', itb_now());
	itb_set('lastCommandType', $type);
	itb_set('lastCommandResult', ($ok ? '' : 'failed: ') . substr($message, 0, 300));
}

/**
 * Does one request and says how it went. Always answers with ok and message;
 * never throws, since a bad request from the toolbox should be reported
 * back, not stall the loop.
 */
function itb_run_command($cmd)
{
	$type = isset($cmd['type']) && is_string($cmd['type']) ? $cmd['type'] : '';
	$args = isset($cmd['args']) && is_array($cmd['args']) ? $cmd['args'] : array();

	switch ($type) {
		case 'snapshot':
			$sync = itb_sync(false, 'request');
			if (!empty($sync['ok']) && empty($sync['skipped']))
				return array('ok' => true, 'message' => 'Snapshot sent (' . $sync['bytes'] . ' bytes)');
			return array('ok' => false, 'message' => isset($sync['error']) ? $sync['error'] : 'The snapshot was not sent.');

		case 'restart_fppd':
			return itb_request_restart();

		case 'test_lights':
			return itb_test_lights($args);

		case 'stop_test':
			list($status, $out, $err) = itb_local_post('/api/testmode', array('enabled' => 0));
			if ($status === 200)
				return array('ok' => true, 'message' => 'Test stopped');
			return array('ok' => false, 'message' => 'Could not stop the test: ' . ($status ? 'HTTP ' . $status : ($err ?: 'no answer')));

		default:
			return array('ok' => false, 'message' => 'unknown command' . ($type !== '' ? ': ' . $type : ''));
	}
}

/**
 * Asks FPP for an fppd restart the way the plugin guidelines require (§3.6,
 * §4.1): by setting FPP's restart flag through its settings API, never by
 * restarting fppd itself, which would cut off a show mid-sequence. FPP then
 * shows its "FPPD Restart Required" banner on its pages, and fppd restarts
 * when someone presses Restart FPPD there, or when the player next boots.
 */
function itb_request_restart()
{
	list($status, $out, $err) = itb_http('PUT', ITB_LOCAL_API . '/api/settings/restartFlag', '1', array('Content-Type: text/plain'));
	if ($status === 200)
		return array('ok' => true, 'message' => 'Restart flagged: FPP now shows "FPPD Restart Required", and fppd restarts when someone presses Restart FPPD on the player or it next boots');
	return array('ok' => false, 'message' => 'Could not flag fppd for a restart: ' . ($status ? 'HTTP ' . $status : ($err ?: 'no answer')));
}

/**
 * Runs FPP's own test mode — the same RGB chase the Testing page offers —
 * over a channel range, and arranges for it to stop by itself. The stop is
 * a detached shell so this request can be answered straight away and the
 * lights still go out if the poll loop is restarted meanwhile.
 *
 * Only the chase is implemented; FPP's fill and single-colour test modes
 * take different JSON and are declined rather than guessed at.
 */
function itb_test_lights($args)
{
	$start = isset($args['startChannel']) && is_numeric($args['startChannel']) ? (int) $args['startChannel'] : 0;
	$count = isset($args['channelCount']) && is_numeric($args['channelCount']) ? (int) $args['channelCount'] : 0;
	$seconds = isset($args['seconds']) && is_numeric($args['seconds']) ? (int) $args['seconds'] : 60;
	$pattern = isset($args['pattern']) && is_string($args['pattern']) && $args['pattern'] !== '' ? $args['pattern'] : 'rgb_chase';

	if ($start < 1 || $count < 1)
		return array('ok' => false, 'message' => 'test_lights needs a startChannel and channelCount of 1 or more');
	if ($pattern !== 'rgb_chase')
		return array('ok' => false, 'message' => 'pattern "' . $pattern . '" is not supported by this plugin version; use rgb_chase');
	$seconds = max(5, min(600, $seconds));
	$end = $start + $count - 1;

	list($status, $out, $err) = itb_local_post('/api/testmode', array(
		'enabled' => 1,
		'cycleMS' => 1000,
		'channelSet' => $start . '-' . $end,
		'channelSetType' => 'channelRange',
		'mode' => 'RGBChase',
		'subMode' => 'RGBChase-RGB',
		'colorPattern' => 'FF000000FF000000FF',
	));
	if ($status !== 200)
		return array('ok' => false, 'message' => 'Could not start the test: ' . ($status ? 'HTTP ' . $status : ($err ?: 'no answer')));

	$stop = 'sleep ' . $seconds . '; curl -s -m 10 -X POST -H \'Content-Type: application/json\' -d \'{"enabled":0}\' ' . ITB_LOCAL_API . '/api/testmode';
	exec('nohup bash -c ' . escapeshellarg($stop) . ' >/dev/null 2>&1 &');

	return array('ok' => true, 'message' => 'Test running on channels ' . $start . '–' . $end . ' for ' . $seconds . ' s');
}

// ── Status ──────────────────────────────────────────────────────────────────

/**
 * What the status page shows. With $checkLink, also asks the toolbox whether
 * the link still stands, which is the honest answer to "is this connected"
 * rather than "is there a token in the file".
 */
function itb_status($checkLink = false)
{
	$token = itb_token();
	$deviceId = itb_setting('deviceId');
	$status = array(
		'pluginVersion' => ITB_PLUGIN_VERSION,
		'apiBaseUrl' => itb_api_base(),
		'linked' => $token !== '' && $deviceId !== '',
		'username' => itb_setting('username'),
		'deviceId' => $deviceId,
		'hostname' => gethostname() ?: '',
		'tokenExpiresUtc' => itb_setting('tokenExpiresUtc'),
		'lastSyncUtc' => itb_setting('lastSyncUtc'),
		'lastSyncResult' => itb_setting('lastSyncResult'),
		'lastSyncError' => itb_setting('lastSyncError'),
		'lastTrigger' => itb_setting('lastTrigger'),
		'autoSync' => itb_setting('autoSync', '1') === '1',
		'allowRemote' => itb_setting('allowRemote', '1') === '1',
		'lastCommandUtc' => itb_setting('lastCommandUtc'),
		'lastCommandType' => itb_setting('lastCommandType'),
		'lastCommandResult' => itb_setting('lastCommandResult'),
	);

	if ($checkLink && $status['linked']) {
		list($code, $out, $err) = itb_http('GET', itb_api_base() . '/api/fpp/devices/' . rawurlencode($deviceId) . '/link', null,
			array('Authorization: Bearer ' . $token), 15);
		$link = array('ok' => $code === 200, 'httpStatus' => $code);
		if ($code === 200) {
			$data = json_decode($out, true);
			$link['username'] = is_array($data) && isset($data['username']) ? $data['username'] : null;
			$link['lastReceivedUtc'] = is_array($data) && isset($data['lastReceivedUtc']) ? $data['lastReceivedUtc'] : null;
		} else {
			$link['error'] = $code === 401
				? 'The toolbox no longer accepts this player\'s token. Pair again.'
				: itb_toolbox_error($code, $out, $err, 'The toolbox did not confirm the link');
		}
		$status['link'] = $link;
	}

	return $status;
}
?>
