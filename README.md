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
the repository URL under *Install from URL*:

```
https://github.com/Illumination-Toolbox/fpp-plugin-IlluminationToolbox.git
```

## Pair

1. In any toolbox tool, open the account menu and choose **FPP players**.
2. Press **Link a player**. It shows an eight-character code, good for ten minutes.
3. On the player, open **Status / Control → Illumination Toolbox**, type the
   code, and press **Link this player**. The first snapshot goes out immediately.

A show with a master and remotes pairs each of them; they appear as separate
players on the account.

## When it sends

- Once an hour (a systemd timer, installed by `scripts/fpp_install.sh`).
- About ninety seconds after fppd starts (`scripts/postStart.sh`).
- Whenever you press **Send now**.

Untick *Send automatically* to send only by hand.

## What the player keeps

Pairing leaves a device token in `config/plugin.fpp-plugin-IlluminationToolbox`.
It can upload this one player's snapshot to the toolbox and nothing else —
it cannot read the account, its conversations, or another player. It lasts a
year; pair again when it lapses. Uninstalling the plugin deletes the file.

## How it works

| Piece | Job |
|---|---|
| `lib/toolbox.php` | Pairing, snapshot building, redaction, sending |
| `api.php` | `/api/plugin/fpp-plugin-IlluminationToolbox/{status,pair,sync,unlink,settings,preview}` |
| `status.php` | The page under Status / Control |
| `scripts/sync.sh` | `POST …/sync?auto=1` on localhost; what the timer and the start hook run |

The snapshot is assembled entirely from the player's own REST API
(`/api/system/info`, `/api/settings`, `/api/configfile/…`, `/api/schedule`,
and so on), never from files under the media directory, so it works the same
on a Pi, a BeagleBone, or a virtual machine.

Toolbox side: `FppController` in the Illumination Toolbox API, at `/api/fpp`.

## Notes

- If the player's web UI is password-protected, FPP still lets localhost
  call the API, which is all the timer and the page need.
- The toolbox API address can be changed under *Advanced* on the status page,
  for anyone running their own toolbox.
