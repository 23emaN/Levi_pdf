const HIGHLIGHT_TABLES = ['detailed-table', 'asn-table', 'dash-table', 'credit-note-table', 'credit-note-asn-table', 'credit-note-dash-table', 'vbrp-table'];
const HIGHLIGHT_PALETTE = [
    { name: 'เหลือง', color: '#fff59d' },
    { name: 'ส้ม', color: '#ffd8a8' },
    { name: 'แดง', color: '#ffc9c9' },
    { name: 'ชมพู', color: '#fcc2d7' },
    { name: 'ม่วง', color: '#d0bfff' },
    { name: 'ฟ้า', color: '#a5d8ff' },
    { name: 'เขียว', color: '#b2f2bb' },
    { name: 'เทา', color: '#dee2e6' }
];
const HIGHLIGHT_HEX = /^#[0-9a-f]{6}$/i;

let columnHighlights = loadColumnHighlights();
let highlightTarget = null;
let highlightMenuEl = null;

function highlightStorageKey() {
    return 'column_highlight_job_' + CURRENT_JOB_ID;
}

// { table id: { data-col of the header: '#rrggbb' } }; the stored value goes into a style rule, so only colours pass
function sanitizeColumnHighlights(raw) {
    const isMap = value => value !== null && typeof value === 'object' && !Array.isArray(value);
    const clean = {};
    HIGHLIGHT_TABLES.forEach(tableId => {
        clean[tableId] = {};
        const saved = isMap(raw) ? raw[tableId] : null;
        if (!isMap(saved)) return;
        Object.keys(saved).forEach(col => {
            if (typeof saved[col] === 'string' && HIGHLIGHT_HEX.test(saved[col])) {
                clean[tableId][col] = saved[col].toLowerCase();
            }
        });
    });
    return clean;
}

function loadColumnHighlights() {
    try {
        return sanitizeColumnHighlights(JSON.parse(localStorage.getItem(highlightStorageKey())));
    } catch (e) {
        return sanitizeColumnHighlights(null);
    }
}

function saveColumnHighlights() {
    try {
        localStorage.setItem(highlightStorageKey(), JSON.stringify(columnHighlights));
    } catch (e) {}
}

function highlightHeaderKeys(tableId) {
    return Array.from(document.querySelectorAll(`#${tableId} thead th`)).map(th => th.dataset.col);
}

function isDarkHighlight(color) {
    const [r, g, b] = [1, 3, 5].map(i => parseInt(color.slice(i, i + 2), 16) / 255)
        .map(c => (c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4)));
    return 0.2126 * r + 0.7152 * g + 0.0722 * b <= 0.179;
}

// The column is painted by position, so rows drawn later (chunked render, sort, filter) come out coloured with no
// per-row work. Bootstrap's .table rules out-rank the plain header rules in app.css, so the header colour is set
// here with !important; its darker bottom edge keeps it reading as a header next to the cells. Rows that span the
// whole table (colspan) stay plain. On a dark colour the text turns white (the status badges keep their own colours).
function columnHighlightCss(tableId, headerKeys, colors) {
    const hover = 'background-image: linear-gradient(rgba(15, 23, 42, 0.07), rgba(15, 23, 42, 0.07));';
    return headerKeys.map((key, index) => {
        const color = colors[key];
        if (!color) return '';
        const n = index + 1;
        const head = `#${tableId} thead th:nth-child(${n})`;
        const cell = `#${tableId} tbody td:nth-child(${n}):not([colspan])`;
        const dark = isDarkHighlight(color);
        return `${head} { background-color: ${color} !important; box-shadow: inset 0 -3px 0 rgba(15, 23, 42, 0.22);${dark ? ' color: #ffffff !important;' : ''} }\n`
            + `${head}:hover { ${hover} }\n`
            + (dark ? `${head} .sort-icon { color: #ffffff; }\n${cell}, ${cell} :not(.pill-badge, .pill-badge *) { color: #ffffff !important; }\n` : '')
            + `${cell} { background-color: ${color} !important; }\n`
            + `#${tableId} tbody tr:hover td:nth-child(${n}):not([colspan]) { ${hover} }\n`;
    }).join('');
}

function applyColumnHighlights() {
    let style = document.getElementById('column-highlight-style');
    if (!style) {
        style = document.createElement('style');
        style.id = 'column-highlight-style';
        document.head.appendChild(style);
    }
    style.textContent = HIGHLIGHT_TABLES
        .map(tableId => columnHighlightCss(tableId, highlightHeaderKeys(tableId), columnHighlights[tableId]))
        .join('');
}

function setColumnHighlight(tableId, key, color) {
    if (color) columnHighlights[tableId][key] = color.toLowerCase();
    else delete columnHighlights[tableId][key];
    saveColumnHighlights();
    applyColumnHighlights();
}

function clearTableHighlights(tableId) {
    columnHighlights[tableId] = {};
    saveColumnHighlights();
    applyColumnHighlights();
}

function highlightMenu() {
    if (highlightMenuEl) return highlightMenuEl;
    const menu = document.createElement('div');
    menu.className = 'col-hl-menu';
    menu.setAttribute('role', 'menu');
    menu.hidden = true;
    menu.innerHTML = `
        <div class="col-hl-title"><i class="fa-solid fa-highlighter me-1"></i><span class="col-hl-title-text"></span></div>
        <div class="col-hl-swatches">
            ${HIGHLIGHT_PALETTE.map(p => `<button type="button" class="col-hl-swatch" data-color="${p.color}" style="background: ${p.color}" title="${p.name}" aria-label="${p.name}"></button>`).join('')}
            <label class="col-hl-swatch col-hl-custom" title="สีอื่น"><input type="color" value="#ffff00" aria-label="สีอื่น"></label>
        </div>
        <button type="button" class="col-hl-item" data-action="clear"><i class="fa-solid fa-ban me-2"></i>ไม่มีสี</button>
        <button type="button" class="col-hl-item" data-action="clear-all"><i class="fa-solid fa-eraser me-2"></i>ล้างสีทั้งตาราง</button>`;
    menu.addEventListener('click', e => {
        const button = e.target.closest('button');
        if (!button || !highlightTarget) return;
        if (button.dataset.color) setColumnHighlight(highlightTarget.tableId, highlightTarget.key, button.dataset.color);
        else if (button.dataset.action === 'clear') setColumnHighlight(highlightTarget.tableId, highlightTarget.key, null);
        else if (button.dataset.action === 'clear-all') clearTableHighlights(highlightTarget.tableId);
        closeHighlightMenu();
    });
    const custom = menu.querySelector('.col-hl-custom input');
    custom.addEventListener('input', () => {
        if (highlightTarget) setColumnHighlight(highlightTarget.tableId, highlightTarget.key, custom.value);
    });
    custom.addEventListener('change', closeHighlightMenu);
    document.body.appendChild(menu);
    highlightMenuEl = menu;
    return menu;
}

function openHighlightMenu(th, x, y) {
    const tableId = th.closest('table').id;
    const key = th.dataset.col;
    const colors = columnHighlights[tableId];
    const current = colors[key] || null;
    highlightTarget = { tableId, key };

    const menu = highlightMenu();
    menu.querySelector('.col-hl-title-text').textContent = `ไฮไลท์คอลัมน์ ${th.textContent.trim()}`;
    menu.querySelectorAll('.col-hl-swatch[data-color]').forEach(swatch => {
        swatch.classList.toggle('is-active', swatch.dataset.color === current);
    });
    const custom = menu.querySelector('.col-hl-custom');
    const isCustom = current !== null && !HIGHLIGHT_PALETTE.some(p => p.color === current);
    custom.classList.toggle('is-active', isCustom);
    if (isCustom) custom.querySelector('input').value = current;
    menu.querySelector('[data-action="clear"]').disabled = current === null;
    menu.querySelector('[data-action="clear-all"]').disabled = Object.keys(colors).length === 0;

    menu.hidden = false;
    menu.style.left = Math.max(8, Math.min(x, window.innerWidth - menu.offsetWidth - 8)) + 'px';
    menu.style.top = Math.max(8, Math.min(y, window.innerHeight - menu.offsetHeight - 8)) + 'px';
}

function closeHighlightMenu() {
    if (highlightMenuEl) highlightMenuEl.hidden = true;
    highlightTarget = null;
}

document.addEventListener('contextmenu', e => {
    const th = e.target.closest(HIGHLIGHT_TABLES.map(id => `#${id} thead th`).join(', '));
    if (!th) {
        closeHighlightMenu();
        return;
    }
    e.preventDefault();
    openHighlightMenu(th, e.clientX, e.clientY);
});
document.addEventListener('mousedown', e => {
    if (highlightMenuEl && !highlightMenuEl.hidden && !highlightMenuEl.contains(e.target)) closeHighlightMenu();
}, true);
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeHighlightMenu();
});
document.addEventListener('scroll', closeHighlightMenu, true);
window.addEventListener('resize', closeHighlightMenu);

applyColumnHighlights();
