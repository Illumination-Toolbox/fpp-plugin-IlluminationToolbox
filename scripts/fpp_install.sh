#!/bin/bash
#
# Installed by FPP after cloning the plugin. Safe to re-run ("Reinstall All"),
# and an upgrade relies on that: the unit files are rewritten every time and
# the units restarted, so a new cadence or a new script takes effect without
# a reboot. FPP runs this as root.
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
#
# Both run as the fpp user when there is one: all they do is call the
# plugin's API on localhost, which needs no privileges. On a player without
# systemd running (FPP on macOS, or FPP's Docker image, where systemctl and
# /etc/systemd/system exist but systemd is not PID 1) there is nothing to
# install; the plugin's page and Send now still work, and the timer and
# requests from the toolbox do not.

set -e

: "${FPPDIR:=/opt/fpp}"
if [ -f "${FPPDIR}/scripts/common" ]; then
	. "${FPPDIR}/scripts/common"
fi

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_LOG="${LOGDIR:-${MEDIADIR:-/home/fpp/media}/logs}/plugin-fpp-plugin-IlluminationToolbox.log"
UNIT="fpp-illumination-toolbox-sync"
POLL="fpp-illumination-toolbox-poll"

# The log is the plugin's, written mostly by the web server as fpp; a line
# from this root script must not leave it owned by root. The logs folder
# belongs to fpp, so a symlink there could point anywhere: root never writes
# through one or hands its target to fpp.
log() {
	echo "$1"
	[ -L "${PLUGIN_LOG}" ] && return 0
	echo "$(date -u '+%Y-%m-%dT%H:%M:%SZ') $1" >> "${PLUGIN_LOG}" 2>/dev/null || true
	if id -u fpp >/dev/null 2>&1; then
		chown -h fpp:fpp "${PLUGIN_LOG}" 2>/dev/null || true
	fi
}

chmod +x "${PLUGIN_DIR}"/scripts/*.sh

# /run/systemd/system exists only while systemd is running as init; it is the
# test sd_booted() uses. systemctl being installed is not enough: in a
# container it is, and every call fails with "System has not been booted
# with systemd as init system".
if ! command -v systemctl >/dev/null 2>&1 || [ ! -d /run/systemd/system ] || [ ! -d /etc/systemd/system ]; then
	log "install: no systemd on this player, so the five-minute check and the request listener were not set up"
	exit 0
fi

RUN_AS=""
if id -u fpp >/dev/null 2>&1; then
	RUN_AS="User=fpp"
fi

tee "/etc/systemd/system/${UNIT}.service" >/dev/null <<EOF
[Unit]
Description=Send this FPP player's configuration to the Illumination Toolbox
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
${RUN_AS}
ExecStart=/bin/bash ${PLUGIN_DIR}/scripts/sync.sh auto
EOF

tee "/etc/systemd/system/${UNIT}.timer" >/dev/null <<EOF
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

tee "/etc/systemd/system/${POLL}.service" >/dev/null <<EOF
[Unit]
Description=Listen for Illumination Toolbox requests to this FPP player
After=network-online.target
Wants=network-online.target

[Service]
${RUN_AS}
ExecStart=/bin/bash ${PLUGIN_DIR}/scripts/poll.sh
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF

# The units are written; from here on a failure is logged, never fatal, so
# FPP does not report the install as failed over a unit that will not start.
systemctl daemon-reload >/dev/null 2>&1 || log "install: systemctl daemon-reload failed; the timer and request listener may not run until the next reboot"
# enable --now starts a unit that was not running; restart makes a unit that
# was already running pick up the rewritten file, which is the upgrade case.
systemctl enable --now "${UNIT}.timer" >/dev/null 2>&1 || true
systemctl enable --now "${POLL}.service" >/dev/null 2>&1 || true
systemctl restart "${UNIT}.timer" >/dev/null 2>&1 || true
systemctl restart "${POLL}.service" >/dev/null 2>&1 || true

log "install: version $(sed -n "s/.*define('ITB_PLUGIN_VERSION', '\([^']*\)').*/\1/p" "${PLUGIN_DIR}/lib/toolbox.php") installed; timer and request listener running${RUN_AS:+ as fpp}"
echo "Illumination Toolbox plugin installed. Open Status/Control -> Illumination Toolbox to pair this player."
