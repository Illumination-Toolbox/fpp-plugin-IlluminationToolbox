<?php
/**
 * The plugin's own REST endpoints, under
 *   /api/plugin/fpp-plugin-IlluminationToolbox/...
 *
 * The status page talks to these, and so do scripts/sync.sh from the timer
 * and scripts/poll.sh from the poll service. Every answer is JSON with an
 * "ok" field; failures say why in "error".
 *
 * Every POST must say Content-Type: application/json, or it is refused with
 * 415 before anything happens. FPP has no login by default, so any web page
 * open in the operator's browser could otherwise post a plain form here and
 * unlink the player or run a light test. A browser sends that header
 * cross-site only after a preflight OPTIONS request, and FPP's API answers
 * OPTIONS with 501, so the browser stops there.
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
		array('method' => 'POST', 'endpoint' => 'switches', 'callback' => 'fpppluginIlluminationToolboxSwitches'),
		array('method' => 'GET', 'endpoint' => 'preview', 'callback' => 'fpppluginIlluminationToolboxPreview'),
	);
}

/**
 * Null when the request is a POST that said it carries JSON; otherwise the
 * 415 answer to return instead of doing anything.
 */
function fpppluginIlluminationToolboxRefuseNonJson()
{
	$type = '';
	if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE']))
		$type = $_SERVER['CONTENT_TYPE'];
	elseif (isset($_SERVER['HTTP_CONTENT_TYPE']) && is_string($_SERVER['HTTP_CONTENT_TYPE']))
		$type = $_SERVER['HTTP_CONTENT_TYPE'];
	$isPost = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
	if ($isPost && stripos(ltrim($type), 'application/json') === 0)
		return null;
	http_response_code(415);
	return json(array('ok' => false, 'error' => 'Send this as a POST with Content-Type: application/json.'));
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

// POST {code, agreeTerms: true} — pairs, then sends the first snapshot straight away.
function fpppluginIlluminationToolboxPair()
{
	if (($refused = fpppluginIlluminationToolboxRefuseNonJson()) !== null)
		return $refused;
	$body = fpppluginIlluminationToolboxBody();
	$result = itb_pair(isset($body['code']) && is_string($body['code']) ? $body['code'] : '',
		isset($body['agreeTerms']) && $body['agreeTerms'] === true);
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
	if (($refused = fpppluginIlluminationToolboxRefuseNonJson()) !== null)
		return $refused;
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
	if (($refused = fpppluginIlluminationToolboxRefuseNonJson()) !== null)
		return $refused;
	return json(itb_poll());
}

function fpppluginIlluminationToolboxUnlink()
{
	if (($refused = fpppluginIlluminationToolboxRefuseNonJson()) !== null)
		return $refused;
	return json(itb_unlink());
}

// POST {autoSync?: bool, allowRemote?: bool}. The toolbox address is not
// one of them: it is fixed, and only a hand edit of the settings file moves it.
// Not called "settings": FPP's own POST /plugin/:RepoName/settings/:SettingName
// answers that path first, with an empty name, and stores the body as a
// nameless setting, so the switches never reached this function.
function fpppluginIlluminationToolboxSwitches()
{
	if (($refused = fpppluginIlluminationToolboxRefuseNonJson()) !== null)
		return $refused;
	$body = fpppluginIlluminationToolboxBody();
	if (array_key_exists('autoSync', $body)) {
		itb_set('autoSync', $body['autoSync'] ? '1' : '0');
		itb_log('Send automatically turned ' . ($body['autoSync'] ? 'on' : 'off'));
	}
	if (array_key_exists('allowRemote', $body)) {
		itb_set('allowRemote', $body['allowRemote'] ? '1' : '0');
		itb_log('requests from the toolbox turned ' . ($body['allowRemote'] ? 'on' : 'off'));
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
