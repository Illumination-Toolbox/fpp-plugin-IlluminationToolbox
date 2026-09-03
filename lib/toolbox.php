<?php
/**
 * Illumination Toolbox plugin — the part that does the work.
 *
 * Shared by the status page, the plugin's own API endpoints, and the timer
 * that runs behind them. Three jobs:
 *
 *   1. Pair. Trade a code the person read off the toolbox for a device token,
 *      and keep it in this plugin's settings file.
 *   2. Snapshot. Ask this player, through its own local REST API, for
 *      everything that describes how it is set up, and redact anything that
 *      looks like a secret before it goes anywhere.
 *   3. Send. PUT the snapshot to the toolbox, which holds it for every tool on
 *      the account.
 *
 * Nothing here reads fppd's internal port or touches files under the media
 * directory directly; everything goes through http://127.0.0.1/api, which is
 * what the plugin guidelines ask for and what keeps this working across FPP
 * versions and platforms.
 *
 * Requires FPP's common.php to be loaded first, for ReadSettingFromFile and
 * WriteSettingToFile. plugin.php and api/index.php both do that.
 */

if (!defined('ITB_PLUGIN')) {
	define('ITB_PLUGIN', 'fpp-plugin-IlluminationToolbox');
	define('ITB_PLUGIN_VERSION', '1.0.0');
	define('ITB_SCHEMA', 'illumination-toolbox.fpp-snapshot/1');
	define('ITB_DEFAULT_API', 'https://api.illuminationtoolbox.com');
	define('ITB_LOCAL_API', 'http://127.0.0.1');
	/** One config file bigger than this is skipped; the toolbox caps the whole snapshot at 4 MB. */
	define('ITB_MAX_CONFIG_FILE_BYTES', 512000);
	define('ITB_SNAPSHOT_BUDGET_BYTES', 2500000);
}

// ── Settings ────────────────────────────────────────────────────────────────
// FPP keeps these in config/plugin.fpp-plugin-IlluminationToolbox as
// key = "value" lines. The device token lives here too, which is why the
// uninstall script deletes the file.

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
	curl_close($ch);

	return array($status, $out === false ? '' : $out, $err);
}

/** GET from this player's own API. */
function itb_local($path)
{
	return itb_http('GET', ITB_LOCAL_API . $path);
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
 */
function itb_is_secret_key($key)
{
	return is_string($key) && preg_match(
		'/pass|passwd|secret|token|psk|apikey|api_key|privatekey|private_key|authorization|cookie|credential/i',
		$key) === 1;
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

// ── Identity ────────────────────────────────────────────────────────────────

/**
 * What this player calls itself to the toolbox. FPP's uuid when it reports
 * one — that survives a hostname change — otherwise the hostname. Reduced to
 * the characters the toolbox accepts in a device id.
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

	$id = preg_replace('/[^A-Za-z0-9._-]+/', '-', $raw);
	$id = ltrim($id, '._-');
	if ($id === '')
		$id = 'fpp';
	return substr($id, 0, 64);
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
 * Everything the toolbox should know about this player, as one document.
 *
 * Shape (the toolbox checks only that system.info is present; the tools read
 * the rest each in their own way):
 *
 *   schema, capturedUtc, plugin{name,version}, device{id,hostname}
 *   system{info,status}      /api/system/info, /api/system/status
 *   settings                 /api/settings
 *   network                  /api/network/interface
 *   cape                     /api/cape (null when there is none)
 *   ports                    /api/fppd/ports (port/eFuse status, where the cape reports it)
 *   outputProcessors         /api/channel/output/processors
 *   schedule                 /api/schedule
 *   playlists{names,items}   /api/playlists and each /api/playlist/:name
 *   plugins                  /api/plugin
 *   configFileNames          every file /api/configfile lists
 *   configFiles{name:body}   the ones itb_wanted_config() keeps, parsed when they are JSON
 *   errors[]                 what could not be captured, and why
 */
function itb_build_snapshot()
{
	$errors = array();

	$info = itb_local_json('/api/system/info', $errors);
	$status = itb_local_json('/api/system/status', $errors);
	$settings = itb_local_json('/api/settings', $errors);
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

	$snapshot = array(
		'schema' => ITB_SCHEMA,
		'capturedUtc' => itb_now(),
		'plugin' => array('name' => ITB_PLUGIN, 'version' => ITB_PLUGIN_VERSION),
		'device' => array(
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
		'configFileNames' => $names,
		'configFiles' => $configFiles,
		'errors' => $errors,
	);

	return itb_redact($snapshot);
}

function itb_encode($snapshot)
{
	$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
	if (defined('JSON_INVALID_UTF8_SUBSTITUTE'))
		$flags |= JSON_INVALID_UTF8_SUBSTITUTE;
	return json_encode($snapshot, $flags);
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
 * Trades a pairing code for a device token and remembers it.
 */
function itb_pair($code, $apiBaseUrl = '')
{
	$api = rtrim($apiBaseUrl !== '' ? $apiBaseUrl : itb_api_base(), '/');
	if (!preg_match('#^https?://[^\s/]+#', $api))
		return array('ok' => false, 'error' => 'The toolbox address must start with https://.');

	$code = trim((string) $code);
	if ($code === '')
		return array('ok' => false, 'error' => 'Enter the code shown in the toolbox.');

	$errors = array();
	$info = itb_local_json('/api/system/info', $errors);
	$deviceId = itb_device_id($info);
	$hostname = itb_hostname($info);

	$body = itb_encode(array('code' => $code, 'deviceId' => $deviceId, 'hostname' => $hostname));
	list($status, $out, $err) = itb_http('POST', $api . '/api/fpp/pair/claim', $body,
		array('Content-Type: application/json'), 30);

	if ($status !== 200)
		return array('ok' => false, 'error' => itb_toolbox_error($status, $out, $err, 'The toolbox refused the code'));

	$data = json_decode($out, true);
	if (!is_array($data) || empty($data['token']) || empty($data['username']))
		return array('ok' => false, 'error' => 'The toolbox answered, but not with a token. Try again.');

	itb_set('apiBaseUrl', $api);
	itb_set('deviceToken', $data['token']);
	itb_set('deviceId', $deviceId);
	itb_set('username', $data['username']);
	itb_set('tokenExpiresUtc', isset($data['expiresUtc']) ? $data['expiresUtc'] : '');
	itb_set('lastSyncUtc', '');
	itb_set('lastSyncResult', '');
	itb_set('lastSyncError', '');

	return array('ok' => true, 'username' => $data['username'], 'deviceId' => $deviceId, 'hostname' => $hostname);
}

/** Forgets the token. What the toolbox already holds stays until removed there. */
function itb_unlink()
{
	foreach (array('deviceToken', 'deviceId', 'username', 'tokenExpiresUtc', 'lastSyncUtc', 'lastSyncResult', 'lastSyncError') as $key)
		itb_set($key, '');
	return array('ok' => true);
}

/**
 * Builds a snapshot and sends it. $auto is true when the timer or fppd's
 * start hook asked, in which case the Auto-sync switch is honoured; a person
 * pressing the button always sends.
 */
function itb_sync($auto = false)
{
	if ($auto && itb_setting('autoSync', '1') !== '1')
		return array('ok' => false, 'skipped' => true, 'error' => 'Auto-sync is off.');

	$token = itb_setting('deviceToken');
	$deviceId = itb_setting('deviceId');
	if ($token === '' || $deviceId === '')
		return array('ok' => false, 'error' => 'This player is not linked to a toolbox account yet.');

	$snapshot = itb_build_snapshot();
	$json = itb_encode($snapshot);
	if ($json === false)
		return itb_record_sync(false, 'Could not encode the snapshot: ' . json_last_error_msg());

	list($status, $out, $err) = itb_http('PUT', itb_api_base() . '/api/fpp/devices/' . rawurlencode($deviceId), $json,
		array('Content-Type: application/json', 'Authorization: Bearer ' . $token), 90);

	if ($status === 200) {
		$entry = json_decode($out, true);
		itb_record_sync(true, '');
		return array(
			'ok' => true,
			'sentUtc' => itb_now(),
			'bytes' => strlen($json),
			'entry' => is_array($entry) ? $entry : null,
			'captureErrors' => $snapshot['errors'],
		);
	}

	if ($status === 401)
		$message = 'The toolbox no longer accepts this player\'s token. Pair again.';
	else
		$message = itb_toolbox_error($status, $out, $err, 'The toolbox refused the snapshot');

	return itb_record_sync(false, $message, $status);
}

function itb_record_sync($ok, $message, $status = null)
{
	itb_set('lastSyncUtc', itb_now());
	itb_set('lastSyncResult', $ok ? 'ok' : 'error');
	itb_set('lastSyncError', $ok ? '' : substr($message, 0, 300));
	return $ok
		? array('ok' => true)
		: array('ok' => false, 'error' => $message, 'httpStatus' => $status);
}

/**
 * What the status page shows. With $checkLink, also asks the toolbox whether
 * the link still stands, which is the honest answer to "is this connected"
 * rather than "is there a token in the file".
 */
function itb_status($checkLink = false)
{
	$token = itb_setting('deviceToken');
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
		'autoSync' => itb_setting('autoSync', '1') === '1',
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
