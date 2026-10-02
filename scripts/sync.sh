#!/bin/bash
#
# Sends a snapshot through the plugin's own API on this player.
#
#   sync.sh         always sends (Send now, or a hand-run)
#   sync.sh auto    the timer: honours the "Send automatically" switch, and
#                   sends only when something changed or an hour has passed
#   sync.sh start   fppd's start hook: honours the switch, always sends
#
# Goes through Apache rather than running PHP directly so the code that
# builds and sends the snapshot is the same one the status page uses.

TRIGGER=manual
case "${1:-}" in
	auto) TRIGGER=timer ;;
	start) TRIGGER=start ;;
esac

curl -s -m 180 -X POST "http://127.0.0.1/api/plugin/fpp-plugin-IlluminationToolbox/sync?trigger=${TRIGGER}"
echo
