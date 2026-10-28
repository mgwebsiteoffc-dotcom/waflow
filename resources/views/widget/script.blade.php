{{-- Public storefront widget. Served as JavaScript. $config contains only non-secret display settings. --}}
window.__WF_CONFIG__ = @json($config);
@verbatim
(function () {
 'use strict';
 var C = window.__WF_CONFIG__;
 if (!C || typeof C !== 'object') return;
 var scriptSrc = (document.currentScript && document.currentScript.src) || '';
 var base = scriptSrc ? scriptSrc.replace(/\/widget\.js(\?.*)?$/, '') : '';
 var endpoint = base + '/capture';
 var openKind = null, entryShown = false, exitShown = false, maxScroll = 0;

 function h(tag, attrs, children) {
  var e = document.createElement(tag);
  if (attrs) Object.keys(attrs).forEach(function (k) {
   if (k === 'class') e.className = attrs[k];
   else if (k === 'text') e.textContent = attrs[k];
   else e.setAttribute(k, attrs[k]);
  });
  (children || []).forEach(function (c) { e.appendChild(c); });
  return e;
 }
 function cookieGet(name) {
  var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
  return m ? decodeURIComponent(m[1]) : null;
 }
 function recentlySeen(name, days) {
  if (days > 0) return cookieGet(name) === '1';
  try { return sessionStorage.getItem(name) === '1'; } catch (e) { return false; }
 }
 function markSeen(name, days) {
  if (days > 0) document.cookie = name + '=1; path=/; max-age=' + (days * 86400) + '; samesite=lax';
  else { try { sessionStorage.setItem(name, '1'); } catch (e) {} }
 }
 function closeModal() {
  var overlay = document.getElementById('wf-overlay');
  if (overlay) overlay.remove();
  openKind = null;
  document.body.style.overflow = '';
 }
 function fail(err, msg) { err.textContent = msg; err.style.display = 'block'; }
 function submitCapture(kind, name, phone, err, success, form) {
  err.style.display = 'none';
  name = (name || '').trim(); phone = (phone || '').trim();
  if (!name) return fail(err, 'Please enter your name.');
  var digits = phone.replace(/\D+/g, '');
  if (digits.length < 8) return fail(err, 'Please enter a valid WhatsApp number with country code.');
  var btn = form.querySelector('button[type=submit]');
  btn.disabled = true; var oldLabel = btn.textContent; btn.textContent = 'Sending…';
  fetch(endpoint, {
   method: 'POST',
   headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
   body: JSON.stringify({ name: name, phone: digits, source: kind })
  }).then(function (res) {
   return res.json().catch(function () { return {}; }).then(function (body) { return { ok: res.ok, body: body }; });
  }).then(function (r) {
   if (r.ok) {
    form.style.display = 'none';
    success.style.display = 'block';
    setTimeout(closeModal, 2400);
   } else {
    var first = r.body && r.body.errors ? Object.values(r.body.errors)[0] : null;
    fail(err, (r.body && r.body.message) || (Array.isArray(first) ? first[0] : 'Something went wrong. Please try again.'));
    btn.disabled = false; btn.textContent = oldLabel;
   }
  }).catch(function () {
   fail(err, 'Network error. Please check your connection and try again.');
   btn.disabled = false; btn.textContent = oldLabel;
  });
 }
 function showModal(kind) {
  if (openKind) return;
  var cfg = kind === 'entry' ? C.entry : C.exit;
  var cookieName = kind === 'entry' ? 'wf_entry_seen' : 'wf_exit_seen';
  if (recentlySeen(cookieName, cfg.frequencyDays)) return;
  markSeen(cookieName, cfg.frequencyDays);
  openKind = kind;

  var nameInput = h('input', { class: 'wf-input', type: 'text', name: 'name', placeholder: 'Your name', required: 'required', 'aria-label': 'Name' });
  var phoneInput = h('input', { class: 'wf-input', type: 'tel', name: 'phone', placeholder: 'WhatsApp number, e.g. +91 98765 43210', required: 'required', 'aria-label': 'WhatsApp number' });
  var err = h('div', { class: 'wf-error' }); err.style.display = 'none';
  var success = h('div', { class: 'wf-success', text: 'Thank you! We will message you on WhatsApp.' }); success.style.display = 'none';
  var form = h('form', { class: 'wf-form', novalid: 'novalidate' }, [
   nameInput, phoneInput, err,
   h('button', { class: 'wf-submit', type: 'submit', text: cfg.button || 'Subscribe' })
  ]);
  form.addEventListener('submit', function (e) {
   e.preventDefault();
   submitCapture(kind, nameInput.value, phoneInput.value, err, success, form);
  });
  var closeBtn = h('button', { class: 'wf-close', type: 'button', 'aria-label': 'Close', text: '×' });
  closeBtn.addEventListener('click', closeModal);
  var card = h('div', { class: 'wf-card', role: 'dialog', 'aria-modal': 'true' }, [
   closeBtn,
   h('div', { class: 'wf-badge', text: 'WhatsApp' }),
   h('h2', { class: 'wf-title', text: cfg.title || '' }),
   h('p', { class: 'wf-text', text: cfg.text || '' }),
   form, success
  ]);
  var overlay = h('div', { class: 'wf-overlay', id: 'wf-overlay' }, [card]);
  overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
  document.addEventListener('keydown', function escClose(e) {
   if (e.key === 'Escape' && openKind) { closeModal(); document.removeEventListener('keydown', escClose); }
  });
  document.body.style.overflow = 'hidden';
  document.body.appendChild(overlay);
 }
 function mountFab() {
  var f = C.float;
  if (!f.enabled || !f.phone) return;
  var svg = '<svg viewBox="0 0 32 32" width="28" height="28" fill="#fff" aria-hidden="true"><path d="M16.04 4C9.93 4 5 8.93 5 15.04c0 2.13.56 4.15 1.54 5.88L5 28l7.24-1.49a11 11 0 0 0 3.8.67h.01c6.11 0 11.04-4.93 11.04-11.04 0-2.95-1.15-5.72-3.24-7.81A10.97 10.97 0 0 0 16.04 4zm0 20.13h-.01a9.1 9.1 0 0 1-4.64-1.27l-.33-.2-3.62.75.77-3.53-.22-.36a9.08 9.08 0 0 1-1.39-4.88c0-5.03 4.1-9.12 9.14-9.12 2.44 0 4.73.95 6.45 2.68a9.07 9.07 0 0 1 2.67 6.46c0 5.03-4.1 9.47-9.15 9.47zm5.02-6.83c-.27-.14-1.62-.8-1.87-.89-.25-.09-.43-.14-.61.14-.18.27-.7.89-.86 1.07-.16.18-.31.2-.58.07-.27-.14-1.15-.42-2.19-1.35-.81-.72-1.36-1.61-1.52-1.88-.16-.27-.02-.42.12-.55.12-.12.27-.31.41-.47.14-.16.18-.27.27-.45.09-.18.05-.34-.02-.48-.07-.14-.61-1.47-.84-2.01-.22-.53-.45-.46-.61-.47h-.52c-.18 0-.47.07-.72.34-.25.27-.95.93-.95 2.27 0 1.34.97 2.63 1.11 2.81.14.18 1.91 2.92 4.63 4.09.65.28 1.15.45 1.55.57.65.21 1.24.18 1.71.11.52-.08 1.62-.66 1.85-1.3.23-.64.23-1.18.16-1.3-.07-.12-.25-.18-.52-.32z"/></svg>';
  var a = h('a', {
   class: 'wf-fab wf-' + (f.position === 'left' ? 'left' : 'right'),
   href: 'https://wa.me/' + f.phone + '?text=' + encodeURIComponent(f.message || ''),
   target: '_blank', rel: 'noopener', title: 'Chat on WhatsApp', 'aria-label': 'Chat on WhatsApp'
  });
  a.style.background = f.color || '#25d366';
  a.innerHTML = svg;
  document.body.appendChild(a);
 }
 var CSS = '.wf-overlay{position:fixed;inset:0;z-index:2147483600;background:rgba(18,16,34,.55);display:flex;align-items:center;justify-content:center;padding:18px;font-family:inherit}.wf-card{position:relative;background:#fff;width:min(430px,100%);border-radius:16px;padding:28px 24px 24px;box-shadow:0 24px 60px rgba(16,12,38,.35);animation:wfpop .22s ease-out}@keyframes wfpop{from{transform:translateY(14px);opacity:0}to{transform:none;opacity:1}}.wf-close{position:absolute;top:10px;right:14px;border:0;background:none;font-size:24px;line-height:1;color:#9b98aa;cursor:pointer}.wf-badge{display:inline-block;background:#e7f7ee;color:#1f8a54;font-size:10px;font-weight:800;letter-spacing:.8px;text-transform:uppercase;padding:4px 9px;border-radius:20px;margin-bottom:12px}.wf-title{margin:0 0 8px;font-size:20px;font-weight:800;color:#17152c;line-height:1.25}.wf-text{margin:0 0 18px;font-size:13px;color:#716f80;line-height:1.55}.wf-form{display:grid;gap:10px}.wf-input{width:100%;border:1px solid #e3e1ec;border-radius:10px;padding:12px 13px;font-size:13px;outline:none;color:#2a2740}.wf-input:focus{border-color:#8f73ee;box-shadow:0 0 0 3px rgba(112,68,232,.12)}.wf-submit{width:100%;border:0;border-radius:10px;padding:13px;background:#7044e8;color:#fff;font-size:13px;font-weight:700;cursor:pointer}.wf-submit:disabled{opacity:.65;cursor:default}.wf-error{background:#fff0ef;color:#ad5654;border:1px solid #f5dcdb;border-radius:9px;padding:9px 11px;font-size:11.5px}.wf-success{background:#eaf8f0;color:#267b50;border:1px solid #d6f1e1;border-radius:9px;padding:12px;font-size:12.5px;font-weight:600;text-align:center}.wf-fab{position:fixed;bottom:22px;width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 8px 22px rgba(16,12,38,.28);z-index:2147483599;text-decoration:none;animation:wffab .25s ease-out}@keyframes wffab{from{transform:scale(.6);opacity:0}to{transform:none;opacity:1}}.wf-fab.right{right:22px}.wf-fab.left{left:22px}.wf-fab:hover{filter:brightness(1.06)}@media(max-width:560px){.wf-fab{bottom:16px;width:50px;height:50px}.wf-fab.right{right:16px}.wf-fab.left{left:16px}.wf-card{padding:24px 18px 20px}}';

 function init() {
  if (!document.body) return;
  document.documentElement.appendChild(h('style', { text: CSS }));
  mountFab();
  if (C.entry && C.entry.enabled) {
   setTimeout(function () {
    if (!openKind && !entryShown && !exitShown) { entryShown = true; showModal('entry'); }
   }, Math.max(0, parseInt(C.entry.delay, 10) || 0) * 1000);
  }
  if (C.exit && C.exit.enabled) {
   document.addEventListener('mouseleave', function (e) {
    if (e.clientY <= 0 && !openKind && !exitShown) { exitShown = true; showModal('exit'); }
   });
   window.addEventListener('scroll', function () { maxScroll = Math.max(maxScroll, window.scrollY || 0); }, { passive: true });
   window.addEventListener('touchend', function () {
    if (maxScroll > 350 && (window.scrollY || 0) < 80 && !openKind && !exitShown) { exitShown = true; showModal('exit'); }
   }, { passive: true });
  }
 }
 if (document.body) init();
 else document.addEventListener('DOMContentLoaded', init);
})();
@endverbatim
