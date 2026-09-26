#!/bin/bash
# The un-get reminder (source/.../scripts/un-get-reminder.sh) against a fake un-get and a fake parked folder.
cd "$(dirname "$0")" || exit 1
S="$PWD/../source/usr/local/emhttp/plugins/plugin-parking/scripts/un-get-reminder.sh"
T=$(mktemp -d)
mkdir -p "$T/parked" "$T/bin"
touch "$T/parked/make-4.4.1-x86_64-1.txz" "$T/parked/gc-8.2.12-x86_64-1.txz" "$T/parked/notes.txt"
printf 'make-4.4.1-x86_64-1.txz\n' > "$T/list"
printf '#!/bin/bash\necho "REAL un-get: $*"\n' > "$T/bin/un-get"; chmod +x "$T/bin/un-get"
pass=0; fail=0
check() { if [ "$2" = ok ]; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "FAIL: $1"; fi; }
run() { PATH="$T/bin:$PATH" PP_UNGET="${PP_UNGET_OVERRIDE:-$T/bin/un-get}" PP_XPARKED="$T/parked" PP_UNGET_LIST="$T/list" bash "${2:--i}" -c ". '$S'; $1" 2>&1 | grep -v -e "no job control" -e "cannot set terminal process group"; }
has() { case "$1" in *"$2"*) echo ok;; *) echo no;; esac; }

out=$(run "un-get upgrade --force")
check "upgrade: note names the parked count (notes.txt not counted)" "$(has "$out" '2 package(s) are parked')"
check "upgrade: explains it brings a package back to /boot/extra" "$(has "$out" 'loads at every boot again')"
check "upgrade: lists the parked package un-get tracks" "$(has "$out" 'tracked by un-get: make-4.4.1-x86_64-1.txz')"
check "upgrade: the real un-get still runs with the same arguments" "$(has "$out" 'REAL un-get: upgrade --force')"
out=$(run "un-get cleanup")
check "cleanup: explains the moved-back trap" "$(has "$out" "Load at every")"
check "cleanup: the real un-get still runs" "$(has "$out" 'REAL un-get: cleanup')"
out=$(run "un-get remove make")
check "remove: says the parked copy stays" "$(has "$out" 'parked copy of the package stays')"
out=$(run "un-get list")
check "other commands: no note" "$([ "$out" = 'REAL un-get: list' ] && echo ok || echo no)" 
out=$(run "un-get upgrade 2>/dev/null")
check "the note goes to stderr, un-get's output stays clean" "$([ "$out" = 'REAL un-get: upgrade' ] && echo ok || echo no)"
out=$(PP_UNGET_OVERRIDE="$T/missing" run "type -t un-get")
check "un-get not installed: nothing is defined" "$([ "$out" = file ] && echo ok || echo no)"
out=$(run "type -t un-get" "+i")
check "non-interactive shells: nothing is defined" "$([ "$out" = file ] && echo ok || echo no)"
rm -f "$T"/parked/*.txz
out=$(run "un-get upgrade")
check "nothing parked: no note" "$([ "$out" = 'REAL un-get: upgrade' ] && echo ok || echo no)"
rm -f "$T/parked/notes.txt" "$T/bin/un-get" "$T/list"; rmdir "$T/parked" "$T/bin" "$T"
echo "unget_reminder_test: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
