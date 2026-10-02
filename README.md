# Illumination Toolbox plugin for FPP

Links a Falcon Player to an [Illumination Toolbox](https://illuminationtoolbox.com)
account and sends a snapshot of how the player is configured to
`api.illuminationtoolbox.com`. The xLights AI reads it when you ask why a prop
is dark or what a port is set to. Every tool on the account reads the same
copy: the toolbox stores the snapshot once, verbatim, and each tool takes the
reading it needs.

Needs FPP 10 or later.

## What is sent

- The value of each of FPP's settings, read from FPP's own settings in
  memory (FPP 10's `/api/settings` describes settings but does not give their
  values). Skipped by name, so their values are never read: FPP's
  credentials (UI and OS passwords, mail and MQTT logins, tether PSK, remote
  token), anything named like a password, key or token, FPP's eight privacy
  settings (`statsPublish`, `ShareCrashData` and the rest) and its
  email-alert addresses. Any value shaped like an email address is left out
  too.
- Channel outputs and inputs (`co-*.json`, `ci-*.json`), output processors,
  pixel overlay models, GPIO and command presets.
- The schedule, playlist names and up to 30 playlists.
- Sequence and music file names; for sequences, size, date, channel count and
  length.
- The installed plugin list, network interfaces and cape information.
- Hostname, IP addresses, FPP version, platform and hardware model, uptime,
  sensors and the warnings the player is showing right now.
- **Controllers.** Every address the player's E1.31 / ArtNet / DDP outputs send
  to, with its universes and whether it answered one ping when the snapshot
  was taken; and the FPP systems and controllers the player has discovered on
  the network (fppd's MultiSync list), each as name, address, type, firmware,
  mode, channel ranges and when it was last heard. A controller that stops
  answering, or a new one appearing, counts as a change and is sent within
  five minutes; the ping times themselves do not.

Before anything leaves the player, any value whose name looks like a
credential (password, passphrase, secret, token, key, PSK, auth…, the same
names FPP's own crash-report redactor uses) is replaced with `[redacted]`,
and MAC addresses, serial numbers and hardware uuids are removed wherever
they appear. Other plugins' settings files, network interface files and
backups are not read at all. **Status / Control → Illumination Toolbox → See
exactly what is sent** opens the redacted snapshot as JSON.

The toolbox keeps the current snapshot and up to ten earlier ones for each
player, and deletes them when you remove the player or delete your account.
[Privacy policy](https://www.illuminationtoolbox.com/privacy).

## Install

FPP → Content Setup → Plugin Manager → install from the plugin list, or paste
the address of the plugin's `pluginInfo.json` under *Install from URL*:

```
https://raw.githubusercontent.com/Illumination-Toolbox/fpp-plugin-IlluminationToolbox/main/pluginInfo.json
```

FPP reads that file to find the repository and the branch to install; the
repository's own `.git` address is not accepted there.

## Pair

1. In any toolbox tool, open **Settings** and choose **FPP players**.
2. Press **Link a player**. It shows an eight-character code, good for ten minutes.
3. On the player, open **Status / Control → Illumination Toolbox**, type the
   code, and press **Link this player**. The first snapshot goes out immediately.

A show with a master and remotes pairs each of them; they appear as separate
players on the account.

## When it sends

- Every five minutes it checks for changes (a systemd timer, installed by
  `scripts/fpp_install.sh`) and sends when something changed — an output, a
  playlist, the schedule, a setting, a sequence file, a new warning, a
  controller that stopped answering. The
  snapshot says which sections moved, so the toolbox can show what changed
  since the last one.
- At least once an hour regardless, so the toolbox still hears from a player
  nothing has happened on.
- About ninety seconds after fppd starts (`scripts/postStart.sh`).
- Whenever you press **Send now**.
- Whenever the toolbox asks (see below).

Untick *Send automatically* to send only by hand and when the toolbox asks.

## Requests from the toolbox

The toolbox cannot reach a player behind your router, so the player asks it:
a small service (`scripts/poll.sh`) keeps a request open at the toolbox and
carries out whatever comes back. Three requests exist:

- **Snapshot** — send a fresh snapshot right now. The *Request snapshot*
  button on the website and the xLights AI both use it, so "I just changed
  the outputs, check again" works without walking to the player.
- **Light test** — run FPP's RGB chase on a channel range for a few seconds,
  so you can see which prop lights. It stops by itself.
- **Restart fppd** — only when you ask for it explicitly, and never by
  restarting fppd itself: the plugin sets FPP's restart flag, as FPP's plugin
  guidelines require, so a running show is not cut off. FPP then shows its
  *FPPD Restart Required* banner, and fppd restarts when you press
  **Restart FPPD** there, or when the player next boots.

Untick *Let the toolbox ask this player…* on the status page to stop
listening; the player then only sends on its own schedule. The status page
shows whether it is listening and what the last request was. A request that
is not picked up within ten minutes lapses.

## What the player keeps

Pairing leaves a device token in
`plugindata/fpp-plugin-IlluminationToolbox/token` under FPP's media folder —
mode 0600 in a 0700 folder, and outside `config/`, so FPP's backups and crash
reports never carry it. It uploads this player's snapshot and collects the
requests you queue for it, nothing else — it cannot read the account, its
conversations, or another player. It lasts a year; pair again when it lapses.
A player paired by 1.1 had the token in the settings file; the first run of
1.2 moves it and blanks the old line.

Beside it, `state.json` remembers a fingerprint of the last snapshot sent, so
the five-minute check can tell a change from a repeat. **Unlink** deletes the
token; uninstalling the plugin deletes the whole folder and the settings file
`config/plugin.fpp-plugin-IlluminationToolbox` (switches, who it is linked to,
what happened last).

What the plugin does goes to `plugin-fpp-plugin-IlluminationToolbox.log` in
FPP's log folder, so it shows in FPP's log viewer and support zip: each send
(why, how big, whether it worked), each request it carried out and how it
went, pairing and unlinking. Skips and an unreachable toolbox are written at
most once an hour each. Never the token, never the snapshot.

## How it works

| Piece | Job |
|---|---|
| `lib/toolbox.php` | Pairing, snapshot building, redaction, change detection, sending, answering requests |
| `api.php` | `/api/plugin/fpp-plugin-IlluminationToolbox/{status,pair,sync,poll,unlink,settings,preview}` |
| `status.php` | The page under Status / Control |
| `scripts/sync.sh` | `POST …/sync?trigger=timer|start|manual` on localhost; what the timer and the start hook run |
| `scripts/poll.sh` | `POST …/poll` in a loop, backing off when the toolbox cannot be reached; what the poll service runs |

Every `POST` to the plugin's routes must carry `Content-Type: application/json`
(an empty `{}` body is fine) or it is refused with 415, so a web page open in
your browser cannot post a plain form to the player and unlink it or run a
light test. The timer and the poll service run as the `fpp` user.

The snapshot is assembled from the player's own REST API
(`/api/system/info`, `/api/configfile/…`, `/api/schedule`, and so on) and,
for setting values, FPP's own settings already loaded in the request — never
from files under the media directory — so it works the same on a Pi, a
BeagleBone, or a virtual machine.

Toolbox side: `FppController` in the Illumination Toolbox API, at `/api/fpp`.
The player sends `PUT /api/fpp/devices/{id}` and claims requests with
`POST /api/fpp/devices/{id}/commands/claim`, answering each at
`POST /api/fpp/devices/{id}/commands/{commandId}/result`.

## Notes

- If the player's web UI is password-protected, FPP still lets localhost
  call the API, which is all the timer and the page need.
- The plugin talks to `https://api.illuminationtoolbox.com`, and no route or
  page changes that. Anyone running their own toolbox can edit the
  `apiBaseUrl` line in `config/plugin.fpp-plugin-IlluminationToolbox` by hand
  before pairing.
- On a player without systemd (FPP on macOS, for one) the installer skips the
  timer and the request listener; the status page and **Send now** still work.
