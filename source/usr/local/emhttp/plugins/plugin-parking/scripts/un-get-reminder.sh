# Installed by Plugin Parking as /etc/profile.d/plugin-parking.sh
#
# un-get only looks at /boot/extra, so it cannot see packages parked in /boot/extra-parked. When un-get
# is installed and something is parked, `un-get upgrade`, `cleanup` and `remove` print a short note first,
# then run the real un-get unchanged. Interactive bash shells only; un-get itself is never modified.
# PP_UNGET, PP_XPARKED and PP_UNGET_LIST exist so the test can point this at a fake tree.
if [ -n "$BASH_VERSION" ] && [ -x "${PP_UNGET:-/usr/bin/un-get}" ]; then
  case $- in
    *i*)
      un-get() {
        case "$1" in
          upgrade|cleanup|remove)
            local dir="${PP_XPARKED:-/boot/extra-parked}" list="${PP_UNGET_LIST:-/boot/config/plugins/un-get/installedpackages_list}" f n=0 tracked=""
            for f in "$dir"/*.t[xgbl]z; do
              [ -e "$f" ] || continue
              n=$((n + 1))
              if [ -f "$list" ] && grep -qxF "$(basename "$f")" "$list"; then tracked="$tracked ${f##*/}"; fi
            done
            if [ "$n" -gt 0 ]; then
              {
                echo "Plugin Parking: $n package(s) are parked in $dir, where un-get cannot see them."
                case "$1" in
                  upgrade) echo "  If un-get finds a newer version of a parked package it tracks, it downloads and installs it into /boot/extra,"
                           echo "  so that package loads at every boot again (the parked copy stays behind)." ;;
                  cleanup) echo "  cleanup only looks at /boot/extra, so it will not offer to delete parked packages. It does offer to delete any"
                           echo "  file there whose package is not installed right now, which includes one you moved back with 'Load at every"
                           echo "  boot' and have not loaded yet. When it finds something to clean up it also drops every package that is not"
                           echo "  installed from un-get's own list, parked ones included." ;;
                  remove)  echo "  remove only deletes what is in /boot/extra: a parked copy of the package stays in $dir." ;;
                esac
                [ -n "$tracked" ] && echo "  Parked and tracked by un-get:$tracked"
                echo "  See Plugins > Boot Packages to load or unpark them."
              } >&2
            fi
            ;;
        esac
        command un-get "$@"
      }
      ;;
  esac
fi
