<?php
/**
 * The plugin's own REST endpoints, under
 *   /api/plugin/fpp-plugin-IlluminationToolbox/...
 *
 * The status page talks to these, and so does scripts/sync.sh from the timer.
 * Every answer is JSON with an "ok" field; failures say why in "error".
 *
 * FPP strips the hyphens from the plugin name to build the function names it
 * looks for, hence getEndpointsfpppluginIlluminationToolbox.
 */

include_once __DIR__ . '/lib/toolbox.php';

function getEndpointsfpppluginIlluminationToolbox()
{
	return array(
		array('method' => 'GET', 'endpoint' => 'status', 'callback' => 'fpppluginIlluminationToolboxStatus'),
		array('method' => 'POST', 'endpoint' => 'pair', 'callback' => 'fpppluginIlluminationToolboxPair'),
		array('method' => 'POST', 'endpoint' => 'sync', 'callback' => 'fpppluginIlluminationToolboxSync'),
		array('method' => 'POST', 'endpoint' => 'unlink', 'callback' => 'fpppluginIlluminationToolboxUnlink'),
		array('method' => 'POST', 'endpoint' => 'settings', 'callback' => 'fpppluginIlluminationToolboxSettings'),
		array('method' => 'GET', 'endpoint' => 'preview', 'callback' => 'fpppluginIlluminationToolboxPreview'),
	);
}

function fpppluginIlluminationToolboxBody()
{
	$raw = file_get_contents('php://input');
	$data = json_decode($raw, true);
	return is_array($data) ? $data : array();
}

// GET /api/plugin/fpp-plugin-IlluminationToolbox/status?check=1
function fpppluginIlluminationToolboxStatus()
{
	$check = isset($_GET['check']) && $_GET['check'] === '1';
	$status = itb_status($check);
	$status['ok'] = true;
	return json($status);
}

// POST {code, apiBaseUrl?} — pairs, then sends the first snapshot straight away.
function fpppluginIlluminationToolboxPair()
{
	$body = fpppluginIlluminationToolboxBody();
	$result = itb_pair(isset($body['code']) ? $body['code'] : '', isset($body['apiBaseUrl']) ? trim($body['apiBaseUrl']) : '');
	if ($result['ok'])
		$result['sync'] = itb_sync(false);
	return json($result);
}

// POST ?auto=1 — from the timer; honours the Auto-sync switch. Without it, always sends.
function fpppluginIlluminationToolboxSync()
{
	$auto = isset($_GET['auto']) && $_GET['auto'] === '1';
	return json(itb_sync($auto));
}

function fpppluginIlluminationToolboxUnlink()
{
	return json(itb_unlink());
}

// POST {autoSync?: bool, apiBaseUrl?: string}
function fpppluginIlluminationToolboxSettings()
{
	$body = fpppluginIlluminationToolboxBody();
	if (array_key_exists('autoSync', $body))
		itb_set('autoSync', $body['autoSync'] ? '1' : '0');
	if (array_key_exists('apiBaseUrl', $body)) {
		$api = rtrim(trim((string) $body['apiBaseUrl']), '/');
		if ($api === '')
			$api = ITB_DEFAULT_API;
		if (!preg_match('#^https?://[^\s/]+#', $api))
			return json(array('ok' => false, 'error' => 'The toolbox address must start with https://.'));
		itb_set('apiBaseUrl', $api);
	}
	$status = itb_status(false);
	$status['ok'] = true;
	return json($status);
}

// GET — exactly what a sync would send, redacted, so a person can read it first.
function fpppluginIlluminationToolboxPreview()
{
	return json(itb_build_snapshot());
}
?>
