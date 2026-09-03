#!/bin/bash
#
# Installed by FPP after cloning the plugin. Safe to re-run ("Reinstall All").
#
# Side effects outside the plugin folder, all undone by fpp_uninstall.sh:
#   /etc/systemd/system/fpp-illumination-toolbox-sync.service
#   /etc/systemd/system/fpp-illumination-toolbox-sync.timer
#
# The timer sends a snapshot once an hour by calling the plugin's own API on
# localhost; see scripts/sync.sh. Nothing is sent until the player is paired.

set -e

. "${FPPDIR:-/opt/fpp}/scripts/common"

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
UNIT="fpp-illumination-toolbox-sync"

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
Description=Hourly Illumination Toolbox sync

[Timer]
OnBootSec=5min
OnUnitActiveSec=1h
RandomizedDelaySec=5min
Unit=${UNIT}.service

[Install]
WantedBy=timers.target
EOF

$SUDO systemctl daemon-reload
$SUDO systemctl enable --now "${UNIT}.timer" >/dev/null 2>&1 || true

echo "Illumination Toolbox plugin installed. Open Status/Control -> Illumination Toolbox to pair this player."
