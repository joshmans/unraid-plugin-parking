# Plugin Parking for Unraid

Keep plugins and boot packages installed, but not loaded at boot. Load them on demand when you need them, without having to remember what they were called in Community Apps.

It adds two tabs to Unraid's own **Plugins** page, plus a **Park** button on each row of the Installed Plugins list.

## Why

Unraid installs every plugin in `/boot/config/plugins` and every package in `/boot/extra` at each boot, one after another, before the web UI and the array start. Each plugin costs a second or two, a few cost far more (unpacking large packages from a USB flash drive), and many of them are tools you only use now and then: a cloud-sync client, a disk tester, a log viewer. Uninstalling them means finding them again later. Parking them keeps them one click away and out of the boot path.

## What it does

### Parking tab
- Lists every plugin with what it does, what it cost at the last boot (read from the syslog, and remembered when the log rotates) and what it does **on its own**: scheduled jobs, a background service, array start/stop hooks, or a process running from its folders right now.
- **Park** keeps the plugin installed but stops it loading at the next boot. It keeps running until then, and its settings stay where they are. A plugin that runs things unattended gets a warning that lists what will stop.
- **Load now** installs a parked plugin without a reboot. It first checks the plugin's update URL and, if a newer version exists, asks whether to install **the latest or the cached copy**. It then asks whether to keep it parked (loaded until the next reboot) or load it at every boot from now on. Offline is fine: the cached copy needs no network.
- **Load at every boot** moves it back to the normal plugins folder.
- Core plugins (`dynamix.*`, Community Applications) and Plugin Parking itself cannot be parked.

### Installed Plugins list
Each row gets a **Park** button next to Remove, and a "parked" tag once it is parked.

### Boot Packages tab
The packages in `/boot/extra` are what Unraid installs at every boot, and where **un-get** keeps what it installs. This tab lists them with their size, boot cost and description, and works out what needs what from shared libraries and script interpreters (so parking `python3` warns that `meson` needs it), plus plugins and user scripts that seem to use a package.
- **Park** moves the file to `/boot/extra-parked`: still on the flash drive, not installed at boot. If the package needs others that also load at boot (`make` needs `guile`, which needs `gc`), you are then offered to park those too. Each one is checked first and left alone if another boot package needs it or a plugin or script seems to use it; you are told what was parked and why anything was kept.
- **Load now** installs it from there (loading any parked packages it needs first), **Unload now** removes it from the running system, and **Load at every boot** puts the file back.

### Removing Plugin Parking
If anything is still parked, removing the plugin asks what to do with it: move it all back so it loads at every boot again, or keep it parked. The Plugins page asks before the removal starts, a terminal asks at the prompt, and if there is no answer at all everything is moved back rather than left where nothing would ever load it.

## Install

In Unraid, go to **Plugins → Install Plugin** and paste:

```
https://raw.githubusercontent.com/joshmans/unraid-plugin-parking/main/plugin-parking.plg
```

Requires Unraid 7.0 or newer. Then open **Plugins** and look for the **Parking** and **Boot Packages** tabs.

## How it works

Unraid's boot script loops over `/boot/config/plugins/*.plg` and `/boot/extra`. A file outside those folders is simply not loaded, so parking moves the file to `/boot/config/plugins-parked` or `/boot/extra-parked` and nothing else: the plugin's own folder, settings and cached packages stay where they were. Loading runs Unraid's own `plugin install` (or `upgradepkg` for packages) on the parked file. While a parked plugin is loaded, Unraid's registration is pointed at the parked copy so the Plugins page keeps working.

## Limits

- **Dependencies are a hint.** They come from shared-library linkage and script interpreters. A Python import, a program a plugin's own scripts call, or anything run through `exec` is not visible, so "nothing found" is not a guarantee.
- **A parked plugin is not update-checked** by Unraid, and Community Apps lists it as not installed. That is what the update check on Load now is for.
- **Parking does not unload anything.** A plugin stays loaded until the next reboot. Removing a plugin from the running system is what the Plugins page's Remove button does, and that runs the plugin's own uninstall script, so it is not offered here. Packages can be unloaded because removing one leaves no data behind.
- **un-get** does not know about parked packages, and two of its commands can undo parking. `un-get upgrade` downloads and installs a newer version of a parked package it tracks, so it loads at every boot again. `un-get cleanup` offers to delete files in `/boot/extra` whose package is not installed (a package you moved back with "Load at every boot" and have not loaded yet counts), and drops uninstalled packages from its own list, parked ones included. `un-get remove` leaves the parked copy behind. When un-get is installed and something is parked, the Boot Packages tab shows a notice about this, and in a terminal those three commands print a short reminder before running (a small script in `/etc/profile.d`, interactive bash only; un-get itself is not modified).
- Extras such as `event/started` run only when a plugin is loaded, so a parked plugin's array hooks and schedules do not run until you load it.

## Tested

Should work on Unraid 7.0 and newer; tested only on **7.4.0-beta.3**. On a real server the plugin's operations were exercised end to end: installing, listing plugins and packages, parking and unparking a plugin, the update check against its real URL, loading a parked plugin, parking, unloading, loading and restoring a package, and all four ways of answering the removal question (choice recorded as keep or restore, no answer, and an interactive terminal). The Parking tabs' JavaScript is covered by automated tests against a copy of the Plugins page's own markup, but has not yet been clicked through in a browser. If something looks different on your version, please [open an issue](https://github.com/joshmans/unraid-plugin-parking/issues) with your Unraid version.

## Development

```sh
tests/run.sh                 # the PHP library, against a fake Unraid tree; needs only php-cli
npm install jsdom            # once, for the browser-side tests
node tests/ui/ui_test.js     # the tab script against a copy of the Plugins page markup
./build.sh 2026.09.26        # builds packages/plugin-parking-<version>.txz and stamps the plg
```

Release by committing the stamped `.plg` and uploading the exact built `.txz` to a GitHub release whose tag equals the version. Every rebuild that is installed needs a new version: Unraid only offers an update when the `.plg` version changes.

## License

MIT
