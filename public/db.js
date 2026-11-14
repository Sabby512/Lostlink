/* LostLink data layer. The server is the source of truth: everything is saved in the database
   through the PHP files in /api (hosted on Vercel, data in Supabase). */
const LOSTLINK_DB_VERSION = 11;
 
/* ---------- Login guard: every page that loads db.js needs a logged-in user ---------- */
(function () {
  if (!localStorage.getItem('lostlink_auth')) location.replace('login.html');
})();
 
/* ---------- Small fetch helper ---------- */
async function llApi(url, body) {
  const opt = { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
  if (body !== undefined) { opt.method = 'POST'; opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
  let r;
  try { r = await fetch(url, opt); }
  catch { throw new Error('Cannot reach the server. Please check your connection and try again.'); }
  let data = null; try { data = await r.json(); } catch { /* not JSON */ }
  if (r.status === 401) { LostLinkUI.clear(); location.replace('login.html'); throw new Error('Please log in.'); }
  if (!r.ok) throw Object.assign(new Error((data && data.error) || 'Something went wrong (' + r.status + ').'), { status: r.status });
  return data;
}
 
/* ---------- Photos: colour copy + black & white copy, made in the browser (the server needs no GD extension) ---------- */
function llLoadImage(src) {
  return new Promise((res, rej) => {
    const i = new Image();
    i.onload = () => res(i);
    i.onerror = () => rej(new Error('Could not read that photo. Please use a JPG or PNG.'));
    i.src = src;
  });
}
async function llPhotoPair(src, max = 1600) {
  const img = await llLoadImage(src);
  const k = Math.min(1, max / Math.max(img.naturalWidth, img.naturalHeight));
  const w = Math.max(1, Math.round(img.naturalWidth * k)), h = Math.max(1, Math.round(img.naturalHeight * k));
  const c = document.createElement('canvas'); c.width = w; c.height = h;
  const x = c.getContext('2d', { willReadFrequently: true });
  x.fillStyle = '#fff'; x.fillRect(0, 0, w, h);
  x.drawImage(img, 0, 0, w, h);
  const color = c.toDataURL('image/jpeg', 0.9);
  const d = x.getImageData(0, 0, w, h), p = d.data;
  for (let i = 0; i < p.length; i += 4) { const g = Math.round(0.299 * p[i] + 0.587 * p[i + 1] + 0.114 * p[i + 2]); p[i] = p[i + 1] = p[i + 2] = g; }
  x.putImageData(d, 0, 0);
  return { color, bw: c.toDataURL('image/jpeg', 0.88) };
}
 
const LostLinkDB = {
  async list() { return llApi('api/items.php'); },
  async get(id) {
    try { return await llApi('api/items.php?id=' + encodeURIComponent(id)); }
    catch (e) { if (e.status === 404) return null; throw e; }
  },
  async add(item) {
    // Every uploaded photo is sent as a black & white public copy plus the colour original (reporter + admins only).
    // If the browser cannot read the photo this throws, so a colour photo is never sent as the public one.
    if (item && typeof item.photo === 'string' && item.photo.startsWith('data:image/')) {
      const p = await llPhotoPair(item.photo);
      item = { ...item, photo: p.bw, photoOriginal: p.color };
    }
    return llApi('api/items.php?action=add', item);
  },
  // Delete one of your own reports. The server refuses if the item already has claims or was returned.
  async remove(id) { return llApi('api/items.php?action=delete', { id }); },
  // Walk-in ticket, e.g. WLK-2026-0148. The database hands out the number, so two staff never get the same one.
  async nextTicket() { return (await llApi('api/items.php?action=ticket', {})).ticket; },
 
  async claims() { return llApi('api/claims.php'); },
  // A claim holds the proof text and optional proof file {name, data(base64 data URL)}. Only the owner and admins can read them.
  async addClaim(claim) { return llApi('api/claims.php', claim); },
 
  /* Notifications only exist AFTER an admin has verified something (match / approved / rejected),
     so a new account starts at 0. The server builds the list and respects Settings > Notifications. */
  async notifications() { return (await llApi('api/notifications.php')).list; },
  async markRead(ids) { return llApi('api/notifications.php', { ids }); },
  async unreadCount() {
    if (!LostLinkUI.settings().notif.inApp) return 0;
    return (await this.notifications()).filter(n => !n.read).length;
  }
};
 
/* ---------- Auth ---------- */
const LostLinkAuth = {
  async changePassword(current, next) { return llApi('api/auth.php?action=change_password', { current, next }); },
  async logout() {
    try { await fetch('api/auth.php?action=logout', { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: '{}' }); } catch {}
    LostLinkUI.clear();
    location.href = 'index.html';          // log out always lands on the home (index) page
  }
};
 
/* ---------- Settings + profile ----------
   The server is the source of truth. A copy lives in localStorage so pages can read it instantly
   (the early theme script on each page reads the same key). */
const DEFAULT_SETTINGS = {
  theme: 'light',
  notif: { inApp: true, email: true, match: true, decisions: true },
  privacy: { hideContact: true, twoStep: false },
  profile: { name: '', idNumber: '', email: '', role: '', program: '', yearLevel: '', section: '', since: '', photo: '', verified: false }
};
 
const LostLinkUI = {
  _saving: 0,
  settings() {
    let s = {}; try { s = JSON.parse(localStorage.getItem('lostlink_settings')) || {}; } catch {}
    return { theme: s.theme || DEFAULT_SETTINGS.theme,
      notif: { ...DEFAULT_SETTINGS.notif, ...(s.notif || {}) },
      privacy: { ...DEFAULT_SETTINGS.privacy, ...(s.privacy || {}) },
      profile: { ...DEFAULT_SETTINGS.profile, ...(s.profile || {}) } };
  },
  _store(s) { try { localStorage.setItem('lostlink_settings', JSON.stringify(s)); } catch {} },
  /* Used by login / signup / refresh: put the server's answer in the local copy. */
  setFromServer(payload) {
    const u = payload.user, st = payload.settings;
    this._store({ theme: st.theme, notif: st.notif, privacy: st.privacy,
      profile: { name: u.name, idNumber: u.idNumber, email: u.email, role: u.role, program: u.program,
                 yearLevel: u.yearLevel, section: u.section, since: u.since, photo: u.photo, verified: !!u.verified } });
    localStorage.setItem('lostlink_auth', '1');
    localStorage.setItem('lostlink_admin', u.isAdmin ? '1' : '0');   // straight from the server, no extra request
    this.applyTheme(st.theme);
  },
  clear() {
    ['lostlink_auth', 'lostlink_settings', 'lostlink_profile_extra', 'lostlink_admin'].forEach(k => localStorage.removeItem(k));
  },
  /* Settings page: saves instantly on screen, then to the database. */
  saveSettings(s) {
    const cur = this.settings();
    this._store({ ...s, profile: { ...cur.profile, name: s.profile.name, email: s.profile.email, idNumber: cur.profile.idNumber || s.profile.idNumber } });
    this.applyTheme(s.theme); this.initNav();
    this._saving++;
    return llApi('api/profile.php?action=save_settings', { theme: s.theme, notif: s.notif, privacy: s.privacy, profile: { name: s.profile.name, email: s.profile.email } })
      .then(p => { this.setFromServer(p); this.initNav(); return p; })
      .catch(e => { this._toast(e.message || 'Could not save your settings.'); })
      .finally(() => { this._saving--; });
  },
  /* Profile page: name, program, year level, section, photo. Resolves with the saved profile. */
  async saveProfile(data) {
    const p = await llApi('api/profile.php?action=save_profile', data);
    this.setFromServer(p); this.initNav();
    return p.user;
  },
  /* Is this account an admin? api/admin.php answers 200 for admins and 403 for everyone else. Remembered in localStorage. */
  async checkAdmin() {
    if (localStorage.getItem('lostlink_admin') !== null) return localStorage.getItem('lostlink_admin') === '1';
    try { const r = await fetch('api/admin.php', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      if (r.status === 401) return false; localStorage.setItem('lostlink_admin', r.ok ? '1' : '0'); return r.ok; } catch { return false; }
  },
  async refresh() {
    if (this._saving) return;
    this.checkAdmin().then(() => { this.adminLink(); this.bwCatchUp(); });
    try { const p = await llApi('api/auth.php?action=me'); if (!this._saving) { this.setFromServer(p); this.initAvatar(); this.adminLink(); document.dispatchEvent(new CustomEvent('lostlink:ready')); } } catch {}
  },
  _toast(m) { const t = document.createElement('div'); t.className = 'toast'; t.textContent = m; document.body.appendChild(t); setTimeout(() => t.remove(), 2800); },
  applyTheme(t) {
    t = t || this.settings().theme;
    if (t === 'device') t = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', t);
  },
  initials() {
    const n = (this.settings().profile.name || '').trim();
    return n ? n.split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase() : '--';
  },
  timeAgo(at) {
    const m = Math.floor((Date.now() - new Date(at)) / 6e4);
    if (m < 1) return 'just now';
    if (m < 60) return m + ' min ago';
    if (m < 1440) return Math.floor(m / 60) + ' h ago';
    if (m < 2880) return 'yesterday';
    return new Date(at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  },
  async initBell() {
    const bell = document.getElementById('bell'), badge = document.getElementById('bellCount');
    if (bell) bell.onclick = () => { location.href = 'notifications.html'; };
    if (!badge) return;
    let n = 0; try { n = await LostLinkDB.unreadCount(); } catch {}
    badge.textContent = n > 9 ? '9+' : n; badge.hidden = n === 0;
  },
  /* Admins no longer get a separate "Admin panel" button in the navbar: it lives in the account (avatar) dropdown. */
  adminLink() { this.avatarMenu(); },
  /* Account dropdown on the student pages. Click the avatar -> "My profile" (everyone) and "Admin panel" (admins only).
     Students keep the old behaviour: the avatar goes straight to the profile page. */
  avatarMenu() {
    const av = document.getElementById('avatar'); if (!av || !av.closest('.nav')) return;
    document.getElementById('adminLink')?.remove();                     // the old navbar button, if a cached page still has it
    if (localStorage.getItem('lostlink_admin') !== '1' || av.dataset.menu) return;
    av.dataset.menu = '1';
    if (!document.getElementById('llMenuCss')) {
      const st = document.createElement('style'); st.id = 'llMenuCss';
      st.textContent = '.ll-menu{position:fixed;z-index:1000;min-width:190px;padding:6px;border-radius:12px;background:var(--card,#fff);color:var(--ink,#14284b);border:1px solid rgba(128,140,170,.35);box-shadow:0 12px 30px rgba(20,40,75,.22)}'
        + '.ll-menu[hidden]{display:none}.ll-menu .who{padding:8px 12px 10px;margin-bottom:4px;border-bottom:1px solid rgba(128,140,170,.3);font-size:13px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
        + '.ll-menu .who small{display:block;font-weight:500;color:var(--muted,#3f4d66);margin-top:2px}'
        + '.ll-menu a{display:block;padding:10px 12px;border-radius:8px;font-size:14px;font-weight:700;color:inherit;text-decoration:none}'
        + '.ll-menu a:hover,.ll-menu a:focus-visible{background:rgba(128,140,170,.18);outline:none}';
      document.head.appendChild(st);
    }
    const p = this.settings().profile;
    const m = document.createElement('div'); m.className = 'll-menu'; m.setAttribute('role', 'menu'); m.hidden = true;
    const who = document.createElement('div'); who.className = 'who'; who.textContent = p.name || 'My account';
    const sm = document.createElement('small'); sm.textContent = 'Administrator'; who.appendChild(sm); m.appendChild(who);
    [['My profile', 'profile.html'], ['Admin panel', 'admin-dashboard.html']].forEach(([t, u]) => {
      const a = document.createElement('a'); a.href = u; a.textContent = t; a.setAttribute('role', 'menuitem'); m.appendChild(a);
    });
    document.body.appendChild(m);
    const close = () => { m.hidden = true; av.setAttribute('aria-expanded', 'false'); };
    const open = () => { const r = av.getBoundingClientRect(); m.style.top = (r.bottom + 8) + 'px'; m.style.right = Math.max(8, innerWidth - r.right) + 'px'; m.hidden = false; av.setAttribute('aria-expanded', 'true'); };
    av.setAttribute('aria-haspopup', 'menu'); av.setAttribute('aria-expanded', 'false');
    av.onclick = e => { e.preventDefault(); e.stopPropagation(); m.hidden ? open() : close(); };
    av.onkeydown = e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); m.hidden ? open() : close(); if (!m.hidden) m.querySelector('a')?.focus(); } };
    document.addEventListener('click', e => { if (!m.hidden && !m.contains(e.target)) close(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !m.hidden) { close(); av.focus(); } });
    addEventListener('resize', close);
  },
  /* Admins: photos uploaded before black & white existed are converted here, in the browser, then saved by the server. */
  async bwCatchUp() {
    if (localStorage.getItem('lostlink_admin') !== '1' || this._bw) return;
    this._bw = true;
    try {
      const list = await llApi('api/admin_items.php?action=bw_pending');
      for (const it of list || []) {
        try {
          const r = await fetch(it.url, { credentials: 'same-origin' }); if (!r.ok) continue;
          const src = await new Promise((res, rej) => { const f = new FileReader(); f.onload = () => res(f.result); f.onerror = rej; r.blob().then(b => f.readAsDataURL(b)); });
          const p = await llPhotoPair(src);
          await llApi('api/admin_items.php?action=bw_upload', { id: it.id, bw: p.bw });
        } catch {}
      }
    } catch {} finally { this._bw = false; }
  },
  initAvatar() {
    const av = document.getElementById('avatar'); if (!av) return;
    const p = this.settings().profile;
    if (p.photo) { av.style.overflow = 'hidden'; av.innerHTML = ''; const i = new Image(); i.src = p.photo; i.alt = ''; i.style.cssText = 'width:100%;height:100%;object-fit:cover'; av.appendChild(i); }
    else av.textContent = this.initials();
  },
  initNav() {
    const here = location.pathname.split('/').pop();
    const go = (el, url) => { if (!el) return; el.onclick = e => { e.preventDefault(); location.href = url; };
      if (el.tagName !== 'BUTTON') { el.setAttribute('role', 'button'); el.tabIndex = 0; el.style.cursor = 'pointer';
        el.onkeydown = e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); location.href = url; } }; } };
    go(document.getElementById('settings'), 'settings.html');
    if (!document.getElementById('avatar')?.dataset.menu) go(document.getElementById('avatar'), 'profile.html');   // admins: the avatar opens the dropdown instead
    document.getElementById('bell')?.classList.toggle('on', here === 'notifications.html');
    document.getElementById('settings')?.classList.toggle('on', here === 'settings.html');
    this.initAvatar();
    const brand = document.querySelector('.nav .brand'); if (brand && brand.getAttribute('href') === '#') brand.setAttribute('href', 'dashboard.html');
    // Log out button on every page -> ends the session and goes back to the index page
    document.querySelectorAll('.logout').forEach(b => { b.onclick = e => { e.preventDefault(); LostLinkAuth.logout(); }; });
    this.userViewButton();
    this.avatarMenu();
    this.initBell();
  },
  /* Admin sidebar: a home button next to Log out that goes back to the student dashboard.
     Added here so every admin page gets it, even if its own HTML was not updated. */
  userViewButton() {
    const out = document.querySelector('.side .me .logout');
    if (!out || document.getElementById('userView')) return;
    const a = document.createElement('a');
    a.id = 'userView'; a.href = 'dashboard.html'; a.title = 'User dashboard'; a.setAttribute('aria-label', 'Go to user dashboard');
    a.className = out.className.replace('logout', '').trim() || 'lo';
    a.style.cssText = 'flex:none;width:34px;height:34px;border-radius:9px;border:1.5px solid rgba(255,255,255,.28);background:transparent;color:#fff;display:grid;place-items:center;text-decoration:none';
    a.innerHTML = '<svg viewBox="0 0 24 24" style="width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><path d="M3 11l9-8 9 8M5 9.5V20h14V9.5M10 20v-6h4v6"/></svg>';
    out.parentNode.style.gap = '8px';          // same spacing on every page, so the two buttons sit together
    out.parentNode.insertBefore(a, out);
  }
};
LostLinkUI.applyTheme();
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => { LostLinkUI.initNav(); LostLinkUI.refresh(); });
else { LostLinkUI.initNav(); LostLinkUI.refresh(); }
matchMedia('(prefers-color-scheme: dark)').addEventListener?.('change', () => { if (LostLinkUI.settings().theme === 'device') LostLinkUI.applyTheme(); });
 
// Make them reachable as window.X too (pages check window.LostLinkAuth / window.LostLinkUI)
window.LostLinkDB = LostLinkDB; window.LostLinkUI = LostLinkUI; window.LostLinkAuth = LostLinkAuth;