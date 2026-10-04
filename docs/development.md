# Development and releases

## Layout

| Path | Job |
|---|---|
| `lib/toolbox.php` | Everything: pairing, snapshot, redaction, change detection, sending, requests, failover relay |
| `api.php` | The plugin's own REST routes on the player |
| `status.php`, `about.php`, `menu.inc` | FPP pages: Status / Control and Help |
| `scripts/fpp_install.sh`, `fpp_uninstall.sh` | Run by FPP as root; write/remove the systemd units and the data folder |
| `scripts/sync.sh`, `poll.sh`, `postStart.sh` | Timer, request listener and fppd start hook; all call the plugin's API on localhost |
| `pluginInfo.json` | Plugin Manager listing, FPP version range and the `privacy` disclosure |

There is no build step and no CI; PHP runs as-is under FPP's Apache.

## Trying a change

FPP installs plugins with `git clone` from the branch in `pluginInfo.json` (`main`). To test before
pushing, copy the folder to a player's `/home/fpp/media/plugins/fpp-plugin-IlluminationToolbox/` and
run `sudo scripts/fpp_install.sh`, or point a test player at a branch. Then:

```
curl -s http://PLAYER/api/plugin/fpp-plugin-IlluminationToolbox/status
curl -s http://PLAYER/api/plugin/fpp-plugin-IlluminationToolbox/preview | less     # what would be sent
curl -s -X POST -H 'Content-Type: application/json' -d '{}' \
  'http://PLAYER/api/plugin/fpp-plugin-IlluminationToolbox/sync?trigger=manual'
```

Check `php -l` on each PHP file, and that the plugin still works on a player without the Player
Failover plugin and without systemd.

## Releasing

1. Bump `ITB_PLUGIN_VERSION` in `lib/toolbox.php` (the installer logs it; the snapshot and User-Agent carry it).
2. If the Terms changed, bump `ITB_TERMS_VERSION`.
3. If what is sent changed, update `pluginInfo.json`'s `description` and `privacy` block, `about.php`,
   and the README's "What is sent".
4. Add a section to `CHANGELOG.md`.
5. Commit to `main` with a subject that starts with the version (`1.6.0: …`) and push. Players with
   updates allowed see it in Plugin Manager; release notes come from git history.

Toolbox-side changes must be deployed before a plugin release that depends on them; a new request
type is refused by the toolbox until then.
