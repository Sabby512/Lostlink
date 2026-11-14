document.documentElement.classList.add('js');
const nav = document.getElementById('nav');
const links = [...document.querySelectorAll('.links a')];
const indicator = document.getElementById('indicator');
const burger = document.getElementById('burger');
 
// Sliding underline that follows the hovered / active link
function moveTo(el) {
  if (!el || !indicator) return;
  indicator.style.width = el.offsetWidth + 'px';
  indicator.style.height = el.offsetHeight + 'px';
  indicator.style.transform = `translate(${el.offsetLeft}px, ${el.offsetTop}px)`;
}
const current = () => document.querySelector('.links a.active');
links.forEach(a => {
  a.addEventListener('click', e => {
    const h = a.getAttribute('href');
    if (h === '#') e.preventDefault();
    if (h && h !== '#' && !h.startsWith('#')) return;   // real page: just go there
    current().classList.remove('active');
    a.classList.add('active');
    a.classList.remove('pop'); void a.offsetWidth; a.classList.add('pop');
    moveTo(a);
  });
});
window.addEventListener('resize', () => moveTo(current()));
// Place the pill right away (no slide), then reveal the white active text
function initPill() {
  if (!indicator) return;
  const box = document.getElementById('links');
  indicator.style.transition = 'none';
  moveTo(current());
  void indicator.offsetWidth;
  indicator.style.transition = '';
  if (box) box.classList.add('ready');
}
initPill();
window.addEventListener('load', initPill);
if (document.fonts) document.fonts.ready.then(initPill);
 
// Shrink nav on scroll
window.addEventListener('scroll', () => nav?.classList.toggle('scrolled', scrollY > 10));
 
// Mobile menu
burger?.addEventListener('click', () => {
  const open = burger.classList.toggle('open');
  burger.setAttribute('aria-expanded', open);
  document.getElementById('links')?.classList.toggle('open', open);
  document.querySelector('.actions')?.classList.toggle('open', open);
});
 
// Search + popular chips
const q = document.getElementById('q');
const result = document.getElementById('result');
document.querySelectorAll('.chip').forEach(c => c.addEventListener('click', () => { if (q) { q.value = c.textContent; q.focus(); } }));
document.getElementById('search')?.addEventListener('submit', e => {
  e.preventDefault();
  const term = q ? q.value.trim() : '';
  location.href = 'search.html' + (term ? '?q=' + encodeURIComponent(term) : '');   // search.html sends guests to log in
});
 
// Already logged in? Swap Log in / Sign up for a dashboard button.
if (localStorage.getItem('lostlink_auth')) {
  const act = document.querySelector('.actions');
  if (act) act.innerHTML = '<a class="btn solid small" href="' + (localStorage.getItem('lostlink_admin') === '1' ? 'admin-dashboard.html' : 'dashboard.html') + '" style="text-decoration:none;display:inline-block">Go to dashboard</a>';
}
 