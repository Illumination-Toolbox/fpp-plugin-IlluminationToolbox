<?php
/**
 * The plugin's own REST endpoints, under
 *   /api/plugin/fpp-plugin-IlluminationToolbox/...
 *
 * The status page talks to these, and so do scripts/sync.sh from the timer
 * and scripts/poll.sh from the poll service. Every answer is JSON with an
 * "ok" field; failures say why in "error".
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
		array('method' => 'POST', 'endpoint' => 'poll', 'callback' => 'fpppluginIlluminationToolboxPoll'),
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
		$result['sync'] = itb_sync(false, 'pair');
	return json($result);
}

// POST ?trigger=timer|start|manual — timer and start honour the Auto-sync
// switch, and the timer sends only when something changed (or an hour has
// passed). Without a trigger, or with the older ?auto=1 (which means timer),
// a person asked: always sends.
function fpppluginIlluminationToolboxSync()
{
	$trigger = isset($_GET['trigger']) && is_string($_GET['trigger']) ? $_GET['trigger'] : '';
	if ($trigger === '' && isset($_GET['auto']) && $_GET['auto'] === '1')
		$trigger = 'timer';
	if (!in_array($trigger, array('timer', 'start', 'manual'), true))
		$trigger = 'manual';
	$auto = $trigger === 'timer' || $trigger === 'start';
	return json(itb_sync($auto, $trigger));
}

// POST — one round of asking the toolbox for requests and carrying them out;
// what scripts/poll.sh calls in a loop. Holds for up to twenty seconds.
function fpppluginIlluminationToolboxPoll()
{
	return json(itb_poll());
}

function fpppluginIlluminationToolboxUnlink()
{
	return json(itb_unlink());
}

// POST {autoSync?: bool, allowRemote?: bool, apiBaseUrl?: string}
function fpppluginIlluminationToolboxSettings()
{
	$body = fpppluginIlluminationToolboxBody();
	if (array_key_exists('autoSync', $body))
		itb_set('autoSync', $body['autoSync'] ? '1' : '0');
	if (array_key_exists('allowRemote', $body))
		itb_set('allowRemote', $body['allowRemote'] ? '1' : '0');
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

// GET — exactly what pressing Send now would send, redacted, so a person can read it first.
function fpppluginIlluminationToolboxPreview()
{
	list($snapshot) = itb_prepare('manual');
	return json($snapshot);
}
?>
