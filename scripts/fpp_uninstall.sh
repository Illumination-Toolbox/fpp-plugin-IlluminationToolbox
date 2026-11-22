#!/bin/bash
#
# Undoes everything fpp_install.sh did outside the plugin folder, and forgets
# the device token. Safe to run more than once. FPP runs this as root.

: "${FPPDIR:=/opt/fpp}"
if [ -f "${FPPDIR}/scripts/common" ]; then
	. "${FPPDIR}/scripts/common"
fi

UNIT="fpp-illumination-toolbox-sync"
POLL="fpp-illumination-toolbox-poll"

if command -v systemctl >/dev/null 2>&1; then
	systemctl disable --now "${UNIT}.timer" >/dev/null 2>&1 || true
	systemctl disable --now "${POLL}.service" >/dev/null 2>&1 || true
fi
rm -f "/etc/systemd/system/${UNIT}.timer" "/etc/systemd/system/${UNIT}.service" "/etc/systemd/system/${POLL}.service"
if command -v systemctl >/dev/null 2>&1; then
	systemctl daemon-reload >/dev/null 2>&1 || true
fi

# The data folder holds the device token. Leaving it behind would leave a
# credential on a player that no longer has the plugin that owns it; the
# state file beside it only remembers what was last sent, but it is ours
# too. The settings file goes as well, and so does the state file 1.1 kept
# beside it.
#
# Except on a Reinstall: FPP 10.4+ runs this script with
# FPP_PLUGIN_REINSTALL=1 before installing the same plugin again, and the
# player should come back still linked (guidelines §2.12). Only the systemd
# units above are removed then; fpp_install.sh puts them back.
MEDIA="${MEDIADIR:-/home/fpp/media}"
if [ "${FPP_PLUGIN_REINSTALL:-0}" != "1" ]; then
	DATA="${MEDIA}/plugindata/fpp-plugin-IlluminationToolbox"
	[ -d "${DATA}" ] && rm -rf "${DATA}"
	rm -f "${MEDIA}/config/plugin.fpp-plugin-IlluminationToolbox" \
	      "${MEDIA}/config/plugin.fpp-plugin-IlluminationToolbox.state.json" \
	      "${MEDIA}/config/plugin.fpp-plugin-IlluminationToolbox.state.json.tmp"
	echo "Illumination Toolbox plugin removed."
else
	echo "Illumination Toolbox plugin removed for reinstall; the link to the toolbox is kept."
fi
exit 0
