# Illumination Toolbox plugin for FPP

Links a Falcon Player to an [Illumination Toolbox](https://illuminationtoolbox.com)
account and sends a snapshot of how the player is configured — channel outputs,
pixel strings, schedule, playlists, settings, plugins, and the warnings it is
showing right now. The xLights AI reads it when you ask why a prop is dark or
what a port is set to. Every tool on the account reads the same copy: the
toolbox stores the snapshot once, verbatim, and each tool takes the reading it
needs.

Secrets never leave the player. Any value whose name looks like a credential
(password, secret, token, key, PSK) is replaced with `[redacted]` before the
snapshot is built, and other plugins' settings files, network interface files
and backups are not read at all. **Status / Control → Illumination Toolbox →
See exactly what is sent** opens the redacted snapshot as JSON.

## Install

FPP → Content Setup → Plugin Manager → install from the plugin list, or paste
the address of the plugin's `pluginInfo.json` under *Install from URL*:

```
https://raw.githubusercontent.com/Illumination-Toolbox/fpp-plugin-IlluminationToolbox/main/pluginInfo.json
```

FPP reads that file to find the repository and the branch to install; the
repository's own `.git` address is not accepted there.

## Pair

1. In any toolbox tool, open the account menu and choose **FPP players**.
2. Press **Link a player**. It shows an eight-character code, good for ten minutes.
3. On the player, open **Status / Control → Illumination Toolbox**, type the
   code, and press **Link this player**. The first snapshot goes out immediately.

A show with a master and remotes pairs each of them; they appear as separate
players on the account.

## When it sends

- Every five minutes it checks for changes (a systemd timer, installed by
  `scripts/fpp_install.sh`) and sends when something changed — an output, a
  playlist, the schedule, a setting, a sequence file, a new warning. The
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
- **Restart fppd** — only when you ask for it explicitly.

Untick *Let the toolbox ask this player…* on the status page to stop
listening; the player then only sends on its own schedule. The status page
shows whether it is listening and what the last request was. A request that
is not picked up within ten minutes lapses.

## What the player keeps

Pairing leaves a device token in `config/plugin.fpp-plugin-IlluminationToolbox`.
It can upload this one player's snapshot and pick up requests meant for this
one player, and nothing else — it cannot read the account, its conversations,
or another player. It lasts a year; pair again when it lapses.

Beside it, `config/plugin.fpp-plugin-IlluminationToolbox.state.json` remembers
a fingerprint of the last snapshot sent, so the five-minute check can tell a
change from a repeat. Uninstalling the plugin deletes both files.

## How it works

| Piece | Job |
|---|---|
| `lib/toolbox.php` | Pairing, snapshot building, redaction, change detection, sending, answering requests |
| `api.php` | `/api/plugin/fpp-plugin-IlluminationToolbox/{status,pair,sync,poll,unlink,settings,preview}` |
| `status.php` | The page under Status / Control |
| `scripts/sync.sh` | `POST …/sync?trigger=timer|start|manual` on localhost; what the timer and the start hook run |
| `scripts/poll.sh` | `POST …/poll` in a loop; what the poll service runs |

The snapshot is assembled entirely from the player's own REST API
(`/api/system/info`, `/api/settings`, `/api/configfile/…`, `/api/schedule`,
and so on), never from files under the media directory, so it works the same
on a Pi, a BeagleBone, or a virtual machine.

Toolbox side: `FppController` in the Illumination Toolbox API, at `/api/fpp`.
The player sends `PUT /api/fpp/devices/{id}` and claims requests with
`POST /api/fpp/devices/{id}/commands/claim`, answering each at
`POST /api/fpp/devices/{id}/commands/{commandId}/result`.

## Notes

- If the player's web UI is password-protected, FPP still lets localhost
  call the API, which is all the timer and the page need.
- The plugin talks to `https://api.illuminationtoolbox.com`. Anyone running
  their own toolbox can point it elsewhere by posting `{"apiBaseUrl": "…"}` to
  the plugin's `settings` route; the status page no longer shows the field.
