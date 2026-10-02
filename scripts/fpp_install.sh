#!/bin/bash
#
# Installed by FPP after cloning the plugin. Safe to re-run ("Reinstall All"),
# and an upgrade relies on that: the unit files are rewritten every time and
# the units restarted, so a new cadence or a new script takes effect without
# a reboot.
#
# Side effects outside the plugin folder, all undone by fpp_uninstall.sh:
#   /etc/systemd/system/fpp-illumination-toolbox-sync.service
#   /etc/systemd/system/fpp-illumination-toolbox-sync.timer
#   /etc/systemd/system/fpp-illumination-toolbox-poll.service
#
# The timer runs scripts/sync.sh every five minutes; the plugin sends only
# when something changed, and at least once an hour regardless. The poll
# service keeps scripts/poll.sh running so the player hears about requests
# from the toolbox. Nothing is sent or asked for until the player is paired.

set -e

. "${FPPDIR:-/opt/fpp}/scripts/common"

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
UNIT="fpp-illumination-toolbox-sync"
POLL="fpp-illumination-toolbox-poll"

SUDO=""
if [ "$(id -u)" -ne 0 ]; then SUDO="sudo"; fi

chmod +x "${PLUGIN_DIR}"/scripts/*.sh

$SUDO tee "/etc/systemd/system/${UNIT}.service" >/dev/null <<EOF
[Unit]
Description=Send this FPP player's configuration to the Illumination Toolbox
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/bin/bash ${PLUGIN_DIR}/scripts/sync.sh auto
EOF

$SUDO tee "/etc/systemd/system/${UNIT}.timer" >/dev/null <<EOF
[Unit]
Description=Illumination Toolbox sync check, every five minutes

[Timer]
OnBootSec=3min
OnUnitActiveSec=5min
RandomizedDelaySec=1min
Unit=${UNIT}.service

[Install]
WantedBy=timers.target
EOF

$SUDO tee "/etc/systemd/system/${POLL}.service" >/dev/null <<EOF
[Unit]
Description=Listen for Illumination Toolbox requests to this FPP player
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=/bin/bash ${PLUGIN_DIR}/scripts/poll.sh
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF

$SUDO systemctl daemon-reload
# enable --now starts a unit that was not running; restart makes a unit that
# was already running pick up the rewritten file, which is the upgrade case.
$SUDO systemctl enable --now "${UNIT}.timer" >/dev/null 2>&1 || true
$SUDO systemctl enable --now "${POLL}.service" >/dev/null 2>&1 || true
$SUDO systemctl restart "${UNIT}.timer" >/dev/null 2>&1 || true
$SUDO systemctl restart "${POLL}.service" >/dev/null 2>&1 || true

echo "Illumination Toolbox plugin installed. Open Status/Control -> Illumination Toolbox to pair this player."
