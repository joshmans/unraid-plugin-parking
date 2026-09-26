<?php
/* Plugin Parking: core logic. Everything works on paths from pp_paths(), so the
 * tests can point PP_ROOT at a fixture tree instead of a real Unraid. */

const PP_NAME = 'plugin-parking';

function pp_paths(): array {
    $r = getenv('PP_ROOT') ?: '';
    return [
        'plugins' => "$r/boot/config/plugins",
        'parked'  => "$r/boot/config/plugins-parked",
        'extra'   => "$r/boot/extra",
        'xparked' => "$r/boot/extra-parked",
        'emhttp'  => "$r/usr/local/emhttp/plugins",
        'varplg'  => "$r/var/log/plugins",
        'varpkg'  => "$r/var/log/packages",
        'syslog'  => "$r/var/log/syslog",
        'rcd'     => ["$r/etc/rc.d", "$r/usr/local/etc/rc.d"],
        'state'   => "$r/boot/config/plugins/" . PP_NAME,
        'unget'   => "$r/boot/config/plugins/un-get/installedpackages_list",
        'scripts' => "$r/boot/config/plugins/user.scripts/scripts",
        'bin'     => "$r/usr/local/sbin",
    ];
}

/* ---------- small helpers ---------- */

/** First $n characters, without requiring the optional mbstring extension. */
function pp_cut(string $s, int $n): string {
    if (function_exists('mb_substr')) return mb_substr($s, 0, $n);
    return strlen($s) <= $n ? $s : (preg_match('/^.{0,' . $n . '}/us', $s, $m) ? $m[0] : substr($s, 0, $n));
}

function pp_valid_name(string $n): bool {
    return $n !== '' && strlen($n) < 200 && preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]*$/', $n) === 1 && strpos($n, '..') === false;
}

function pp_valid_plg(string $n): bool {
    return substr($n, -4) === '.plg' && pp_valid_name($n);
}

function pp_valid_pkgfile(string $n): bool {
    return pp_valid_name($n) && preg_match('/\.(txz|tgz|tbz|tlz)$/', $n) === 1;
}

/** Runs a shell command and returns [exit code, output]. Tests replace it with $GLOBALS['pp_run']. */
function pp_run(string $cmd): array {
    if (isset($GLOBALS['pp_run'])) return ($GLOBALS['pp_run'])($cmd);
    $out = [];
    exec($cmd . ' 2>&1', $out, $rc);
    return [$rc, implode("\n", $out)];
}

/** Fetches a URL; returns the body or null. Tests replace it with $GLOBALS['pp_fetch']. */
function pp_fetch(string $url): ?string {
    if (isset($GLOBALS['pp_fetch'])) return ($GLOBALS['pp_fetch'])($url);
    if (!preg_match('#^https?://#i', $url)) return null;
    [$rc, $out] = pp_run('curl -sL --max-time 15 --max-filesize 3000000 -A ' . escapeshellarg('plugin-parking') . ' ' . escapeshellarg($url));
    return $rc === 0 && $out !== '' ? $out : null;
}

function pp_json_read(string $file): array {
    $t = @file_get_contents($file);
    $d = $t === false ? null : json_decode($t, true);
    return is_array($d) ? $d : [];
}

function pp_write_atomic(string $file, string $data): bool {
    @mkdir(dirname($file), 0755, true);
    $tmp = $file . '.tmp' . getmypid();
    if (@file_put_contents($tmp, $data) === false) return false;
    return @rename($tmp, $file);
}

/* ---------- reading a .plg ---------- */

function pp_parse_plg(string $text): array {
    $ent = [];
    if (preg_match_all('/<!ENTITY\s+(\S+)\s+"([^"]*)"\s*>/', $text, $m, PREG_SET_ORDER)) {
        foreach ($m as $e) $ent[$e[1]] = $e[2];
    }
    $sub = function (string $s) use ($ent): string {
        for ($i = 0; $i < 5; $i++) {
            $n = preg_replace_callback('/&([A-Za-z0-9_.-]+);/', fn($x) => $ent[$x[1]] ?? $x[0], $s);
            if ($n === $s) break;
            $s = $n;
        }
        return $s;
    };
    $attrs = [];
    if (preg_match('/<PLUGIN\b([^>]*)>/s', $text, $t)) {
        if (preg_match_all('/([A-Za-z_]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $t[1], $a, PREG_SET_ORDER)) {
            foreach ($a as $x) $attrs[$x[1]] = $sub($x[2] !== '' || !isset($x[3]) ? $x[2] : $x[3]);
        }
    }
    $desc = '';
    if (preg_match('/<DESCRIPTION>(.*?)<\/DESCRIPTION>/s', $text, $d)) {
        $desc = trim(preg_replace('/\s+/', ' ', strip_tags($sub(preg_replace('/<!\[CDATA\[|\]\]>/', '', $d[1])))));
    }
    $dirs = [];
    if (preg_match_all('#/usr/local/emhttp/plugins/([A-Za-z0-9_.&;-]+)#', $text, $dm)) {
        foreach ($dm[1] as $x) { $x = $sub($x); if (strpos($x, '&') === false && $x !== '') $dirs[$x] = true; }
    }
    $flash = [];
    if (preg_match_all('#/boot/config/plugins/([A-Za-z0-9_.&;-]+)#', $text, $fm)) {
        foreach ($fm[1] as $x) {
            $x = $sub($x);
            if (strpos($x, '&') === false && $x !== '' && !preg_match('/\.(plg|cfg|txz|tgz|cron|json|ini|conf)$/', $x)) $flash[$x] = true;
        }
    }
    $name = $attrs['name'] ?? '';
    return [
        'name'        => $name,
        'title'       => $attrs['Title'] ?? ($attrs['title'] ?? $name),
        'author'      => $attrs['author'] ?? '',
        'version'     => $attrs['version'] ?? '',
        'pluginURL'   => $attrs['pluginURL'] ?? '',
        'min'         => $attrs['min'] ?? '',
        'max'         => $attrs['max'] ?? '',
        'icon'        => $attrs['icon'] ?? '',
        'launch'      => $attrs['launch'] ?? '',
        'description' => pp_cut($desc, 400),
        'dirs'        => array_keys($dirs),
        'flashDirs'   => array_keys($flash),
    ];
}

function pp_read_plg(string $file): array {
    $t = @file_get_contents($file);
    return $t === false ? [] : pp_parse_plg($t);
}

/** Unraid's own rule (plugin script): a version is newer when its string compares greater. */
function pp_is_newer(string $candidate, string $current): bool {
    return $candidate !== '' && strcmp($candidate, $current) > 0;
}

/* ---------- which plugins may be parked ---------- */

function pp_protected(string $plg): bool {
    $b = basename($plg, '.plg');
    if (in_array($b, [PP_NAME, 'community.applications', 'unRAIDServer', 'unRAIDServer-plus', 'unraid.core.prod'], true)) return true;
    return strpos($b, 'dynamix.') === 0;
}

const PP_SHARED_DIRS = ['dynamix', 'dynamix.plugin.manager', 'dynamix.docker.manager', 'dynamix.vm.manager', 'dynamix.my.servers', 'community.applications'];

/* ---------- listing ---------- */

/** @return string[] .plg file names of plugins that are installed right now */
function pp_loaded(array $P): array {
    $out = [];
    foreach (glob($P['varplg'] . '/*.plg') ?: [] as $f) $out[basename($f)] = true;
    return array_keys($out);
}

function pp_flash_plugins(array $P): array {
    return array_map('basename', glob($P['plugins'] . '/*.plg') ?: []);
}

function pp_parked_plugins(array $P): array {
    return array_map('basename', glob($P['parked'] . '/*.plg') ?: []);
}

/* ---------- what a plugin does on its own ---------- */

/** `ps` output listing every process's command line. Tests set $GLOBALS['pp_ps']. */
function pp_ps(): string {
    if (isset($GLOBALS['pp_ps'])) return $GLOBALS['pp_ps'];
    static $cache = null;
    if ($cache === null) { [$rc, $out] = pp_run('ps -eo args'); $cache = $rc === 0 ? $out : ''; }
    return $cache;
}

/** Which of the folders a plugin's .plg mentions are its own (a plugin often also touches another plugin's folder). */
function pp_owned_dirs(string $base, array $candidates): array {
    $norm = fn(string $x): string => strtolower(preg_replace('/[-_.]/', '', $x));
    $mine = [$norm($base), $norm(preg_replace('/-next$/', '', $base))];
    $match = array_values(array_filter($candidates, fn($d) => in_array($norm($d), $mine, true)));
    return $match ?: $candidates;
}

function pp_evidence(string $plg, array $P): array {
    $base = basename($plg, '.plg');
    $file = is_file("{$P['plugins']}/$plg") ? "{$P['plugins']}/$plg" : "{$P['parked']}/$plg";
    $info = pp_read_plg($file);
    $cand = array_values(array_diff($info['dirs'] ?? [], PP_SHARED_DIRS));
    if (is_dir("{$P['emhttp']}/$base") && !in_array($base, PP_SHARED_DIRS, true)) $cand[] = $base;
    $dirs = pp_owned_dirs($base, array_values(array_unique($cand)));
    $flash = pp_owned_dirs($base, $info['flashDirs'] ?? []);

    $cron = count(glob("{$P['plugins']}/$base/*.cron") ?: []);
    $svc = 0; $hooks = 0; $pages = 0; $present = false;
    $frags = ["/boot/config/plugins/$base/"];
    foreach (array_merge($dirs, $flash) as $d) { $frags[] = "/usr/local/emhttp/plugins/$d/"; $frags[] = "/boot/config/plugins/$d/"; }
    $running = 0;
    if ($base !== PP_NAME) {
        foreach (explode("\n", pp_ps()) as $line) {
            if (strpos($line, PP_NAME) !== false) continue;
            foreach (array_unique($frags) as $fr) if (strpos($line, $fr) !== false) { $running++; break; }
        }
    }
    foreach ($P['rcd'] as $d) $svc += count(glob("$d/rc.$base*") ?: []);
    foreach ($dirs as $d) {
        $e = "{$P['emhttp']}/$d";
        if (!is_dir($e)) continue;
        $present = true;
        $cron  += count(glob("$e/*.cron") ?: []);
        $svc   += count(glob("$e/scripts/rc.*") ?: []);
        $hooks += count(glob("$e/event/*") ?: []);
        $pages += count(glob("$e/*.page") ?: []);
    }
    return ['cron' => $cron, 'service' => $svc, 'hooks' => $hooks, 'running' => $running, 'pages' => $pages, 'known' => $present || $cron > 0];
}

/* ---------- boot cost, read from the syslog ---------- */

function pp_ts(string $line): ?int {
    if (!preg_match('/^([A-Z][a-z]{2})\s+(\d+)\s+(\d\d):(\d\d):(\d\d)\s/', $line, $m)) return null;
    $t = strtotime("{$m[1]} {$m[2]} " . date('Y') . " {$m[3]}:{$m[4]}:{$m[5]}");
    return $t === false ? null : $t;
}

/** The syslog and its plain-text rotations, oldest first. */
function pp_syslog_files(array $P): array {
    $files = array_values(array_filter(glob($P['syslog'] . '.*') ?: [], fn($f) => preg_match('/\.\d+$/', $f) === 1 && is_file($f)));
    usort($files, fn($a, $b) => strnatcmp($b, $a));
    if (is_file($P['syslog'])) $files[] = $P['syslog'];
    return $files;
}

function pp_parse_costs(array $P): array {
    $res = ['plugins' => [], 'packages' => []];
    $prevPlg = null; $prevPk = null;
    foreach (pp_syslog_files($P) as $file) {
        $f = @fopen($file, 'r');
        if (!$f) continue;
        while (($l = fgets($f)) !== false) {
            $t = pp_ts($l);
            if ($t === null) continue;
            if (strpos($l, 'kernel: Linux version') !== false) { $prevPlg = null; $prevPk = null; continue; }
            if (strpos($l, 'rc.local: Installing /boot/extra packages') !== false) { $prevPk = $t; continue; }
            // Unraid prints each "Installing" / "installing" line when that item is done, so the time since
            // the previous line is what the item just finished cost.
            if (strpos($l, 'rc.local: plugin: installing: ') !== false && preg_match('/installing: (\S+\.plg)/', $l, $m)) {
                if ($prevPlg !== null && $t >= $prevPlg) $res['plugins'][$m[1]] = $t - $prevPlg;
                $prevPlg = $t;
            } elseif (preg_match('/--: Installing: (\S+?):/', $l, $m)) {
                if ($prevPk !== null && $t >= $prevPk) $res['packages'][$m[1]] = $t - $prevPk;
                $prevPk = $t;
            }
        }
        fclose($f);
    }
    return $res;
}

/**
 * Seconds per plugin / package at the last boot. The syslog rotates during the day, so what a boot cost is
 * remembered in a small file (refreshed by the array-start hook) and used when the log no longer has it.
 * @return array{plugins: array<string,int>, packages: array<string,int>}
 */
function pp_boot_costs(array $P): array {
    $res = pp_parse_costs($P);
    $cacheFile = "{$P['state']}/boot-costs.json";
    $cache = pp_json_read($cacheFile);
    if ($res['plugins'] || $res['packages']) {
        if (($cache['plugins'] ?? null) !== $res['plugins'] || ($cache['packages'] ?? null) !== $res['packages']) {
            pp_write_atomic($cacheFile, json_encode(['plugins' => $res['plugins'], 'packages' => $res['packages'], 'at' => date('c')]));
        }
        return $res;
    }
    return ['plugins' => $cache['plugins'] ?? [], 'packages' => $cache['packages'] ?? []];
}

/* ---------- parking plugins ---------- */

function pp_meta_file(array $P, string $plg): string { return "{$P['state']}/meta/" . basename($plg, '.plg') . '.json'; }

function pp_repoint_symlink(array $P, string $plg, string $target): void {
    $link = "{$P['varplg']}/$plg";
    if (is_link($link) || file_exists($link)) { @unlink($link); @symlink($target, $link); }
}

function pp_park(string $plg, array $P): array {
    if (!pp_valid_plg($plg)) return ['ok' => false, 'error' => 'Invalid plugin name'];
    if (pp_protected($plg)) return ['ok' => false, 'error' => 'This plugin cannot be parked'];
    $src = "{$P['plugins']}/$plg";
    if (!is_file($src)) return ['ok' => false, 'error' => 'Plugin file not found'];
    $ev = pp_evidence($plg, $P);
    $info = pp_read_plg($src);
    $costs = pp_boot_costs($P);
    $meta = [
        'title' => $info['title'], 'version' => $info['version'], 'description' => $info['description'],
        'evidence' => $ev, 'bootSeconds' => $costs['plugins'][$plg] ?? null, 'parkedAt' => date('c'),
    ];
    if (!is_dir($P['parked']) && !@mkdir($P['parked'], 0700, true)) return ['ok' => false, 'error' => 'Cannot create ' . $P['parked']];
    $dst = "{$P['parked']}/$plg";
    if (is_file($dst)) @unlink($dst);
    if (!@rename($src, $dst)) return ['ok' => false, 'error' => 'Could not move the plugin file'];
    pp_write_atomic(pp_meta_file($P, $plg), json_encode($meta));
    pp_repoint_symlink($P, $plg, $dst);
    return ['ok' => true, 'meta' => $meta];
}

function pp_unpark(string $plg, array $P): array {
    if (!pp_valid_plg($plg)) return ['ok' => false, 'error' => 'Invalid plugin name'];
    $src = "{$P['parked']}/$plg"; $dst = "{$P['plugins']}/$plg";
    if (!is_file($src)) return ['ok' => false, 'error' => 'Not parked'];
    if (is_file($dst)) return ['ok' => false, 'error' => 'A plugin file with this name is already in the plugins folder'];
    if (!@rename($src, $dst)) return ['ok' => false, 'error' => 'Could not move the plugin file'];
    @unlink(pp_meta_file($P, $plg));
    pp_repoint_symlink($P, $plg, $dst);
    return ['ok' => true];
}

/** Compares the parked (cached) copy with the newest one at its update URL. */
function pp_check_update(string $plg, array $P): array {
    if (!pp_valid_plg($plg)) return ['ok' => false, 'error' => 'Invalid plugin name'];
    $file = "{$P['parked']}/$plg";
    if (!is_file($file)) return ['ok' => false, 'error' => 'Not parked'];
    $cached = pp_read_plg($file);
    $r = ['ok' => true, 'cached' => $cached['version'], 'latest' => null, 'newer' => false, 'url' => $cached['pluginURL'], 'error' => null];
    if ($cached['pluginURL'] === '') { $r['error'] = 'This plugin has no update URL'; return $r; }
    $body = pp_fetch($cached['pluginURL']);
    if ($body === null) { $r['error'] = 'Could not reach the update URL (offline?)'; return $r; }
    $latest = pp_parse_plg($body);
    if ($latest['version'] === '') { $r['error'] = 'The update URL did not return a plugin file'; return $r; }
    $r['latest'] = $latest['version'];
    $r['newer'] = pp_is_newer($latest['version'], $cached['version']);
    return $r;
}

/**
 * Loads a parked plugin now. $mode: 'cached' installs the parked copy, 'latest' installs the newest from its update URL.
 * $keep: 'session' leaves it parked (it stays loaded until the next reboot), 'enable' also makes it load at boot again.
 */
function pp_load(string $plg, string $mode, string $keep, array $P): array {
    if (!pp_valid_plg($plg)) return ['ok' => false, 'error' => 'Invalid plugin name'];
    if (!in_array($mode, ['cached', 'latest'], true) || !in_array($keep, ['session', 'enable'], true)) return ['ok' => false, 'error' => 'Invalid options'];
    $parked = "{$P['parked']}/$plg";
    if (!is_file($parked)) return ['ok' => false, 'error' => 'Not parked'];
    $info = pp_read_plg($parked);
    if (in_array($plg, pp_loaded($P), true)) return ['ok' => false, 'error' => 'Already loaded'];

    if ($mode === 'latest') {
        if (!preg_match('#^https?://#i', $info['pluginURL'])) return ['ok' => false, 'error' => 'No update URL to install from'];
        $cmd = $P['bin'] . '/plugin install ' . escapeshellarg($info['pluginURL']);
    } else {
        $cmd = $P['bin'] . '/plugin install ' . escapeshellarg($parked);
    }
    [$rc, $out] = pp_run($cmd);
    $installed = "{$P['plugins']}/$plg";
    $loaded = is_file($installed) && in_array($plg, pp_loaded($P), true);
    if ($rc !== 0 && !$loaded) return ['ok' => false, 'error' => 'The install failed', 'output' => $out];

    if ($keep === 'enable') {
        @unlink($parked);
        @unlink(pp_meta_file($P, $plg));
    } else {
        // Keep it parked: the copy that was just installed (possibly a newer version) becomes the parked copy.
        if (is_file($installed)) { @unlink($parked); @rename($installed, $parked); }
        pp_repoint_symlink($P, $plg, $parked);
    }
    return ['ok' => true, 'output' => $out, 'mode' => $mode, 'keep' => $keep];
}

function pp_state_plugins(array $P): array {
    $costs = pp_boot_costs($P);
    $loaded = []; $parked = []; $seen = [];
    $parkedNames = pp_parked_plugins($P);
    foreach (pp_loaded($P) as $plg) {
        $file = is_file("{$P['plugins']}/$plg") ? "{$P['plugins']}/$plg" : "{$P['parked']}/$plg";
        if (in_array($plg, $parkedNames, true)) continue;
        $i = pp_read_plg($file);
        $ev = pp_evidence($plg, $P);
        $loaded[] = [
            'plg' => $plg, 'title' => $i['title'] ?: basename($plg, '.plg'), 'version' => $i['version'], 'description' => $i['description'],
            'bootSeconds' => $costs['plugins'][$plg] ?? null, 'evidence' => $ev, 'protected' => pp_protected($plg),
        ];
    }
    foreach ($parkedNames as $plg) {
        $i = pp_read_plg("{$P['parked']}/$plg");
        $meta = pp_json_read(pp_meta_file($P, $plg));
        $parked[] = [
            'plg' => $plg, 'title' => $i['title'] ?: basename($plg, '.plg'), 'version' => $i['version'], 'description' => $i['description'],
            'bootSeconds' => $meta['bootSeconds'] ?? null, 'evidence' => $meta['evidence'] ?? null,
            'loadedNow' => in_array($plg, pp_loaded($P), true), 'parkedAt' => $meta['parkedAt'] ?? null,
        ];
    }
    usort($loaded, fn($a, $b) => ($b['bootSeconds'] ?? -1) <=> ($a['bootSeconds'] ?? -1));
    usort($parked, fn($a, $b) => strcasecmp($a['title'], $b['title']));
    $save = 0;
    foreach ($parked as $p) if (!$p['loadedNow']) $save += (int)($p['bootSeconds'] ?? 0);
    return ['loaded' => $loaded, 'parked' => $parked, 'summary' => [
        'bootTotal' => array_sum(array_map(fn($x) => (int)($x['bootSeconds'] ?? 0), $loaded)),
        'parkedCount' => count($parked), 'savedSeconds' => $save,
    ]];
}

/* ---------- boot packages (/boot/extra) ---------- */

function pp_pkg_parse(string $file): array {
    $b = preg_replace('/\.(txz|tgz|tbz|tlz)$/', '', $file);
    if (preg_match('/^(.+)-([^-]+)-([^-]+)-([^-]+)$/', $b, $m)) return ['base' => $b, 'name' => $m[1], 'version' => $m[2], 'arch' => $m[3], 'build' => $m[4]];
    return ['base' => $b, 'name' => $b, 'version' => '', 'arch' => '', 'build' => ''];
}

function pp_pkg_info(string $base, array $P): array {
    $f = "{$P['varpkg']}/$base";
    if (!is_file($f)) return ['installed' => false, 'size' => null, 'description' => '', 'files' => []];
    $size = null; $desc = []; $files = []; $mode = '';
    foreach (file($f, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
        if (strpos($l, 'UNCOMPRESSED PACKAGE SIZE:') === 0) $size = trim(substr($l, 26));
        elseif (strpos($l, 'PACKAGE DESCRIPTION:') === 0) $mode = 'd';
        elseif (strpos($l, 'FILE LIST:') === 0) $mode = 'f';
        elseif ($mode === 'd' && preg_match('/^[^:]+:\s?(.*)$/', $l, $m)) { if (trim($m[1]) !== '') $desc[] = trim($m[1]); }
        elseif ($mode === 'f' && $l !== '' && substr($l, -1) !== '/') $files[] = $l;
    }
    return ['installed' => true, 'size' => $size, 'description' => pp_cut(implode(' ', $desc), 300), 'files' => $files];
}

function pp_ungot(array $P): array {
    $out = [];
    foreach (@file($P['unget'], FILE_IGNORE_NEW_LINES) ?: [] as $l) if (trim($l) !== '') $out[trim($l)] = true;
    return $out;
}

function pp_state_packages(array $P): array {
    $costs = pp_boot_costs($P)['packages'];
    $ung = pp_ungot($P);
    $rows = [];
    foreach (['extra' => 'boot', 'xparked' => 'parked'] as $dirKey => $where) {
        foreach (glob($P[$dirKey] . '/*') ?: [] as $f) {
            if (!is_file($f) || !pp_valid_pkgfile(basename($f))) continue;
            $file = basename($f);
            $p = pp_pkg_parse($file);
            $info = pp_pkg_info($p['base'], $P);
            $rows[] = [
                'file' => $file, 'base' => $p['base'], 'name' => $p['name'], 'version' => $p['version'],
                'where' => $where, 'diskMB' => round(filesize($f) / 1048576, 1), 'installed' => $info['installed'],
                'size' => $info['size'], 'description' => $info['description'],
                'bootSeconds' => $costs[$p['base']] ?? null, 'ungetInstalled' => isset($ung[$file]),
            ];
        }
    }
    usort($rows, fn($a, $b) => [$a['where'] === 'parked', strtolower($a['name'])] <=> [$b['where'] === 'parked', strtolower($b['name'])]);
    return ['packages' => $rows];
}

/** Sonames a binary needs; tests replace it with $GLOBALS['pp_elf']. */
function pp_elf_needed(string $path): ?array {
    if (isset($GLOBALS['pp_elf'])) return ($GLOBALS['pp_elf'])($path);
    $h = @fopen($path, 'rb');
    if (!$h) return null;
    $magic = fread($h, 4);
    fclose($h);
    if ($magic !== "\x7fELF") return null;
    [$rc, $out] = pp_run('readelf -d ' . escapeshellarg($path) . ' 2>/dev/null');
    if ($rc !== 0) return [];
    preg_match_all('/\(NEEDED\)\s+Shared library: \[([^\]]+)\]/', $out, $m);
    return $m[1];
}

/**
 * Which of the /boot/extra packages need which others, judged from shared-library linkage and script interpreters.
 * Best effort: a Python module import or a runtime `exec` is not visible from here.
 * @return array{deps: array<string,string[]>, users: array<string,string[]>}
 */
function pp_pkg_deps(array $P): array {
    $bases = [];
    foreach (glob($P['extra'] . '/*') ?: [] as $f) if (pp_valid_pkgfile(basename($f))) $bases[pp_pkg_parse(basename($f))['base']] = true;
    foreach (glob($P['xparked'] . '/*') ?: [] as $f) if (pp_valid_pkgfile(basename($f))) $bases[pp_pkg_parse(basename($f))['base']] = true;
    $files = []; $owner = []; $byBase = [];
    foreach (glob($P['varpkg'] . '/*') ?: [] as $pf) {
        $base = basename($pf);
        $info = pp_pkg_info($base, $P);
        foreach ($info['files'] as $rel) {
            $owner['/' . $rel] = $base;
            $bn = basename($rel);
            $byBase[$bn][] = $base;
            if (preg_match('/\.so(\.|$)/', $bn)) {
                while (preg_match('/^(.*\.so(?:\.\d+)*)\.\d+$/', $bn, $mm)) { $bn = $mm[1]; $byBase[$bn][] = $base; }
            }
        }
        if (isset($bases[$base])) $files[$base] = $info['files'];
    }
    $deps = []; $users = [];
    $root = substr($P['varpkg'], 0, -strlen('/var/log/packages'));
    $rootReal = $root === '' ? '' : (realpath($root) ?: $root);
    foreach ($files as $base => $list) {
        $seen = [];
        foreach ($list as $rel) {
            $abs = "$root/$rel";
            if (!is_file($abs) || is_link($abs)) continue;
            $isBin = preg_match('#^(usr/)?(local/)?s?bin/#', $rel) === 1;
            $isLib = preg_match('#\.so(\.|$)#', $rel) === 1;
            if (!$isBin && !$isLib) continue;
            $needed = pp_elf_needed($abs);
            if ($needed === null && $isBin) {
                $h = @fopen($abs, 'r'); $first = $h ? fgets($h, 200) : ''; if ($h) fclose($h);
                if (is_string($first) && preg_match('/^#!\s*(\S+)(?:\s+(\S+))?/', $first, $m)) {
                    $interp = $m[1] === '/usr/bin/env' && isset($m[2]) ? null : $m[1];
                    if ($interp !== null) {
                        $real = $owner[$interp] ?? (($p = @realpath("$root$interp")) ? ($owner[substr($p, strlen($rootReal))] ?? null) : null);
                        if ($real && $real !== $base && isset($bases[$real])) $seen[$real] = true;
                    }
                }
                continue;
            }
            foreach ($needed ?? [] as $so) {
                foreach ($byBase[$so] ?? [] as $prov) if ($prov !== $base && isset($bases[$prov])) $seen[$prov] = true;
            }
        }
        $deps[$base] = array_keys($seen);
        foreach ($seen as $prov => $_) $users[$prov][] = $base;
    }
    return ['deps' => $deps, 'users' => $users];
}

/** Quotes a string for grep -E: only the characters that mean something in an extended regex. */
function pp_ere_quote(string $x): string {
    return preg_replace('/[.\\\\+*?\\[^\\]$(){}|]/', '\\\\$0', $x);
}

/** Program names that are also ordinary words: never counted as a sign that a package is used. */
const PP_COMMON_WORDS = ['size', 'strip', 'make', 'strings', 'test', 'file', 'install', 'date', 'time', 'sort', 'head', 'tail', 'link', 'sync', 'split', 'which', 'stat', 'less', 'more', 'look', 'true', 'false', 'yes', 'who', 'join', 'cut', 'tee', 'env', 'top', 'free', 'watch', 'expand', 'fold', 'group', 'users', 'update', 'remove', 'list', 'find', 'open', 'start', 'stop', 'main', 'run'];

/**
 * Plugins and user scripts that seem to use a package: its file name is mentioned (name-1.2...), or one of its
 * programs is run as a command in a .plg, a user script, or a plugin's own scripts. A bare word like "size" in a
 * sentence does not count.
 */
function pp_pkg_usage(string $base, array $P): array {
    $name = pp_pkg_parse($base . '.txz')['name'];
    $progs = [];
    foreach (pp_pkg_info($base, $P)['files'] as $rel) {
        if (preg_match('#^(usr/)?(local/)?s?bin/([^/]+)$#', $rel, $m) && strlen($m[3]) >= 3 && !in_array(strtolower($m[3]), PP_COMMON_WORDS, true)) $progs[$m[3]] = true;
    }
    $cmd = ''; $alt = '';
    if ($progs) {
        $alt = implode('|', array_map(fn($x) => preg_quote($x, '/'), array_keys($progs)));
        $cmd = '/(?:^|[;&|`(\'"]|\$\()[ \t]*(?:\/?(?:[A-Za-z0-9_.-]+\/)*)?(?:' . $alt . ')(?![A-Za-z0-9_.-])/m';
    }
    $fileName = '/(?<![A-Za-z0-9_.-])' . preg_quote($name, '/') . '-[0-9]/';
    $uses = fn(string $t): bool => preg_match($fileName, $t) === 1 || ($cmd !== '' && preg_match($cmd, $t) === 1);
    $plugins = []; $scripts = []; $runtime = [];
    foreach (array_merge(glob($P['plugins'] . '/*.plg') ?: [], glob($P['parked'] . '/*.plg') ?: []) as $f) {
        if (basename($f, '.plg') === PP_NAME) continue;
        $t = @file_get_contents($f);
        if ($t !== false && $uses($t)) $plugins[] = basename($f, '.plg');
    }
    foreach (glob($P['scripts'] . '/*/script') ?: [] as $sc) {
        $t = @file_get_contents($sc);
        if ($t !== false && $uses($t)) $scripts[] = basename(dirname($sc));
    }
    if ($progs && is_dir($P['emhttp'])) {
        [$rc, $out] = pp_run('grep -rIlE -s ' . escapeshellarg('(^|[^A-Za-z0-9_.-])(' . implode('|', array_map('pp_ere_quote', array_keys($progs))) . ')([^A-Za-z0-9_.-]|$)')
            . ' ' . escapeshellarg($P['emhttp']) . ' --include=*.php --include=*.sh --include=*.page --include=rc.* --include=started --include=stopping_svcs');
        foreach (explode("\n", (string)$out) as $file) {
            if (strpos($file, $P['emhttp'] . '/') !== 0 || strpos($file, '/plugin-parking/') !== false) continue;
            $dir = explode('/', ltrim(substr($file, strlen($P['emhttp'])), '/'))[0] ?? '';
            if ($dir === '' || in_array($dir, $runtime, true) || in_array($dir, PP_SHARED_DIRS, true)) continue;
            $t = @file_get_contents($file);
            if ($t !== false && preg_match($cmd, $t) === 1) $runtime[] = $dir;
        }
    }
    return ['plugins' => $plugins, 'scripts' => $scripts, 'runtime' => $runtime];
}

function pp_pkg_park(string $file, array $P): array {
    if (!pp_valid_pkgfile($file)) return ['ok' => false, 'error' => 'Invalid package name'];
    $src = "{$P['extra']}/$file";
    if (!is_file($src)) return ['ok' => false, 'error' => 'Package not found in /boot/extra'];
    if (!is_dir($P['xparked']) && !@mkdir($P['xparked'], 0700, true)) return ['ok' => false, 'error' => 'Cannot create ' . $P['xparked']];
    return @rename($src, "{$P['xparked']}/$file") ? ['ok' => true] : ['ok' => false, 'error' => 'Could not move the package'];
}

/**
 * Parks the packages that a package needed, but only those nothing else still needs. Run right after the package
 * itself was parked. Starts from everything it needs (and what those need in turn) that still loads at boot, then
 * checks each one afresh: another boot package that needs it, or a plugin or script that seems to use it, keeps it
 * loaded, and that in turn keeps whatever it needs.
 * @return array{ok: bool, parked?: string[], kept?: array<int,array{name: string, reasons: string[]}>, error?: string}
 */
function pp_pkg_park_deps(string $file, array $P): array {
    if (!pp_valid_pkgfile($file)) return ['ok' => false, 'error' => 'Invalid package name'];
    $root = pp_pkg_parse($file)['base'];
    $d = pp_pkg_deps($P);
    $boot = [];
    foreach (glob($P['extra'] . '/*') ?: [] as $f) if (is_file($f) && pp_valid_pkgfile(basename($f))) $boot[pp_pkg_parse(basename($f))['base']] = basename($f);
    $nm = fn(string $b): string => pp_pkg_parse($b . '.txz')['name'];

    $cand = []; $todo = $d['deps'][$root] ?? []; $seen = [$root => true];
    while (($b = array_shift($todo)) !== null) {
        if (isset($seen[$b])) continue;
        $seen[$b] = true;
        if (isset($boot[$b])) $cand[$b] = true;
        foreach ($d['deps'][$b] ?? [] as $n) $todo[] = $n;
    }

    $kept = [];
    foreach (array_keys($cand) as $b) {
        $u = pp_pkg_usage($b, $P);
        $r = [];
        if ($u['plugins']) $r[] = 'plugin ' . implode(', ', $u['plugins']) . ' seems to use it';
        if ($u['runtime']) $r[] = 'the scripts of ' . implode(', ', $u['runtime']) . ' call one of its programs';
        if ($u['scripts']) $r[] = 'user script ' . implode(', ', $u['scripts']) . ' calls one of its programs';
        if ($r) { $kept[$b] = $r; unset($cand[$b]); }
    }
    do {
        $changed = false;
        foreach (array_keys($cand) as $b) {
            $outside = array_values(array_filter($d['users'][$b] ?? [], fn($x) => $x !== $root && isset($boot[$x]) && !isset($cand[$x])));
            if ($outside) {
                $kept[$b] = ['needed by ' . implode(', ', array_map($nm, $outside)) . ', which stays loaded'];
                unset($cand[$b]);
                $changed = true;
            }
        }
    } while ($changed);

    $parked = [];
    foreach (array_keys($cand) as $b) {
        $r = pp_pkg_park($boot[$b], $P);
        if ($r['ok']) $parked[] = $nm($b); else $kept[$b] = [$r['error'] ?? 'could not be parked'];
    }
    $keptOut = [];
    foreach ($kept as $b => $r) $keptOut[] = ['name' => $nm($b), 'reasons' => $r];
    return ['ok' => true, 'parked' => $parked, 'kept' => $keptOut];
}

function pp_pkg_unpark(string $file, array $P): array {
    if (!pp_valid_pkgfile($file)) return ['ok' => false, 'error' => 'Invalid package name'];
    $src = "{$P['xparked']}/$file";
    if (!is_file($src)) return ['ok' => false, 'error' => 'Not parked'];
    if (is_file("{$P['extra']}/$file")) return ['ok' => false, 'error' => 'Already in /boot/extra'];
    if (!is_dir($P['extra'])) @mkdir($P['extra'], 0755, true);
    return @rename($src, "{$P['extra']}/$file") ? ['ok' => true] : ['ok' => false, 'error' => 'Could not move the package'];
}

/** Installs a package now, from wherever it is stored (the same way Unraid installs /boot/extra at boot). */
function pp_pkg_load(string $file, array $P): array {
    if (!pp_valid_pkgfile($file)) return ['ok' => false, 'error' => 'Invalid package name'];
    $path = is_file("{$P['extra']}/$file") ? "{$P['extra']}/$file" : "{$P['xparked']}/$file";
    if (!is_file($path)) return ['ok' => false, 'error' => 'Package not found'];
    [$rc, $out] = pp_run('upgradepkg --install-new ' . escapeshellarg($path));
    return $rc === 0 ? ['ok' => true, 'output' => $out] : ['ok' => false, 'error' => 'The install failed', 'output' => $out];
}

function pp_pkg_unload(string $file, array $P): array {
    if (!pp_valid_pkgfile($file)) return ['ok' => false, 'error' => 'Invalid package name'];
    $base = pp_pkg_parse($file)['base'];
    if (!is_file("{$P['varpkg']}/$base")) return ['ok' => false, 'error' => 'Not installed'];
    [$rc, $out] = pp_run('removepkg ' . escapeshellarg($base));
    return $rc === 0 ? ['ok' => true, 'output' => $out] : ['ok' => false, 'error' => 'The removal failed', 'output' => $out];
}

/* ---------- uninstall choice ---------- */

function pp_parked_counts(array $P): array {
    return [
        'plugins'  => count(glob($P['parked'] . '/*.plg') ?: []),
        'packages' => count(array_filter(glob($P['xparked'] . '/*') ?: [], fn($f) => is_file($f) && pp_valid_pkgfile(basename($f)))),
    ];
}

function pp_set_remove_decision(string $d, array $P): array {
    if (!in_array($d, ['restore', 'keep'], true)) return ['ok' => false, 'error' => 'Invalid choice'];
    return pp_write_atomic("{$P['state']}/remove-decision", $d) ? ['ok' => true] : ['ok' => false, 'error' => 'Could not save the choice'];
}

/** Moves everything parked back where Unraid loads it at boot. */
function pp_restore_all(array $P): array {
    $moved = ['plugins' => 0, 'packages' => 0, 'skipped' => []];
    foreach (glob($P['parked'] . '/*.plg') ?: [] as $f) {
        $dst = "{$P['plugins']}/" . basename($f);
        if (is_file($dst)) { $moved['skipped'][] = basename($f); continue; }
        if (@rename($f, $dst)) { $moved['plugins']++; pp_repoint_symlink($P, basename($f), $dst); }
    }
    foreach (glob($P['xparked'] . '/*') ?: [] as $f) {
        if (!is_file($f) || !pp_valid_pkgfile(basename($f))) continue;
        if (!is_dir($P['extra'])) @mkdir($P['extra'], 0755, true);
        $dst = "{$P['extra']}/" . basename($f);
        if (is_file($dst)) { $moved['skipped'][] = basename($f); continue; }
        if (@rename($f, $dst)) $moved['packages']++;
    }
    @rmdir($P['parked']); @rmdir($P['xparked']);
    return $moved;
}

/* ---------- API ---------- */

function pp_api(string $action, array $in, ?array $P = null): array {
    $P = $P ?? pp_paths();
    $s = fn($k) => isset($in[$k]) && is_string($in[$k]) ? $in[$k] : '';
    switch ($action) {
        case 'plugins':      return pp_state_plugins($P);
        case 'park':         return pp_park($s('plg'), $P);
        case 'unpark':       return pp_unpark($s('plg'), $P);
        case 'check':        return pp_check_update($s('plg'), $P);
        case 'load':         return pp_load($s('plg'), $s('mode'), $s('keep'), $P);
        case 'packages':     return pp_state_packages($P);
        case 'pkg_deps':     return pp_pkg_deps($P);
        case 'pkg_usage':    return $s('base') !== '' && pp_valid_name($s('base')) ? pp_pkg_usage($s('base'), $P) : ['plugins' => [], 'scripts' => [], 'runtime' => []];
        case 'pkg_park':     return pp_pkg_park($s('file'), $P);
        case 'pkg_park_deps': return pp_pkg_park_deps($s('file'), $P);
        case 'pkg_unpark':   return pp_pkg_unpark($s('file'), $P);
        case 'pkg_load':     return pp_pkg_load($s('file'), $P);
        case 'pkg_unload':   return pp_pkg_unload($s('file'), $P);
        case 'parked_counts': return pp_parked_counts($P);
        case 'remove_choice': return pp_set_remove_decision($s('choice'), $P);
    }
    return ['ok' => false, 'error' => 'Unknown action'];
}
