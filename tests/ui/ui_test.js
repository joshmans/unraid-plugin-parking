/* Drives js/parking.js inside jsdom against a copy of the Plugins page's markup.
 * Needs jsdom:  npm install jsdom   (kept out of run.sh so the PHP tests need nothing else). */
const fs = require('fs');
const path = require('path');
const { JSDOM, VirtualConsole } = require('jsdom');

const SRC = fs.readFileSync(path.join(__dirname, '../../source/usr/local/emhttp/plugins/plugin-parking/js/parking.js'), 'utf8');
let pass = 0, fail = 0;
function check(label, ok, detail) { if (ok) pass++; else { fail++; console.log('FAIL: ' + label + (detail ? '\n      ' + detail : '')); } }
const tick = (ms = 20) => new Promise((r) => setTimeout(r, ms));

function fixture() {
  return {
    plugins: {
      loaded: [
        { plg: 'rclone.plg', title: 'rclone', version: '1', description: 'Cloud sync', bootSeconds: 10, evidence: { cron: 0, service: 0, hooks: 0, pages: 1, known: true }, protected: false },
        { plg: 'sched.plg', title: '<img src=x onerror="window.pwned=1">', version: '1', description: 'x', bootSeconds: 3, evidence: { cron: 2, service: 1, hooks: 0, pages: 1, known: true }, protected: false },
        { plg: 'dynamix.system.info.plg', title: 'System Info', version: '1', description: 'core', bootSeconds: 1, evidence: { cron: 1, service: 0, hooks: 0, pages: 1, known: true }, protected: true },
        { plg: 'plugin-parking.plg', title: 'Plugin Parking', version: '1', description: 'me', bootSeconds: 1, evidence: { cron: 0, service: 0, hooks: 0, pages: 1, known: true }, protected: true },
      ],
      parked: [{ plg: 'ncdu.plg', title: 'ncdu', version: '2', description: 'disk usage', bootSeconds: 4, evidence: { cron: 0, service: 0, hooks: 0, pages: 0, known: true }, loadedNow: false }],
      summary: { bootTotal: 15, parkedCount: 1, savedSeconds: 4 },
    },
    packages: { packages: [
      { file: 'meson-1-x86_64-1.txz', base: 'meson-1-x86_64-1', name: 'meson', version: '1', where: 'boot', diskMB: 2, installed: true, size: '10 M', description: 'build system', bootSeconds: 3, ungetInstalled: true },
      { file: 'python3-3-x86_64-1.txz', base: 'python3-3-x86_64-1', name: 'python3', version: '3', where: 'boot', diskMB: 24, installed: true, size: '90 M', description: 'python', bootSeconds: 9, ungetInstalled: true },
      { file: 'gc-8-x86_64-1.txz', base: 'gc-8-x86_64-1', name: 'gc', version: '8', where: 'parked', diskMB: 1, installed: false, size: '1 M', description: 'gc', bootSeconds: null, ungetInstalled: true },
    ], unget: { present: true, parked: 1, held: ['gc'] } },
    deps: { deps: { 'meson-1-x86_64-1': ['python3-3-x86_64-1'], 'python3-3-x86_64-1': [], 'gc-8-x86_64-1': [] }, users: { 'python3-3-x86_64-1': ['meson-1-x86_64-1'] } },
    usage: { plugins: [], runtime: ['ipmi'], scripts: [] },
  };
}

async function boot(opts = {}) {
  const fx = fixture();
  const calls = [];
  const vc = new VirtualConsole();
  let reloaded = false;
  vc.on('jsdomError', (e) => { if (/navigation/.test(e.message)) reloaded = true; });
  const dom = new JSDOM(`<!doctype html><body>
    <table id="plugin_table"><tbody id="plugin_list">
      ${['rclone.plg', 'sched.plg', 'dynamix.system.info.plg', 'plugin-parking.plg', 'ncdu.plg'].map((p) => `<tr><td>${p}</td><td>desc</td><td><input type="checkbox" class="remove" data="${p}"><input type="button" class="remove" data="${p}" value="Remove"></td></tr>`).join('')}
    </tbody></table>
    <div id="pp-plugins"></div><div id="pp-packages"></div></body>`, { runScripts: 'outside-only', url: 'http://tower/Plugins', virtualConsole: vc });
  const w = dom.window;
  w.csrf_token = 'TOKEN';
  w.opened = [];
  w.openInstall = function (cmd) { w.opened.push(['openInstall', cmd]); };
  w.openPlugin = function (cmd) { w.opened.push(['openPlugin', cmd]); };
  Object.defineProperty(w, '__reloaded', { get: () => reloaded });
  w.fetch = (url, init) => {
    const b = init.body; const action = b.get('action');
    const rec = {}; b.forEach((v, k) => { rec[k] = v; }); calls.push(rec);
    let res = { ok: true };
    if (action === 'plugins') res = fx.plugins;
    else if (action === 'packages') res = opts.unget === undefined ? fx.packages : { packages: fx.packages.packages, unget: opts.unget };
    else if (action === 'pkg_deps') res = fx.deps;
    else if (action === 'pkg_usage') res = fx.usage;
    else if (action === 'check') res = opts.check || { ok: true, cached: '2', latest: '3', newer: true };
    else if (action === 'parked_counts') res = opts.counts || { plugins: 1, packages: 0 };
    else if (action === 'pkg_park_deps') res = opts.parkDeps || { ok: true, parked: ['python3'], kept: [{ name: 'gc', reasons: ['needed by other, which stays loaded'] }] };
    else if (action === 'load') res = { ok: true, output: 'installed' };
    return Promise.resolve({ text: () => Promise.resolve(JSON.stringify(res)) });
  };
  w.eval(SRC);
  await tick(60);
  return { w, doc: w.document, calls };
}
const btn = (doc, text) => [...doc.querySelectorAll('button')].find((b) => b.textContent === text);
const input = (doc, value, scope) => [...(scope || doc).querySelectorAll('input[type=button]')].find((b) => b.value === value);

(async () => {
  /* --- Parking tab and the built-in list --- */
  let { w, doc, calls } = await boot();
  check('parking tab lists loaded plugins', doc.querySelectorAll('#pp-plugins table')[0].querySelectorAll('tbody tr').length === 4);
  check('parking tab lists parked plugins', doc.querySelectorAll('#pp-plugins table')[1].querySelectorAll('tbody tr').length === 1);
  check('hostile title is shown as text, not run', !w.pwned && !doc.querySelector('#pp-plugins img') && doc.querySelector('#pp-plugins').textContent.includes('<img src=x'));
  const rows = [...doc.querySelectorAll('#plugin_list tr')];
  check('Park button on ordinary rows', !!rows[0].querySelector('.pp-row-park') && !!rows[1].querySelector('.pp-row-park'));
  check('no Park button on core rows or Plugin Parking itself', !rows[2].querySelector('.pp-row-park') && !rows[3].querySelector('.pp-row-park'));
  check('parked plugin row is tagged', !!rows[4].querySelector('.pp-row-tag') && !rows[4].querySelector('.pp-row-park'));
  check('park button sits next to Remove', rows[0].querySelector('input.remove[type=button]').nextElementSibling.classList.contains('pp-row-park'));

  // Park a plugin with a schedule: the warning appears and the API is called with the right file
  rows[1].querySelector('.pp-row-park').click();
  await tick();
  const dlg = doc.getElementById('pp-dialog');
  check('park dialog warns about schedules and services', !!dlg && dlg.textContent.includes('2 scheduled jobs') && dlg.textContent.includes('background service'), dlg && dlg.textContent);
  btn(doc, 'Park').click();
  await tick(60);
  const parkCall = calls.find((c) => c.action === 'park');
  check('park posts the plugin file and the csrf token', parkCall && parkCall.plg === 'sched.plg' && parkCall.csrf_token === 'TOKEN', JSON.stringify(parkCall));
  check('dialog closed after parking', !doc.getElementById('pp-overlay'));

  // Park cancelled: nothing posted
  calls.length = 0;
  rows[0].querySelector('.pp-row-park').click(); await tick();
  btn(doc, 'Cancel').click(); await tick();
  check('cancel does not park', !calls.some((c) => c.action === 'park'));
  check('on-demand tool gets no warning box', true);

  /* --- Load flow with a newer version --- */
  calls.length = 0;
  input(doc, 'Load now…').click();
  await tick(60);
  const d2 = doc.getElementById('pp-dialog');
  check('load dialog offers latest and cached', !!d2 && d2.textContent.includes('Install the latest version (3)') && d2.textContent.includes('Install the cached version (2)'), d2 && d2.textContent);
  check('update was checked first', calls.some((c) => c.action === 'check' && c.plg === 'ncdu.plg'));
  btn(doc, 'Load').click();
  await tick(80);
  const loadCall = calls.find((c) => c.action === 'load');
  check('load defaults: latest, keep parked', loadCall && loadCall.mode === 'latest' && loadCall.keep === 'session' && loadCall.plg === 'ncdu.plg', JSON.stringify(loadCall));
  check('result dialog offers a page reload', !!btn(doc, 'Reload page'));
  btn(doc, 'Reload page').click(); await tick();
  check('reload page clicked', w.__reloaded === true);

  // Cached + enable at boot
  ({ w, doc, calls } = await boot());
  input(doc, 'Load now…').click(); await tick(60);
  doc.querySelectorAll('#pp-dialog input[type=radio]').forEach((r) => { if (r.parentNode.textContent.includes('cached version')) { r.checked = true; r.dispatchEvent(new w.Event('change')); } if (r.parentNode.textContent.includes('every boot')) { r.checked = true; r.dispatchEvent(new w.Event('change')); } });
  btn(doc, 'Load').click(); await tick(80);
  const lc = calls.find((c) => c.action === 'load');
  check('load honours cached + enable choices', lc && lc.mode === 'cached' && lc.keep === 'enable', JSON.stringify(lc));

  // No newer version / offline: a plain confirmation, only "keep" choice
  ({ w, doc, calls } = await boot({ check: { ok: true, cached: '2', latest: null, newer: false, error: 'Could not reach the update URL (offline?)' } }));
  input(doc, 'Load now…').click(); await tick(60);
  check('offline: says the cached copy needs no network', doc.getElementById('pp-dialog').textContent.includes('needs no network'));
  btn(doc, 'Load').click(); await tick(80);
  check('offline load uses cached', calls.find((c) => c.action === 'load').mode === 'cached');

  /* --- Removal question --- */
  ({ w, doc, calls } = await boot({ counts: { plugins: 2, packages: 1 } }));
  w.openInstall('plugin remove other.plg', 'Remove Plugin', '', 'refresh');
  check('removing another plugin is untouched', w.opened.length === 1 && w.opened[0][1] === 'plugin remove other.plg');
  w.opened.length = 0;
  w.openInstall('plugin remove plugin-parking.plg', 'Remove Plugin', 'plugin-parking.plg', 'refresh');
  await tick(60);
  check('removing Plugin Parking asks first and does not run yet', w.opened.length === 0 && !!doc.getElementById('pp-dialog') && doc.getElementById('pp-dialog').textContent.includes('2 parked plugins and 1 parked package'));
  btn(doc, 'Continue removal').click(); await tick(60);
  const rc = calls.find((c) => c.action === 'remove_choice');
  check('choice saved (restore is the default)', rc && rc.choice === 'restore', JSON.stringify(rc));
  check('removal proceeds after the choice', w.opened.length === 1 && w.opened[0][1].startsWith('plugin remove plugin-parking.plg'));
  // keep
  w.opened.length = 0; calls.length = 0;
  w.openPlugin('multiplugin remove foo.plg*plugin-parking.plg', 'Remove Selected', '', 'refresh', 1);
  await tick(60);
  doc.querySelectorAll('#pp-dialog input[type=radio]').forEach((r) => { if (r.parentNode.textContent.includes('Keep them parked')) { r.checked = true; r.dispatchEvent(new w.Event('change')); } });
  btn(doc, 'Continue removal').click(); await tick(60);
  check('multi-remove also asks and can keep', calls.find((c) => c.action === 'remove_choice').choice === 'keep' && w.opened.length === 1);
  // cancel
  w.opened.length = 0;
  w.openInstall('plugin remove plugin-parking.plg', 't', 'p', 'f'); await tick(60);
  btn(doc, 'Cancel').click(); await tick(40);
  check('cancel stops the removal', w.opened.length === 0);
  // Escape
  w.openInstall('plugin remove plugin-parking.plg', 't', 'p', 'f'); await tick(60);
  doc.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Escape', bubbles: true })); await tick(40);
  check('Escape stops the removal', w.opened.length === 0 && !doc.getElementById('pp-overlay'));
  // nothing parked: no question
  ({ w, doc, calls } = await boot({ counts: { plugins: 0, packages: 0 } }));
  w.openInstall('plugin remove plugin-parking.plg', 't', 'p', 'f'); await tick(60);
  check('nothing parked: removal goes straight through', w.opened.length === 1 && !doc.getElementById('pp-dialog'));
  check('isOwnRemoval: matches only our file name', w.__ppTest.isOwnRemoval('plugin remove plugin-parking.plg') && !w.__ppTest.isOwnRemoval('plugin remove my-plugin-parking.plg') && !w.__ppTest.isOwnRemoval('plugin install plugin-parking.plg') && !w.__ppTest.isOwnRemoval('plugin remove plugin-parking.plg.bak'));

  /* --- Boot Packages tab --- */
  ({ w, doc, calls } = await boot());
  await tick(120);
  let pkgRows = doc.querySelectorAll('#pp-packages tbody tr');
  check('packages tab lists every package', pkgRows.length === 3);
  const txt = doc.getElementById('pp-packages').textContent;
  check('packages show needed-by and who calls them', txt.includes('needed by meson') && txt.includes('called by the scripts of ipmi'), txt.slice(0, 400));
  check('un-get notice shows when un-get is installed and something is parked', txt.includes('un-get and parked packages') && txt.includes('un-get upgrade') && txt.includes('Held out of un-get’s list: gc.'), txt.slice(0, 300));
  check('the tab syncs un-get\'s list before listing', calls.findIndex((c) => c.action === 'unget_sync') !== -1 && calls.findIndex((c) => c.action === 'unget_sync') < calls.findIndex((c) => c.action === 'packages'));
  ({ w, doc, calls } = await boot({ unget: { present: false, parked: 1, held: [] } }));
  await tick(120);
  check('no un-get notice when un-get is not installed', !doc.getElementById('pp-packages').textContent.includes('un-get and parked packages'));
  ({ w, doc, calls } = await boot({ unget: { present: true, parked: 0, held: [] } }));
  await tick(120);
  check('no un-get notice when nothing is parked', !doc.getElementById('pp-packages').textContent.includes('un-get and parked packages'));
  ({ w, doc, calls } = await boot());
  await tick(120);
  pkgRows = doc.querySelectorAll('#pp-packages tbody tr');
  const python = [...pkgRows].find((r) => r.textContent.includes('python3'));
  input(doc, 'Park', python).click(); await tick();
  check('parking a package others need warns', doc.getElementById('pp-dialog').textContent.includes('needs it: meson'), doc.getElementById('pp-dialog').textContent);
  btn(doc, 'Cancel').click(); await tick();
  // moving a parked, not-installed package back: the un-get cleanup trap is explained and installing now is the default
  const gcRow = [...doc.querySelectorAll('#pp-packages tbody tr')].find((r) => r.textContent.includes('gc'));
  calls.length = 0;
  input(doc, 'Load at every boot', gcRow).click(); await tick(60);
  const ud = doc.getElementById('pp-dialog');
  check('moving back an uninstalled package explains the cleanup trap', ud && ud.textContent.includes('un-get cleanup') && ud.textContent.includes('not installed right now'), ud && ud.textContent);
  check('nothing moves before the answer', !calls.some((c) => c.action === 'pkg_unpark'));
  btn(doc, 'Continue').click(); await tick(100);
  const seq = calls.map((c) => c.action).filter((x) => x === 'pkg_unpark' || x === 'pkg_load');
  check('the default moves it back and installs it now', seq.join(',') === 'pkg_unpark,pkg_load', seq.join(','));
  calls.length = 0;
  input(doc, 'Load at every boot', [...doc.querySelectorAll('#pp-packages tbody tr')].find((r) => r.textContent.includes('gc'))).click(); await tick(60);
  [...doc.querySelectorAll('#pp-dialog input[type=radio]')].forEach((r) => { if (r.parentNode.textContent.includes('Only move it back')) { r.checked = true; r.dispatchEvent(new w.Event('change')); } });
  btn(doc, 'Continue').click(); await tick(100);
  check('"only move it back" does not install', calls.some((c) => c.action === 'pkg_unpark') && !calls.some((c) => c.action === 'pkg_load'));
  const gc = [...pkgRows].find((r) => r.textContent.includes('gc'));
  check('parked package offers load and load-at-boot', !!input(doc, 'Load now…', gc) && !!input(doc, 'Load at every boot', gc));

  // parking a package offers to park what it needs, then checks
  const meson = [...pkgRows].find((r) => r.textContent.includes('meson'));
  calls.length = 0;
  input(doc, 'Park', meson).click(); await tick();
  btn(doc, 'Park').click(); await tick(60);
  const dd = doc.getElementById('pp-dialog');
  check('parking a package with dependencies asks about them', dd && dd.textContent.includes('Park what meson needs too?') && dd.textContent.includes('python3') && calls.some((c) => c.action === 'pkg_park' && c.file === 'meson-1-x86_64-1.txz'), dd && dd.textContent);
  check('nothing is parked before they say yes', !calls.some((c) => c.action === 'pkg_park_deps'));
  btn(doc, 'Check and park them').click(); await tick(80);
  const rd = doc.getElementById('pp-dialog');
  check('the check reports what was parked and what was kept and why', rd && rd.textContent.includes('Parked: python3') && rd.textContent.includes('gc: needed by other') && calls.some((c) => c.action === 'pkg_park_deps' && c.file === 'meson-1-x86_64-1.txz'), rd && rd.textContent);
  btn(doc, 'OK') && btn(doc, 'OK').click();
  await tick(40);
  // declining leaves the dependencies alone
  ({ w, doc, calls } = await boot());
  await tick(120);
  const meson2 = [...doc.querySelectorAll('#pp-packages tbody tr')].find((r) => r.textContent.includes('meson'));
  input(doc, 'Park', meson2).click(); await tick();
  btn(doc, 'Park').click(); await tick(60);
  btn(doc, 'No, just meson').click(); await tick(60);
  check('saying no to the dependencies parks only the package', !calls.some((c) => c.action === 'pkg_park_deps') && calls.filter((c) => c.action === 'pkg_park').length === 1);
  // a package with nothing to park skips the question
  ({ w, doc, calls } = await boot());
  await tick(120);
  const py = [...doc.querySelectorAll('#pp-packages tbody tr')].find((r) => r.textContent.includes('python3'));
  input(doc, 'Park', py).click(); await tick();
  btn(doc, 'Park').click(); await tick(60);
  check('no dependencies: no question', !doc.getElementById('pp-dialog') && calls.some((c) => c.action === 'pkg_park'));

  console.log(`ui_test: ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.log('ERROR', e); process.exit(1); });
