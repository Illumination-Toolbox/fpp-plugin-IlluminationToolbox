# Illumination Toolbox plugin for FPP

Links a Falcon Player to an [Illumination Toolbox](https://illuminationtoolbox.com)
account and sends a snapshot of how the player is configured to
`api.illuminationtoolbox.com`. The xLights AI reads it when you ask why a prop
is dark or what a port is set to, and the other toolbox tools read the same copy.

Needs FPP 10 or later.

## What is sent

- The value of each of FPP's settings, except credentials (UI and OS
  passwords, mail and MQTT logins, tether PSK, remote token), anything named
  like a password, key or token, FPP's privacy settings and its email-alert
  addresses. Any value shaped like an email address is left out too.
- Channel outputs and inputs, output processors, pixel overlay models, GPIO
  and command presets.
- The schedule, playlist names and up to 30 playlists, and the playlists the
  schedule will start in the next nine days.
- What is playing at the moment: idle, playing, paused or testing, and which
  playlist, sequence and song.
- Free and total disk space.
- Sequence and music file names; for sequences, size, date, channel count and
  length.
- The installed plugin list, network interfaces and cape information.
- Hostname, IP addresses, FPP version, platform and hardware model, uptime,
  sensors and the warnings the player is showing.
- The controllers the player sends to and whether they answer a ping, and the
  FPP systems and controllers it has discovered on the network.
- With the [Player Failover](https://github.com/Illumination-Toolbox/fpp-plugin-IlluminationToolbox-Failover)
  plugin installed, a short status of its own (see below).

Before anything leaves the player, any value whose name looks like a
credential is replaced with `[redacted]`, and MAC addresses, serial numbers
and hardware uuids are removed. Other plugins' settings files, network
interface files and backups are not read at all. **Status / Control →
Illumination Toolbox → See exactly what is sent** shows the snapshot as it
would go.

The toolbox keeps the current snapshot and up to ten earlier ones for each
player, and deletes them when you remove the player or delete your account.
[Privacy policy](https://www.illuminationtoolbox.com/privacy).

## Install

FPP → Content Setup → Plugin Manager → install from the plugin list, or paste
this under *Install from URL*:

```
https://raw.githubusercontent.com/Illumination-Toolbox/fpp-plugin-IlluminationToolbox/main/pluginInfo.json
```

## Pair

1. In any toolbox tool, open **Settings** and choose **FPP players**.
2. Press **Link a player**. It shows a code, good for ten minutes.
3. On the player, open **Status / Control → Illumination Toolbox**, type the
   code, tick the box to agree to the
   [Terms of Service](https://www.illuminationtoolbox.com/terms) and
   [Privacy Policy](https://www.illuminationtoolbox.com/privacy), and press
   **Link this player**. The first snapshot goes out immediately.

A show with a master and remotes pairs each of them; they appear as separate
players on the account.

## When it sends

- When something changes: an output, a playlist, the schedule, a setting, a
  sequence file, a new warning, a show starting or stopping, a disk filling
  up, a controller that stopped answering. The player checks every five
  minutes.
- At least once an hour regardless.
- Shortly after fppd starts.
- Whenever you press **Send now**, or the toolbox asks for a fresh one.

Untick *Send automatically* to send only by hand and when the toolbox asks.

## Requests from the toolbox

Requests are **off** until you tick *Let the toolbox ask this player…* on the
status page. While it is ticked, anyone signed in to the linked toolbox
account can ask the player, from anywhere on the internet, to:

- **Send a snapshot** right now.
- **Run a light test**: an RGB chase or a solid red, green, blue or white fill
  on a channel range, for up to ten minutes. It stops by itself, and is
  refused while a show is playing unless the request says to go ahead anyway.
- **Stop a light test.**
- **Flag fppd for a restart.** The plugin only raises FPP's own *FPPD Restart
  Required* banner; fppd restarts when you press **Restart FPPD** or the player
  next boots, so a running show is never cut off.
- **Switch the show between failover players**, with the Player Failover
  plugin installed: take over, hand over and stand by, fail back to the
  primary, or stop holding in standby.

That is all it accepts: no shell commands, files or other FPP pages. No port
is opened. The player keeps a connection open to the toolbox and picks up
requests through it, which is why the plugin declares internet remote access.
A request that is not picked up within ten minutes lapses. Untick the box to
stop listening.

## Player failover

When the Player Failover plugin is installed and given a role, the player
also sends its failover status every fifteen seconds, and straight away when
it changes: role and state, the other player's name, address and whether it
answers, what is playing, and its last ten events. Control Booth uses it to
show which player has the show. It needs the player linked, *Send
automatically* on and requests ticked.

## What the player keeps

Pairing stores a device token in the plugin's data folder, readable only by
FPP and kept out of FPP's backups and crash reports. It can only upload this
player's snapshot and collect requests for it; it cannot read the account or
another player. It renews itself while the player stays linked. **Unlink**
deletes it, and uninstalling the plugin deletes its data folder and settings.

What the plugin does is logged to `plugin-fpp-plugin-IlluminationToolbox.log`
in FPP's log viewer: each send, each request and how it went, pairing and
unlinking. Never the token or the snapshot.

## Notes

- FPP has no login by default, so anyone on your network who can open the
  player's pages can unlink it or link it to another account. Turn on **UI
  password** (Settings → UI) to stop that.
- On a player without systemd running (FPP on macOS, or FPP's Docker image)
  there is no automatic sending or request listener; the status page and
  **Send now** still work.

More: [`docs/troubleshooting.md`](docs/troubleshooting.md),
[`docs/development.md`](docs/development.md) and [`CHANGELOG.md`](CHANGELOG.md).
