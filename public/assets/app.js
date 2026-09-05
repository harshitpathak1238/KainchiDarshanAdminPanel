const app = document.querySelector('#app');
const apiBase = location.pathname.startsWith('/admin') ? './api/' : 'api/';
const state = { csrf: '', user: null, route: 'dashboard', page: 1, perPage: 20, filter: '' };

const navGroups = [
  { label: 'Overview', items: [['dashboard', '▦', 'Dashboard'], ['orders', '≡', 'Orders']] },
  { label: 'Catalog', items: [['listings', '◇', 'Listings'], ['stays', '⌂', 'Stays'], ['rides', '↗', 'Rides'], ['rentals', '▣', 'Rentals'], ['activities', '✦', 'Activities'], ['packages', '▤', 'Packages']] },
  { label: 'Operations', items: [['customers', '♙', 'Customers'], ['partners', '◉', 'Partners'], ['pickups', '↕', 'Pickups & Vehicles'], ['payouts', '₹', 'Payouts'], ['analytics', '⌁', 'Analytics']] },
  { label: 'Content', items: [['blog', '▤', 'Blog'], ['media', '▧', 'Media Library'], ['settings', '⚙', 'Settings']] },
];
const labels = Object.fromEntries(navGroups.flatMap(group => group.items.map(item => [item[0], item[2]])));

async function api(path, options = {}) {
  const request = { ...options, headers: { 'Content-Type': 'application/json', ...(options.headers || {}) } };
  if (request.body && typeof request.body !== 'string') request.body = JSON.stringify(request.body);
  if (request.method && request.method !== 'GET') request.headers['X-CSRF-Token'] = state.csrf;
  const response = await fetch(`${apiBase}${path}`, request);
  const payload = await response.json().catch(() => ({ error: 'The server returned an invalid response.' }));
  if (!response.ok) throw new Error(payload.error || `Request failed with HTTP ${response.status}.`);
  return payload.data;
}

async function boot() {
  try {
    const auth = await api('auth/csrf');
    state.csrf = auth.csrf;
    state.user = auth.user;
    state.user ? renderShell() : renderLogin();
  } catch (error) {
    renderLogin(error.message);
  }
}

function renderLogin(error = '') {
  app.innerHTML = `<main class="login"><form class="login-card" id="login-form">
    <div class="brand"><span class="mark">K</span><span>KainchiDarshan <span class="muted">/ Admin</span></span></div>
    <h1>Welcome back</h1><p class="muted">Enter an approved admin email to continue.</p>
    ${error ? `<p class="error">${escapeHtml(error)}</p>` : ''}
    <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" autocomplete="email" required placeholder="name@example.com"></div>
    <button class="btn" style="width:100%">Continue with email</button>
    <p class="subtle" style="margin-top:18px">Access is restricted to approved admin accounts.</p>
  </form></main>`;
  document.querySelector('#login-form').onsubmit = async event => {
    event.preventDefault();
    const button = event.target.querySelector('button'); button.disabled = true;
    try { const result = await api('auth/login', { method: 'POST', body: Object.fromEntries(new FormData(event.target)) }); state.user = result.user; state.csrf = result.csrf; renderShell(); }
    catch (loginError) { renderLogin(loginError.message); }
  };
}

function renderShell() {
  app.innerHTML = `<aside class="sidebar" id="sidebar"><div class="sidebar-header"><div class="brand-stack"><div class="brand"><span class="mark">K</span><span class="brand-name">Operations</span></div><div class="brand-sub">Admin</div></div></div><nav class="nav">${navGroups.map(group => `<div class="nav-section">${group.label}</div>${group.items.map(([key, icon, label]) => `<button data-route="${key}"><span class="nav-icon">${icon}</span>${label}</button>`).join('')}`).join('')}</nav><div class="sidebar-footer"><button class="nav" style="border:0;background:transparent;color:#6d7175;width:100%;text-align:left" onclick="window.open('/', '_blank')">↗ View website</button></div></aside>
  <div class="main"><header class="topbar"><button class="mobile-menu" id="mobile-menu" aria-label="Open navigation">☰</button><div class="topbar-title"><small>Pahadi Stay</small>Admin</div><div class="account"><span class="role">${escapeHtml(state.user.name)} · ${escapeHtml(state.user.role)}</span><span class="avatar">${escapeHtml(state.user.name.slice(0, 1).toUpperCase())}</span><button class="btn secondary" id="sign-out">Sign out</button></div></header><main class="content" id="content"></main></div>`;
  document.querySelectorAll('[data-route]').forEach(button => button.onclick = () => navigate(button.dataset.route));
  document.querySelector('#mobile-menu').onclick = () => document.querySelector('#sidebar').classList.toggle('open');
  document.querySelector('#sign-out').onclick = async () => { try { await api('auth/logout', { method: 'POST' }); state.user = null; renderLogin(); } catch (error) { toast(error.message); } };
  navigate(location.hash.slice(1) || 'dashboard');
}

function navigate(route) {
  state.route = route; state.page = 1; location.hash = route;
  document.querySelectorAll('[data-route]').forEach(button => button.classList.toggle('active', button.dataset.route === route));
  document.querySelector('#sidebar')?.classList.remove('open');
  const routes = { dashboard: dashboard, settings: settings, blog: blogPage, media: mediaPage, analytics: analyticsPage };
  if (routes[route]) return routes[route]();
  if (['listings', 'stays', 'rides', 'rentals', 'activities', 'packages'].includes(route)) return listingsPage(route);
  return resourcePage(route);
}

function pageHeading(eyebrow, title, description, action = '') {
  return `<div class="page-heading"><div><p class="eyebrow">${eyebrow}</p><h1>${title}</h1><p>${description}</p></div><div class="actions">${action}<button class="btn secondary" onclick="location.reload()">↻ Refresh</button></div></div>`;
}
function skeleton(rows = 5) { return Array.from({ length: rows }, () => '<div class="skeleton-row"><div class="skeleton" style="width:42%"></div><div class="skeleton" style="width:68%"></div><div class="skeleton" style="width:26%"></div></div>').join(''); }
function panelHead(title, action = '') { return `<div class="panel-head"><h2>${title}</h2>${action}</div>`; }
function badge(value) { return `<span class="status ${String(value || '').toLowerCase()}">${escapeHtml(value || '—')}</span>`; }
function money(value) { return Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 0, maximumFractionDigits: 2 }); }
function date(value) { return value ? new Date(`${value}Z`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '—'; }
function escapeHtml(value) { return String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char])); }
function debounce(callback, wait = 300) { let timer; return (...args) => { clearTimeout(timer); timer = setTimeout(() => callback(...args), wait); }; }
function toast(message) { document.querySelector('.toast')?.remove(); document.body.insertAdjacentHTML('beforeend', `<div class="toast">${escapeHtml(message)}</div>`); setTimeout(() => document.querySelector('.toast')?.remove(), 3500); }

async function dashboard() {
  const content = document.querySelector('#content');
  content.innerHTML = pageHeading('Operations overview', 'Today at a glance', 'Monitor demand, revenue, and anything that needs an operator.', '<button class="btn" onclick="navigate(\'orders\')">View orders</button>') + `<div id="dashboard-alert"></div><div class="grid kpis" id="kpis">${skeleton(4)}</div><div class="grid dashboard-grid"><section class="panel" id="action-panel"></section><section class="panel" id="categories"></section></div><section class="panel" style="margin-top:16px" id="recent"></section>`;
  try {
    const data = await api('dashboard');
    document.querySelector('#kpis').innerHTML = [['Today\'s bookings', data.bookingsToday, 'Live count'], ['Revenue this period', `₹${money(data.revenue30)}`, 'Last 30 days'], ['Pickups to assign', data.unassignedPickups, 'Needs attention'], ['Partner applications', data.pendingPartners, 'Awaiting review']].map(([label, value, hint]) => `<article class="panel kpi"><span class="label">${label}</span><strong>${value}</strong><small>${hint}</small></article>`).join('');
    const alerts = [['Pickups to assign', data.unassignedPickups, 'pickups'], ['Partner applications', data.pendingPartners, 'partners'], ['Failed payments', data.failedPayments, 'failed payments']].filter(item => Number(item[1]) > 0);
    document.querySelector('#action-panel').innerHTML = panelHead('Action needed') + `<div class="panel-body">${alerts.length ? alerts.map(item => `<div class="notice alert" style="margin-bottom:8px">${item[1]} ${item[2]} need review.</div>`).join('') : '<div class="subtle">✓ Nothing urgent right now.</div>'}</div>`;
    document.querySelector('#categories').innerHTML = panelHead('Bookings by category') + `<div class="panel-body bars">${categoryBars(data.categoryCounts)}</div>`;
    document.querySelector('#recent').innerHTML = panelHead('Recent orders', '<button class="btn secondary" onclick="navigate(\'orders\')">View all</button>') + table(['Order', 'Guest', 'Items', 'Amount', 'Status'], (data.recentOrders || []).map(order => [`<span class="row-title">${escapeHtml(order.reference)}</span><div class="row-meta">${date(order.created_at)}</div>`, escapeHtml(order.guest), order.booking_count, `₹${money(order.amount)}`, badge(order.status)]));
    if (data.degraded?.length) document.querySelector('#dashboard-alert').innerHTML = `<div class="notice">Some live panels need attention: ${data.degraded.join(', ')}. Check the server log for the exact query error.</div>`;
  } catch (error) { document.querySelector('#dashboard-alert').innerHTML = `<div class="notice">Dashboard request failed: ${escapeHtml(error.message)}</div>`; }
}
function categoryBars(items = []) { if (!items.length) return '<div class="empty">No bookings in the selected period.</div>'; const max = Math.max(...items.map(item => Number(item.count)), 1); return items.map(item => `<div class="bar-row"><span>${escapeHtml(item.category)}</span><span class="bar"><i style="width:${Math.round(Number(item.count) / max * 100)}%"></i></span><b>${item.count}</b></div>`).join(''); }
function table(headers, rows, empty = 'No records found.') { if (!rows.length) return `<div class="empty">${empty}</div>`; return `<div class="table-wrap"><table><thead><tr>${headers.map(header => `<th>${header}</th>`).join('')}</tr></thead><tbody>${rows.map(row => `<tr>${row.map(cell => `<td>${cell ?? '—'}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`; }

async function listingsPage(route) {
  const category = route === 'listings' ? '' : ({ stays: 'STAY', rides: 'RIDE', rentals: 'RENTAL', activities: 'ACTIVITY' }[route] || '');
  const content = document.querySelector('#content');
  content.innerHTML = pageHeading('Catalog', labels[route], category ? `Manage ${category.toLowerCase()} inventory and publishing state.` : 'Manage every sellable stay, ride, rental, and activity.', '<button class="btn" id="new-listing">Add listing</button>') + `<section class="panel"><div class="tabs">${[['', 'All'], ['STAY', 'Stays'], ['RIDE', 'Rides'], ['RENTAL', 'Rentals'], ['ACTIVITY', 'Activities']].map(([value, label]) => `<button class="${category === value ? 'active' : ''}" data-category="${value}">${label}</button>`).join('')}</div><div class="toolbar"><input id="resource-search" placeholder="Search listings, location, partner"><select id="resource-status"><option value="">All statuses</option><option>LIVE</option><option>DRAFT</option><option>PAUSED</option><option>PENDING_REVIEW</option></select></div><div id="resource-table">${skeleton()}</div></section>`;
  document.querySelectorAll('[data-category]').forEach(button => button.onclick = () => loadListings(button.dataset.category));
  document.querySelector('#resource-search').oninput = debounce(() => loadListings(category));
  document.querySelector('#resource-status').onchange = () => loadListings(category);
  document.querySelector('#new-listing').onclick = () => listingForm(category);
  loadListings(category);
}
async function loadListings(category = '') {
  const params = new URLSearchParams({ category, search: document.querySelector('#resource-search')?.value || '', status: document.querySelector('#resource-status')?.value || '', page: state.page, perPage: state.perPage });
  try { const result = await api(`listings?${params}`); const rows = result.map(item => [`<span class="row-title">${escapeHtml(item.title)}</span><div class="row-meta">/${escapeHtml(item.slug)}</div>`, badge(item.category), escapeHtml(item.partner_name || 'Unassigned'), escapeHtml(item.location), `₹${money(item.base_price)} → ₹${money(item.sell_price)}`, badge(item.status), `<button class="btn secondary" onclick="event.stopPropagation();listingForm('${item.category}','${item.id}')">Edit</button>`]); document.querySelector('#resource-table').innerHTML = table(['Listing', 'Category', 'Partner', 'Location', 'Price', 'Status', ''], rows); } catch (error) { document.querySelector('#resource-table').innerHTML = `<div class="empty">Listings request failed: ${escapeHtml(error.message)}</div>`; }
}
function listingForm(category = '', id = '') {
  openDrawer(`<div class="drawer-head"><div><p class="eyebrow">Catalog</p><h2>${id ? 'Edit listing' : 'Add listing'}</h2></div><button class="btn secondary" onclick="closeDrawer()">Close</button></div><form id="listing-form"><div class="form-grid"><div class="field span-2"><label>Title</label><input name="title" required></div><div class="field"><label>Category</label><select name="category"><option>STAY</option><option>RIDE</option><option>RENTAL</option><option>ACTIVITY</option></select></div><div class="field"><label>Location</label><input name="location" required></div><div class="field"><label>Base price (INR)</label><input name="base_price" type="number" min="0" step="0.01" value="0"></div><div class="field"><label>Selling price (INR)</label><input name="sell_price" type="number" min="0" step="0.01" value="0"></div><div class="field"><label>Status</label><select name="status"><option>DRAFT</option><option>LIVE</option><option>PAUSED</option><option>PENDING_REVIEW</option></select></div><div class="field span-2"><label>Description</label><textarea name="description" rows="6"></textarea></div></div><div id="form-save-bar" class="save-bar hidden"><span>Unsaved changes</span><div class="actions"><button type="button" class="btn secondary" onclick="closeDrawer()">Discard</button><button class="btn">Save listing</button></div></div></form></div>`);
  const form = document.querySelector('#listing-form'); form.querySelector('[name=category]').value = category || 'STAY'; form.addEventListener('input', () => document.querySelector('#form-save-bar').classList.remove('hidden')); form.onsubmit = async event => { event.preventDefault(); try { await api(`listings${id ? `/${id}` : ''}`, { method: id ? 'PATCH' : 'POST', body: Object.fromEntries(new FormData(form)) }); closeDrawer(); toast('Listing saved successfully.'); listingsPage(state.route); } catch (error) { toast(`Listing save failed: ${error.message}`); } };
}

async function resourcePage(resource) {
  const content = document.querySelector('#content');
  content.innerHTML = pageHeading('Operations', labels[resource] || resource, resource === 'orders' ? 'Search and review trips, guests, and payment state.' : `Manage ${String(labels[resource] || resource).toLowerCase()} records.`) + `<section class="panel"><div class="toolbar"><input id="resource-search" placeholder="Search ${String(labels[resource] || resource).toLowerCase()}"><button class="btn secondary" id="export">Export CSV</button></div><div id="resource-table">${skeleton()}</div></section>`;
  document.querySelector('#resource-search').oninput = debounce(() => loadResource(resource)); document.querySelector('#export').onclick = () => toast('CSV export is available after the filtered results load.'); loadResource(resource);
}
async function loadResource(resource) {
  try { const result = await api(`${resource}?search=${encodeURIComponent(document.querySelector('#resource-search')?.value || '')}&page=${state.page}&perPage=${state.perPage}`); const normalized = Array.isArray(result) ? result : []; let headers = ['Name', 'Status', 'Created'], rows = [];
    if (resource === 'orders') { headers = ['Order', 'Guest', 'Items', 'Amount', 'Status', 'Created']; rows = normalized.map(item => [`<span class="row-title">${escapeHtml(item.reference)}</span>`, escapeHtml(item.guest), item.booking_count, `₹${money(item.amount)}`, badge(item.status), date(item.created_at)]); }
    else if (resource === 'customers' || resource === 'users') { headers = ['Name', 'Email', 'Role', 'Phone', 'Created']; rows = normalized.map(item => [`<span class="row-title">${escapeHtml(item.name || 'Unnamed')}</span>`, escapeHtml(item.email || '—'), badge(item.role), escapeHtml(item.phone || '—'), date(item.createdAt)]); }
    else if (resource === 'partners') { headers = ['Business', 'Category', 'Verification', 'User ID']; rows = normalized.map(item => [`<span class="row-title">${escapeHtml(item.businessName)}</span>`, badge(item.category), badge(item.verificationStatus), escapeHtml(item.userId)]); }
    else if (resource === 'vehicles') { headers = ['Type', 'Registration', 'Driver', 'Phone', 'Active']; rows = normalized.map(item => [escapeHtml(item.type), escapeHtml(item.registrationNumber), escapeHtml(item.driverName), escapeHtml(item.driverPhone), item.isActive ? badge('ACTIVE') : badge('INACTIVE')]); }
    else if (resource === 'pickups') { headers = ['Booking', 'Pickup', 'Drop-off', 'Requested', 'Status']; rows = normalized.map(item => [escapeHtml(item.bookingId), escapeHtml(item.pickupLocationText), escapeHtml(item.dropoffLocationText), date(item.requestedTime), badge(item.status)]); }
    else if (resource === 'packages') { headers = ['Package', 'Price', 'Created']; rows = normalized.map(item => [`<span class="row-title">${escapeHtml(item.title)}</span>`, `₹${money(item.price)}`, date(item.createdAt)]); }
    document.querySelector('#resource-table').innerHTML = table(headers, rows); }
  catch (error) { document.querySelector('#resource-table').innerHTML = `<div class="empty">${escapeHtml(labels[resource] || resource)} request failed: ${escapeHtml(error.message)}</div>`; }
}

async function blogPage() {
  const content = document.querySelector('#content');
  const toolbar = `
    <div class="blog-toolbar">
      <div class="blog-toolbar-actions">
        <button class="btn btn-primary" onclick="blogForm()">Add new</button>
      </div>
      <div class="blog-toolbar-filters">
        <input id="resource-search" placeholder="Search posts">
        <select id="blog-status">
          <option value="">All statuses</option>
          <option>DRAFT</option>
          <option>SCHEDULED</option>
          <option>PUBLISHED</option>
          <option>ARCHIVED</option>
        </select>
      </div>
    </div>`;
  content.innerHTML = pageHeading('Content', 'Blog', 'Manage stories, search previews, and scheduled publishing.') + `<section class="panel blog-list-panel">${toolbar}<div id="resource-table">${skeleton()}</div></section>`;
  document.querySelector('#resource-search').oninput = debounce(loadBlog);
  document.querySelector('#blog-status').onchange = loadBlog;
  loadBlog();
}

async function loadBlog() {
  try {
    const search = document.querySelector('#resource-search')?.value || '';
    const status = document.querySelector('#blog-status')?.value || '';
    const posts = await api(`blog?search=${encodeURIComponent(search)}`);
    const filtered = (Array.isArray(posts) ? posts : []).filter(post => !status || (post.status || '') === status);
    filtered.sort((a, b) => new Date(b.publishedAt || b.scheduledAt || b.createdAt || 0) - new Date(a.publishedAt || a.scheduledAt || a.createdAt || 0));

    document.querySelector('#resource-table').innerHTML = `
      <div class="blog-table-header">
        <div class="blog-col post-col">POST</div>
        <div class="blog-col author-col">AUTHOR</div>
        <div class="blog-col status-col">STATUS</div>
        <div class="blog-col date-col">PUBLISH DATE</div>
        <div class="blog-col action-col"></div>
      </div>
      ${filtered.length ? filtered.map(post => `
        <div class="blog-row">
          <div class="blog-col post-col">
            <div class="blog-post-main">
              <div class="blog-post-icon">✎</div>
              <div class="blog-post-copy">
                <div class="blog-title">${escapeHtml(post.title)}</div>
                <div class="blog-slug">/${escapeHtml(post.slug)}</div>
              </div>
            </div>
          </div>
          <div class="blog-col author-col"><span class="blog-author-pill">${escapeHtml(post.authorName || 'Admin')}</span></div>
          <div class="blog-col status-col">${badge(post.status)}</div>
          <div class="blog-col date-col">${date(post.publishedAt || post.scheduledAt || post.createdAt)}</div>
          <div class="blog-col action-col">
            <button class="icon-btn" title="Edit post" onclick="blogForm('${post.id}')">✎</button>
            <button class="icon-btn danger" title="Delete post" onclick="deleteBlogPost('${post.id}')">🗑</button>
          </div>
        </div>
      `).join('') : '<div class="empty">No blog posts found.</div>'}`;
  } catch (error) {
    document.querySelector('#resource-table').innerHTML = `<div class="empty">Blog request failed: ${escapeHtml(error.message)}</div>`;
  }
}

async function deleteBlogPost(id) {
  if (!id || !confirm('Delete this blog post?')) return;
  try {
    await api(`blog/${id}`, { method: 'DELETE' });
    toast('Blog post deleted successfully.');
    loadBlog();
  } catch (error) {
    toast(`Delete failed: ${error.message}`);
  }
}

function videoEmbedUrl(value) {
  try {
    const url = new URL(value);
    if (url.hostname === 'youtu.be') return `https://www.youtube.com/embed/${url.pathname.slice(1).split('/')[0]}`;
    if (url.hostname.endsWith('youtube.com')) return url.searchParams.get('v') ? `https://www.youtube.com/embed/${url.searchParams.get('v')}` : url.href;
    if (url.hostname === 'vimeo.com') return `https://player.vimeo.com/video/${url.pathname.slice(1).split('/')[0]}`;
    if (url.hostname === 'player.vimeo.com') return url.href;
  } catch (error) {}
  return '';
}

function updateEditorSize(editor) {
  const warning = editor.wrapper.querySelector('.content-size-warning');
  const size = new Blob([editor.source.value]).size;
  warning.textContent = size > 64 * 1024 ? `This field is ${Math.ceil(size / 1024)} KB. Large HTML may slow the editor.` : `${Math.ceil(size / 1024)} KB of HTML`;
  warning.classList.toggle('warning', size > 64 * 1024);
}

function insertRichHtml(editor, html) {
  editor.visual.focus();
  const selection = window.getSelection();
  const range = editor.savedRange || (selection?.rangeCount ? selection.getRangeAt(0) : null);
  if (range && editor.visual.contains(range.commonAncestorContainer)) {
    selection.removeAllRanges(); selection.addRange(range);
    document.execCommand('insertHTML', false, html);
  } else {
    editor.visual.insertAdjacentHTML('beforeend', html);
  }
  editor.source.value = editor.visual.innerHTML;
  updateEditorSize(editor);
}

async function openMediaPicker(onSelect) {
  const existing = document.querySelector('#media-picker');
  existing?.remove();
  document.body.insertAdjacentHTML('beforeend', `<div class="drawer-backdrop" id="media-picker-backdrop"></div><section class="media-picker panel" id="media-picker"><div class="drawer-head"><div><p class="eyebrow">Content</p><h2>Choose media</h2></div><button class="btn secondary" type="button" id="close-media-picker">Close</button></div><div class="media-picker-actions"><button class="btn" type="button" id="media-picker-upload">Upload new</button><input type="file" id="media-picker-input" class="hidden" accept="image/jpeg,image/png,image/webp"><span class="subtle">Choose an existing image or upload one.</span></div><div class="media-picker-grid" id="media-picker-grid">${skeleton(3)}</div></section>`);
  const close = () => { document.querySelector('#media-picker-backdrop')?.remove(); document.querySelector('#media-picker')?.remove(); };
  document.querySelector('#close-media-picker').onclick = close;
  document.querySelector('#media-picker-backdrop').onclick = close;
  document.querySelector('#media-picker-upload').onclick = () => document.querySelector('#media-picker-input').click();
  document.querySelector('#media-picker-input').onchange = async event => {
    const file = event.target.files?.[0];
    if (!file) return;
    try {
      const formData = new FormData(); formData.append('file', file);
      const response = await fetch(`${apiBase}media`, { method: 'POST', headers: { 'X-CSRF-Token': state.csrf }, body: formData });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(payload.error || 'Upload failed.');
      onSelect(payload.data || payload); close();
    } catch (error) { toast(`Image upload failed: ${error.message}`); }
  };
  try {
    const assets = await api('media');
    document.querySelector('#media-picker-grid').innerHTML = assets.filter(asset => asset.mime_type?.startsWith('image/')).map(asset => `<button type="button" class="media-picker-item" data-url="${escapeHtml(asset.public_url)}" data-name="${escapeHtml(asset.filename)}"><img src="${escapeHtml(asset.public_url)}" alt="${escapeHtml(asset.alt_text || asset.filename)}"><span>${escapeHtml(asset.filename)}</span></button>`).join('') || '<div class="empty">No images in the library yet.</div>';
    document.querySelectorAll('.media-picker-item').forEach(button => button.onclick = () => { onSelect({ public_url: button.dataset.url, filename: button.dataset.name }); close(); });
  } catch (error) { document.querySelector('#media-picker-grid').innerHTML = `<div class="empty">Media request failed: ${escapeHtml(error.message)}</div>`; }
}

function initializeRichEditor(wrapper) {
  const editor = { wrapper, visual: wrapper.querySelector('[contenteditable]'), source: wrapper.querySelector('textarea'), savedRange: null, sourceMode: false };
  wrapper._richEditor = editor;
  const rememberRange = () => { const selection = window.getSelection(); if (selection?.rangeCount && editor.visual.contains(selection.anchorNode)) editor.savedRange = selection.getRangeAt(0).cloneRange(); };
  const syncSource = () => { editor.source.value = editor.visual.innerHTML; updateEditorSize(editor); };
  const runCommand = async command => {
    rememberRange(); editor.visual.focus();
    if (command === 'link') { const url = prompt('Link URL'); if (url) document.execCommand('createLink', false, url); }
    else if (command === 'image') await openMediaPicker(asset => { const alt = prompt('Alt text for this image', asset.filename || '') ?? ''; insertRichHtml(editor, `<img class="responsive-image" src="${escapeHtml(asset.public_url)}" alt="${escapeHtml(alt)}">`); });
    else if (command === 'video') { const url = videoEmbedUrl(prompt('YouTube or Vimeo URL') || ''); if (url) insertRichHtml(editor, `<div class="video-embed"><iframe src="${escapeHtml(url)}" title="Embedded video" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>`); else toast('Enter a valid YouTube or Vimeo URL.'); }
    else if (command === 'table') insertRichHtml(editor, '<table><thead><tr><th>Heading</th><th>Heading</th></tr></thead><tbody><tr><td>Cell</td><td>Cell</td></tr></tbody></table><p><br></p>');
    else if (command === 'h2' || command === 'h3') document.execCommand('formatBlock', false, command.toUpperCase());
    else if (command === 'blockquote') document.execCommand('formatBlock', false, 'BLOCKQUOTE');
    else document.execCommand(command, false, null);
    syncSource();
  };
  editor.visual.addEventListener('input', syncSource);
  editor.visual.addEventListener('keyup', rememberRange);
  editor.visual.addEventListener('mouseup', rememberRange);
  editor.source.addEventListener('input', () => updateEditorSize(editor));
  wrapper.querySelectorAll('[data-editor-command]').forEach(button => { button.addEventListener('mousedown', event => event.preventDefault()); button.onclick = () => runCommand(button.dataset.editorCommand); });
  wrapper.querySelector('[data-editor-toggle]').onclick = () => {
    editor.sourceMode = !editor.sourceMode;
    if (editor.sourceMode) syncSource(); else editor.visual.innerHTML = editor.source.value;
    editor.visual.classList.toggle('hidden', editor.sourceMode); editor.source.classList.toggle('hidden', !editor.sourceMode);
    wrapper.querySelector('[data-editor-toggle]').textContent = editor.sourceMode ? 'Visual editor' : 'Edit code';
  };
  syncSource();
  return editor;
}

function setRichEditorValue(id, value) {
  const wrapper = document.querySelector(`#${id}-editor`); if (!wrapper?._richEditor) return;
  const editor = wrapper._richEditor; editor.source.value = value || ''; editor.visual.innerHTML = value || ''; updateEditorSize(editor);
}

async function blogForm(id = '') {
  const content = document.querySelector('#content');
  const editorMarkup = (id, name, label, small = false) => `<div class="rich-editor" id="${id}-editor"><div class="rich-toolbar"><button type="button" data-editor-command="bold"><strong>B</strong></button><button type="button" data-editor-command="italic"><em>I</em></button><button type="button" data-editor-command="h2">H2</button><button type="button" data-editor-command="h3">H3</button><button type="button" data-editor-command="insertUnorderedList">• List</button><button type="button" data-editor-command="insertOrderedList">1. List</button><button type="button" data-editor-command="blockquote">Quote</button><button type="button" data-editor-command="link">Link</button>${small ? '' : '<button type="button" data-editor-command="table">Table</button><button type="button" data-editor-command="image">Image</button><button type="button" data-editor-command="video">Video</button>'}<button type="button" class="source-toggle" data-editor-toggle>Edit code</button></div><div class="rich-canvas" contenteditable="true" role="textbox" aria-label="${label}"></div><textarea name="${name}" class="rich-source blog-editor-input hidden" rows="${small ? 5 : 18}" aria-label="${label} HTML"></textarea><div class="content-size-warning">0 KB of HTML</div></div>`;
  content.innerHTML = `<div class="detail-back"><button onclick="blogPage()">← Posts</button> / Blog editor</div><div class="page-heading"><div><p class="eyebrow">Content</p><h1>${id ? 'Edit post' : 'New post'}</h1></div><div class="actions"><button class="btn secondary" id="blog-preview-btn" type="button">Preview</button><button class="btn" id="blog-save-btn" type="button">Save</button></div></div><div id="blog-save-banner" class="blog-success-banner hidden">Blog saved.</div><form id="blog-editor" class="blog-editor-layout"><div class="blog-main panel"><div class="field blog-field"><label>Title</label><input name="title" class="blog-text-input" required placeholder="Give your story a clear title"></div><div class="field blog-field"><label>URL slug / handle</label><input name="slug" class="blog-text-input" placeholder="your-post-slug"></div><div class="field blog-field"><label>Body</label>${editorMarkup('blog-body', 'body', 'Post body')}</div><div class="field blog-field"><label>Excerpt / summary</label>${editorMarkup('blog-excerpt', 'excerpt', 'Post excerpt', true)}</div></div><aside class="blog-side-panel panel"><div class="field blog-field"><label>Category</label><input name="category" class="blog-text-input" placeholder="Travel, Guide, Experience..."></div><div class="field blog-field"><label>Primary keyword</label><input name="primaryKeyword" class="blog-text-input" placeholder="Primary keyword"></div><div class="field blog-field"><label>Tags</label><input name="tags" class="blog-text-input" placeholder="Comma separated"></div><div class="field blog-field"><label>SEO title</label><input name="metaTitle" class="blog-text-input" placeholder="SEO title"></div><div class="field blog-field"><label>SEO description</label><textarea name="metaDescription" rows="3" class="blog-editor-input blog-summary-input" placeholder="Short meta description"></textarea></div><div class="field blog-field"><label>Author</label><select name="authorId" class="blog-select"><option value="">Loading staff accounts...</option></select></div><div class="field blog-field"><label>Status</label><select name="status" class="blog-select"><option value="DRAFT">Draft</option><option value="SCHEDULED">Scheduled</option><option value="PUBLISHED">Published</option><option value="ARCHIVED">Archived</option></select></div><div class="field blog-field"><label>Scheduled publish</label><input name="scheduledAt" class="blog-text-input" type="datetime-local"></div><div class="field blog-field"><label>Featured image</label><div class="image-upload-box"><div class="image-upload-preview" id="featured-image-preview">No image selected</div><div class="featured-upload-actions"><button type="button" class="btn secondary" id="featured-image-trigger">Upload image</button><input type="hidden" name="featuredImage" value=""><input type="file" id="featured-image-input" accept="image/*" class="hidden"><span class="subtle" id="featured-image-name">No file chosen</span></div></div></div><div class="field blog-field"><label>Featured image alt text</label><input name="imageAltText" class="blog-text-input" placeholder="Describe the feature image"></div></aside></form>`;

  const editor = document.querySelector('#blog-editor');
  const bodyEditor = initializeRichEditor(document.querySelector('#blog-body-editor'));
  initializeRichEditor(document.querySelector('#blog-excerpt-editor'));
  const saveBanner = document.querySelector('#blog-save-banner');

  editor.addEventListener('input', () => {
    saveBanner.classList.add('hidden');
  });

  document.querySelectorAll('#blog-editor input, #blog-editor select, #blog-editor textarea').forEach(input => input.addEventListener('input', () => saveBanner.classList.add('hidden')));

  try {
    const authors = await api('blog-authors');
    const authorSelect = document.querySelector('[name="authorId"]');
    authorSelect.innerHTML = '<option value="">Select staff account</option>' + authors.map(author => `<option value="${escapeHtml(author.id)}">${escapeHtml(author.name)} · ${escapeHtml(author.role)}</option>`).join('');
    if (state.user?.id) authorSelect.value = state.user.id;
  } catch (error) { toast(`Unable to load staff accounts: ${error.message}`); }

  document.querySelector('#featured-image-trigger').addEventListener('click', () => {
    document.querySelector('#featured-image-input').click();
  });

  document.querySelector('#featured-image-input').addEventListener('change', async event => {
    const file = event.target.files?.[0];
    if (!file) return;
    try {
      const formData = new FormData();
      formData.append('file', file);
      const response = await fetch(`${apiBase}media`, {
        method: 'POST',
        headers: { 'X-CSRF-Token': state.csrf },
        body: formData,
      });
      const payload = await response.json().catch(() => ({ error: 'Upload failed.' }));
      if (!response.ok) throw new Error(payload.error || 'Upload failed.');
      const url = payload.data?.public_url || payload.public_url || '';
      document.querySelector('input[name="featuredImage"]').value = url;
      document.querySelector('#featured-image-name').textContent = file.name;
      const preview = document.querySelector('#featured-image-preview');
      preview.innerHTML = url ? `<img src="${escapeHtml(url)}" alt="${escapeHtml(file.name)}">` : 'No image selected';
      toast('Featured image uploaded successfully.');
    } catch (error) {
      toast(`Image upload failed: ${error.message}`);
    }
  });

  const savePost = async () => {
    if (!editor.reportValidity()) return;
    const formData = new FormData(editor);
    const data = Object.fromEntries(formData.entries());
    data.tags = (data.tags || '').split(',').map(item => item.trim()).filter(Boolean);
    if (!data.status) data.status = 'DRAFT';
    try {
      await api(`blog${id ? `/${id}` : ''}`, { method: id ? 'PATCH' : 'POST', body: data });
      saveBanner.classList.remove('hidden');
      toast('Blog post saved successfully.');
      if (!id) blogPage();
    } catch (error) {
      toast(`Blog post save failed: ${error.message}`);
    }
  };

  document.querySelector('#blog-save-btn').addEventListener('click', savePost);
  document.querySelector('#blog-preview-btn').addEventListener('click', () => {
    const previewWindow = window.open('', '_blank', 'noopener,noreferrer');
    const html = `<!doctype html><html><head><title>${escapeHtml(document.querySelector('input[name="title"]').value || 'Untitled post')}</title><style>body{font-family:Arial,sans-serif;max-width:760px;margin:40px auto;padding:0 20px;color:#1a1a1a;line-height:1.7}h1,h2{font-weight:700}img{max-width:100%;height:auto;border-radius:10px}p{margin:16px 0}table{border-collapse:collapse;width:100%}td,th{border:1px solid #ddd;padding:8px}</style></head><body>${bodyEditor.source.value}</body></html>`;
    previewWindow.document.write(html);
    previewWindow.document.close();
  });

  if (id) {
    try {
      const posts = await api('blog');
      const post = (Array.isArray(posts) ? posts : []).find(item => item.id === id);
      if (!post) return;
      const form = document.querySelector('#blog-editor');
      form.querySelector('[name="title"]').value = post.title || '';
      form.querySelector('[name="slug"]').value = post.slug || '';
      setRichEditorValue('blog-body', post.body || '');
      setRichEditorValue('blog-excerpt', post.excerpt || '');
      form.querySelector('[name="category"]').value = post.category || '';
      form.querySelector('[name="primaryKeyword"]').value = post.primaryKeyword || '';
      form.querySelector('[name="tags"]').value = Array.isArray(post.tags) ? post.tags.join(', ') : (post.tags || '');
      form.querySelector('[name="metaTitle"]').value = post.metaTitle || '';
      form.querySelector('[name="metaDescription"]').value = post.metaDescription || '';
      form.querySelector('[name="authorId"]').value = post.authorId || '';
      form.querySelector('[name="status"]').value = post.status || 'DRAFT';
      form.querySelector('[name="scheduledAt"]').value = post.scheduledAt ? post.scheduledAt.replace(' ', 'T').slice(0, 16) : '';
      form.querySelector('[name="featuredImage"]').value = post.featuredImage || '';
      form.querySelector('[name="imageAltText"]').value = post.imageAltText || '';
      const preview = document.querySelector('#featured-image-preview');
      if (post.featuredImage) {
        document.querySelector('#featured-image-name').textContent = 'Image assigned';
        preview.innerHTML = `<img src="${escapeHtml(post.featuredImage)}" alt="${escapeHtml(post.imageAltText || post.title || 'Featured image')}">`;
      } else {
        preview.textContent = 'No image selected';
      }
    } catch (error) {
      toast(`Unable to load blog post: ${error.message}`);
    }
  }
}

async function mediaPage() { const content = document.querySelector('#content'); content.innerHTML = pageHeading('Content', 'Media Library', 'One home for reusable images and videos.', '<button class="btn" onclick="document.querySelector(\'#media-input\').click()">↥ Upload files</button>') + `<section class="panel"><div class="dropzone" id="dropzone"><strong>Drop images or videos here</strong><br><span class="subtle">JPG, PNG, WebP up to 10 MB · MP4 and MOV up to 100 MB</span><input id="media-input" class="hidden" type="file" multiple accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime"></div><div id="upload-queue"></div><div class="toolbar"><input id="resource-search" placeholder="Search files"><select><option>All types</option><option>Images</option><option>Videos</option></select><select><option>Newest</option><option>Oldest</option><option>Name A-Z</option><option>Largest</option></select></div><div id="media-grid" class="media-grid">${skeleton(4)}</div></section>`; const input = document.querySelector('#media-input'); input.onchange = event => uploadFiles(event.target.files); const dropzone = document.querySelector('#dropzone'); dropzone.ondragover = event => { event.preventDefault(); dropzone.classList.add('dragging'); }; dropzone.ondragleave = () => dropzone.classList.remove('dragging'); dropzone.ondrop = event => { event.preventDefault(); dropzone.classList.remove('dragging'); uploadFiles(event.dataTransfer.files); }; loadMedia(); }
async function loadMedia() { try { const assets = await api('media'); document.querySelector('#media-grid').innerHTML = assets.length ? assets.map(asset => `<article class="media-card"><div class="media-thumb">${asset.mime_type?.startsWith('image/') ? `<img src="${escapeHtml(asset.public_url)}" alt="${escapeHtml(asset.alt_text || asset.filename)}">` : 'VIDEO'}</div><div class="media-meta"><strong title="${escapeHtml(asset.filename)}">${escapeHtml(asset.filename)}</strong><small>${Math.round((asset.size_bytes || 0) / 1024)} KB · Used ${asset.usage_count || 0} times</small></div></article>`).join('') : '<div class="empty" style="grid-column:1/-1">No media assets uploaded yet.</div>'; } catch (error) { document.querySelector('#media-grid').innerHTML = `<div class="empty" style="grid-column:1/-1">Media request failed: ${escapeHtml(error.message)}</div>`; } }
function uploadFiles(files) { Array.from(files).forEach(file => { const isImage = ['image/jpeg', 'image/png', 'image/webp'].includes(file.type); const isVideo = ['video/mp4', 'video/quicktime'].includes(file.type); const limit = isVideo ? 100 * 1024 * 1024 : 10 * 1024 * 1024; if ((!isImage && !isVideo) || file.size > limit) { toast(`${file.name}: unsupported type or file is over the size limit.`); return; } const queue = document.querySelector('#upload-queue'); const item = document.createElement('div'); item.className = 'panel-body'; item.innerHTML = `<strong>${escapeHtml(file.name)}</strong><div class="progress"><i style="width:0%"></i></div><small class="subtle">Starting upload…</small>`; queue.appendChild(item); const xhr = new XMLHttpRequest(); xhr.open('POST', `${apiBase}media`); xhr.setRequestHeader('X-CSRF-Token', state.csrf); xhr.upload.onprogress = event => { if (!event.lengthComputable) return; const percent = Math.round(event.loaded / event.total * 100); item.querySelector('i').style.width = `${percent}%`; item.querySelector('small').textContent = `Uploading… ${percent}%`; }; xhr.onload = () => { let result = {}; try { result = JSON.parse(xhr.responseText); } catch (error) {} if (xhr.status >= 200 && xhr.status < 300) { item.querySelector('small').textContent = 'Uploaded successfully'; loadMedia(); } else { item.querySelector('small').textContent = result.error || 'Upload failed. Check storage permissions.'; item.querySelector('small').style.color = 'var(--danger)'; } }; xhr.onerror = () => { item.querySelector('small').textContent = 'Upload failed: network error.'; item.querySelector('small').style.color = 'var(--danger)'; }; const body = new FormData(); body.append('file', file); xhr.send(body); }); }

async function settings() { const content = document.querySelector('#content'); content.innerHTML = pageHeading('Configuration', 'Settings', 'Business rules and operational defaults persisted for the admin team.') + `<section class="panel"><div class="panel-body" id="settings-form">${skeleton(4)}</div></section>`; try { const values = Object.fromEntries((await api('settings')).map(item => [item.key, item.typed_value])); document.querySelector('#settings-form').innerHTML = `<form id="settings-editor"><div class="form-grid"><div class="field"><label>Business name</label><input name="business_name" value="${escapeHtml(values.business_name || 'Pahadi Stay')}"></div><div class="field"><label>Default commission (%)</label><input name="commission_percent" type="number" min="0" max="100" value="${escapeHtml(values.commission_percent || '12')}"></div><div class="field"><label>Timezone</label><input name="timezone" value="${escapeHtml(values.timezone || 'Asia/Kolkata')}"></div><div class="field"><label>Currency</label><input name="currency" value="${escapeHtml(values.currency || 'INR')}"></div></div><div class="save-bar hidden" id="settings-save"><span>Unsaved changes</span><button class="btn">Save settings</button></div></form>`; const form = document.querySelector('#settings-editor'); form.oninput = () => document.querySelector('#settings-save').classList.remove('hidden'); form.onsubmit = async event => { event.preventDefault(); const data = Object.fromEntries(new FormData(form)); if (+data.commission_percent < 0 || +data.commission_percent > 100) return toast('Commission must be between 0 and 100.'); try { await api('settings', { method: 'PATCH', body: data }); toast('Settings saved successfully.'); document.querySelector('#settings-save').classList.add('hidden'); } catch (error) { toast(`Settings save failed: ${error.message}`); } }; } catch (error) { document.querySelector('#settings-form').innerHTML = `<div class="empty">Settings request failed: ${escapeHtml(error.message)}</div>`; } }
function analyticsPage() { document.querySelector('#content').innerHTML = pageHeading('Operations', 'Analytics', 'Compare revenue, category mix, and booking performance from live data.') + `<div class="grid dashboard-grid"><section class="panel">${panelHead('Revenue over time', '<button class="btn secondary">Last 30 days</button>')}<div class="chart"><div class="empty">Chart data will appear as booking history grows.</div></div></section><section class="panel">${panelHead('Booking funnel')}<div class="panel-body bars"><div class="bar-row"><span>Created</span><span class="bar"><i style="width:100%"></i></span><b>—</b></div><div class="bar-row"><span>Paid</span><span class="bar"><i style="width:70%"></i></span><b>—</b></div><div class="bar-row"><span>Completed</span><span class="bar"><i style="width:42%"></i></span><b>—</b></div></div></section></div>`; }
function openDrawer(content) { document.body.insertAdjacentHTML('beforeend', `<div class="drawer-backdrop" onclick="closeDrawer()"></div><aside class="drawer">${content}</aside>`); }
function closeDrawer() { document.querySelector('.drawer-backdrop')?.remove(); document.querySelector('.drawer')?.remove(); }
window.navigate = navigate; window.listingForm = listingForm; window.blogForm = blogForm; window.blogPage = blogPage; window.closeDrawer = closeDrawer; window.deleteBlogPost = deleteBlogPost;
boot();
