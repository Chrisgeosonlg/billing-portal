'use strict';
document.getElementById('add')?.addEventListener('click', () => {
  const items = document.getElementById('items');
  if (items.children.length >= 30) return;
  const row = items.firstElementChild.cloneNode(true);
  row.querySelectorAll('input').forEach(input => { input.value = input.name === 'quantity[]' ? '1' : ''; });
  items.append(row);
  row.querySelector('input').focus();
});
document.getElementById('items')?.addEventListener('click', event => {
  if (event.target.matches('.remove') && event.currentTarget.children.length > 1) event.target.closest('.item').remove();
});
document.getElementById('print')?.addEventListener('click', () => window.print());
document.getElementById('copy-customer-link')?.addEventListener('click', async () => {
  const input = document.getElementById('customer-link');
  const status = document.getElementById('copy-link-status');
  try { await navigator.clipboard.writeText(input.value); status.textContent = 'Link copied.'; }
  catch { input.focus(); input.select(); status.textContent = 'Link selected. Copy it using your browser or keyboard.'; }
});
document.getElementById('recurring-toggle')?.addEventListener('change', event => {
  const fields = document.getElementById('recurring-fields');
  fields.hidden = !event.target.checked;
  if (event.target.checked) fields.querySelector('input')?.focus();
});
document.querySelector('.mark-choice')?.addEventListener('change', event => event.currentTarget.requestSubmit());
// Send result pop-up: success closes itself after a while, a failure stays until closed.
const toast = document.getElementById('toast');
if (toast) {
  const close = () => { toast.classList.add('toast-hide'); setTimeout(() => toast.remove(), 300); };
  toast.querySelector('.toast-close').addEventListener('click', close);
  if (toast.classList.contains('toast-success')) setTimeout(close, 12000);
}
document.querySelector('.email-form')?.addEventListener('submit', event => {
  const button = event.currentTarget.querySelector('button:not([type=button])');
  setTimeout(() => { button.disabled = true; button.textContent = 'Sending…'; });
});
// Document page action rows: remember which are open, and reopen the row an action came from.
document.querySelectorAll('details.panel').forEach(panel => {
  const key = 'bp-panel-' + panel.id;
  if (!panel.dataset.force) { try { const saved = localStorage.getItem(key); if (saved !== null) panel.open = saved === '1'; } catch {} }
  panel.addEventListener('toggle', () => { try { localStorage.setItem(key, panel.open ? '1' : '0'); } catch {} });
});
const hashTarget = location.hash.length > 1 && document.getElementById(location.hash.slice(1));
const hashPanel = hashTarget && hashTarget.closest('details.panel');
if (hashPanel) { hashPanel.open = true; hashPanel.scrollIntoView({ block: 'nearest' }); }
// Create document: type to narrow the customer list (name, email or phone; every word must match).
const customerSelect = document.getElementById('customer-select');
const customerSearch = document.getElementById('customer-search');
if (customerSelect && customerSearch) {
  const placeholder = customerSelect.options[0];
  const everyone = [...customerSelect.options].slice(1).map(option => ({ option, text: (option.textContent + ' ' + (option.dataset.info || '')).toLowerCase() }));
  const info = document.getElementById('customer-info');
  const showInfo = () => {
    const chosen = customerSelect.selectedOptions[0];
    info.textContent = chosen && chosen.value ? 'Selected: ' + chosen.textContent + (chosen.dataset.info ? ' · ' + chosen.dataset.info : '') : '';
  };
  customerSearch.hidden = false;
  customerSearch.addEventListener('input', () => {
    const words = customerSearch.value.toLowerCase().trim().split(/\s+/).filter(Boolean);
    const current = customerSelect.value;
    const matches = everyone.filter(c => words.every(word => c.text.includes(word)));
    customerSelect.replaceChildren(placeholder, ...matches.map(c => c.option));
    placeholder.textContent = !words.length ? 'Choose a customer' : matches.length ? matches.length + ' matching customer' + (matches.length > 1 ? 's' : '') + ' – choose one' : 'No customer matches – add them under Customers first';
    // Several matches: show them as an open list; otherwise a normal dropdown.
    customerSelect.size = words.length && matches.length > 1 ? Math.min(8, matches.length + 1) : 0;
    if (matches.length === 1) customerSelect.value = matches[0].option.value;
    else customerSelect.value = matches.some(c => c.option.value === current) ? current : '';
    showInfo();
  });
  // Enter moves to the list instead of submitting the form.
  customerSearch.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); customerSelect.focus(); } });
  customerSelect.addEventListener('change', showInfo);
  showInfo();
}
// Password fields: an eye button shows or hides what was typed.
const eyeOpen = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>';
const eyeShut = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.9 17.9A10.1 10.1 0 0 1 12 20C5 20 1 12 1 12a18.5 18.5 0 0 1 5.1-5.9M9.9 4.2A9.1 9.1 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.2 3.2M14.1 14.1a3 3 0 1 1-4.2-4.2"/><path d="M1 1l22 22"/></svg>';
document.querySelectorAll('input[type=password]').forEach(input => {
  const wrap = document.createElement('span');
  wrap.className = 'pw-wrap';
  input.replaceWith(wrap);
  const toggle = document.createElement('button');
  toggle.type = 'button';
  toggle.className = 'pw-toggle';
  const show = visible => {
    input.type = visible ? 'text' : 'password';
    toggle.innerHTML = visible ? eyeShut : eyeOpen;
    toggle.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
    toggle.setAttribute('aria-pressed', visible);
  };
  toggle.addEventListener('click', () => { show(input.type === 'password'); input.focus(); });
  // Hide again before sending so the browser never saves it as plain text.
  input.form?.addEventListener('submit', () => show(false));
  show(false);
  wrap.append(input, toggle);
});
// Forms or buttons that need a second thought, such as removing a user or deleting customers, ask before submitting.
document.addEventListener('submit', event => {
  const message = event.submitter?.dataset.confirm || event.target.dataset.confirm;
  if (message && !confirm(message)) event.preventDefault();
});
// Customer and document lists: the delete bar appears once something is ticked. Every delete asks first, and asks
// a second time, naming what else is lost, when it includes payments or documents.
document.querySelectorAll('form.bulk-form').forEach(form => {
  const boxes = [...form.querySelectorAll('.pick-one')];
  const all = form.querySelector('.pick-all'); const bar = form.querySelector('.bulk-bar');
  const picked = () => boxes.filter(box => box.checked);
  const update = () => {
    const n = picked().length;
    if (bar) { bar.hidden = !n; bar.querySelector('.bulk-count').textContent = n + ' selected'; }
    if (all) { all.checked = n > 0 && n === boxes.length; all.indeterminate = n > 0 && n < boxes.length; }
    boxes.forEach(box => box.closest('tr').classList.toggle('picked', box.checked));
  };
  const tick = on => { boxes.forEach(box => { box.checked = on; }); update(); };
  all?.addEventListener('change', () => tick(all.checked));
  form.querySelector('.bulk-mark')?.addEventListener('click', () => tick(true));
  form.querySelector('.bulk-clear')?.addEventListener('click', () => tick(false));
  boxes.forEach(box => box.addEventListener('change', update));
  form.addEventListener('submit', event => {
    const button = event.submitter; let what; let risks;
    if (button?.name === 'only' || button?.name === 'all') { what = button.dataset.label || button.dataset.what; risks = button.dataset.risk ? [button.dataset.risk] : []; }
    else {
      const chosen = picked();
      if (!chosen.length) { event.preventDefault(); return; }
      what = chosen.length === 1 ? chosen[0].dataset.label : chosen.length + ' ' + form.dataset.plural;
      risks = chosen.map(box => box.dataset.risk).filter(Boolean);
    }
    if (!confirm('Delete ' + what + '? This cannot be undone.')) { event.preventDefault(); return; }
    form.querySelector('input[name=confirmed]').value = '';
    if (risks.length) {
      const list = risks.slice(0, 8).join('\n• ') + (risks.length > 8 ? '\n• …and ' + (risks.length - 8) + ' more' : '');
      if (!confirm('WARNING – this also permanently deletes:\n• ' + list + '\n\nPayment records cannot be recovered. Delete anyway?')) { event.preventDefault(); return; }
      form.querySelector('input[name=confirmed]').value = '1';
    }
  });
  update();
});
