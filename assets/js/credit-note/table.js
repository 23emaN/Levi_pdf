const CN_INITIAL_RENDER_ROWS = 80;
const CN_CHUNK_RENDER_ROWS = 220;
const CN_PLACEHOLDER_ROWS = 18;

const CN_TYPE_PILLS = {
    'Standard CN': 'cn-type-standard',
    'Attachment Sheet': 'cn-type-attachment',
    'Variance / Refund': 'cn-type-variance'
};

// The tab on screen (page.js switches it); a table drawn while its tab is hidden is parked instead and drawn on return
const creditNoteTabs = { active: 'pdf' };

// all: every document of the Job in the order the server keeps them; shown: what the table lists after search and sort
const creditNoteState = {
    all: [],
    shown: [],
    counts: { files: 0, documents: 0 },
    query: '',
    sort: { key: '#', direction: 'asc' },
    render: { token: 0, frame: null, parked: false }
};

// The extractor writes '-' where a field is missing on the PDF; it is shown, sorted and exported as missing
function creditNoteIsBlank(value) {
    return value === null || value === undefined || value === '' || value === '-';
}

function creditNoteAmount(value) {
    if (creditNoteIsBlank(value)) return null;
    const num = typeof value === 'number' ? value : parseFloat(String(value).replace(/,/g, ''));
    return isNaN(num) ? null : num;
}

// dd.mm.yyyy (dots, slashes or dashes) or yyyy-mm-dd as yyyymmdd, so dates sort by day across months and years
function creditNoteDateKey(value) {
    const text = creditNoteIsBlank(value) ? '' : String(value).trim();
    const dmy = /^(\d{1,2})[./-](\d{1,2})[./-](\d{4})$/.exec(text);
    if (dmy) return dmy[3] + dmy[2].padStart(2, '0') + dmy[1].padStart(2, '0');
    const ymd = /^(\d{4})[./-](\d{1,2})[./-](\d{1,2})$/.exec(text);
    return ymd ? ymd[1] + ymd[2].padStart(2, '0') + ymd[3].padStart(2, '0') : null;
}

// The reader gives doc_date_iso; documents read before it existed only have the date as printed (06.04.2026, 11-04-2026)
function creditNoteDocDateKey(doc) {
    return creditNoteDateKey(doc.doc_date_iso) || creditNoteDateKey(doc.doc_date);
}

function creditNoteDateText(key) {
    return key ? `${key.slice(6, 8)}/${key.slice(4, 6)}/${key.slice(0, 4)}` : null;
}

// Every date of the credit note pages reads dd/mm/yyyy; a printed date that is not a date stays as printed
function creditNoteDocDate(doc) {
    const text = creditNoteDateText(creditNoteDocDateKey(doc));
    if (text) return text;
    return creditNoteIsBlank(doc.doc_date) ? null : String(doc.doc_date);
}

function creditNoteTextCell(value, className, withTitle) {
    if (creditNoteIsBlank(value)) return `<td class="${className} text-null-subtle">–</td>`;
    const text = escapeHtml(value);
    return `<td class="${className}"${withTitle ? ` title="${text}"` : ''}>${text}</td>`;
}

function creditNoteAmountCell(value, className) {
    const num = creditNoteAmount(value);
    if (num === null) return `<td class="text-end tabular-num text-null-subtle">–</td>`;
    return `<td class="text-end tabular-num${className ? ' ' + className : ''}">${formatMoney(num)}</td>`;
}

// One cell per column key, in the order of CN_COLUMNS (credit_note.php)
const CN_CELLS = {
    '#': (doc, rowNum) => `<td class="text-center fw-medium text-secondary tabular-num">${rowNum}</td>`,
    filename: doc => creditNoteTextCell(doc.filename, 'fw-medium', true),
    pages: doc => creditNoteIsBlank(doc.pages)
        ? '<td class="text-center text-null-subtle">–</td>'
        : `<td class="text-center"><span class="pill-badge pill-page tabular-num">${escapeHtml(doc.pages)}</span></td>`,
    doc_no: doc => creditNoteTextCell(doc.doc_no, 'tabular-num fw-semibold text-dark', false),
    doc_date: doc => creditNoteTextCell(creditNoteDocDate(doc), 'tabular-num', false),
    customer: doc => creditNoteTextCell(doc.customer, '', true),
    format_type: doc => creditNoteIsBlank(doc.format_type)
        ? '<td class="text-center text-null-subtle">–</td>'
        : `<td class="text-center"><span class="pill-badge ${CN_TYPE_PILLS[doc.format_type] || 'cn-type-standard'}">${escapeHtml(doc.format_type)}</span></td>`,
    orig_amount: doc => creditNoteAmountCell(doc.orig_amount, ''),
    corr_amount: doc => creditNoteAmountCell(doc.corr_amount, ''),
    diff_amount: doc => creditNoteAmountCell(doc.diff_amount, ''),
    vat_amount: doc => creditNoteAmountCell(doc.vat_amount, ''),
    amount: doc => creditNoteAmountCell(doc.amount, 'fw-semibold text-dark'),
    excl_vat: doc => creditNoteAmountCell(doc.excl_vat, ''),
    vat: doc => creditNoteAmountCell(doc.vat, ''),
    incl_vat: doc => creditNoteAmountCell(doc.incl_vat, 'fw-semibold text-dark')
};

function creditNoteRowHtml(doc, rowNum) {
    return `<tr>${CN_COLUMNS.map(col => CN_CELLS[col.key](doc, rowNum)).join('')}</tr>`;
}

// What the search box matches for an amount: as shown (1,234.50) and as typed without the comma (1234.50)
function creditNoteMoneySearch(value) {
    const num = creditNoteAmount(value);
    return num === null ? '' : `${formatMoney(num)} ${num.toFixed(2)}`;
}

function creditNoteSearchText(doc) {
    return CN_COLUMNS.filter(col => col.key !== '#').map(col => {
        if (col.type === 'currency') return creditNoteMoneySearch(doc[col.key]);
        if (col.type === 'date') return [creditNoteDocDate(doc), doc[col.key]].filter(v => !creditNoteIsBlank(v)).join(' ');
        return creditNoteIsBlank(doc[col.key]) ? '' : String(doc[col.key]);
    }).filter(Boolean).join(' ').toLowerCase();
}

// _order keeps the server's order for the # column; _search is the text the search box matches, in lower case
function creditNotePrepare(doc, order) {
    doc._order = order;
    doc._search = creditNoteSearchText(doc);
    return doc;
}

function creditNoteSortValue(col, doc) {
    if (col.key === '#') return doc._order;
    if (col.type === 'currency') return creditNoteAmount(doc[col.key]);
    if (col.type === 'date') return creditNoteDocDateKey(doc);
    return creditNoteIsBlank(doc[col.key]) ? null : String(doc[col.key]);
}

// Missing values (null) go last in both directions; ties keep the server's order (_order). Shared by the three tables.
function creditNoteOrderBy(valueOf, direction) {
    const sign = direction === 'desc' ? -1 : 1;
    return (a, b) => {
        const va = valueOf(a);
        const vb = valueOf(b);
        if (va === null || vb === null) {
            if (va !== vb) return va === null ? 1 : -1;
            return a._order - b._order;
        }
        const diff = typeof va === 'number' ? va - vb : thCollator.compare(va, vb);
        return diff !== 0 ? sign * diff : a._order - b._order;
    };
}

function creditNoteComparator(sort) {
    const col = CN_COLUMNS.find(c => c.key === sort.key);
    return creditNoteOrderBy(doc => creditNoteSortValue(col, doc), sort.direction);
}

function creditNoteSearchWords(query) {
    return String(query || '').toLowerCase().split(/\s+/).filter(Boolean);
}

function creditNoteVisibleRows(all, query, sort) {
    const words = creditNoteSearchWords(query);
    const rows = words.length ? all.filter(doc => words.every(word => doc._search.includes(word))) : all.slice();
    return rows.sort(creditNoteComparator(sort));
}

// has_items is false for a document stored before the reader gave lines and totals (an Attachment Sheet read since
// has it true, with no lines)
function creditNoteNeedsReread(doc) {
    return doc.has_items === false;
}

function creditNoteRereadDocs() {
    return creditNoteState.all.filter(creditNoteNeedsReread);
}

function creditNoteIsDefaultView() {
    return creditNoteState.query.trim() === '' && creditNoteState.sort.key === '#' && creditNoteState.sort.direction === 'asc';
}

// Amounts are numbers and a missing value is an empty cell, never a dash or a zero
function creditNoteExcelRows(rows) {
    return rows.map((doc, i) => {
        const out = {};
        CN_COLUMNS.forEach(col => {
            if (col.key === '#') out[col.label] = i + 1;
            else if (col.type === 'currency') out[col.label] = creditNoteAmount(doc[col.key]);
            else if (col.type === 'date') out[col.label] = creditNoteDocDate(doc);
            else out[col.label] = creditNoteIsBlank(doc[col.key]) ? null : String(doc[col.key]);
        });
        return out;
    });
}

// One sheet in the column order of labels; the money columns show #,##0.00. Every Excel button of the page writes here.
async function creditNoteWriteExcel(sheetRows, labels, moneyLabels, sheetName, fileName) {
    await ensureXlsxLoaded();
    const ws = XLSX.utils.json_to_sheet(sheetRows, { header: labels });
    labels.forEach((label, c) => {
        if (!moneyLabels.includes(label)) return;
        for (let r = 1; r <= sheetRows.length; r++) {
            const cell = ws[XLSX.utils.encode_cell({ r, c })];
            if (cell) cell.z = '#,##0.00';
        }
    });
    ws['!cols'] = labels.map(label => {
        let maxLen = label.length;
        sheetRows.forEach(row => {
            const len = row[label] === null || row[label] === undefined ? 0 : String(row[label]).length;
            if (len > maxLen) maxLen = Math.min(len, 50);
        });
        return { wch: maxLen + 3 };
    });

    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, sheetName);
    XLSX.writeFile(wb, fileName);
}

async function creditNoteExportExcel(rows) {
    if (!rows || rows.length === 0) return;
    await creditNoteWriteExcel(
        creditNoteExcelRows(rows),
        CN_COLUMNS.map(col => col.label),
        CN_COLUMNS.filter(col => col.type === 'currency').map(col => col.label),
        'Credit_Notes',
        `Job_${CURRENT_JOB_ID}_${CN_JOB_NAME_CLEAN}_Credit_Notes.xlsx`
    );
}

function creditNotePlaceholderHtml(columnCount) {
    const emptyCells = '<td></td>'.repeat(columnCount - 1);
    let html = '';
    for (let i = 1; i <= CN_PLACEHOLDER_ROWS; i++) {
        html += `<tr class="placeholder-grid-row"><td class="text-center text-muted tabular-num opacity-40">${i}</td>${emptyCells}</tr>`;
    }
    return html;
}

function creditNoteCancelRender(render) {
    render.token++;
    if (render.frame !== null) {
        cancelAnimationFrame(render.frame);
        render.frame = null;
    }
}

// Writes rows[from..] after what the body already holds: the first CN_INITIAL_RENDER_ROWS at once, the rest one
// animation frame per CN_CHUNK_RENDER_ROWS, so a long list never blocks the page
function creditNoteAppendRows(tbody, rows, from, rowHtml, render) {
    const token = render.token;
    const firstEnd = Math.min(rows.length, from + CN_INITIAL_RENDER_ROWS);
    const html = [];
    for (let i = from; i < firstEnd; i++) html.push(rowHtml(rows[i], i + 1));
    tbody.insertAdjacentHTML('beforeend', html.join(''));

    let cursor = firstEnd;
    const pump = () => {
        if (token !== render.token) return;
        const end = Math.min(cursor + CN_CHUNK_RENDER_ROWS, rows.length);
        const parts = [];
        for (let i = cursor; i < end; i++) parts.push(rowHtml(rows[i], i + 1));
        tbody.insertAdjacentHTML('beforeend', parts.join(''));
        cursor = end;
        render.frame = cursor < rows.length ? requestAnimationFrame(pump) : null;
    };
    if (cursor < rows.length) render.frame = requestAnimationFrame(pump);
}

function creditNoteMarkSorted(tableSelector, th, direction) {
    resetSortIcons(tableSelector);
    th.classList.add('sorted');
    const icon = th.querySelector('.sort-icon');
    if (icon) icon.className = `fa-solid fa-sort-${direction === 'asc' ? 'up' : 'down'} sort-icon`;
}

// A search box with its clear button: onQuery gets the text 100 ms after the last key, or at once when cleared
function creditNoteWireSearch(inputId, clearId, onQuery) {
    const input = document.getElementById(inputId);
    const clear = document.getElementById(clearId);
    const debounced = debounce(() => onQuery(input.value), 100);
    input.addEventListener('input', () => {
        clear.style.display = input.value ? 'block' : 'none';
        debounced();
    });
    clear.addEventListener('click', () => {
        input.value = '';
        clear.style.display = 'none';
        onQuery('');
        input.focus();
    });
}

// A table of a hidden tab is emptied and drawn again when its tab comes back (page.js)
function creditNoteParkTable(tbodyId, render) {
    creditNoteCancelRender(render);
    const tbody = document.getElementById(tbodyId);
    if (tbody.children.length > 0) tbody.innerHTML = '';
    render.parked = true;
}

function creditNoteRenderTable() {
    const render = creditNoteState.render;
    creditNoteCancelRender(render);
    creditNoteRenderStatus();
    if (creditNoteTabs.active !== 'pdf') {
        creditNoteParkTable('credit-note-tbody', render);
        return;
    }
    render.parked = false;
    const tbody = document.getElementById('credit-note-tbody');
    const rows = creditNoteState.shown;
    if (rows.length === 0) {
        tbody.innerHTML = creditNoteState.all.length === 0
            ? creditNotePlaceholderHtml(CN_COLUMNS.length)
            : `<tr><td colspan="${CN_COLUMNS.length}" class="text-center py-5 text-muted"><i class="fa-solid fa-magnifying-glass me-2"></i>ไม่พบข้อมูลที่ตรงกับคำค้นหา</td></tr>`;
    } else {
        tbody.innerHTML = '';
        creditNoteAppendRows(tbody, rows, 0, creditNoteRowHtml, render);
    }
}

function creditNoteRenderStatus() {
    const files = new Set(creditNoteState.shown.map(doc => doc.filename).filter(Boolean));
    document.getElementById('credit-note-files-count').textContent = files.size.toLocaleString();
    document.getElementById('credit-note-docs-count').textContent = creditNoteState.shown.length.toLocaleString();
    document.getElementById('credit-note-export').disabled = creditNoteState.shown.length === 0;
}

function creditNoteApplyView() {
    creditNoteState.shown = creditNoteVisibleRows(creditNoteState.all, creditNoteState.query, creditNoteState.sort);
    creditNoteRenderTable();
}

function creditNoteSetDocs(docs, counts) {
    creditNoteState.all = (docs || []).map((doc, i) => creditNotePrepare(doc, i));
    creditNoteState.counts = counts || { files: 0, documents: 0 };
    creditNoteApplyView();
}

// Rows of a batch that just finished: in the default view they go under the rows already drawn; a search or a
// sort decides their place, so the view is drawn again
function creditNoteAddDocs(docs) {
    if (!docs || docs.length === 0) return;
    const start = creditNoteState.all.length;
    const added = docs.map((doc, i) => creditNotePrepare(doc, start + i));
    creditNoteState.all.push(...added);
    if (!creditNoteIsDefaultView() || creditNoteState.render.parked) {
        creditNoteApplyView();
        return;
    }
    const tbody = document.getElementById('credit-note-tbody');
    if (creditNoteState.shown.length === 0) {
        creditNoteCancelRender(creditNoteState.render);
        tbody.innerHTML = '';
    }
    const from = creditNoteState.shown.length;
    creditNoteState.shown.push(...added);
    if (creditNoteState.render.frame === null) {
        creditNoteAppendRows(tbody, creditNoteState.shown, from, creditNoteRowHtml, creditNoteState.render);
    }
    creditNoteRenderStatus();
}
