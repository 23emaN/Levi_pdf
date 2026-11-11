// Tab 3: the overview of the credit notes of the ASN (job_cn_dashboard), adapted from workspace/dashboard.js.
// The rows, sort, search and Excel need no DOM, so tests/credit_note_compare.js runs them in Node.

// rows: one per credit note number of the ASN, in the server's order (store, then number); token drops an answer
// that a later refresh has overtaken
const creditNoteDash = {
    rows: [],
    status: 'ALL',
    query: '',
    sort: { key: 'store_no', direction: 'asc' },
    emptyMessage: '',
    token: 0
};

function creditNoteDashSourceHtml(files) {
    if (!files || files.length === 0) return CN_NULL_CELL;
    const shown = files.length > 2 ? files.slice(0, 1) : files;
    const names = shown.map(name => `<span class="dash-source-name">${escapeHtml(name).replace(/-/g, '-<wbr>')}</span>`).join('');
    const more = files.length > 2 ? `<span class="source-file-more">+${files.length - 1} ไฟล์</span>` : '';
    return `<div class="dash-source" title="${escapeHtml(files.join('\n'))}">${names}${more}</div>`;
}

// The cells of the overview table by column key (CN_DASH_COLUMNS in credit_note.php); value(row) as in
// CN_COMPARE_CELLS
const CN_DASH_CELLS = {
    '#': {
        value: r => r._order,
        html: (r, n) => `<td class="text-center tabular-num text-muted">${n}</td>`,
        excel: (r, n, col) => ({ [col.label]: n })
    },
    store_no: {
        value: r => creditNoteText(r.store_no),
        html: r => `<td>${r.store_no ? `<span class="fw-semibold tabular-num">${escapeHtml(r.store_no)}</span>` : CN_NULL_CELL}</td>`,
        search: true
    },
    doc_no: {
        value: r => creditNoteText(r.doc_no),
        html: r => `<td class="tabular-num fw-semibold text-dark">${escapeHtml(r.doc_no) || CN_NULL_CELL}</td>`,
        search: true
    },
    source_file: {
        value: r => creditNoteText((r.source_files || [])[0]),
        html: r => `<td class="dash-source-cell">${creditNoteDashSourceHtml(r.source_files)}</td>`,
        search: r => (r.source_files || []).join(' '),
        excel: (r, n, col) => ({ [col.label]: (r.source_files || []).join('; ') || null })
    },
    units: {
        value: r => creditNoteAmount(r.units),
        html: r => {
            const units = creditNoteAmount(r.units);
            return `<td class="text-center tabular-num fw-bold">${units === null ? CN_NULL_CELL : units.toLocaleString()}</td>`;
        }
    },
    amount: {
        value: r => creditNoteAmount(r.amount),
        html: r => {
            const basis = CN_AMOUNT_BASIS[r.amount_kind];
            return `<td class="text-end tabular-num">${creditNoteMoneyHtml(creditNoteAmount(r.amount), '', basis && basis.asnName)}</td>`;
        },
        search: true,
        excel: (r, n, col) => {
            const basis = CN_AMOUNT_BASIS[r.amount_kind];
            return { [col.label]: creditNoteAmount(r.amount), 'Amount Basis': basis ? basis.excel : null };
        }
    },
    pages: {
        value: r => typeof r.pages === 'number' ? r.pages : creditNoteText(r.pages),
        html: r => `<td class="text-center tabular-num">${escapeHtml(CN_DASH_CELLS.pages.value(r)) || CN_NULL_CELL}</td>`
    },
    status: {
        value: r => (CN_STATUS_DISPLAY[r.status] || CN_STATUS_DISPLAY.MISMATCH).rank,
        html: r => `<td class="text-center">${creditNoteStatusBadge(r.status)}</td>`,
        search: r => (CN_STATUS_DISPLAY[r.status] || { label: '' }).label,
        excel: (r, n, col) => ({ [col.label]: (CN_STATUS_DISPLAY[r.status] || { label: r.status }).label })
    },
    remark: {
        value: r => creditNoteRemarkText(r._remark) || null,
        html: r => `<td class="dash-note">${r._remark.length
            ? `<div class="dash-note-text" title="${escapeHtml(creditNoteRemarkText(r._remark))}">${creditNoteRemarkHtml(r._remark)}</div>` : ''}</td>`,
        search: true
    }
};

function creditNoteDashPrepare(row, order) {
    row._order = order;
    row._remark = creditNoteRemarkLines(row.status, row.problem, false);
    row._search = CN_DASH_COLUMNS.map(col => {
        const cell = CN_DASH_CELLS[col.key];
        if (!cell.search) return '';
        if (typeof cell.search === 'function') return cell.search(row);
        const value = cell.value(row);
        return col.type === 'currency' ? creditNoteMoneySearch(value) : (value === null ? '' : String(value));
    }).filter(Boolean).join(' ').toLowerCase();
    return row;
}

function creditNoteDashVisibleRows(rows, status, query, sort) {
    const words = creditNoteSearchWords(query);
    const cell = CN_DASH_CELLS[sort.key] || CN_DASH_CELLS['#'];
    return rows.filter(r => (status === 'ALL' || r.status === status) && words.every(word => r._search.includes(word)))
        .sort(creditNoteOrderBy(cell.value, sort.direction));
}

function creditNoteDashRowHtml(row, rowNum) {
    return `<tr>${CN_DASH_COLUMNS.map(col => CN_DASH_CELLS[col.key].html(row, rowNum)).join('')}</tr>`;
}

function creditNoteDashExcelRows(rows) {
    return rows.map((r, i) => Object.assign({}, ...CN_DASH_COLUMNS.map(col => {
        const cell = CN_DASH_CELLS[col.key];
        return cell.excel ? cell.excel(r, i + 1, col) : { [col.label]: cell.value(r) };
    })));
}

async function creditNoteDashExportExcel(rows) {
    if (!rows || rows.length === 0) return;
    const sheetRows = creditNoteDashExcelRows(rows);
    await creditNoteWriteExcel(sheetRows, Object.keys(sheetRows[0]), ['Amount'], 'Credit_Note_Summary',
        `Job_${CURRENT_JOB_ID}_${CN_JOB_NAME_CLEAN}_Credit_Note_Summary.xlsx`);
}

function creditNotePercent(value, total) {
    if (!total) return '–';
    return (value * 100 / total).toLocaleString('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%';
}

async function creditNoteDashLoad() {
    const token = ++creditNoteDash.token;
    creditNoteDashSetLoading(true);
    try {
        const json = await creditNoteApi('job_cn_dashboard');
        if (token === creditNoteDash.token) creditNoteDashRender(json.data);
    } catch (err) {
        if (token === creditNoteDash.token) creditNoteDashRender(null, err.message);
    } finally {
        if (token === creditNoteDash.token) creditNoteDashSetLoading(false);
    }
}

function creditNoteDashSetLoading(isLoading) {
    document.getElementById('credit-note-dash-section').classList.toggle('is-loading', isLoading);
    const refresh = document.getElementById('credit-note-dash-refresh');
    refresh.disabled = isLoading;
    refresh.querySelector('i').classList.toggle('fa-spin', isLoading);
}

function creditNoteDashSetBar(parts, total) {
    const bar = document.getElementById('credit-note-dash-bar');
    let shown = false;
    bar.querySelectorAll('.dash-seg').forEach(seg => {
        const value = parts[seg.dataset.seg] || 0;
        seg.style.flexGrow = value;
        seg.classList.toggle('has-value', value > 0);
        seg.title = value > 0 ? value.toLocaleString() : '';
        shown = shown || value > 0;
    });
    bar.classList.toggle('is-empty', !shown || !total);
}

function creditNoteDashSetState(kind, text, iconClass) {
    const state = document.getElementById('credit-note-dash-state');
    state.className = `dash-state is-${kind}`;
    state.innerHTML = `<i class="fa-solid ${iconClass}"></i> `;
    state.appendChild(document.createTextNode(text));
}

// Complete and wrong are known only after a compare; before it every credit note of the ASN waits for one
function creditNoteDashRender(data, errorMessage) {
    const known = !!data && !!data.compared;
    const total = data ? Number(data.total || 0) : 0;
    const parts = data ? { complete: known ? data.complete : 0, pending: data.pending, error: known ? data.error : 0 } : {};
    const shown = { total: data ? total : null, complete: known ? data.complete : null, pending: data ? data.pending : null, error: known ? data.error : null };
    Object.keys(shown).forEach(field => {
        const value = shown[field];
        document.getElementById(`credit-note-dash-${field}`).textContent = value === null || value === undefined ? '–' : Number(value).toLocaleString();
    });
    ['complete', 'pending', 'error'].forEach(field => {
        document.querySelector(`[data-cn-pct="${field}"]`).textContent = shown[field] === null ? '–' : creditNotePercent(shown[field], total);
        document.querySelector(`[data-cn-legend="${field}"]`).textContent = shown[field] === null ? '–' : Number(shown[field]).toLocaleString();
    });
    creditNoteDashSetBar(parts, total);

    let message = '';
    if (errorMessage) {
        creditNoteDashSetState('error', 'โหลดไม่สำเร็จ', 'fa-circle-exclamation');
        message = errorMessage;
    } else if (total === 0) {
        creditNoteDashSetState('muted', 'ยังไม่มีข้อมูล ASN', 'fa-circle-minus');
        message = 'ยังไม่มีข้อมูล ASN ใน Job นี้ นำเข้าไฟล์ ASN ที่หน้าเปรียบเทียบก่อน';
    } else if (!known) {
        creditNoteDashSetState('warn', 'ยังไม่ได้เทียบกับ PDF', 'fa-clock');
        message = 'ยังไม่ได้เทียบกับ PDF ทุกใบลดหนี้จึงรอเทียบ ตัวเลขสมบูรณ์และผิดพลาดจะขึ้นหลังกดเทียบกับ PDF';
    } else {
        creditNoteDashSetState('ok', 'เทียบกับ PDF แล้ว', 'fa-circle-check');
    }
    const notice = document.getElementById('credit-note-dash-notice');
    document.getElementById('credit-note-dash-notice-text').textContent = message;
    document.getElementById('credit-note-dash-notice-icon').className = errorMessage ? 'fa-solid fa-circle-exclamation' : 'fa-solid fa-circle-info text-primary';
    document.getElementById('credit-note-dash-notice-action').classList.toggle('d-none', !!errorMessage);
    notice.classList.toggle('is-error', !!errorMessage);
    notice.classList.toggle('d-none', message === '');
    document.getElementById('credit-note-dash-updated').textContent =
        errorMessage ? '' : 'อัปเดตล่าสุด ' + new Date().toLocaleTimeString('th-TH', { hour12: false });

    creditNoteDash.rows = (data && data.documents ? data.documents : []).map((row, i) => creditNoteDashPrepare(row, i));
    creditNoteDash.emptyMessage = errorMessage ? 'โหลดข้อมูลไม่สำเร็จ' : 'ยังไม่มีข้อมูล ASN';
    creditNoteDashRenderTable();
}

function creditNoteDashRenderTable() {
    const d = creditNoteDash;
    const rows = creditNoteDashVisibleRows(d.rows, d.status, d.query, d.sort);
    const counts = creditNoteCompareCounts(d.rows);
    document.querySelectorAll('#credit-note-dash-filters .recon-filter-btn').forEach(btn => {
        const el = btn.querySelector('.recon-count');
        const count = counts[btn.dataset.filter] || 0;
        el.textContent = count.toLocaleString();
        el.classList.toggle('is-zero', count === 0);
        btn.classList.toggle('active', btn.dataset.filter === d.status);
    });
    const filtered = d.status !== 'ALL' || creditNoteSearchWords(d.query).length > 0;
    document.getElementById('credit-note-dash-export').disabled = rows.length === 0;
    document.getElementById('credit-note-dash-count').textContent = d.rows.length === 0 ? '–'
        : (filtered ? `${rows.length.toLocaleString()} จาก ${d.rows.length.toLocaleString()} ใบ` : `${d.rows.length.toLocaleString()} ใบ`);

    const tbody = document.getElementById('credit-note-dash-tbody');
    if (rows.length === 0) {
        const message = d.rows.length === 0 ? d.emptyMessage : 'ไม่พบใบลดหนี้ที่ตรงกับตัวกรอง';
        tbody.innerHTML = `<tr><td colspan="${CN_DASH_COLUMNS.length}" class="dash-table-empty">${escapeHtml(message)}</td></tr>`;
        return;
    }
    tbody.innerHTML = rows.map((row, i) => creditNoteDashRowHtml(row, i + 1)).join('');
}

function creditNoteDashInit() {
    document.getElementById('credit-note-dash-refresh').addEventListener('click', creditNoteDashLoad);
    document.getElementById('credit-note-dash-notice-action').addEventListener('click', () => creditNoteSwitchTab('asn'));
    document.getElementById('credit-note-dash-export').addEventListener('click', () =>
        creditNoteDashExportExcel(creditNoteDashVisibleRows(creditNoteDash.rows, creditNoteDash.status, creditNoteDash.query, creditNoteDash.sort)));
    document.querySelectorAll('#credit-note-dash-filters .recon-filter-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            creditNoteDash.status = btn.dataset.filter;
            creditNoteDashRenderTable();
        });
    });
    creditNoteWireSearch('credit-note-dash-search', 'credit-note-dash-clear-search', query => {
        creditNoteDash.query = query;
        creditNoteDashRenderTable();
    });
    document.querySelectorAll('#credit-note-dash-table thead th').forEach(th => {
        th.addEventListener('click', e => {
            if (e.target.classList.contains('resizer') || creditNoteDash.rows.length === 0) return;
            const sort = creditNoteDash.sort;
            sort.direction = sort.key === th.dataset.col && sort.direction === 'asc' ? 'desc' : 'asc';
            sort.key = th.dataset.col;
            creditNoteMarkSorted('#credit-note-dash-table', th, sort.direction);
            creditNoteDashRenderTable();
        });
    });
}
