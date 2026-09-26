/* Plugin Parking: the "Parking" and "Boot Packages" tabs of Unraid's Plugins page, a Park button on each row of the
 * installed-plugins list, and the question asked before Plugin Parking itself is removed. */
(function () {
  'use strict';
  if (window.__ppLoaded) return;
  window.__ppLoaded = true;

  const API = '/plugins/plugin-parking/include/api.php';
  const SELF = 'plugin-parking.plg';
  const state = { plugins: null, packages: null, deps: null, usage: {} };

  /* ---------- small helpers ---------- */

  function append(el, c) {
    if (c === null || c === undefined || c === false) return;
    if (Array.isArray(c)) c.forEach((x) => append(el, x));
    else el.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
  }

  function h(tag, props, ...children) {
    const el = document.createElement(tag);
    Object.keys(props || {}).forEach((k) => {
      const v = props[k];
      if (k === 'class') el.className = v;
      else if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), v);
      else if (v !== null && v !== undefined && v !== false) el.setAttribute(k, v === true ? '' : v);
    });
    children.forEach((c) => append(el, c));
    return el;
  }

  function clear(el) { while (el.firstChild) el.removeChild(el.firstChild); }

  function api(action, data) {
    const body = new URLSearchParams();
    body.set('action', action);
    body.set('csrf_token', window.csrf_token || '');
    Object.keys(data || {}).forEach((k) => body.set(k, data[k]));
    return fetch(API, { method: 'POST', body: body, credentials: 'same-origin' }).then((r) =>
      r.text().then((t) => {
        try { return JSON.parse(t); } catch (e) { throw new Error('Unexpected response from the server'); }
      })
    );
  }

  const secs = (n) => (n === null || n === undefined ? '–' : n + ' s');
  const plural = (n, w) => n + ' ' + w + (n === 1 ? '' : 's');

  /* ---------- dialog ---------- */

  /**
   * opts: { title, body: [nodes], choices: [{name, options: [{value, label, checked}]}], buttons: [{label, value, primary}] }
   * Resolves { button, picks } or null when dismissed with Escape.
   */
  function dialog(opts) {
    return new Promise((resolve) => {
      const picks = {};
      const dlg = h('div', { id: 'pp-dialog', role: 'dialog', 'aria-modal': 'true' }, h('h4', {}, opts.title));
      (opts.body || []).forEach((n) => append(dlg, n));
      (opts.choices || []).forEach((c) => {
        const box = h('div', { class: 'pp-choices' });
        c.options.forEach((o, i) => {
          const id = 'pp-c-' + c.name + '-' + i;
          const input = h('input', { type: 'radio', name: 'pp-' + c.name, id: id, checked: !!o.checked });
          if (o.checked) picks[c.name] = o.value;
          input.addEventListener('change', () => { picks[c.name] = o.value; });
          box.appendChild(h('label', { for: id }, input, ' ', o.label));
        });
        dlg.appendChild(box);
      });
      const overlay = h('div', { id: 'pp-overlay' }, dlg);
      function onKey(e) { if (e.key === 'Escape') { e.stopPropagation(); done(null); } }
      function done(v) { document.removeEventListener('keydown', onKey, true); overlay.remove(); resolve(v); }
      const bar = h('div', { class: 'pp-buttons' });
      (opts.buttons || [{ label: 'OK', value: 'ok', primary: true }]).forEach((b) =>
        bar.appendChild(h('button', { type: 'button', class: b.primary ? 'pp-primary' : '', onclick: () => done({ button: b.value, picks: picks }) }, b.label))
      );
      dlg.appendChild(bar);
      document.addEventListener('keydown', onKey, true);
      document.body.appendChild(overlay);
      const first = bar.querySelector('.pp-primary') || bar.querySelector('button');
      if (first) first.focus();
    });
  }

  const notify = (title, msg, extra) => dialog({ title: title, body: [h('p', {}, msg), extra || null] });
  const warnBox = (lines) => (lines.length ? h('div', { class: 'pp-warnbox' }, lines.map((l) => h('div', {}, l))) : null);

  function busy(text) {
    const overlay = h('div', { id: 'pp-overlay' }, h('div', { id: 'pp-dialog' }, h('h4', {}, text), h('p', {}, 'This can take a little while.')));
    document.body.appendChild(overlay);
    return () => overlay.remove();
  }

  /* ---------- what a plugin does on its own ---------- */

  
  function badges(ev) {
    if (!ev) return [h('span', { class: 'pp-badge pp-info' }, 'unknown')];
    const out = [];
    if (ev.cron) out.push(h('span', { class: 'pp-badge pp-warn', title: 'Has scheduled jobs' }, plural(ev.cron, 'schedule')));
    if (ev.service) out.push(h('span', { class: 'pp-badge pp-warn', title: 'Runs a background service' }, 'service'));
    if (ev.hooks) out.push(h('span', { class: 'pp-badge pp-warn', title: 'Runs when the array starts or stops' }, 'array hooks'));
    if (ev.running) out.push(h('span', { class: 'pp-badge pp-warn', title: 'A process is running from this plugin\'s folders right now' }, 'running now'));
    if (!out.length) out.push(h('span', { class: 'pp-badge pp-good', title: 'No schedule, service, hook or running process found. It only adds pages, tools or UI changes, so parking it just means those are missing until you load it.' }, 'no background activity'));
    return out;
  }

  function evidenceWarnings(ev) {
    const w = [];
    if (!ev) return ['I could not tell whether it runs anything on its own.'];
    if (ev.cron) w.push(plural(ev.cron, 'scheduled job') + ' will stop running until you load it again.');
    if (ev.service) w.push('Its background service will not start at boot.');
    if (ev.hooks) w.push('Its array start/stop hooks will not run.');
    if (ev.running) w.push('Something from it is running right now (' + plural(ev.running, 'process') + '), and it will not start again at boot.');
    return w;
  }

  /* ---------- Parking tab ---------- */

  function loadPlugins() {
    return api('plugins').then((s) => { state.plugins = s; renderPlugins(); enhanceMainList(); });
  }

  function pluginName(p) {
    return h('td', {}, h('span', { class: 'pp-name' }, p.title), h('span', { class: 'pp-sub' }, p.plg));
  }

  function renderPlugins() {
    const root = document.getElementById('pp-plugins');
    if (!root || !state.plugins) return;
    clear(root);
    const s = state.plugins.summary;
    root.appendChild(h('p', { class: 'pp-lead' },
      'A parked plugin stays installed on the flash drive but is not loaded at boot. Load it whenever you need it, without having to look it up in Community Apps. ' +
      'Plugins with schedules or background services should stay loaded; tools you only use now and then are good candidates.'));
    root.appendChild(h('p', { class: 'pp-summary' },
      s.bootTotal + ' s spent loading plugins at the last boot. ' + plural(s.parkedCount, 'plugin') + ' parked' +
      (s.savedSeconds ? ', saving about ' + s.savedSeconds + ' s per boot.' : '.')));
    root.appendChild(h('input', { type: 'button', value: 'Refresh', onclick: () => loadPlugins() }));

    root.appendChild(h('h3', {}, 'Loaded at boot'));
    const rows = state.plugins.loaded.map((p) => h('tr', {},
      pluginName(p),
      h('td', {}, p.description || ''),
      h('td', { class: 'pp-num' }, secs(p.bootSeconds)),
      h('td', {}, badges(p.evidence)),
      h('td', { class: 'pp-actions' }, p.protected
        ? h('span', { class: 'pp-sub', title: 'Core plugins and Plugin Parking itself cannot be parked' }, 'required')
        : h('input', { type: 'button', value: 'Park', onclick: () => parkFlow(p) }))));
    root.appendChild(h('table', { class: 'unraid pp-table' },
      h('thead', {}, h('tr', {}, h('th', {}, 'Plugin'), h('th', {}, 'What it does'), h('th', { class: 'pp-num' }, 'Boot time'), h('th', {}, 'Runs on its own'), h('th', {}, ''))),
      h('tbody', {}, rows)));

    root.appendChild(h('h3', {}, 'Parked'));
    if (!state.plugins.parked.length) {
      root.appendChild(h('div', { class: 'pp-empty' }, 'Nothing is parked. Use Park on a plugin above, or on its row in the Installed Plugins list.'));
      return;
    }
    const prow = state.plugins.parked.map((p) => h('tr', {},
      pluginName(p),
      h('td', {}, p.description || ''),
      h('td', {}, p.version || '–'),
      h('td', { class: 'pp-num' }, secs(p.bootSeconds)),
      h('td', {}, [p.loadedNow ? h('span', { class: 'pp-badge pp-info', title: 'Loaded until the next reboot' }, 'loaded now') : null, badges(p.evidence)]),
      h('td', { class: 'pp-actions' },
        h('input', { type: 'button', value: 'Load now…', disabled: p.loadedNow, onclick: () => loadFlow(p) }),
        h('input', { type: 'button', value: 'Load at every boot', title: 'Move it back to the normal plugins folder', onclick: () => unparkFlow(p) }))));
    root.appendChild(h('table', { class: 'unraid pp-table' },
      h('thead', {}, h('tr', {}, h('th', {}, 'Plugin'), h('th', {}, 'What it does'), h('th', {}, 'Cached version'), h('th', { class: 'pp-num' }, 'Boot time saved'), h('th', {}, 'Runs on its own'), h('th', {}, ''))),
      h('tbody', {}, prow)));
  }

  function parkFlow(p) {
    const w = evidenceWarnings(p.evidence);
    return dialog({
      title: 'Park “' + p.title + '”?',
      body: [
        h('p', {}, 'It will not load at the next boot. It keeps running until then, and its settings stay where they are.'),
        w.length ? h('p', {}, 'Until you load it again:') : null,
        warnBox(w),
        p.bootSeconds ? h('p', {}, 'It took about ' + p.bootSeconds + ' s to load at the last boot.') : null,
      ],
      buttons: [{ label: 'Cancel', value: 'no' }, { label: 'Park', value: 'park', primary: true }],
    }).then((r) => {
      if (!r || r.button !== 'park') return null;
      return api('park', { plg: p.plg }).then((res) => (res.ok ? loadPlugins() : notify('Could not park it', res.error || 'Unknown error')));
    });
  }

  function unparkFlow(p) {
    return api('unpark', { plg: p.plg }).then((res) => (res.ok ? loadPlugins() : notify('Could not move it back', res.error || 'Unknown error')));
  }

  function loadFlow(p) {
    const stop = busy('Checking for a newer version of “' + p.title + '”…');
    return api('check', { plg: p.plg }).then((c) => {
      stop();
      const keep = { name: 'keep', options: [
        { value: 'session', label: 'Keep it parked: load it now, but not at the next boot', checked: true },
        { value: 'enable', label: 'Load it now and at every boot from now on' } ] };
      let body; const choices = [];
      if (c.ok && c.newer) {
        body = [h('p', {}, 'A newer version is available. Cached: ' + c.cached + '. Latest: ' + c.latest + '.')];
        choices.push({ name: 'mode', options: [
          { value: 'latest', label: 'Install the latest version (' + c.latest + ')', checked: true },
          { value: 'cached', label: 'Install the cached version (' + c.cached + ')' } ] });
      } else {
        body = [h('p', {}, 'Load the cached version (' + (c.cached || p.version || 'unknown') + ')?'),
          h('p', { class: 'pp-sub' }, c.ok && c.latest ? 'It is the newest version available.' : (c.error ? c.error + '. The cached copy needs no network.' : ''))];
      }
      choices.push(keep);
      return dialog({ title: 'Load “' + p.title + '”', body: body, choices: choices, buttons: [{ label: 'Cancel', value: 'no' }, { label: 'Load', value: 'go', primary: true }] });
    }).then((r) => {
      if (!r || r.button !== 'go') return null;
      const mode = r.picks.mode || 'cached';
      const stop = busy('Loading “' + p.title + '”…');
      return api('load', { plg: p.plg, mode: mode, keep: r.picks.keep || 'session' }).then((res) => {
        stop();
        const out = res.output ? h('pre', {}, res.output) : null;
        if (!res.ok) return notify('Could not load it', res.error || 'Unknown error', out);
        return dialog({ title: 'Loaded', body: [h('p', {}, '“' + p.title + '” is loaded. Reload the page to see it in the menus.'), out],
          buttons: [{ label: 'Close', value: 'close' }, { label: 'Reload page', value: 'reload', primary: true }] })
          .then((d) => { if (d && d.button === 'reload') location.reload(); else loadPlugins(); });
      });
    }).catch((e) => { notify('Something went wrong', e.message); });
  }

  /* ---------- Park buttons on the built-in Installed Plugins list ---------- */

  function enhanceMainList() {
    const list = document.getElementById('plugin_list');
    if (!list || !state.plugins) return;
    const loaded = {}; state.plugins.loaded.forEach((p) => { loaded[p.plg] = p; });
    const parked = {}; state.plugins.parked.forEach((p) => { parked[p.plg] = p; });
    list.querySelectorAll('input.remove[type="button"]').forEach((btn) => {
      const plg = btn.getAttribute('data');
      const cell = btn.parentNode;
      const mine = cell.querySelector('.pp-row-park');
      if (loaded[plg] && !loaded[plg].protected) {
        if (!mine) btn.insertAdjacentElement('afterend', h('input', { type: 'button', class: 'pp-row-park', value: 'Park', title: 'Keep it installed but do not load it at boot', onclick: () => parkFlow(loaded[plg]) }));
      } else if (mine) {
        mine.remove();
      }
      const row = cell.closest('tr');
      const first = row && row.querySelector('td');
      const tag = first && first.querySelector('.pp-row-tag');
      if (parked[plg] && first && !tag) first.appendChild(h('span', { class: 'pp-badge pp-info pp-row-tag', title: 'Parked: it will not load at the next boot' }, 'parked'));
      else if (!parked[plg] && tag) tag.remove();
    });
  }

  function watchMainList() {
    const list = document.getElementById('plugin_list');
    if (!list || list.__ppWatched || !window.MutationObserver) return;
    list.__ppWatched = true;
    new MutationObserver(() => enhanceMainList()).observe(list, { childList: true, subtree: true });
  }

  /* ---------- Boot Packages tab ---------- */

  function loadPackages() {
    return api('packages').then((s) => {
      state.packages = s;
      renderPackages();
      if (!state.deps) {
        api('pkg_deps').then((d) => { state.deps = d; renderPackages(); fetchUsage(); }).catch(() => {});
      } else {
        fetchUsage();
      }
    });
  }

  function fetchUsage() {
    const todo = state.packages.packages.map((p) => p.base).filter((b) => !state.usage[b]);
    (function next() {
      const b = todo.shift();
      if (!b) return;
      api('pkg_usage', { base: b }).then((u) => { state.usage[b] = u; renderPackages(); next(); }).catch(() => next());
    })();
  }

  const byBase = () => { const m = {}; (state.packages ? state.packages.packages : []).forEach((p) => { m[p.base] = p; }); return m; };
  const nameOf = (base) => { const p = byBase()[base]; return p ? p.name : base; };

  function usedBy(p) {
    const parts = [];
    const users = state.deps ? (state.deps.users[p.base] || []) : null;
    if (users && users.length) parts.push('needed by ' + users.map(nameOf).join(', '));
    const u = state.usage[p.base];
    if (u && u.plugins.length) parts.push('may be used by plugin ' + u.plugins.join(', '));
    if (u && u.runtime && u.runtime.length) parts.push('called by the scripts of ' + u.runtime.join(', '));
    if (u && u.scripts.length) parts.push('may be called from user script ' + u.scripts.join(', '));
    if (!parts.length) return h('span', { class: 'pp-sub' }, state.deps && state.usage[p.base] ? 'nothing found' : 'checking…');
    return parts.map((t) => h('div', {}, t));
  }

  function renderPackages() {
    const root = document.getElementById('pp-packages');
    if (!root || !state.packages) return;
    clear(root);
    root.appendChild(h('p', { class: 'pp-lead' },
      'Unraid installs every package in /boot/extra at each boot, which is where un-get keeps what it installs. ' +
      'Park a package to keep the file on the flash drive without installing it at boot; load it again when you need it. ' +
      'Dependencies are worked out from shared libraries and script interpreters, so a plain “nothing found” is a hint, not a guarantee.'));
    const pk = state.packages.packages;
    const total = pk.filter((p) => p.where === 'boot').reduce((a, p) => a + (p.bootSeconds || 0), 0);
    root.appendChild(h('p', { class: 'pp-summary' }, plural(pk.filter((p) => p.where === 'boot').length, 'package') + ' load at boot' + (total ? ' (about ' + total + ' s at the last boot)' : '') + '; ' + pk.filter((p) => p.where === 'parked').length + ' parked.'));
    const ug = state.packages.unget;
    if (ug && ug.present && ug.parked) {
      root.appendChild(h('div', { class: 'pp-unget' },
        h('strong', {}, 'un-get cannot see parked packages. '),
        'It only looks at /boot/extra. ',
        h('code', {}, 'un-get upgrade'), ' downloads and installs a newer version of a parked package it tracks, so that package loads at every boot again. ',
        h('code', {}, 'un-get cleanup'), ' offers to delete files in /boot/extra whose package is not installed (such as one you moved back with “Load at every boot” and have not loaded yet), and drops packages that are not installed from its own list. ',
        h('code', {}, 'un-get remove'), ' leaves the parked copy behind.',
        ug.tracked.length ? h('div', {}, 'Parked and in un-get’s list: ' + ug.tracked.join(', ') + '.') : null,
        h('div', { class: 'pp-sub' }, 'In a terminal, un-get prints a reminder about this before those commands.')));
    }
    root.appendChild(h('input', { type: 'button', value: 'Refresh', onclick: () => { state.deps = null; state.usage = {}; loadPackages(); } }));
    if (!pk.length) { root.appendChild(h('div', { class: 'pp-empty' }, 'There are no packages in /boot/extra.')); return; }
    const rows = pk.map((p) => h('tr', {},
      h('td', {}, h('span', { class: 'pp-name' }, p.name), h('span', { class: 'pp-sub' }, p.version), p.ungetInstalled ? h('span', { class: 'pp-badge pp-info', title: 'Listed in un-get\'s installed list' }, 'un-get') : null),
      h('td', {}, p.description || ''),
      h('td', { class: 'pp-num' }, p.size || (p.diskMB + ' MB file')),
      h('td', { class: 'pp-num' }, p.where === 'boot' ? secs(p.bootSeconds) : '–'),
      h('td', {}, p.where === 'boot' ? h('span', { class: 'pp-badge pp-good' }, 'loads at boot') : h('span', { class: 'pp-badge pp-info' }, 'parked'), p.installed ? h('span', { class: 'pp-badge pp-warn' }, 'installed now') : null),
      h('td', {}, usedBy(p)),
      h('td', { class: 'pp-actions' }, pkgActions(p))));
    root.appendChild(h('table', { class: 'unraid pp-table' },
      h('thead', {}, h('tr', {}, h('th', {}, 'Package'), h('th', {}, 'What it is'), h('th', { class: 'pp-num' }, 'Size'), h('th', { class: 'pp-num' }, 'Boot time'), h('th', {}, 'State'), h('th', {}, 'Used by'), h('th', {}, ''))),
      h('tbody', {}, rows)));
  }

  function pkgActions(p) {
    if (p.where === 'boot') return [h('input', { type: 'button', value: 'Park', onclick: () => pkgParkFlow(p) })];
    const out = [];
    if (!p.installed) out.push(h('input', { type: 'button', value: 'Load now…', onclick: () => pkgLoadFlow(p) }));
    else out.push(h('input', { type: 'button', value: 'Unload now…', onclick: () => pkgUnloadFlow(p) }));
    out.push(h('input', { type: 'button', value: 'Load at every boot', onclick: () => api('pkg_unpark', { file: p.file }).then((r) => (r.ok ? loadPackages() : notify('Could not move it back', r.error))) }));
    return out;
  }

  function warningsForPark(p) {
    const w = [];
    const map = byBase();
    const users = ((state.deps && state.deps.users[p.base]) || []).filter((b) => map[b] && map[b].where === 'boot');
    if (users.length) w.push('Loaded at boot and needs it: ' + users.map(nameOf).join(', ') + '. They will not work without it.');
    const u = state.usage[p.base];
    if (u && u.plugins.length) w.push('Plugin ' + u.plugins.join(', ') + ' seems to use it.');
    if (u && u.runtime && u.runtime.length) w.push('The scripts of ' + u.runtime.join(', ') + ' call one of its programs.');
    if (u && u.scripts.length) w.push('User script ' + u.scripts.join(', ') + ' calls one of its programs.');
    if (!state.deps) w.push('Dependencies are still being worked out; you may want to wait for the “Used by” column to fill in.');
    return w;
  }

  /** What a package needs (and what those need in turn) that still loads at boot. */
  function bootDeps(p) {
    const map = byBase();
    const out = []; const seen = { [p.base]: true }; const todo = ((state.deps && state.deps.deps[p.base]) || []).slice();
    while (todo.length) {
      const b = todo.shift();
      if (seen[b]) continue;
      seen[b] = true;
      if (map[b] && map[b].where === 'boot') out.push(b);
      ((state.deps && state.deps.deps[b]) || []).forEach((n) => todo.push(n));
    }
    return out;
  }

  function parkDepsFlow(p, deps) {
    return dialog({
      title: 'Park what ' + p.name + ' needs too?',
      body: [h('p', {}, p.name + ' needs ' + deps.map(nameOf).join(', ') + ', which also load at every boot.'),
        h('p', {}, 'If you say yes, each one is checked first: any that another package needs, or that a plugin or script seems to use, is left where it is.')],
      buttons: [{ label: 'No, just ' + p.name, value: 'no' }, { label: 'Check and park them', value: 'go', primary: true }],
    }).then((r) => {
      if (!r || r.button !== 'go') return null;
      const stop = busy('Checking what else needs them…');
      return api('pkg_park_deps', { file: p.file }).then((res) => {
        stop();
        if (!res.ok) return notify('Could not check them', res.error);
        const lines = [];
        if (res.parked.length) lines.push(h('p', {}, 'Parked: ' + res.parked.join(', ') + '.'));
        if (res.kept.length) {
          lines.push(h('p', {}, 'Left where they are:'));
          lines.push(h('ul', {}, res.kept.map((k) => h('li', {}, k.name + ': ' + k.reasons.join('; ') + '.'))));
        }
        if (!lines.length) lines.push(h('p', {}, 'There was nothing more to park.'));
        return notify('Dependencies checked', 'Nothing was parked that anything else needs.', h('div', {}, lines));
      }).catch((e) => { stop(); return notify('Could not check them', e.message); });
    });
  }

  function pkgParkFlow(p) {
    const deps = bootDeps(p);
    return dialog({
      title: 'Park ' + p.name + '?',
      body: [h('p', {}, 'It will stay on the flash drive but will not be installed at the next boot. It stays installed until then.'), warnBox(warningsForPark(p))],
      buttons: [{ label: 'Cancel', value: 'no' }, { label: 'Park', value: 'park', primary: true }],
    }).then((r) => {
      if (!r || r.button !== 'park') return null;
      return api('pkg_park', { file: p.file }).then((res) => {
        if (!res.ok) return notify('Could not park it', res.error);
        return (deps.length ? parkDepsFlow(p, deps) : Promise.resolve()).then(() => loadPackages());
      });
    });
  }

  function pkgLoadFlow(p) {
    const map = byBase();
    const need = ((state.deps && state.deps.deps[p.base]) || []).filter((b) => map[b] && map[b].where === 'parked' && !map[b].installed);
    return dialog({
      title: 'Load ' + p.name + ' now?',
      body: [h('p', {}, 'It is installed straight from the flash drive; no network is needed.'),
        need.length ? h('p', {}, 'It needs parked packages too, which will be loaded first: ' + need.map(nameOf).join(', ') + '.') : null],
      buttons: [{ label: 'Cancel', value: 'no' }, { label: 'Load', value: 'go', primary: true }],
    }).then((r) => {
      if (!r || r.button !== 'go') return null;
      const files = need.map((b) => map[b].file).concat([p.file]);
      const stop = busy('Loading ' + p.name + '…');
      let log = '';
      return files.reduce((pr, f) => pr.then((ok) => (!ok ? false : api('pkg_load', { file: f }).then((res) => { log += (res.output || '') + '\n'; if (!res.ok) throw new Error(res.error || 'The install failed'); return true; }))), Promise.resolve(true))
        .then(() => { stop(); return notify('Loaded', p.name + ' is installed.', h('pre', {}, log.trim())).then(() => loadPackages()); })
        .catch((e) => { stop(); return notify('Could not load it', e.message, h('pre', {}, log.trim())); });
    });
  }

  function pkgUnloadFlow(p) {
    const map = byBase();
    const users = ((state.deps && state.deps.users[p.base]) || []).filter((b) => map[b] && map[b].installed);
    return dialog({
      title: 'Unload ' + p.name + ' now?',
      body: [h('p', {}, 'This removes the installed package from the running system. The file stays parked on the flash drive.'),
        warnBox(users.length ? ['Installed packages that need it: ' + users.map(nameOf).join(', ') + '.'] : [])],
      buttons: [{ label: 'Cancel', value: 'no' }, { label: 'Unload', value: 'go', primary: true }],
    }).then((r) => (r && r.button === 'go'
      ? api('pkg_unload', { file: p.file }).then((res) => (res.ok ? loadPackages() : notify('Could not unload it', res.error, res.output ? h('pre', {}, res.output) : null)))
      : null));
  }

  /* ---------- the question asked before Plugin Parking is removed ---------- */

  function askBeforeRemoval() {
    return api('parked_counts').then((c) => {
      if (!c.plugins && !c.packages) return true;
      const what = [c.plugins ? plural(c.plugins, 'parked plugin') : null, c.packages ? plural(c.packages, 'parked package') : null].filter(Boolean).join(' and ');
      return dialog({
        title: 'Remove Plugin Parking?',
        body: [h('p', {}, 'You have ' + what + '. What should happen to them?')],
        choices: [{ name: 'choice', options: [
          { value: 'restore', label: 'Move them back so they load at every boot again (recommended)', checked: true },
          { value: 'keep', label: 'Keep them parked (they will not load at boot; you would have to load them by hand)' } ] }],
        buttons: [{ label: 'Cancel', value: 'no' }, { label: 'Continue removal', value: 'go', primary: true }],
      }).then((r) => {
        if (!r || r.button !== 'go') return false;
        return api('remove_choice', { choice: r.picks.choice || 'restore' }).then((res) => {
          if (res.ok) return true;
          return notify('Could not save your choice', 'The removal will move everything back to be safe.').then(() => true);
        });
      });
    }).catch(() => true);
  }

  const isOwnRemoval = (cmd) => typeof cmd === 'string' && /^(multi)?plugin remove\b/.test(cmd) && /(^|[\s*])plugin-parking\.plg(?![\w.-])/.test(cmd);

  function guardRemoval() {
    ['openInstall', 'openPlugin'].forEach((name) => {
      const orig = window[name];
      if (typeof orig !== 'function' || orig.__ppGuard) return;
      const wrapped = function (cmd) {
        const args = arguments;
        if (!isOwnRemoval(cmd)) return orig.apply(this, args);
        askBeforeRemoval().then((go) => { if (go) orig.apply(window, args); });
      };
      wrapped.__ppGuard = true;
      window[name] = wrapped;
    });
  }

  /* ---------- start ---------- */

  function init() {
    guardRemoval();
    setTimeout(guardRemoval, 1500);
    watchMainList();
    if (document.getElementById('pp-plugins')) {
      loadPlugins().catch((e) => {
        const r = document.getElementById('pp-plugins');
        clear(r); r.appendChild(h('div', { class: 'pp-empty' }, 'Could not read the plugin list: ' + e.message));
      });
    }
    const pk = document.getElementById('pp-packages');
    if (pk) {
      const go = () => loadPackages().catch((e) => { clear(pk); pk.appendChild(h('div', { class: 'pp-empty' }, 'Could not read the package list: ' + e.message)); });
      if ('IntersectionObserver' in window) {
        const io = new IntersectionObserver((entries) => { if (entries.some((x) => x.isIntersecting)) { io.disconnect(); go(); } });
        io.observe(pk);
      } else {
        go();
      }
    }
  }

  window.__ppTest = { isOwnRemoval: isOwnRemoval, h: h, dialog: dialog, state: state, parkFlow: parkFlow };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
