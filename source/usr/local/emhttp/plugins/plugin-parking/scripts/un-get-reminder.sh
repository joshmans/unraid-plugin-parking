# Installed by Plugin Parking as /etc/profile.d/plugin-parking.sh
#
# un-get only looks at /boot/extra, so it cannot see packages parked in /boot/extra-parked. While a package is
# parked, Plugin Parking takes its entry out of un-get's list (and puts it back on "Load at every boot"), so
# `un-get upgrade` and `cleanup` leave it alone. When un-get is installed and something is parked, those two
# commands and `remove` also print a short note first, then run the real un-get unchanged. Interactive bash
# shells only; un-get itself is never modified.
# PP_UNGET, PP_XPARKED, PP_UNGET_LIST and PP_UNGET_HELD exist so the test can point this at a fake tree.
if [ -n "$BASH_VERSION" ] && [ -x "${PP_UNGET:-/usr/bin/un-get}" ]; then
  case $- in
    *i*)
      un-get() {
        case "$1" in
          upgrade|cleanup|remove)
            local dir="${PP_XPARKED:-/boot/extra-parked}" list="${PP_UNGET_LIST:-/boot/config/plugins/un-get/installedpackages_list}"
            local heldf="${PP_UNGET_HELD:-/boot/config/plugins/plugin-parking/unget-held}" f n=0 held="" stale=""
            for f in "$dir"/*.t[xgbl]z; do
              [ -e "$f" ] || continue
              n=$((n + 1))
              if [ -f "$list" ] && grep -qxF "${f##*/}" "$list"; then stale="$stale ${f##*/}"; fi
              if [ -f "$heldf" ] && grep -qxF "${f##*/}" "$heldf"; then held="$held ${f##*/}"; fi
            done
            if [ "$n" -gt 0 ]; then
              {
                echo "Plugin Parking: $n package(s) are parked in $dir, where un-get cannot see them."
                case "$1" in
                  upgrade) echo "  Parked packages are taken out of un-get's list while they are parked, so upgrade leaves them alone."
                           echo "  They go back into the list when you load them at every boot (Plugins > Boot Packages)." ;;
                  cleanup) echo "  cleanup only looks at /boot/extra, so parked packages are safe. A package you moved back but have not"
                           echo "  installed yet would be offered for deletion, because cleanup deletes files whose package is not installed." ;;
                  remove)  echo "  remove only deletes what is in /boot/extra: a parked copy of the package stays in $dir." ;;
                esac
                [ -n "$held" ] && echo "  Held out of un-get's list:$held"
                if [ -n "$stale" ]; then
                  echo "  STILL in un-get's list, so upgrade or cleanup can affect them:$stale"
                  echo "  Open Plugins > Boot Packages once to take them out."
                fi
              } >&2
            fi
            ;;
        esac
        command un-get "$@"
      }
      ;;
  esac
fi
