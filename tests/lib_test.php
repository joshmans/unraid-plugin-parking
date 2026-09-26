<?php
/* Runs the library against a fake Unraid tree. Needs only php-cli. */
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/plugin-parking/include/lib.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; return; }
    $fail++;
    echo "FAIL: $label" . ($detail !== '' ? "\n      $detail" : '') . "\n";
}

function tmproot(): string {
    $d = sys_get_temp_dir() . '/pp-test-' . bin2hex(random_bytes(4));
    foreach (['boot/config/plugins', 'boot/extra', 'usr/local/emhttp/plugins', 'var/log/plugins', 'var/log/packages', 'etc/rc.d', 'usr/local/sbin'] as $x) mkdir("$d/$x", 0777, true);
    putenv("PP_ROOT=$d");
    return $d;
}
function rmtree(string $d): void {
    if (!is_dir($d)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        ($f->isDir() && !$f->isLink()) ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($d);
}
function put(string $f, string $c = ''): void { @mkdir(dirname($f), 0777, true); file_put_contents($f, $c); }

function plg(string $name, string $ver, string $url = 'https://example.test/x.plg', string $extra = ''): string {
    return "<?xml version='1.0' standalone='yes'?>\n<!DOCTYPE PLUGIN [\n<!ENTITY name \"$name\">\n<!ENTITY author \"tester\">\n<!ENTITY version \"$ver\">\n"
         . "<!ENTITY pluginURL \"$url\">\n]>\n<PLUGIN name=\"&name;\" author=\"&author;\" version=\"&version;\" pluginURL=\"&pluginURL;\" Title=\"Title of $name\" min=\"7.0\">\n"
         . "<DESCRIPTION>\nDoes a thing for $name.\n</DESCRIPTION>\n$extra</PLUGIN>\n";
}

/* ---------- names ---------- */
check('valid plg', pp_valid_plg('rclone.plg') && pp_valid_plg('a.b-c_d.plg'));
foreach (['../x.plg', 'a b.plg', 'x.plg;rm', '', '.hidden.plg', 'x.txt', 'a/../b.plg', str_repeat('a', 210) . '.plg'] as $bad) check("invalid plg [$bad]", !pp_valid_plg($bad));
check('valid pkg file', pp_valid_pkgfile('binutils-2.46-x86_64-1.txz') && pp_valid_pkgfile('sg3_utils-1.48.tgz') && !pp_valid_pkgfile('x.zip') && !pp_valid_pkgfile('../a.txz'));

/* ---------- parsing and versions ---------- */
$i = pp_parse_plg(plg('rclone', '2026.09.25a', 'https://h.test/rclone.plg', '<FILE Name="/usr/local/emhttp/plugins/rclone/x.page"></FILE>'));
check('parse name/version/url/title', $i['name'] === 'rclone' && $i['version'] === '2026.09.25a' && $i['pluginURL'] === 'https://h.test/rclone.plg' && $i['title'] === 'Title of rclone', json_encode($i));
check('parse description', $i['description'] === 'Does a thing for rclone.', $i['description']);
check('parse emhttp dir', $i['dirs'] === ['rclone'], json_encode($i['dirs']));
$nested = "<!DOCTYPE PLUGIN [\n<!ENTITY git \"https://g.test/r\">\n<!ENTITY pluginURL \"&git;/x.plg\">\n<!ENTITY version \"1.2\">\n]>\n<PLUGIN name='q' version='&version;' pluginURL='&pluginURL;'>\n</PLUGIN>";
$n = pp_parse_plg($nested);
check('nested entities + single quotes', $n['pluginURL'] === 'https://g.test/r/x.plg' && $n['version'] === '1.2', json_encode($n));
check('is_newer', pp_is_newer('2026.09.25a', '2026.09.25') && !pp_is_newer('2026.09.25', '2026.09.25') && !pp_is_newer('2026.09.24', '2026.09.25') && !pp_is_newer('', '1'));
check('protected', pp_protected('plugin-parking.plg') && pp_protected('dynamix.system.info.plg') && pp_protected('community.applications.plg') && !pp_protected('rclone.plg'));

/* ---------- boot cost from the syslog ---------- */
$root = tmproot(); $P = pp_paths();
put($P['syslog'],
    "Sep 25 12:01:13 catan rc.local: Installing /boot/extra packages\n"
  . "Sep 25 12:01:13 catan --: Installing: rpm2tgz-1.2.2-x86_64-7: a tool ....\n"
  . "Sep 25 12:01:14 catan --: Installing: python3-3.12.13-x86_64-1: interp ....\n"
  . "Sep 25 12:01:20 catan --: Installing: make-4.4.1-x86_64-1: make ....\n"
  . "Sep 25 12:02:13 catan rc.local: plugin: installing: dynamix.unraid.net.plg\n"
  . "Sep 25 12:02:14 catan rc.local: plugin: installing: DiskSpace.plg\n"
  . "Sep 25 12:02:47 catan rc.local: plugin: installing: rclone.plg\n"
  . "Sep 25 12:02:57 catan rc.local: plugin: installing: ncdu.plg\n");
$c = pp_boot_costs($P);
check('plugin cost attributed to the plugin that just finished', ($c['plugins']['rclone.plg'] ?? -1) === 33 && ($c['plugins']['ncdu.plg'] ?? -1) === 10 && ($c['plugins']['DiskSpace.plg'] ?? -1) === 1, json_encode($c['plugins']));
check('first plugin has no cost', !isset($c['plugins']['dynamix.unraid.net.plg']));
check('package cost attributed to the package that just finished', ($c['packages']['rpm2tgz-1.2.2-x86_64-7'] ?? -1) === 0 && ($c['packages']['python3-3.12.13-x86_64-1'] ?? -1) === 1 && ($c['packages']['make-4.4.1-x86_64-1'] ?? -1) === 6, json_encode($c['packages']));

/* ---------- boot costs survive log rotation ---------- */
put($P['syslog'] . '.1',
    "Sep 24 08:00:00 catan kernel: Linux version 6.18 (old boot)\n"
  . "Sep 24 08:02:00 catan rc.local: plugin: installing: dynamix.unraid.net.plg\n"
  . "Sep 24 08:02:50 catan rc.local: plugin: installing: oldplugin.plg\n"
  . "Sep 25 12:00:00 catan kernel: Linux version 6.18 (this boot)\n"
  . "Sep 25 12:03:00 catan rc.local: plugin: installing: dynamix.unraid.net.plg\n"
  . "Sep 25 12:03:07 catan rc.local: plugin: installing: rotated.plg\n");
put($P['syslog'] . '.2.gz', 'binary junk');
$sf = array_map('basename', pp_syslog_files($P));
check('rotated logs: plain numbered files only, oldest first, current last', $sf === ['syslog.1', 'syslog'], json_encode($sf));
$c = pp_boot_costs($P);
check('rotated: cost read from syslog.1', ($c['plugins']['rotated.plg'] ?? -1) === 7, json_encode($c['plugins']));
check('boot marker: no phantom cost across the boot boundary', !isset($c['plugins']['dynamix.unraid.net.plg']));
check('boot cost cache written', is_file("{$P['state']}/boot-costs.json") && (pp_json_read("{$P['state']}/boot-costs.json")['plugins']['rotated.plg'] ?? 0) === 7);
$before = file_get_contents($P['syslog']); $before1 = file_get_contents($P['syslog'] . '.1');
file_put_contents($P['syslog'], ''); unlink($P['syslog'] . '.1'); unlink($P['syslog'] . '.2.gz');
$c = pp_boot_costs($P);
check('cache used when the log no longer has the boot', ($c['plugins']['rotated.plg'] ?? -1) === 7 && ($c['plugins']['ncdu.plg'] ?? -1) === 10 || ($c['plugins']['rotated.plg'] ?? -1) === 7, json_encode($c['plugins']));
file_put_contents($P['syslog'], $before);

/* ---------- park / unpark ---------- */
put("{$P['plugins']}/rclone.plg", plg('rclone', '2026.09.01', 'https://h.test/rclone.plg', '<FILE Name="/usr/local/emhttp/plugins/rclone/rclone.page"></FILE>'));
symlink("{$P['plugins']}/rclone.plg", "{$P['varplg']}/rclone.plg");
put("{$P['emhttp']}/rclone/rclone.page", "Menu=x\n---\n");
put("{$P['emhttp']}/rclone/event/started", "#!/bin/sh\n");
put("{$P['plugins']}/rclone/rclone.cron", "* * * * * x\n");
put("{$P['rcd'][0]}/rc.rclone", "#!/bin/sh\n");

$ev = pp_evidence('rclone.plg', $P);
check('evidence: cron/service/hooks/pages', $ev['cron'] === 1 && $ev['service'] === 1 && $ev['hooks'] === 1 && $ev['pages'] === 1 && $ev['known'] && $ev['running'] === 0, json_encode($ev));

/* a plugin that also touches another plugin's folder is judged only by its own */
put("{$P['plugins']}/pre.clear-next.plg", plg('pre.clear-next', '1', 'https://h.test/p.plg', '<FILE Name="/usr/local/emhttp/plugins/other.plugin/hook.php"></FILE><FILE Name="/usr/local/emhttp/plugins/pre.clear/x.page"></FILE>'));
put("{$P['emhttp']}/other.plugin/event/started", "#!/bin/sh\n");
put("{$P['emhttp']}/other.plugin/scripts/rc.other", "#!/bin/sh\n");
put("{$P['emhttp']}/pre.clear/x.page", "Menu=x\n---\n");
$evp = pp_evidence('pre.clear-next.plg', $P);
check("evidence ignores another plugin's folder", $evp['hooks'] === 0 && $evp['service'] === 0 && $evp['pages'] === 1, json_encode($evp));
@unlink("{$P['plugins']}/pre.clear-next.plg");
check('owned dirs: name match wins, single dir kept, ambiguous keeps all', pp_owned_dirs('a.b-next', ['x', 'a.b']) === ['a.b'] && pp_owned_dirs('UManagerCompanion', ['u-manager-companion']) === ['u-manager-companion'] && pp_owned_dirs('zzz', ['p', 'q']) === ['p', 'q']);
$GLOBALS['pp_ps'] = "COMMAND\n/usr/sbin/nginx\nbash /boot/config/plugins/u-manager-companion/watcher.sh\nbash /boot/config/plugins/u-manager-companion/service-supervisor.sh\nphp /usr/local/emhttp/plugins/plugin-parking/include/api.php\n";
put("{$P['plugins']}/UManagerCompanion.plg", plg('UManagerCompanion', '1', 'https://h.test/u.plg', '<FILE Name="/usr/local/emhttp/plugins/u-manager-companion/README.md"></FILE><FILE Name="/boot/config/plugins/u-manager-companion/watcher.sh"></FILE>'));
$ev2 = pp_evidence('UManagerCompanion.plg', $P);
check('running processes found through the folders named in the plg', $ev2['running'] === 2 && $ev2['cron'] === 0 && $ev2['service'] === 0, json_encode($ev2));
check('parse: flash dirs, not files', pp_read_plg("{$P['plugins']}/UManagerCompanion.plg")['flashDirs'] === ['u-manager-companion']);
$GLOBALS['pp_ps'] = "COMMAND\n/usr/sbin/nginx\n";
check('nothing running when no process is from its folders', pp_evidence('UManagerCompanion.plg', $P)['running'] === 0);
unset($GLOBALS['pp_ps']);
@unlink("{$P['plugins']}/UManagerCompanion.plg");

check('park protected refused', pp_park('plugin-parking.plg', $P)['ok'] === false && pp_park('dynamix.system.info.plg', $P)['ok'] === false);
check('park invalid refused', pp_park('../evil.plg', $P)['ok'] === false && pp_park('nothere.plg', $P)['ok'] === false);
$r = pp_park('rclone.plg', $P);
check('park ok', $r['ok'] === true, json_encode($r));
check('park moved the file', !is_file("{$P['plugins']}/rclone.plg") && is_file("{$P['parked']}/rclone.plg"));
check('park kept the symlink working', is_link("{$P['varplg']}/rclone.plg") && is_file("{$P['varplg']}/rclone.plg") && realpath("{$P['varplg']}/rclone.plg") === realpath("{$P['parked']}/rclone.plg"));
check('park kept the data dir', is_file("{$P['plugins']}/rclone/rclone.cron"));
$meta = pp_json_read(pp_meta_file($P, 'rclone.plg'));
check('park saved meta with cost and evidence', ($meta['evidence']['cron'] ?? 0) === 1 && ($meta['bootSeconds'] ?? null) === 33 && $meta['version'] === '2026.09.01', json_encode($meta));
$st = pp_state_plugins($P);
check('state: parked, still loaded now', count($st['parked']) === 1 && $st['parked'][0]['loadedNow'] === true && $st['parked'][0]['bootSeconds'] === 33, json_encode($st['parked']));
check('state: not listed among loaded', !in_array('rclone.plg', array_column($st['loaded'], 'plg')));
check('state: nothing to save while it is loaded', $st['summary']['savedSeconds'] === 0);

/* ---------- update check ---------- */
$GLOBALS['pp_fetch'] = fn($u) => plg('rclone', '2026.09.25a');
$u = pp_check_update('rclone.plg', $P);
check('update: newer found', $u['ok'] && $u['newer'] === true && $u['cached'] === '2026.09.01' && $u['latest'] === '2026.09.25a', json_encode($u));
$GLOBALS['pp_fetch'] = fn($u) => plg('rclone', '2026.09.01');
check('update: same version is not newer', pp_check_update('rclone.plg', $P)['newer'] === false);
$GLOBALS['pp_fetch'] = fn($u) => null;
$u = pp_check_update('rclone.plg', $P);
check('update: offline reported, cached still usable', $u['ok'] && $u['latest'] === null && $u['error'] !== null && $u['newer'] === false);
$GLOBALS['pp_fetch'] = fn($u) => '<html>not a plugin</html>';
check('update: junk response reported', pp_check_update('rclone.plg', $P)['error'] !== null);
check('update: not parked', pp_check_update('ncdu.plg', $P)['ok'] === false);
put("{$P['parked']}/nourl.plg", "<PLUGIN name=\"nourl\" version=\"1\"></PLUGIN>");
check('update: no url', pp_check_update('nourl.plg', $P)['error'] !== null);
@unlink("{$P['parked']}/nourl.plg");
unset($GLOBALS['pp_fetch']);

/* ---------- unpark ---------- */
$r = pp_unpark('rclone.plg', $P);
check('unpark ok', $r['ok'] === true && is_file("{$P['plugins']}/rclone.plg") && !is_file("{$P['parked']}/rclone.plg") && !is_file(pp_meta_file($P, 'rclone.plg')), json_encode($r));
check('unpark repointed the symlink', realpath("{$P['varplg']}/rclone.plg") === realpath("{$P['plugins']}/rclone.plg"));
check('unpark when not parked', pp_unpark('rclone.plg', $P)['ok'] === false);
put("{$P['parked']}/rclone.plg", plg('rclone', '1'));
check('unpark refuses to overwrite', pp_unpark('rclone.plg', $P)['ok'] === false);
@unlink("{$P['parked']}/rclone.plg");

/* ---------- loading a parked plugin ---------- */
// The fake `plugin install`: what Unraid's own script does, copy the plg into plugins/ and register it.
$GLOBALS['pp_run'] = function (string $cmd) use ($P) {
    $GLOBALS['ran'][] = $cmd;
    if (!preg_match("#plugin install '([^']+)'#", $cmd, $m)) return [1, 'bad command'];
    $src = $m[1];
    if (getenv('PP_FAIL')) return [1, 'boom'];
    $name = preg_match('#^https?://#', $src) ? 'rclone.plg' : basename($src);
    $text = preg_match('#^https?://#', $src) ? plg('rclone', '2026.09.25a') : file_get_contents($src);
    file_put_contents("{$P['plugins']}/$name", $text);
    @unlink("{$P['varplg']}/$name"); symlink("{$P['plugins']}/$name", "{$P['varplg']}/$name");
    return [0, "plugin: $name installed"];
};
pp_park('rclone.plg', $P);
@unlink("{$P['varplg']}/rclone.plg");                     // as after a reboot: parked and not loaded
check('load: invalid options', pp_load('rclone.plg', 'x', 'session', $P)['ok'] === false && pp_load('rclone.plg', 'cached', 'x', $P)['ok'] === false);
check('load: not parked', pp_load('ncdu.plg', 'cached', 'session', $P)['ok'] === false);
$GLOBALS['ran'] = [];
$r = pp_load('rclone.plg', 'cached', 'session', $P);
check('load cached ok', $r['ok'] === true, json_encode($r));
check('load cached installs the parked copy', strpos($GLOBALS['ran'][0], "{$P['parked']}/rclone.plg") !== false);
check('load cached session: stays parked, symlink follows', is_file("{$P['parked']}/rclone.plg") && !is_file("{$P['plugins']}/rclone.plg") && realpath("{$P['varplg']}/rclone.plg") === realpath("{$P['parked']}/rclone.plg"));
check('load: already loaded refused', pp_load('rclone.plg', 'cached', 'session', $P)['ok'] === false);
@unlink("{$P['varplg']}/rclone.plg");
$r = pp_load('rclone.plg', 'latest', 'session', $P);
check('load latest ok, installs from the url', $r['ok'] === true && strpos(end($GLOBALS['ran']), 'https://h.test/rclone.plg') !== false, json_encode($r) . end($GLOBALS['ran']));
check('load latest session: parked copy is now the newest', pp_read_plg("{$P['parked']}/rclone.plg")['version'] === '2026.09.25a' && !is_file("{$P['plugins']}/rclone.plg"));
@unlink("{$P['varplg']}/rclone.plg");
$r = pp_load('rclone.plg', 'cached', 'enable', $P);
check('load enable: back in plugins/, no longer parked, no meta', $r['ok'] === true && is_file("{$P['plugins']}/rclone.plg") && !is_file("{$P['parked']}/rclone.plg") && !is_file(pp_meta_file($P, 'rclone.plg')));
pp_park('rclone.plg', $P); @unlink("{$P['varplg']}/rclone.plg");
putenv('PP_FAIL=1');
$r = pp_load('rclone.plg', 'cached', 'session', $P);
check('load failure reported and nothing moved', $r['ok'] === false && isset($r['output']) && is_file("{$P['parked']}/rclone.plg"), json_encode($r));
putenv('PP_FAIL');
unset($GLOBALS['pp_run']);

/* ---------- boot packages ---------- */
put("{$P['extra']}/binutils-2.46-x86_64-1.txz", str_repeat('x', 2048));
put("{$P['extra']}/sg3_utils-1.48.tgz", 'y');
put("{$P['extra']}/notes.txt", 'ignore me');
put("{$P['varpkg']}/binutils-2.46-x86_64-1", "PACKAGE NAME:  binutils-2.46-x86_64-1\nCOMPRESSED PACKAGE SIZE:     9.4 M\nUNCOMPRESSED PACKAGE SIZE:     47 M\nPACKAGE DESCRIPTION:\nbinutils: binutils (GNU binary utilities)\nbinutils:\nbinutils: A collection of binary tools.\nFILE LIST:\n./\nusr/\nusr/bin/\nusr/bin/ld\nusr/bin/objdump\n");
put(pp_paths()['unget'], "binutils-2.46-x86_64-1.txz\n");
$pk = pp_state_packages($P)['packages'];
$byName = array_column($pk, null, 'name');
check('packages listed (notes.txt ignored)', count($pk) === 2 && isset($byName['binutils']) && isset($byName['sg3_utils-1.48']), json_encode(array_column($pk, 'name')));
check('package info: installed/size/desc/un-get', $byName['binutils']['installed'] === true && $byName['binutils']['size'] === '47 M' && strpos($byName['binutils']['description'], 'GNU binary utilities') !== false && $byName['binutils']['ungetInstalled'] === true && $byName['binutils']['version'] === '2.46');
check('package not installed detected', $byName['sg3_utils-1.48']['installed'] === false);
check('package park invalid', pp_pkg_park('../x.txz', $P)['ok'] === false && pp_pkg_park('nothere.txz', $P)['ok'] === false);
check('package park', pp_pkg_park('binutils-2.46-x86_64-1.txz', $P)['ok'] === true && is_file("{$P['xparked']}/binutils-2.46-x86_64-1.txz") && !is_file("{$P['extra']}/binutils-2.46-x86_64-1.txz"));
$pk = array_column(pp_state_packages($P)['packages'], null, 'name');
check('parked package reported as parked', $pk['binutils']['where'] === 'parked' && $pk['sg3_utils-1.48']['where'] === 'boot');
$GLOBALS['pp_run'] = function (string $cmd) { $GLOBALS['ran'][] = $cmd; return [0, 'ok']; };
$GLOBALS['ran'] = [];
check('package load runs upgradepkg on the parked file', pp_pkg_load('binutils-2.46-x86_64-1.txz', $P)['ok'] === true && strpos($GLOBALS['ran'][0], "upgradepkg --install-new '{$P['xparked']}/binutils-2.46-x86_64-1.txz'") === 0, $GLOBALS['ran'][0]);
check('package unload runs removepkg on the base name', pp_pkg_unload('binutils-2.46-x86_64-1.txz', $P)['ok'] === true && $GLOBALS['ran'][1] === "removepkg 'binutils-2.46-x86_64-1'", $GLOBALS['ran'][1] ?? '');
check('package unload when not installed', pp_pkg_unload('sg3_utils-1.48.tgz', $P)['ok'] === false);
unset($GLOBALS['pp_run']);
check('package unpark', pp_pkg_unpark('binutils-2.46-x86_64-1.txz', $P)['ok'] === true && is_file("{$P['extra']}/binutils-2.46-x86_64-1.txz"));
check('package unpark when not parked', pp_pkg_unpark('binutils-2.46-x86_64-1.txz', $P)['ok'] === false);

/* ---------- package dependencies (linkage + interpreters) ---------- */
rmtree($root); $root = tmproot(); $P = pp_paths();
$mk = function (string $base, array $files) use ($P) {
    $t = "PACKAGE NAME:  $base\nUNCOMPRESSED PACKAGE SIZE:     1 M\nPACKAGE DESCRIPTION:\nx: y\nFILE LIST:\n./\n";
    foreach ($files as $f) $t .= "$f\n";
    put("{$P['varpkg']}/$base", $t);
    put("{$P['extra']}/$base.txz", 'x');
};
$mk('python3-3.12.13-x86_64-1', ['usr/bin/python3.12']);
$mk('meson-1.11.1-x86_64-1', ['usr/bin/meson']);
$mk('fwupd-1.9.24-x86_64-1_slackdce', ['usr/bin/fwupdmgr']);
$mk('libxmlb-0.3.26-x86_64-1_slackdce', ['usr/lib64/libxmlb.so.2.0.0']);
$mk('ipmitool-1.8.19-x86_64-2_SBo', ['usr/bin/ipmitool']);
put("$root/usr/bin/python3.12", "\x7fELFfake");
symlink("$root/usr/bin/python3.12", "$root/usr/bin/python3");
put("$root/usr/bin/meson", "#!/usr/bin/python3\nprint('hi')\n");
put("$root/usr/bin/fwupdmgr", "\x7fELFfake");
put("$root/usr/lib64/libxmlb.so.2.0.0", "\x7fELFfake");
put("$root/usr/bin/ipmitool", "\x7fELFfake");
$GLOBALS['pp_elf'] = function (string $path) {
    if (substr($path, -8) === 'fwupdmgr') return ['libxmlb.so.2', 'libc.so.6'];
    if (substr($path, -8) === 'ipmitool') return ['libc.so.6'];
    $h = fopen($path, 'rb'); $m = fread($h, 4); fclose($h);
    return $m === "\x7fELF" ? [] : null;
};
$d = pp_pkg_deps($P);
check('deps: shebang python script needs python3', in_array('python3-3.12.13-x86_64-1', $d['deps']['meson-1.11.1-x86_64-1'] ?? []), json_encode($d));
check('deps: shared library needs its provider', in_array('libxmlb-0.3.26-x86_64-1_slackdce', $d['deps']['fwupd-1.9.24-x86_64-1_slackdce'] ?? []));
check('deps: libc (not a boot package) is ignored, no false links', ($d['deps']['ipmitool-1.8.19-x86_64-2_SBo'] ?? ['x']) === []);
check('deps: reverse map', in_array('meson-1.11.1-x86_64-1', $d['users']['python3-3.12.13-x86_64-1'] ?? []) && in_array('fwupd-1.9.24-x86_64-1_slackdce', $d['users']['libxmlb-0.3.26-x86_64-1_slackdce'] ?? []));
unset($GLOBALS['pp_elf']);

/* ---------- usage hints ---------- */
put("{$P['plugins']}/ipmi.plg", plg('ipmi', '1', 'https://h.test/i.plg', "<!-- needs ipmitool-1.8.19 -->\n<FILE Run=\"/bin/bash\"><INLINE>ipmitool sdr</INLINE></FILE>"));
put("{$P['scripts']}/fan-check/script", "#!/bin/bash\nipmitool sensor | grep Fan\n");
put("{$P['scripts']}/other/script", "#!/bin/bash\necho nothing\n");
$us = pp_pkg_usage('ipmitool-1.8.19-x86_64-2_SBo', $P);
check('usage: plugin mentioning the package', in_array('ipmi', $us['plugins']), json_encode($us));
put("{$P['plugins']}/chatty.plg", plg('chatty', '1', 'https://h.test/c.plg', "<DESCRIPTION>We make sure nothing breaks. Please install ipmitool separately.</DESCRIPTION>\n<CHANGES>\n# make it faster\n</CHANGES>"));
put("{$P['plugins']}/runner.plg", plg('runner', '1', 'https://h.test/r.plg', "<FILE Run=\"/bin/bash\"><INLINE>\necho start\nx=$(ipmitool chassis status)\n</INLINE></FILE>"));
$us2 = pp_pkg_usage('ipmitool-1.8.19-x86_64-2_SBo', $P);
check('usage: a word in a sentence is not a use, a command is', !in_array('chatty', $us2['plugins']) && in_array('runner', $us2['plugins']), json_encode($us2));
@unlink("{$P['plugins']}/chatty.plg"); @unlink("{$P['plugins']}/runner.plg");
put("{$P['emhttp']}/ipmi/scripts/sensors.php", "<?php\n\$out = shell_exec(\"ipmitool sdr\");\n");
put("{$P['emhttp']}/prose/readme.php", "<?php // we like ipmitool-style names\n");
put("{$P['emhttp']}/dynamix/x.php", "<?php shell_exec('ipmitool foo');\n");
$us3 = pp_pkg_usage('ipmitool-1.8.19-x86_64-2_SBo', $P);
check("usage: a plugin's own scripts calling the program", in_array('ipmi', $us3['runtime']), json_encode($us3));
check('usage: prose is not a call, core folders are skipped', !in_array('prose', $us3['runtime']) && !in_array('dynamix', $us3['runtime']), json_encode($us3));
put("{$P['varpkg']}/binutils-2.46-x86_64-1", "PACKAGE NAME:  binutils-2.46-x86_64-1\nFILE LIST:\n./\nusr/bin/\nusr/bin/size\nusr/bin/strip\nusr/bin/ld\n");
put("{$P['emhttp']}/ipmi/scripts/words.php", "<?php\n\$size = 5;\nsize the icons\nstrip trailing\n");
$us4 = pp_pkg_usage('binutils-2.46-x86_64-1', $P);
check('usage: ordinary words that are also program names never count', $us4['runtime'] === [] && $us4['plugins'] === [] && $us4['scripts'] === [], json_encode($us4));
check('usage: user script calling its program', $us['scripts'] === ['fan-check'], json_encode($us));

/* ---------- uninstall choice ---------- */
check('choice invalid', pp_set_remove_decision('maybe', $P)['ok'] === false);
check('choice saved', pp_set_remove_decision('keep', $P)['ok'] === true && trim(file_get_contents("{$P['state']}/remove-decision")) === 'keep');
put("{$P['parked']}/a.plg", plg('a', '1'));
put("{$P['parked']}/b.plg", plg('b', '1'));
put("{$P['xparked']}/gc-8.2.12-x86_64-1.txz", 'x');
put("{$P['plugins']}/b.plg", plg('b', '2'));                     // b already exists in plugins/: must not be overwritten
check('parked counts', pp_parked_counts($P) === ['plugins' => 2, 'packages' => 1], json_encode(pp_parked_counts($P)));
$m = pp_restore_all($P);
check('restore moves what it can', $m['plugins'] === 1 && $m['packages'] === 1 && is_file("{$P['plugins']}/a.plg") && is_file("{$P['extra']}/gc-8.2.12-x86_64-1.txz"), json_encode($m));
check('restore never overwrites', $m['skipped'] === ['b.plg'] && pp_read_plg("{$P['plugins']}/b.plg")['version'] === '2' && is_file("{$P['parked']}/b.plg"), json_encode($m));
@unlink("{$P['parked']}/b.plg");
pp_restore_all($P);
check('restore removes the empty parked folders', !is_dir($P['parked']) && !is_dir($P['xparked']));

/* ---------- api ---------- */
check('api unknown action', pp_api('nope', [], $P)['ok'] === false);
check('api rejects a non-string name', pp_api('park', ['plg' => ['x']], $P)['ok'] === false);
check('api plugins returns a list', isset(pp_api('plugins', [], $P)['loaded']));

rmtree($root);
echo "lib_test: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
