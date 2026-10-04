# Troubleshooting

Start with **Status / Control → Illumination Toolbox** (it asks the toolbox whether the link still stands)
and the plugin's log, `plugin-fpp-plugin-IlluminationToolbox.log`, in FPP's log viewer. The log records
every send, request, pairing and unlink; it never holds the token or the snapshot.

## Pairing

| Message | Fix |
|---|---|
| "Tick the box to agree to the Terms of Service and Privacy Policy first." | Tick the box, then **Link this player**. |
| "That code is not valid. Codes last ten minutes and work once…" | Press **Link a player** in the toolbox again and use the new code. |
| "Could not reach the toolbox: …" | The player has no internet access or DNS. Check FPP's network settings and that `https://api.illuminationtoolbox.com` is reachable from the player. |
| "Paired, but this player could not store its token…" | The media folder isn't writable by the web server. Check free space and ownership of `plugindata/`. |

## "The toolbox no longer accepts this player's token. Pair again."

The player was removed in the toolbox, all devices were signed out, or the token expired. Pair again
with a new code; unless you pressed **Unlink** first, the player keeps its device id.

## Nothing is being sent

- **Send automatically** off: the timer and start hook skip (logged once an hour). **Send now** still works.
- Not linked: pair first.
- Nothing changed: the timer only sends on a change, and at least hourly.
- No systemd (FPP on macOS): the installer skips the timer and the request listener. Check with
  `systemctl status fpp-illumination-toolbox-sync.timer`.
- After an upgrade, if the units look stale, use Plugin Manager's reinstall; `fpp_install.sh` rewrites
  and restarts them.

## Requests from the toolbox don't arrive

- **Let the toolbox ask this player…** must be ticked. It is off on a new install and on every player
  upgraded to 1.6.0; tick it once after upgrading.
- `systemctl status fpp-illumination-toolbox-poll` should be active. When the toolbox can't be reached
  the loop backs off up to five minutes; it recovers by itself.
- A request not collected within ten minutes lapses; the toolbox holds at most five waiting
  requests per player.

## Light test refused

"Not testing: the player is playing …" — a show is running. Stop it, or ask again with *force*.
"pattern … is not supported" — only `rgb_chase`, `red`, `green`, `blue` and `white` exist.

## Restart requested but fppd didn't restart

By design. The plugin only sets FPP's restart flag; press **Restart FPPD** on the player's banner, or
reboot it.

## Control Booth shows no failover status

The Player Failover plugin must be installed with a role other than *off*, the player linked, **Send
automatically** on and the request listener running. Check
`curl -s 'http://127.0.0.1/api/plugin-apis/failover?action=status'` on the player answers within three
seconds. A failover request that is refused shows "Player Failover said: …" under *Last request*.

## Anyone on the network can unlink the player

FPP has no login by default. Turn on **UI password** (Settings → UI).
