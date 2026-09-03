#!/bin/bash
#
# Sends a snapshot through the plugin's own API on this player.
#
#   sync.sh        always sends
#   sync.sh auto   honours the "Send automatically" switch (the timer and
#                  fppd's start hook use this)
#
# Goes through Apache rather than running PHP directly so the code that
# builds and sends the snapshot is the same one the status page uses.

AUTO=0
if [ "${1:-}" = "auto" ]; then AUTO=1; fi

curl -s -m 180 -X POST "http://127.0.0.1/api/plugin/fpp-plugin-IlluminationToolbox/sync?auto=${AUTO}"
echo
