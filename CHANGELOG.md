# Changelog

The version is `ITB_PLUGIN_VERSION` in `lib/toolbox.php`. FPP's Plugin Manager installs from `main` and
shows git history as release notes (`releaseNotesStyle: gitHistory`). Reconstructed from git history.

## 1.6.0 — 2026-10-05

- Remote requests (and the Player Failover relay, which rides on them) are off until *Let the toolbox ask
  this player…* is ticked. Because the box never saved before (see the fix below), every player
  upgrading to 1.6.0 stops listening until someone ticks it.
- `pluginInfo.json` declares `remoteAccess: internet-authenticated` instead of `none`: no port is
  opened, but the toolbox account can send the player requests from the internet. The description,
  summary and `other` say so, and that only the five request types are accepted. FPP shows the changed
  privacy block for approval on upgrade.
- Status page, about page and README say requests are off by default and what turning them on allows.
- Fix: the status page's two switches (*Send automatically* and *Let the toolbox ask this player…*)
  never saved. Their endpoint was `POST …/settings`, which FPP's own
  `/plugin/:RepoName/settings/:SettingName` route answers first, storing the body as a nameless
  setting. The endpoint is now `POST …/switches`.

## 1.5.0 — 2026-10-04

- Relays Player Failover status: when the failover plugin is installed with a role, each poll round reads
  `GET /api/plugin-apis/failover?action=status` (3 s) and sends a trimmed copy to
  `PUT /api/fpp/devices/{id}/failover` every 15 s, at once on a role or state change, and right after a
  failover request. The claim waits 12 s instead of 20 while relaying.
- New request `failover` (`takeover`, `takeover_now`, `yield`, `failback`, `resume`), passed to the
  failover plugin's own API; its refusal message is reported back.

## 1.4.2 — 2026-10-05

- Installs cleanly on FPP's Docker image. It has `systemctl` but systemd is not PID 1, so
  `daemon-reload` failed and the install script exited 1. The installer now treats a player as
  having systemd only when `/run/systemd/system` exists, and a `daemon-reload` failure after the
  units are written is logged rather than fatal.

## 1.4.1 — 2026-10-04

- `test_lights` runs the `red`, `green`, `blue` and `white` patterns the toolbox accepts, as FPP's
  RGBFill test mode; before, everything but `rgb_chase` was declined.
- `pluginInfo.json` and `LICENSE` name Arrowood Enterprises LLC, the operator the Terms name.

## 1.4.0 — 2026-10-04

- Linking needs the Terms of Service / Privacy Policy box ticked; the agreed version goes to the toolbox.
- The device token is renewed once it is a month old. A revoked token can't be renewed.
- `test_lights` is refused while fppd is playing (or its state can't be read) unless the request says `force`.

## 1.3.0 — 2026-10-03

- Snapshot adds `system.storage` (free/total bytes), `nowPlaying` and `scheduleUpcoming` (next nine days).
  Song changes and small disk drift don't count as changes.

## 1.2.0 — 2026-10-02

- Passes FPP's plugin review: a restart request sets FPP's restart flag instead of restarting fppd; the
  token and change state move to `plugindata/` (token 0600, migrated from the settings file); MAC
  addresses, serials and uuids stripped; hashed device ids for new pairings; FPP's privacy settings
  removed; POST routes require JSON; the toolbox address can't be changed over the API; poll back-off;
  own log; services run as `fpp`; tolerates no systemd.
- Snapshot pings every output controller and lists the systems FPP discovered.
- Sends the real value of each FPP setting (from FPP's `$settings`), skipping credentials, privacy
  settings and email addresses by name.

## 1.1.0 — 2026-10-02

- Five-minute timer sends only on a change (fingerprint), and at least hourly; the snapshot names the
  sections that changed and lists sequence and music files.
- Poll service: requests from the toolbox (snapshot, RGB chase light test, stop, fppd restart), with a
  switch to turn them off.
- Declares FPP 10 (`minFPPVersion` 10.0), ships the icon and the `privacy` block; status page redrawn
  with FPP's Bootstrap classes; Advanced panel removed; install from `pluginInfo.json`.

## 1.0.0 — 2026-09-02

- First version: pair a player with a code, snapshot its configuration, send it to
  `api.illuminationtoolbox.com`. Moved to the Illumination-Toolbox GitHub org.
