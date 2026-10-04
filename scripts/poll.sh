#!/bin/bash
#
# Keeps asking the toolbox whether anyone has a request for this player.
# Run by the fpp-illumination-toolbox-poll systemd service, forever.
#
# Each call to the plugin's poll endpoint holds a long request open at the
# toolbox for up to twenty seconds, then carries out whatever came back, so
# a "Request snapshot" button in the toolbox reaches the player within
# seconds without the toolbox ever connecting inward. When the Player
# Failover plugin is installed, each round also passes its status on to the
# toolbox and holds the request open twelve seconds instead, so the status
# goes about every fifteen seconds. How long to wait
# before the next call depends on the answer:
#
#   "skipped"       not linked, the switch is off, or the token has lapsed;
#                   no point asking again in three seconds, so 30 s
#   "ok":false      the toolbox could not be reached or refused; 5 s, then
#                   10, 20, 40… up to five minutes, back to 5 on the next ok
#   nothing         Apache is not answering yet; 10 s
#   ok              straight round again after 3 s
#
# The plugin logs what it did; the answer itself is only read here, never
# printed, so nothing lands in the journal.

URL="http://127.0.0.1/api/plugin/fpp-plugin-IlluminationToolbox/poll"
BACKOFF=0

while true; do
	OUT="$(curl -s -m 60 -X POST -H 'Content-Type: application/json' -d '{}' "${URL}" 2>/dev/null)"
	case "${OUT}" in
		*'"skipped":true'*)
			BACKOFF=0
			sleep 30 ;;
		'')
			sleep 10 ;;
		*'"ok":false'*)
			if [ "${BACKOFF}" -eq 0 ]; then BACKOFF=5; else BACKOFF=$((BACKOFF * 2)); fi
			if [ "${BACKOFF}" -gt 300 ]; then BACKOFF=300; fi
			sleep "${BACKOFF}" ;;
		*)
			BACKOFF=0
			sleep 3 ;;
	esac
done
