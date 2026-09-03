#!/bin/bash
#
# Undoes everything fpp_install.sh did outside the plugin folder, and forgets
# the device token. Safe to run more than once.

. "${FPPDIR:-/opt/fpp}/scripts/common"

UNIT="fpp-illumination-toolbox-sync"

SUDO=""
if [ "$(id -u)" -ne 0 ]; then SUDO="sudo"; fi

$SUDO systemctl disable --now "${UNIT}.timer" >/dev/null 2>&1 || true
$SUDO rm -f "/etc/systemd/system/${UNIT}.timer" "/etc/systemd/system/${UNIT}.service"
$SUDO systemctl daemon-reload >/dev/null 2>&1 || true

# The settings file holds the device token. Leaving it behind would leave a
# credential on a player that no longer has the plugin that owns it.
MEDIA="${MEDIADIR:-/home/fpp/media}"
rm -f "${MEDIA}/config/plugin.fpp-plugin-IlluminationToolbox"

echo "Illumination Toolbox plugin removed."
