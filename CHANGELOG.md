# Plugin Parking

## 2026.09.26c

- Boot Packages: after you park a package, Plugin Parking offers to park what it needs too (parking `make` also offers `guile` and `gc`). If you say yes, each one is checked first, and it stays where it is if another boot package needs it or a plugin or script seems to use it. The result lists what was parked and, for anything left alone, why.

## 2026.09.26b

- Boot Packages: a program called through a full path (`/usr/bin/ipmitool ...`) now counts as a use, and Plugin Parking no longer counts its own changelog as a user of a package.

## 2026.09.26a

- The "used by" hints on the Boot Packages tab are more accurate. They now also look inside plugins' own scripts (so a plugin that calls `ipmitool` from its PHP is found), and ordinary words that happen to be program names (`size`, `strip`, `make`...) no longer count as a sign that a package is used.

## 2026.09.26

First release.

- A **Parking** tab on the Plugins page. Park a plugin and it stays installed on the flash drive but is not loaded at boot. It shows what each plugin costs at boot (read from the last boot's syslog) and whether it runs anything on its own (schedules, background services, array hooks), so you can tell a tool you use now and then from something that has to stay loaded.
- A **Park** button on each row of the built-in Installed Plugins list, and a "parked" tag on rows that are parked.
- **Load now** for a parked plugin. It checks for a newer version first and asks whether to install the cached copy or the latest one, and whether to keep it parked afterwards or load it at every boot again.
- A **Boot Packages** tab for the packages in `/boot/extra` (where un-get keeps what it installs and where Unraid installs from at every boot). Park a package to keep the file without installing it at boot; load or unload it on demand. It shows what needs each package, judged from shared libraries and script interpreters, plus plugins and user scripts that mention it.
- Removing Plugin Parking asks what to do with anything still parked: move it back so it loads at every boot again, or keep it parked. From a terminal it asks there. With no answer it moves everything back rather than leave anything stranded.
