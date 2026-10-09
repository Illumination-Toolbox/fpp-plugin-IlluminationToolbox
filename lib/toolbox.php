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
 *      something — a fresh snapshot, a light test, an fppd restart, a
 *      failover switch — do it, and report back.
 *   5. Relay. When the Player Failover plugin is installed, pass its status
 *      on to the toolbox every fifteen seconds, so Control Booth can show
 *      which player has the show.
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
	define('ITB_PLUGIN_VERSION', '1.6.1');
	/** The Illumination Toolbox Terms of Service (dated) a person agrees to when linking. */
	define('ITB_TERMS_VERSION', '2026-10-04');
	define('ITB_TERMS_URL', 'https://www.illuminationtoolbox.com/terms');
	define('ITB_PRIVACY_URL', 'https://www.illuminationtoolbox.com/privacy');
	/** A token older than this is swapped for a fresh one, so a linked player never reaches the end of its year. */
	define('ITB_RENEW_AFTER_SECONDS', 30 * 86400);
	/** After a renewal that did not work, wait this long before trying again. */
	define('ITB_RENEW_RETRY_SECONDS', 6 * 3600);
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
	/** scheduleUpcoming: how far ahead it looks, and at most how many playlists it lists. */
	define('ITB_UPCOMING_DAYS', 9);
	define('ITB_MAX_UPCOMING', 50);
	/** A skip, or a poll that could not reach the toolbox, is logged at most this often per reason. */
	define('ITB_LOG_QUIET_SECONDS', 3600);
	/** The Player Failover plugin's API on this player. The ?action= form works on every FPP 10 build; subpaths 404 on 10.0–10.1. */
	define('ITB_FAILOVER_API', '/api/plugin-apis/failover');
	/** Player Failover status goes to the toolbox at most this often, unless the role or state changed. */
	define('ITB_FAILOVER_EVERY_SECONDS', 15);
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

/** When the token was issued (its JWT iat), or 0 when that can't be read. Not verified: it's our own token. */
function itb_token_issued_at($token)
{
	$parts = explode('.', (string) $token);
	if (count($parts) !== 3)
		return 0;
	$payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', (4 - strlen($parts[1]) % 4) % 4)), true);
	return is_array($payload) && isset($payload['iat']) && is_numeric($payload['iat']) ? (int) $payload['iat'] : 0;
}

/**
 * Swaps the device token for a fresh one once it is a month old, so a linked
 * player keeps working past the token's year without anyone pairing it
 * again. Called before every poll and send; does nothing most of the time. A
 * token the toolbox no longer accepts (removed, signed out) can't be renewed,
 * which is the point: renewal never undoes a revocation.
 */
function itb_maybe_renew_token()
{
	$token = itb_token();
	$deviceId = itb_setting('deviceId');
	if ($token === '' || $deviceId === '')
		return;
	$issued = itb_token_issued_at($token);
	if ($issued > 0 && time() - $issued < ITB_RENEW_AFTER_SECONDS)
		return;
	$lastTry = (int) itb_setting('renewAttemptUnix', '0');
	if ($lastTry > 0 && time() - $lastTry < ITB_RENEW_RETRY_SECONDS)
		return;
	itb_set('renewAttemptUnix', (string) time());

	list($status, $out, $err) = itb_http('POST', itb_api_base() . '/api/fpp/devices/' . rawurlencode($deviceId) . '/token', '',
		array('Content-Type: application/json', 'Authorization: Bearer ' . $token), 20);
	$data = $status === 200 ? json_decode($out, true) : null;
	if (!is_array($data) || empty($data['token']) || !is_string($data['token'])) {
		itb_log_quietly('renew', 'renewing the device token: ' . itb_toolbox_error($status, $out, $err, 'the toolbox did not renew it'));
		return;
	}
	if (!itb_set_token($data['token'])) {
		itb_log('renewing the device token: could not write ' . itb_data_dir() . '/token');
		return;
	}
	itb_set('tokenExpiresUtc', isset($data['expiresUtc']) && is_string($data['expiresUtc']) ? $data['expiresUtc'] : '');
	itb_set('renewAttemptUnix', '');
	itb_log('device token renewed' . (isset($data['expiresUtc']) ? '; good until ' . $data['expiresUtc'] : ''));
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
 * (guidelines §14.9). Not the plugin's business, so itb_capture_settings()
 * skips them by name and never reads their values.
 */
function itb_privacy_setting_keys()
{
	return array('statsPublish', 'statsPublishUrl', 'ShareCrashData', 'FetchVendorLogos',
		'SendVendorSerial', 'SendVendorLogos', 'privacyConsent', 'LegalJurisdiction');
}

/**
 * The names of FPP's own settings that hold a credential: the UI password,
 * the OS password, the mail and MQTT logins, the tether PSK, the remote
 * token. Matched case-exactly, as FPP stores them, in addition to every
 * credential-shaped name itb_is_secret_key() catches.
 */
function itb_core_credential_setting_keys()
{
	return array('password', 'osPassword', 'emailpass', 'emailuser', 'MQTTPassword', 'MQTTUsername',
		'TetherPSK', 'remoteToken');
}

/**
 * FPP's email-alert settings that hold someone's address or mail login. Not
 * credentials, but personal, and nothing the toolbox needs to see; whether
 * email is on and which port it uses are kept.
 */
function itb_personal_setting_keys()
{
	return array('emailAddress', 'emailguser', 'emailtoemail', 'emailfromtext', 'emailfromuser');
}

/**
 * The player's settings, with their values.
 *
 * FPP 10's /api/settings answers with what each setting is — its label,
 * type and default — but not what it is set to, so a snapshot built from it
 * told the toolbox nothing about this player. The values are in FPP's own
 * $settings, which every page and API call has loaded (guidelines §3.1).
 *
 * Reading that array whole would read FPP's credentials along with
 * everything else, so it is filtered by NAME before any value is looked at:
 * a credential, anything whose name looks like one, FPP's eight privacy
 * settings and its email-alert addresses are skipped without their values
 * ever being read. What
 * is left is plain switches, numbers and short strings; anything else
 * (arrays, long text) is left out rather than guessed at.
 */
function itb_capture_settings(&$errors)
{
	$all = isset($GLOBALS['settings']) && is_array($GLOBALS['settings']) ? $GLOBALS['settings'] : null;
	if ($all === null) {
		$errors[] = 'settings: FPP\'s settings were not loaded in this request';
		return null;
	}

	$skip = array_merge(itb_core_credential_setting_keys(), itb_privacy_setting_keys(), itb_personal_setting_keys());
	$out = array();
	foreach (array_keys($all) as $key) {
		if (!is_string($key) || $key === '' || in_array($key, $skip, true) || itb_is_secret_key($key))
			continue;
		$value = $all[$key];
		if (is_bool($value) || is_int($value) || is_float($value))
			$out[$key] = $value;
		elseif (is_string($value) && strlen($value) <= 200 && !preg_match('/[^\s@]+@[^\s@]+\.[^\s@]+/', $value))
			// An email address under any other name is left out too.
			$out[$key] = $value;
	}
	ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
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

// ── Storage, what is playing, what is coming up ─────────────────────────────
// Three small blocks the xLights AI reads directly — "is the SD card full?",
// "what is it playing right now?", "will the show start tonight?" — each
// built from an answer FPP already gives and cut down to a fixed shape. A
// value FPP does not give is null, or the whole block is left out; nothing
// here is guessed.

/** A byte count as an integer, or null. FPP's disk figures come from PHP's disk_*_space, which answers floats. */
function itb_bytes($value)
{
	return is_numeric($value) && $value >= 0 ? (int) $value : null;
}

/**
 * system.storage: the root filesystem and the one holding FPP's media
 * folder, which on a player booting from SD with a USB stick for media are
 * two different disks.
 *
 * Source: /api/system/info's Utilization.Disk.{Root,Media}.{Free,Total}
 * (FPP 10's GetSystemInfoJsonInternal in www/common.php, which measures
 * Media at the uploads folder under the media directory). Where FPP does not
 * report a figure, PHP's own disk_free_space / disk_total_space on "/" and on
 * the media folder stand in — the same calls FPP makes. Left out (null)
 * when there is no root figure either way.
 */
function itb_capture_storage($info)
{
	$disk = is_array($info) && isset($info['Utilization']['Disk']) && is_array($info['Utilization']['Disk'])
		? $info['Utilization']['Disk'] : array();
	$figure = function ($name, $field) use ($disk) {
		return isset($disk[$name][$field]) ? itb_bytes($disk[$name][$field]) : null;
	};

	$rootFree = $figure('Root', 'Free');
	$rootTotal = $figure('Root', 'Total');
	if ($rootFree === null || $rootTotal === null) {
		$rootFree = itb_bytes(@disk_free_space('/'));
		$rootTotal = itb_bytes(@disk_total_space('/'));
	}
	if ($rootFree === null || $rootTotal === null || $rootTotal <= 0)
		return null;

	$mediaPath = itb_media_dir();
	$mediaFree = $figure('Media', 'Free');
	$mediaTotal = $figure('Media', 'Total');
	if (($mediaFree === null || $mediaTotal === null) && is_dir($mediaPath)) {
		$mediaFree = itb_bytes(@disk_free_space($mediaPath));
		$mediaTotal = itb_bytes(@disk_total_space($mediaPath));
	}
	if ($mediaFree === null || $mediaTotal === null || $mediaTotal <= 0) {
		$mediaFree = null;
		$mediaTotal = null;
		$mediaPath = null;
	}

	return array(
		'rootUsedPct' => round(($rootTotal - min($rootFree, $rootTotal)) * 100 / $rootTotal, 1),
		'freeBytes' => $rootFree,
		'totalBytes' => $rootTotal,
		'mediaFreeBytes' => $mediaFree,
		'mediaTotalBytes' => $mediaTotal,
		'mediaPath' => $mediaPath,
	);
}

/** A non-empty string from an FPP answer, or null. */
function itb_text_or_null($value)
{
	if (!is_string($value) && !is_numeric($value))
		return null;
	$value = trim((string) $value);
	return $value === '' ? null : $value;
}

/** A whole number of seconds from an FPP answer (which gives them as strings), or null. */
function itb_seconds_or_null($value)
{
	return is_numeric($value) && $value >= 0 ? (int) $value : null;
}

/**
 * nowPlaying: what the player was doing at capturedUtc. The snapshot is
 * periodic — a send every five minutes at best — so elapsed and remaining
 * are as of capturedUtc, not as of whenever someone reads them.
 *
 * Source: /api/system/status, which is fppd's /fppd/status (FPP 10's
 * GetCurrentFPPDStatus in src/httpAPI.cpp) plus PHP's additions:
 *   status_name        idle | playing | paused | testing | stopping gracefully
 *                      | stopping gracefully after loop | stopping now
 *                      | unknown, or stopped / updating when fppd is not running
 *   current_playlist.playlist, current_sequence, current_song
 *   seconds_played, seconds_remaining   numbers as strings
 *   repeat_mode        the playlist's repeat flag, "0" when idle
 *   scheduler.status   playing (started by the schedule) | manual | idle
 * In remote mode there is no playlist, only the sequence and media being
 * followed. Anything not there is null; a status FPP did not answer at all
 * leaves the whole block out.
 */
function itb_capture_now_playing($status, $capturedUtc)
{
	if (!is_array($status))
		return null;

	$name = isset($status['status_name']) && is_string($status['status_name']) ? strtolower(trim($status['status_name'])) : '';
	if ($name === 'idle' || $name === 'playing' || $name === 'paused' || $name === 'testing')
		$state = $name;
	elseif (strpos($name, 'stopping') === 0)
		$state = 'stopping';
	else
		// Includes fppd not running ("stopped", "updating"): nothing is
		// playing, but the player is not idle in any useful sense either.
		$state = 'unknown';

	$active = $state === 'playing' || $state === 'paused' || $state === 'stopping';

	$playlist = isset($status['current_playlist']['playlist']) ? itb_text_or_null($status['current_playlist']['playlist']) : null;
	$sequence = isset($status['current_sequence']) ? itb_text_or_null($status['current_sequence']) : null;
	// fppd names the pause FPP inserts between sequences as if it were one.
	if (!empty($status['global_pause']['active']) || $sequence === 'Global Pause')
		$sequence = null;
	$media = isset($status['current_song']) ? itb_text_or_null($status['current_song']) : null;

	$elapsed = null;
	$remaining = null;
	if ($active) {
		$elapsed = itb_seconds_or_null(isset($status['seconds_played']) ? $status['seconds_played']
			: (isset($status['seconds_elapsed']) ? $status['seconds_elapsed'] : null));
		$remaining = itb_seconds_or_null(isset($status['seconds_remaining']) ? $status['seconds_remaining'] : null);
	}

	$scheduled = null;
	if ($active && isset($status['scheduler']['status']) && is_string($status['scheduler']['status'])) {
		if ($status['scheduler']['status'] === 'playing')
			$scheduled = true;
		elseif ($status['scheduler']['status'] === 'manual')
			$scheduled = false;
	}

	$repeat = null;
	if ($active && $playlist !== null && isset($status['repeat_mode']) && (is_numeric($status['repeat_mode']) || is_bool($status['repeat_mode'])))
		$repeat = (int) $status['repeat_mode'] !== 0;

	return array(
		'status' => $state,
		'playlist' => $active ? $playlist : null,
		'sequence' => $active ? $sequence : null,
		'media' => $active ? $media : null,
		'elapsedSec' => $elapsed,
		'remainingSec' => $remaining,
		'scheduled' => $scheduled,
		'repeat' => $repeat,
		'capturedUtc' => $capturedUtc,
	);
}

/**
 * nowPlaying without what moves while a show runs: the clock, and the song.
 * A playlist starting or stopping is worth a send; the next sequence in it
 * coming up every three minutes is not.
 */
function itb_now_playing_stable($nowPlaying)
{
	if (!is_array($nowPlaying))
		return $nowPlaying;
	foreach (array('elapsedSec', 'remainingSec', 'capturedUtc', 'sequence', 'media') as $key)
		unset($nowPlaying[$key]);
	return $nowPlaying;
}

/** The player's own time zone: FPP's TimeZone setting, else whatever PHP has. */
function itb_player_timezone()
{
	global $settings;
	if (isset($settings['TimeZone']) && is_string($settings['TimeZone']) && $settings['TimeZone'] !== '') {
		try {
			return new DateTimeZone($settings['TimeZone']);
		} catch (Exception $e) {
		}
	}
	return new DateTimeZone(date_default_timezone_get());
}

/** Epoch seconds as player-local ISO-8601 without an offset, e.g. 2026-12-24T17:00:00. */
function itb_local_iso($epoch, $tz)
{
	$when = new DateTime('@' . (int) $epoch);
	$when->setTimezone($tz);
	return $when->format('Y-m-d\TH:i:s');
}

/** A flag FPP gives as true/false, 1/0 or "true"/"false"; null if it is none of those. */
function itb_flag_or_null($value)
{
	if (is_bool($value))
		return $value;
	if (is_int($value) || (is_string($value) && ($value === '0' || $value === '1')))
		return (int) $value !== 0;
	if (is_string($value) && ($value === 'true' || $value === 'false'))
		return $value === 'true';
	return null;
}

/**
 * scheduleUpcoming: every playlist FPP's scheduler will start or is running
 * from now to ITB_UPCOMING_DAYS ahead, soonest first, at most
 * ITB_MAX_UPCOMING. Commands the schedule fires are left out — they are not
 * playlists — and so is an occurrence a higher-priority entry overrides,
 * since it will not play.
 *
 * Source: /api/fppd/schedule/range?start=&end=&summary=1&includeDisabled=1
 * (FPP 10's Scheduler::GetScheduleRange): fppd's own expansion of the
 * schedule rules — sunrise and sunset times, date ranges, holidays — into
 * occurrences, each with startTime/endTime epochs, playlist, repeat, enabled
 * and overridden. summary=1 folds the hundreds of occurrences a repeating
 * command makes into one a day; a playlist only occurs once a day anyway.
 * Disabled entries are included with enabled false, so "why did the show
 * not start" has an answer. When the scheduler as a whole is switched off,
 * every item is enabled false.
 *
 * Fallback, for a build without /range: /api/fppd/schedule (Scheduler::
 * GetSchedule), which covers only the ScheduleDistance days fppd has
 * committed to, with items whose args are [playlist, repeat, ...] and whose
 * id points into entries[].
 *
 * Null when neither answered; an empty list when nothing is scheduled.
 */
function itb_capture_schedule_upcoming(&$errors)
{
	$now = time();
	$end = $now + ITB_UPCOMING_DAYS * 86400;
	$tz = itb_player_timezone();

	$ignored = array();
	$range = itb_local_json('/api/fppd/schedule/range?start=' . $now . '&end=' . $end . '&summary=1&includeDisabled=1', $ignored);
	$fromRange = is_array($range) && isset($range['schedule']['items']) && is_array($range['schedule']['items']);
	if ($fromRange) {
		$schedule = $range['schedule'];
	} else {
		$answer = itb_local_json('/api/fppd/schedule', $errors);
		if (!is_array($answer) || !isset($answer['schedule']['items']) || !is_array($answer['schedule']['items'])) {
			if ($answer !== null)
				$errors[] = '/api/fppd/schedule: no items in the answer';
			return null;
		}
		$schedule = $answer['schedule'];
	}

	return itb_upcoming_items($schedule, $fromRange, $now, $end, $tz, $errors);
}

/**
 * The scheduleUpcoming list from one of FPP's schedule answers (the
 * "schedule" member of /api/fppd/schedule/range or /api/fppd/schedule; see
 * itb_capture_schedule_upcoming): playlists only, not overridden, ending
 * after $now and starting by $end, soonest first, capped.
 */
function itb_upcoming_items($schedule, $fromRange, $now, $end, $tz, &$errors)
{
	if (!is_array($schedule) || !isset($schedule['items']) || !is_array($schedule['items']))
		return array();

	$schedulerOn = isset($schedule['enabled']) ? itb_flag_or_null($schedule['enabled']) : null;
	$entries = isset($schedule['entries']) && is_array($schedule['entries']) ? $schedule['entries'] : array();

	$items = array();
	foreach ($schedule['items'] as $item) {
		if (!is_array($item) || !empty($item['overridden']))
			continue;
		$args = isset($item['args']) && is_array($item['args']) ? array_values($item['args']) : array();
		$entry = isset($item['id']) && is_numeric($item['id']) && isset($entries[(int) $item['id']]) && is_array($entries[(int) $item['id']])
			? $entries[(int) $item['id']] : array();

		$playlist = isset($item['playlist']) ? itb_text_or_null($item['playlist']) : null;
		if ($playlist === null && isset($item['command']) && $item['command'] === 'Start Playlist' && isset($args[0]))
			$playlist = itb_text_or_null($args[0]);
		if ($playlist === null)
			continue;

		$startEpoch = isset($item['startTime']) && is_numeric($item['startTime']) ? (int) $item['startTime'] : null;
		$endEpoch = isset($item['endTime']) && is_numeric($item['endTime']) ? (int) $item['endTime'] : null;
		// start and end are required strings, so an item without its times is no use.
		if ($startEpoch === null || $endEpoch === null || $endEpoch < $now || $startEpoch > $end)
			continue;

		$repeat = isset($item['repeat']) ? itb_flag_or_null($item['repeat']) : null;
		if ($repeat === null && isset($args[1]))
			$repeat = itb_flag_or_null($args[1]);
		if ($repeat === null && isset($entry['repeat']))
			$repeat = itb_flag_or_null($entry['repeat']);

		$enabled = isset($item['enabled']) ? itb_flag_or_null($item['enabled']) : null;
		if ($enabled === null && isset($entry['enabled']))
			$enabled = itb_flag_or_null($entry['enabled']);
		if ($enabled === null && !$fromRange)
			// GetSchedule only ever lists what fppd will run.
			$enabled = true;
		if ($schedulerOn === false)
			$enabled = false;

		$items[] = array(
			'playlist' => $playlist,
			'start' => itb_local_iso($startEpoch, $tz),
			'end' => itb_local_iso($endEpoch, $tz),
			'startEpoch' => $startEpoch,
			'endEpoch' => $endEpoch,
			'repeat' => $repeat,
			'enabled' => $enabled,
		);
	}

	usort($items, function ($a, $b) {
		if ($a['startEpoch'] !== $b['startEpoch'])
			return $a['startEpoch'] < $b['startEpoch'] ? -1 : 1;
		return strcmp($a['playlist'], $b['playlist']);
	});
	if (count($items) > ITB_MAX_UPCOMING) {
		$errors[] = 'scheduleUpcoming: only the first ' . ITB_MAX_UPCOMING . ' upcoming playlists were captured';
		$items = array_slice($items, 0, ITB_MAX_UPCOMING);
	}
	return $items;
}

/**
 * system.storage reduced to what is worth a send: how full each disk is, in
 * tenths. Free space moves by the minute as logs grow; a disk crossing from
 * 80 % to 90 % full is news.
 */
function itb_storage_stable($storage)
{
	if (!is_array($storage))
		return null;
	$band = function ($free, $total) {
		return is_numeric($free) && is_numeric($total) && $total > 0 ? (int) floor(($total - $free) * 10 / $total) : null;
	};
	return array(
		'root' => $band($storage['freeBytes'], $storage['totalBytes']),
		'media' => $band($storage['mediaFreeBytes'], $storage['mediaTotalBytes']),
	);
}

/**
 * Everything the toolbox should know about this player, as one document.
 *
 * Shape (the toolbox checks only that system.info is present; the tools read
 * the rest each in their own way):
 *
 *   schema, capturedUtc, plugin{name,version}, device{id,hostname}
 *   trigger, changed[], fingerprint   why it was sent and what moved — see itb_prepare()
 *   system{info,status,storage}   /api/system/info, /api/system/status; storage is
 *                            the root and media disks' free and total bytes — see
 *                            itb_capture_storage()
 *   nowPlaying               what was playing at its capturedUtc, from /api/system/status —
 *                            see itb_capture_now_playing()
 *   scheduleUpcoming[]       the playlists the scheduler starts in the next nine days,
 *                            soonest first — see itb_capture_schedule_upcoming()
 *   settings{name:value}     FPP's own $settings, without credentials or FPP's privacy settings
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
	$statusUtc = itb_now();
	$status = itb_local_json('/api/system/status', $errors);
	// Real values from FPP's own settings, credentials and privacy settings
	// skipped by name; see itb_capture_settings.
	$settings = itb_capture_settings($errors);
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
	$storage = itb_capture_storage($info);
	$nowPlaying = itb_capture_now_playing($status, $statusUtc);
	$upcoming = itb_capture_schedule_upcoming($errors);

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
		'nowPlaying' => $nowPlaying,
		'settings' => $settings,
		'network' => $network,
		'cape' => $cape,
		'ports' => $ports,
		'outputProcessors' => $processors,
		'schedule' => $schedule,
		'scheduleUpcoming' => $upcoming,
		'playlists' => array('names' => $playlistNames, 'items' => $playlists),
		'plugins' => $plugins,
		'files' => $files,
		'configFileNames' => $names,
		'configFiles' => $configFiles,
		'controllers' => $controllers,
		'errors' => $errors,
	);
	// Each of these is left out, not sent as null, when FPP could not give it.
	if ($storage !== null)
		$snapshot['system']['storage'] = $storage;
	foreach (array('nowPlaying', 'scheduleUpcoming') as $key) {
		if ($snapshot[$key] === null)
			unset($snapshot[$key]);
	}

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
 * goes in without its timings (itb_controllers_stable), nowPlaying without
 * its clock or the current song (itb_now_playing_stable), and the disks as
 * how full they are in tenths (itb_storage_stable). Every one of them still
 * goes out whole in the snapshot that is sent.
 */
function itb_fingerprint($snapshot)
{
	$stable = $snapshot;
	foreach (array('capturedUtc', 'trigger', 'changed', 'fingerprint', 'ports') as $key)
		unset($stable[$key]);
	if (isset($stable['system']) && is_array($stable['system'])) {
		unset($stable['system']['status']);
		if (isset($stable['system']['storage']))
			$stable['system']['storage'] = itb_storage_stable($stable['system']['storage']);
		if (isset($stable['system']['info']) && is_array($stable['system']['info']))
			unset($stable['system']['info']['Utilization']);
	}
	if (isset($stable['controllers']))
		$stable['controllers'] = itb_controllers_stable($stable['controllers']);
	if (isset($stable['nowPlaying']))
		$stable['nowPlaying'] = itb_now_playing_stable($stable['nowPlaying']);
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
	foreach (array('settings', 'network', 'cape', 'outputProcessors', 'schedule', 'scheduleUpcoming', 'playlists', 'plugins') as $key)
		$sections[$key] = itb_hash(isset($snapshot[$key]) ? $snapshot[$key] : null);
	$sections['warnings'] = itb_hash(itb_status_warnings($snapshot));
	$sections['fppd'] = itb_hash(itb_status_fppd($snapshot));
	$sections['files'] = itb_hash(isset($snapshot['files']) ? $snapshot['files'] : null);
	$sections['controllers'] = itb_hash(itb_controllers_stable(isset($snapshot['controllers']) ? $snapshot['controllers'] : null));
	$sections['nowPlaying'] = itb_hash(itb_now_playing_stable(isset($snapshot['nowPlaying']) ? $snapshot['nowPlaying'] : null));
	$sections['storage'] = itb_hash(itb_storage_stable(isset($snapshot['system']['storage']) ? $snapshot['system']['storage'] : null));
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
function itb_pair($code, $agreedToTerms = false)
{
	if ($agreedToTerms !== true)
		return array('ok' => false, 'error' => 'Tick the box to agree to the Terms of Service and Privacy Policy first.');

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

	$body = itb_encode(array('code' => $code, 'deviceId' => $deviceId, 'hostname' => $hostname, 'termsVersion' => ITB_TERMS_VERSION));
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
	itb_set('termsAccepted', ITB_TERMS_VERSION);
	itb_set('termsAcceptedUtc', itb_now());
	itb_set('renewAttemptUnix', '');
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
	itb_maybe_renew_token();
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
		$newState = array(
			'fingerprint' => $snapshot['fingerprint'],
			'sections' => $sections,
			'sentUtc' => $sentUtc,
		);
		// The poll loop keeps its own failover bookkeeping in the same file.
		$current = itb_read_state();
		if (isset($current['failover']) && is_array($current['failover']))
			$newState['failover'] = $current['failover'];
		$stateSaved = itb_write_state($newState);
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
	if (itb_setting('allowRemote', '0') !== '1')
		return array('ok' => false, 'skipped' => true, 'error' => 'Remote requests are off.');

	itb_maybe_renew_token();
	$token = itb_token();
	$auth = array('Content-Type: application/json', 'Authorization: Bearer ' . $token);

	// Player Failover status first, so it never waits behind a request. When
	// the failover plugin answers, the claim holds for twelve seconds rather
	// than twenty, which with poll.sh's three-second pause brings the next
	// round, and the next status, about fifteen seconds on. A request still
	// arrives the moment it is queued either way.
	$failover = itb_failover_relay(false);
	$wait = !empty($failover['present']) ? 12 : 20;

	// An empty string rather than null, so curl sends a proper POST with Content-Length: 0.
	list($status, $out, $err) = itb_http('POST', itb_commands_url($deviceId, '/claim?wait=' . $wait), '', $auth, 35);

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
	$failoverRan = false;
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
		if ($type === 'failover')
			$failoverRan = true;
	}

	// After a failover switch, the toolbox hears the new status at once
	// rather than on the next round. The failover plugin acts on its own
	// next tick, so give it a moment first; whatever it has not finished by
	// then shows up as a change on the next round.
	if ($failoverRan) {
		usleep(1000000);
		itb_failover_relay(true);
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

		case 'failover':
			return itb_failover_command($args);

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
 * Runs FPP's own test mode over a channel range — the RGB chase, or a solid
 * red, green, blue or white fill, as the Testing page offers — and arranges
 * for it to stop by itself. The stop is a detached shell so this request can
 * be answered straight away and the lights still go out if the poll loop is
 * restarted meanwhile.
 */
function itb_test_lights($args)
{
	$start = isset($args['startChannel']) && is_numeric($args['startChannel']) ? (int) $args['startChannel'] : 0;
	$count = isset($args['channelCount']) && is_numeric($args['channelCount']) ? (int) $args['channelCount'] : 0;
	$seconds = isset($args['seconds']) && is_numeric($args['seconds']) ? (int) $args['seconds'] : 60;
	$pattern = isset($args['pattern']) && is_string($args['pattern']) && $args['pattern'] !== '' ? $args['pattern'] : 'rgb_chase';

	if ($start < 1 || $count < 1)
		return array('ok' => false, 'message' => 'test_lights needs a startChannel and channelCount of 1 or more');
	// FPP's RGBFill takes the colour as three 0-255 channel values.
	$fills = array(
		'red' => array(255, 0, 0),
		'green' => array(0, 255, 0),
		'blue' => array(0, 0, 255),
		'white' => array(255, 255, 255),
	);
	if ($pattern !== 'rgb_chase' && !isset($fills[$pattern]))
		return array('ok' => false, 'message' => 'pattern "' . $pattern . '" is not supported; use rgb_chase, red, green, blue or white');

	// Test mode takes over the lights. Never during a show someone may be
	// watching, unless the person asked for exactly that (force).
	$force = isset($args['force']) && $args['force'] === true;
	if (!$force) {
		$errors = array();
		$fppd = itb_local_json('/api/fppd/status', $errors);
		$state = is_array($fppd) && isset($fppd['status_name']) && is_string($fppd['status_name']) ? strtolower($fppd['status_name']) : null;
		if ($state === null)
			return array('ok' => false, 'message' => 'Not testing: could not tell whether a show is playing. Ask again with force if the show is definitely off.');
		if ($state !== 'idle') {
			$what = isset($fppd['current_playlist']['playlist']) && is_string($fppd['current_playlist']['playlist']) && $fppd['current_playlist']['playlist'] !== ''
				? '"' . $fppd['current_playlist']['playlist'] . '"' : 'a show';
			return array('ok' => false, 'message' => 'Not testing: the player is ' . $state . ' ' . $what . ', and a test would take over the lights in front of anyone watching. Stop the show first, or ask again with force.');
		}
	}
	$seconds = max(5, min(600, $seconds));
	$end = $start + $count - 1;

	$test = array(
		'enabled' => 1,
		'cycleMS' => 1000,
		'channelSet' => $start . '-' . $end,
		'channelSetType' => 'channelRange',
	);
	if ($pattern === 'rgb_chase') {
		$test['mode'] = 'RGBChase';
		$test['subMode'] = 'RGBChase-RGB';
		$test['colorPattern'] = 'FF000000FF000000FF';
	} else {
		$test['mode'] = 'RGBFill';
		list($test['color1'], $test['color2'], $test['color3']) = $fills[$pattern];
	}
	list($status, $out, $err) = itb_local_post('/api/testmode', $test);
	if ($status !== 200)
		return array('ok' => false, 'message' => 'Could not start the test: ' . ($status ? 'HTTP ' . $status : ($err ?: 'no answer')));

	$stop = 'sleep ' . $seconds . '; curl -s -m 10 -X POST -H \'Content-Type: application/json\' -d \'{"enabled":0}\' ' . ITB_LOCAL_API . '/api/testmode';
	exec('nohup bash -c ' . escapeshellarg($stop) . ' >/dev/null 2>&1 &');

	$what = $pattern === 'rgb_chase' ? 'RGB chase' : ucfirst($pattern) . ' fill';
	return array('ok' => true, 'message' => $what . ' running on channels ' . $start . '–' . $end . ' for ' . $seconds . ' s');
}

// ── Player Failover ─────────────────────────────────────────────────────────
// The Player Failover plugin (fpp-plugin-IlluminationToolbox-Failover) runs
// a primary and a backup player that hand the show between them. When it
// is installed and given a role, this plugin passes a trimmed copy of its
// status to the toolbox, so Control Booth can show which player has the
// show, and carries out the switches a person asks for there. Both ride on the poll loop: the status goes out at the start of
// a round, at most every fifteen seconds unless the role or state moved, and
// a switch is just another request. Nothing happens when the failover plugin
// is not installed, not answering, or has no role.

/**
 * The failover plugin's status, as it answers it, or null when it is not
 * installed, not answering within three seconds, or switched off (role off).
 */
function itb_failover_local_status()
{
	list($status, $out, $err) = itb_http('GET', ITB_LOCAL_API . ITB_FAILOVER_API . '?action=status', null, array(), 3);
	if ($status !== 200)
		return null;
	$data = json_decode($out, true);
	if (!is_array($data) || !isset($data['role']) || !is_string($data['role']) || $data['role'] === '' || $data['role'] === 'off')
		return null;
	return $data;
}

/**
 * Exactly the fields the toolbox takes (the hub contract's PUT
 * /api/fpp/devices/{id}/failover body), each as the type it should be or
 * null. Everything else the failover plugin reports — its witness list,
 * content sync, what it has heard on the network — stays on the player.
 * None of it is a credential.
 */
function itb_failover_trim($s)
{
	$text = function ($v) {
		return is_string($v) || is_int($v) || is_float($v) ? (string) $v : null;
	};
	$int = function ($v) {
		return is_numeric($v) ? (int) $v : null;
	};
	$flag = function ($v) {
		return is_bool($v) ? $v : null;
	};
	$get = function ($a, $k) {
		return is_array($a) && array_key_exists($k, $a) ? $a[$k] : null;
	};
	$hot = isset($s['hot']) && is_array($s['hot']) ? $s['hot'] : array();
	$peer = isset($s['peer']) && is_array($s['peer']) ? $s['peer'] : array();
	$player = isset($s['player']) && is_array($s['player']) ? $s['player'] : array();

	$events = array();
	if (isset($s['events']) && is_array($s['events'])) {
		foreach ($s['events'] as $e) {
			if (is_string($e))
				$events[] = $e;
		}
		// The plugin keeps the newest last.
		$events = array_slice($events, -10);
	}

	return array(
		'capturedUtc' => itb_now(),
		'version' => $text($get($s, 'version')),
		'role' => $text($get($s, 'role')),
		'state' => $text($get($s, 'state')),
		'epoch' => $int($get($s, 'epoch')),
		'manualStandby' => $flag($get($s, 'manualStandby')),
		'fppMode' => $text($get($s, 'fppMode')),
		'hot' => array(
			'capable' => $flag($get($hot, 'capable')),
			'standby' => $flag($get($hot, 'standby')),
			'actingPlayer' => $flag($get($hot, 'actingPlayer')),
			'lastSyncMsAgo' => $int($get($hot, 'lastSyncMsAgo')),
		),
		'peer' => array(
			'address' => $text($get($peer, 'address')),
			'host' => $text($get($peer, 'host')),
			'alive' => $flag($get($peer, 'alive')),
			'lastOkMsAgo' => $int($get($peer, 'lastOkMsAgo')),
			'state' => $text($get($peer, 'state')),
			'role' => $text($get($peer, 'role')),
			'manualStandby' => $flag($get($peer, 'manualStandby')),
		),
		'player' => array(
			'status_name' => $text($get($player, 'status_name')),
			'playlist' => $text($get($player, 'playlist')),
			'sequence' => $text($get($player, 'sequence')),
			'seconds_remaining' => $int($get($player, 'seconds_remaining')),
		),
		'events' => $events,
	);
}

/** What counts as a change worth sending before fifteen seconds are up: who has the show, and whether the other player is there. */
function itb_failover_change_key($trimmed)
{
	return itb_hash(array(
		$trimmed['role'], $trimmed['state'], $trimmed['epoch'], $trimmed['manualStandby'],
		$trimmed['peer']['alive'], $trimmed['peer']['state'], $trimmed['peer']['role'], $trimmed['peer']['manualStandby'],
	));
}

/** The failover bookkeeping in state.json, replaced (or removed, with null) without touching the snapshot's. */
function itb_failover_save_state($entry)
{
	$state = itb_read_state();
	if ($entry === null) {
		if (!isset($state['failover']))
			return;
		unset($state['failover']);
	} else {
		$state['failover'] = $entry;
	}
	itb_write_state($state);
}

/**
 * Sends the failover plugin's status to the toolbox when it is due: fifteen
 * seconds after the last try, as soon as the role or state changed, or at
 * once with $force (after a failover request). Only while linked, and — for
 * the regular sends — only while Send automatically is on; a switch someone
 * asked for always reports back, the way a snapshot request does.
 *
 * Returns null when there is nothing to relay (not linked, switched off, or
 * no failover plugin with a role), else array(present => true, sent => bool,
 * ok => bool).
 */
function itb_failover_relay($force)
{
	$token = itb_token();
	$deviceId = itb_setting('deviceId');
	if ($token === '' || $deviceId === '')
		return null;
	if (!$force && itb_setting('autoSync', '1') !== '1')
		return null;

	$local = itb_failover_local_status();
	if ($local === null) {
		itb_failover_save_state(null);
		return null;
	}

	$trimmed = itb_failover_trim($local);
	$key = itb_failover_change_key($trimmed);
	$state = itb_read_state();
	$last = isset($state['failover']) && is_array($state['failover']) ? $state['failover'] : array();
	$lastTry = isset($last['triedUnix']) && is_numeric($last['triedUnix']) ? (int) $last['triedUnix'] : 0;
	$lastKey = isset($last['key']) && is_string($last['key']) ? $last['key'] : '';
	$due = $force || $lastKey !== $key || time() - $lastTry >= ITB_FAILOVER_EVERY_SECONDS;
	if (!$due)
		return array('present' => true, 'sent' => false, 'ok' => true);

	$json = itb_encode($trimmed);
	if ($json === false)
		list($status, $out, $err) = array(0, '', 'could not encode the status');
	else
		list($status, $out, $err) = itb_http('PUT', itb_api_base() . '/api/fpp/devices/' . rawurlencode($deviceId) . '/failover', $json,
			array('Content-Type: application/json', 'Authorization: Bearer ' . $token), 10);
	$ok = $status >= 200 && $status < 300;

	$entry = array('triedUnix' => time(), 'key' => $ok ? $key : $lastKey);
	foreach (array('sentUtc', 'role', 'state') as $k) {
		if (isset($last[$k]))
			$entry[$k] = $last[$k];
	}
	if ($ok) {
		$entry['sentUtc'] = itb_now();
		$entry['role'] = $trimmed['role'];
		$entry['state'] = $trimmed['state'];
		if ($lastKey !== $key)
			itb_log('Player Failover status sent: ' . $trimmed['role'] . ', ' . $trimmed['state']
				. ($trimmed['manualStandby'] ? ', held in standby' : ''));
		else
			itb_log_quietly('failover-sent', 'sending Player Failover status to the toolbox every ' . ITB_FAILOVER_EVERY_SECONDS . ' s');
	} else {
		$message = $status === 401
			? 'The toolbox no longer accepts this player\'s token. Pair again.'
			: itb_toolbox_error($status, $out, $err, 'The toolbox refused the failover status');
		itb_log_quietly('failover-error', 'sending Player Failover status: ' . $message);
	}
	itb_failover_save_state($entry);
	return array('present' => true, 'sent' => true, 'ok' => $ok);
}

/** What the status page shows about the relay: when the last failover status went, and what it said. Null when none has. */
function itb_failover_last_sent()
{
	$state = itb_read_state();
	if (!isset($state['failover']['sentUtc']) || !is_string($state['failover']['sentUtc']))
		return null;
	return array(
		'sentUtc' => $state['failover']['sentUtc'],
		'role' => isset($state['failover']['role']) ? $state['failover']['role'] : null,
		'state' => isset($state['failover']['state']) ? $state['failover']['state'] : null,
	);
}

/**
 * A failover switch asked for in Control Booth: one POST to the failover
 * plugin's own API, the same calls its status page buttons make. The plugin
 * carries the switch out on its next tick; this only says whether it took
 * the request, in its own words when it did not.
 */
function itb_failover_command($args)
{
	$action = isset($args['action']) && is_string($args['action']) ? $args['action'] : '';
	$actions = array(
		'takeover' => array('takeover', array('graceful' => true, 'manual' => true),
			'Asked this player to take over the show.'),
		'takeover_now' => array('takeover', array('graceful' => false, 'manual' => true),
			'Asked this player to take over the show right away.'),
		'yield' => array('yield', array('manual' => true),
			'Asked this player to hand the show over and stand by.'),
		'failback' => array('failback', new stdClass(),
			'Asked the primary player to take the show back.'),
		'resume' => array('resume', new stdClass(),
			'This player is no longer held in standby.'),
	);
	if (!isset($actions[$action]))
		return array('ok' => false, 'message' => 'failover needs an action: takeover, takeover_now, yield, failback or resume');

	list($call, $body, $done) = $actions[$action];
	list($status, $out, $err) = itb_http('POST', ITB_LOCAL_API . ITB_FAILOVER_API . '?action=' . $call, itb_encode($body),
		array('Content-Type: application/json'), 10);
	$answer = json_decode($out, true);
	$said = is_array($answer) && isset($answer['message']) && is_string($answer['message']) && $answer['message'] !== ''
		? $answer['message'] : '';

	if ($status === 200 && !(is_array($answer) && isset($answer['status']) && $answer['status'] === 'error'))
		return array('ok' => true, 'message' => $done);
	if ($said !== '')
		return array('ok' => false, 'message' => 'Player Failover said: ' . $said);
	if ($status === 0)
		return array('ok' => false, 'message' => 'Could not reach the Player Failover plugin: ' . ($err ?: 'no answer'));
	if ($status === 404)
		return array('ok' => false, 'message' => 'The Player Failover plugin is not installed on this player.');
	return array('ok' => false, 'message' => 'Player Failover did not take the request (HTTP ' . $status . ').');
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
		'allowRemote' => itb_setting('allowRemote', '0') === '1',
		'lastCommandUtc' => itb_setting('lastCommandUtc'),
		'lastCommandType' => itb_setting('lastCommandType'),
		'lastCommandResult' => itb_setting('lastCommandResult'),
		'failover' => itb_failover_last_sent(),
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
