#!/bin/bash
#
# Keeps asking the toolbox whether anyone has a request for this player.
# Run by the fpp-illumination-toolbox-poll systemd service, forever.
#
# Each call to the plugin's poll endpoint holds a long request open at the
# toolbox for up to twenty seconds, then carries out whatever came back, so
# a "Request snapshot" button in the toolbox reaches the player within
# seconds without the toolbox ever connecting inward. The plugin answers
# "skipped" when the player is not linked, the switch is off, or the token
# has lapsed; there is no point asking again in three seconds then.

URL="http://127.0.0.1/api/plugin/fpp-plugin-IlluminationToolbox/poll"

while true; do
	OUT="$(curl -s -m 60 -X POST "${URL}")"
	case "${OUT}" in
		*'"skipped":true'*) sleep 30 ;;
		'') sleep 10 ;;   # Apache is not answering yet; give it a moment
		*) sleep 3 ;;
	esac
done
