// Tab 4 "ใส่ยอด PDF ลง ASN". The user imports an ASN workbook and picks its sheets: the workbook is kept on the server as
// uploaded and every line of the picked sheets is stored with all its columns (CreditNoteAsnFill). "อ่านยอดจาก PDF"
// puts on each line the Total Amt Excluding VAT and the VAT (7%) of the credit note PDF with the same number (the key
// of the compare, CreditNoteReconciler::key) or marks it as not found; the credit notes not found are listed with
// their count and can be exported. "Export ASN" patches the kept workbook: two new columns after the last column of
// each imported sheet (the sheet's own columns are never overwritten), the PDF figures on a yellow fill in red, and
// CN_FILL_MISSING_TEXT in red on a line not found. Only the sheet XML inside the .xlsx changes, so every other sheet,
// cell, format, filter and formula stays as the user saved it.

const CN_FILL_HEADERS = { cost: 'PDF_TOTAL_COST_EX_VAT', vat: 'PDF_TOTAL_VAT' };
const CN_FILL_MISSING_TEXT = 'ไม่พบใน PDF';
const CN_FILL_FILL_RGB = 'FFFFFF00';
const CN_FILL_FONT_RGB = 'FFFF0000';
const CN_FILL_SAMPLE = 30;

// pdf_status of a line as the page shows it; the filter keys reuse the colours of the other tabs
const CN_FILL_STATUS = {
    FOUND: { filter: 'MATCH', label: 'พบใน PDF', icon: 'fa-check', tone: 'match', rank: 2 },
    MISSING: { filter: 'MISMATCH', label: 'ไม่พบใน PDF', icon: 'fa-triangle-exclamation', tone: 'mismatch', rank: 0 },
    '': { filter: 'PENDING', label: 'ยังไม่อ่านยอด', icon: 'fa-clock', tone: 'waiting', rank: 1 }
};

const creditNoteFill = {
    loaded: false,
    files: [],
    sheets: new Map(),
    rows: [],
    summary: null,
    filter: 'ALL',
    query: '',
    sort: { key: '#', direction: 'asc' },
    shown: [],
    render: { token: 0, frame: null, parked: false },
    pending: null
};

// Headers are compared with underscores as spaces, so TOTAL_COST_EX_VAT and Total Cost Ex VAT are one column
function creditNoteFillHeaderText(value) {
    return String(value === null || value === undefined ? '' : value).replace(/_/g, ' ').replace(/\s+/g, ' ').trim();
}

// The columns of one sheet: the header row and the credit note number and barcode columns (0-based), the ones
// creditNoteDetectSheetColumns looks for, plus a bare "Invoice" header; null when the sheet has no such header row.
function creditNoteFillDetect(rows) {
    for (let r = 0; r < Math.min(rows.length, 50); r++) {
        const row = rows[r];
        if (!Array.isArray(row)) continue;
        const found = {};
        row.forEach((cell, c) => {
            const text = creditNoteFillHeaderText(cell);
            if (!text) return;
            if (found.invoice === undefined && /invoice\s*no|^invoice$|bill\s*no|tax\s*inv|cn\s*no|credit\s*note|billing\s*doc/i.test(text)) found.invoice = c;
            else if (found.barcode === undefined && /^ean\s*\/?\s*upc$|^ean$|^upc$|^barcode$/i.test(text)) found.barcode = c;
        });
        if (found.invoice !== undefined && found.barcode !== undefined) return Object.assign({ headerRow: r }, found);
    }
    return null;
}

// A SheetJS sheet as rows of values by absolute position (rows[0] is Excel row 1), built from the cells it holds so a
// sheet formatted down to column XFD costs nothing; lastCol is the last column that holds a value
function creditNoteFillSheetRows(ws) {
    const rows = [];
    let lastCol = -1;
    Object.keys(ws).forEach(ref => {
        if (ref[0] === '!') return;
        const cell = ws[ref];
        if (!cell || cell.v === undefined || cell.v === null || cell.v === '') return;
        const at = XLSX.utils.decode_cell(ref);
        (rows[at.r] = rows[at.r] || [])[at.c] = cell.v;
        if (at.c > lastCol) lastCol = at.c;
    });
    for (let r = 0; r < rows.length; r++) rows[r] = rows[r] || [];
    return { rows, lastCol };
}

// One sheet as job_cn_fill_import stores it, or a sheet the picker shows disabled (lines: 0). A workbook exported by
// this tab already ends with the two PDF columns: they are written over again, never appended a second time.
function creditNoteFillReadSheet(ws, name, hidden) {
    const { rows, lastCol } = creditNoteFillSheetRows(ws);
    const found = creditNoteFillDetect(rows);
    if (!found) return { name, hidden, lines: [], detected: false };

    const header = rows[found.headerRow];
    let dataLastCol = lastCol;
    const costCol = header.findIndex(cell => String(cell === undefined ? '' : cell).trim() === CN_FILL_HEADERS.cost);
    if (costCol > 0 && String(header[costCol + 1] === undefined ? '' : header[costCol + 1]).trim() === CN_FILL_HEADERS.vat) {
        dataLastCol = costCol - 1;
    }
    const width = dataLastCol + 1;
    const cellValue = value => (value === undefined ? null : value);

    const lines = [];
    for (let r = found.headerRow + 1; r < rows.length; r++) {
        const row = rows[r];
        const docNo = String(row[found.invoice] === undefined ? '' : row[found.invoice]).trim();
        const barcode = String(row[found.barcode] === undefined ? '' : row[found.barcode]).replace(/[^0-9]/g, '');
        if (!docNo || !/^\d{7,15}$/.test(barcode) || /total|grand|summary|ex\./i.test(docNo)) continue;
        const values = [];
        for (let c = 0; c < width; c++) values.push(cellValue(row[c]));
        lines.push({ row_no: r + 1, doc_no: docNo, barcode, values });
    }
    return {
        name,
        hidden,
        detected: true,
        lines,
        header_row: found.headerRow + 1,
        last_col: dataLastCol,
        headers: Array.from({ length: width }, (_, c) => String(header[c] === undefined ? '' : header[c]))
    };
}

function creditNoteFillReadWorkbook(buffer) {
    const workbook = XLSX.read(buffer, { type: 'array' });
    return workbook.SheetNames.map(name => creditNoteFillReadSheet(workbook.Sheets[name], name, creditNoteSheetHidden(workbook, name)));
}

// ---- Data from the server ----

function creditNoteFillStatus(row) {
    return CN_FILL_STATUS[row.pdf_status] || CN_FILL_STATUS[''];
}

// The ASN columns of a sheet the table shows (quantity, Total Cost Ex VAT, Total VAT), found as tab 2 finds them
function creditNoteFillSheetColumns(headers) {
    const detected = creditNoteDetectSheetColumns([headers.map(creditNoteFillHeaderText)]);
    return detected ? detected.colMap : {};
}

function creditNoteFillSetData(json) {
    creditNoteFill.loaded = true;
    creditNoteFill.files = json.files || [];
    creditNoteFill.sheets = new Map();
    creditNoteFill.files.forEach(file => file.sheets.forEach(sheet => {
        creditNoteFill.sheets.set(sheet.id, Object.assign({ file, columns: creditNoteFillSheetColumns(sheet.headers || []) }, sheet));
    }));
    creditNoteFill.rows = (json.rows || []).map((row, i) => Object.assign({ _order: i + 1 }, row));
    creditNoteFill.summary = json.summary || null;
    creditNoteFillApplyView();
}

async function creditNoteFillLoad() {
    creditNoteFillSetData(await creditNoteApi('job_cn_fill_data'));
}

function creditNoteFillSheetOf(row) {
    return creditNoteFill.sheets.get(row.sheet_id) || null;
}

function creditNoteFillAsnValue(row, key) {
    const sheet = creditNoteFillSheetOf(row);
    if (!sheet || sheet.columns[key] === undefined) return null;
    const value = row.values[sheet.columns[key]];
    return value === null || value === undefined || value === '' ? null : value;
}

// The cells of the table by column key (CN_FILL_COLUMNS in credit_note.php puts them in order). value(row) is what
// the column sorts and searches on (null sorts last) and what the Excel of the missing lines takes.
const CN_FILL_CELLS = {
    '#': {
        value: r => r._order,
        html: (r, n) => `<td class="text-center tabular-num text-muted">${n}</td>`
    },
    status: {
        value: r => creditNoteFillStatus(r).rank,
        html: r => {
            const s = creditNoteFillStatus(r);
            return `<td class="text-center"><span class="pill-badge status-${s.tone}"><i class="fa-solid ${s.icon} me-1"></i>${s.label}</span></td>`;
        }
    },
    doc_no: { value: r => r.doc_no, html: r => `<td class="tabular-num fw-semibold text-dark">${escapeHtml(r.doc_no)}</td>`, search: true },
    barcode: { value: r => r.barcode, html: r => `<td class="tabular-num">${escapeHtml(r.barcode)}</td>`, search: true },
    file: {
        value: r => { const s = creditNoteFillSheetOf(r); return s ? s.file.file_name : null; },
        html: r => `<td class="text-truncate" title="${escapeHtml(CN_FILL_CELLS.file.value(r))}">${escapeHtml(CN_FILL_CELLS.file.value(r))}</td>`,
        search: true
    },
    sheet: {
        value: r => { const s = creditNoteFillSheetOf(r); return s ? s.name : null; },
        html: r => `<td class="text-truncate" title="${escapeHtml(CN_FILL_CELLS.sheet.value(r))}">${escapeHtml(CN_FILL_CELLS.sheet.value(r))}</td>`,
        search: true
    },
    row_no: { value: r => r.row_no, html: r => `<td class="text-center tabular-num">${r.row_no}</td>` },
    quantity: {
        value: r => creditNoteAmount(creditNoteFillAsnValue(r, 'quantity')),
        html: r => {
            const v = CN_FILL_CELLS.quantity.value(r);
            return `<td class="text-center tabular-num">${v === null ? CN_NULL_CELL : v.toLocaleString()}</td>`;
        }
    },
    asn_cost: {
        value: r => creditNoteAmount(creditNoteFillAsnValue(r, 'total_cost_ex_vat')),
        html: r => `<td class="text-end tabular-num">${creditNoteMoneyHtml(CN_FILL_CELLS.asn_cost.value(r), '')}</td>`
    },
    asn_vat: {
        value: r => creditNoteAmount(creditNoteFillAsnValue(r, 'total_vat')),
        html: r => `<td class="text-end tabular-num">${creditNoteMoneyHtml(CN_FILL_CELLS.asn_vat.value(r), '')}</td>`
    },
    pdf_cost: {
        value: r => r.pdf_cost,
        html: r => r.pdf_status === 'MISSING'
            ? `<td class="text-end cn-fill-none">${CN_FILL_MISSING_TEXT}</td>`
            : `<td class="text-end tabular-num${r.pdf_cost === null ? '' : ' cn-fill-pdf'}">${creditNoteMoneyHtml(r.pdf_cost, '')}</td>`
    },
    pdf_vat: {
        value: r => r.pdf_vat,
        html: r => `<td class="text-end tabular-num${r.pdf_vat === null ? '' : ' cn-fill-pdf'}">${creditNoteMoneyHtml(r.pdf_vat, '')}</td>`
    },
    pdf_file: {
        value: r => creditNoteFillRemark(r),
        html: r => `<td class="text-truncate${r.pdf_status === 'MISSING' ? ' cn-fill-none' : ''}" title="${escapeHtml(creditNoteFillRemark(r))}">${escapeHtml(creditNoteFillRemark(r)) || CN_NULL_CELL}</td>`,
        search: true
    }
};

function creditNoteFillRemark(row) {
    if (row.pdf_status === 'FOUND') return row.pdf_file || '';
    if (row.pdf_status === 'MISSING') return row.pdf_file || CN_FILL_MISSING_TEXT;
    return '';
}

// ---- The table ----

function creditNoteFillRowHtml(row, n) {
    return '<tr>' + CN_FILL_COLUMNS.map(col => CN_FILL_CELLS[col.key].html(row, n)).join('') + '</tr>';
}

function creditNoteFillApplyView() {
    const f = creditNoteFill;
    const query = f.query.trim().toLowerCase();
    let rows = f.rows;
    if (f.filter !== 'ALL') rows = rows.filter(row => creditNoteFillStatus(row).filter === f.filter);
    if (query) {
        const searchable = CN_FILL_COLUMNS.filter(col => CN_FILL_CELLS[col.key].search).map(col => CN_FILL_CELLS[col.key].value);
        rows = rows.filter(row => searchable.some(value => String(value(row) === null ? '' : value(row)).toLowerCase().includes(query)));
    }
    const cell = CN_FILL_CELLS[f.sort.key];
    if (cell && f.sort.key !== '#') {
        const dir = f.sort.direction === 'asc' ? 1 : -1;
        rows = rows.slice().sort((a, b) => {
            const va = cell.value(a);
            const vb = cell.value(b);
            if (va === null && vb === null) return a._order - b._order;
            if (va === null) return 1;
            if (vb === null) return -1;
            const cmp = typeof va === 'number' && typeof vb === 'number' ? va - vb : String(va).localeCompare(String(vb), 'th', { numeric: true });
            return cmp === 0 ? a._order - b._order : cmp * dir;
        });
    } else if (f.sort.direction === 'desc') {
        rows = rows.slice().reverse();
    }
    f.shown = rows;
    creditNoteFillRenderToolbar();
    creditNoteFillRender();
}

function creditNoteFillRender() {
    const f = creditNoteFill;
    creditNoteCancelRender(f.render);
    if (creditNoteTabs.active !== 'fill') {
        creditNoteParkTable('credit-note-fill-tbody', f.render);
        return;
    }
    f.render.parked = false;
    const tbody = document.getElementById('credit-note-fill-tbody');
    if (f.rows.length === 0) {
        tbody.innerHTML = creditNotePlaceholderHtml(CN_FILL_COLUMNS.length);
        return;
    }
    if (f.shown.length === 0) {
        tbody.innerHTML = `<tr><td colspan="${CN_FILL_COLUMNS.length}" class="text-center text-muted py-4">ไม่มีแถวที่ตรงกับตัวกรองหรือคำค้นหา</td></tr>`;
        return;
    }
    tbody.innerHTML = '';
    creditNoteAppendRows(tbody, f.shown, 0, creditNoteFillRowHtml, f.render);
}

function creditNoteFillRenderToolbar() {
    const f = creditNoteFill;
    const counts = { ALL: f.rows.length, MATCH: 0, MISMATCH: 0, PENDING: 0 };
    f.rows.forEach(row => counts[creditNoteFillStatus(row).filter]++);
    document.querySelectorAll('[data-cn-fill-count]').forEach(el => {
        el.textContent = (counts[el.dataset.cnFillCount] || 0).toLocaleString();
    });
    document.querySelectorAll('#credit-note-fill-filters .recon-filter-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.filter === f.filter);
    });

    const hasRows = f.rows.length > 0;
    document.getElementById('credit-note-fill-section').dataset.step = hasRows ? 'fill' : 'select';
    document.getElementById('credit-note-fill-read').disabled = !hasRows;
    document.getElementById('credit-note-fill-export').disabled = !hasRows || counts.PENDING === counts.ALL;
    document.getElementById('credit-note-fill-export-missing').disabled = counts.MISMATCH === 0;

    const pill = document.getElementById('credit-note-fill-file-pill');
    const names = f.files.map(file => `${file.file_name} (${file.sheets.map(sheet => sheet.name).join(', ')})`);
    const text = f.files.length === 0 ? 'ยังไม่ได้นำเข้าไฟล์ ASN'
        : (f.files.length === 1 ? f.files[0].file_name : `${f.files.length} ไฟล์`) + ` · ${f.sheets.size.toLocaleString()} ชีต · ${f.rows.length.toLocaleString()} แถว`;
    document.getElementById('credit-note-fill-file-text').textContent = text;
    pill.title = names.length ? names.join('\n') : 'ยังไม่ได้นำเข้าไฟล์ ASN';

    const missing = f.summary ? f.summary.missing : [];
    document.getElementById('credit-note-fill-missing').classList.toggle('d-none', missing.length === 0);
    document.getElementById('credit-note-fill-missing-docs').textContent = missing.length.toLocaleString();
    document.getElementById('credit-note-fill-missing-rows').textContent = counts.MISMATCH.toLocaleString();
    document.getElementById('credit-note-fill-missing-list').textContent = missing.slice(0, CN_FILL_SAMPLE)
        .map(doc => `${doc.doc_no} (${doc.rows.toLocaleString()} แถว)`).join(', ') + (missing.length > CN_FILL_SAMPLE ? ` และอีก ${(missing.length - CN_FILL_SAMPLE).toLocaleString()} ใบ` : '');
}

function creditNoteFillSetFilter(filter) {
    creditNoteFill.filter = filter;
    creditNoteFillApplyView();
}

function creditNoteFillSortBy(th) {
    const sort = creditNoteFill.sort;
    sort.direction = sort.key === th.dataset.col && sort.direction === 'asc' ? 'desc' : 'asc';
    sort.key = th.dataset.col;
    creditNoteMarkSorted('#credit-note-fill-table', th, sort.direction);
    creditNoteFillApplyView();
}

// ---- Import ----

async function creditNoteFillChoose(event) {
    const file = (event.target.files || [])[0];
    event.target.value = '';
    if (!file) return;
    try {
        showLoadingOverlay('กำลังอ่านไฟล์ ASN...', file.name);
        await ensureXlsxLoaded();
        const buffer = new Uint8Array(await file.arrayBuffer());
        if (buffer[0] !== 0x50 || buffer[1] !== 0x4B) throw new Error(`${file.name} ไม่ใช่ไฟล์ .xlsx (ไฟล์ .xls ให้เปิดใน Excel แล้วบันทึกเป็น .xlsx ก่อน)`);
        const sheets = creditNoteFillReadWorkbook(buffer);
        hideLoadingOverlay();
        if (!sheets.some(sheet => sheet.lines.length > 0)) {
            creditNoteShowNotice({ tone: 'warning', title: 'ไม่พบชีตที่มีข้อมูล ASN', message: `ไม่มีชีตใดใน ${file.name} ที่มีคอลัมน์เลขใบลดหนี้ (Invoice No / CN No) และบาร์โค้ด (EAN/UPC) พร้อมแถวข้อมูล` });
            return;
        }
        creditNoteFillOpenImport(file, sheets);
    } catch (err) {
        console.error(err);
        creditNoteShowNotice({ tone: 'error', title: 'อ่านไฟล์ ASN ไม่ได้', message: err.message });
    }
}

function creditNoteFillOpenImport(file, sheets) {
    const earlier = creditNoteFill.files.find(f => f.file_name === file.name);
    const earlierNames = new Set(earlier ? earlier.sheets.map(sheet => sheet.name) : []);
    const firstVisible = sheets.find(sheet => sheet.lines.length > 0 && !sheet.hidden) || sheets.find(sheet => sheet.lines.length > 0);
    creditNoteFill.pending = { file, sheets };

    document.getElementById('creditNoteFillImportTitle').textContent = file.name;
    document.getElementById('creditNoteFillImportReplace').classList.toggle('d-none', !earlier);
    document.getElementById('creditNoteFillImportList').innerHTML = sheets.map((sheet, i) => {
        const usable = sheet.lines.length > 0;
        const checked = usable && (earlier ? earlierNames.has(sheet.name) : sheet === firstVisible);
        const docs = usable ? new Set(sheet.lines.map(line => creditNoteDocKey(line.doc_no))).size : 0;
        const meta = usable
            ? `${sheet.lines.length.toLocaleString()} แถว · ${docs.toLocaleString()} ใบ · ${sheet.headers.length.toLocaleString()} คอลัมน์`
            : (sheet.detected ? 'ไม่มีแถวข้อมูล' : 'ไม่มีคอลัมน์เลขใบลดหนี้/บาร์โค้ด');
        return `<label class="list-group-item${usable ? '' : ' is-disabled'}">
            <input class="form-check-input m-0" type="checkbox" data-sheet-index="${i}"${checked ? ' checked' : ''}${usable ? '' : ' disabled'}>
            <span class="fw-medium">${escapeHtml(sheet.name)}</span>
            ${sheet.hidden ? '<span class="badge text-bg-light border">ชีตซ่อน</span>' : ''}
            <span class="cn-fill-sheet-meta tabular-num">${meta}</span>
        </label>`;
    }).join('');
    creditNoteFillImportCount();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('creditNoteFillImportModal')).show();
}

function creditNoteFillImportBoxes() {
    return Array.from(document.querySelectorAll('#creditNoteFillImportList input[data-sheet-index]:not(:disabled)'));
}

function creditNoteFillImportCount() {
    const boxes = creditNoteFillImportBoxes();
    const picked = boxes.filter(box => box.checked).length;
    document.getElementById('creditNoteFillImportCount').textContent = picked.toLocaleString();
    document.getElementById('creditNoteFillImportConfirm').disabled = picked === 0;
    const all = document.getElementById('creditNoteFillImportAll');
    all.checked = boxes.length > 0 && picked === boxes.length;
    all.indeterminate = picked > 0 && picked < boxes.length;
}

async function creditNoteFillRunImport() {
    const pending = creditNoteFill.pending;
    if (!pending) return;
    const picked = creditNoteFillImportBoxes().filter(box => box.checked).map(box => pending.sheets[Number(box.dataset.sheetIndex)]);
    if (picked.length === 0) return;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('creditNoteFillImportModal')).hide();
    showLoadingOverlay('กำลังนำเข้าไฟล์ ASN...', `${pending.file.name} (${picked.length.toLocaleString()} ชีต)`);
    try {
        const payload = picked.map(sheet => ({
            name: sheet.name, header_row: sheet.header_row, last_col: sheet.last_col, headers: sheet.headers, rows: sheet.lines
        }));
        const json = await creditNoteApi('job_cn_fill_import', { job_id: CURRENT_JOB_ID, file: pending.file, sheets: JSON.stringify(payload) });
        creditNoteFill.pending = null;
        creditNoteFill.filter = 'ALL';
        await creditNoteFillLoad();
        creditNoteShowNotice({
            tone: 'success',
            title: json.replaced ? 'นำเข้าไฟล์ ASN แทนข้อมูลเดิมแล้ว' : 'นำเข้าไฟล์ ASN แล้ว',
            message: `${json.file_name}: ${Number(json.sheets).toLocaleString()} ชีต ${Number(json.rows).toLocaleString()} แถว บันทึกลงฐานข้อมูลแล้ว ขั้นต่อไปกด "อ่านยอดจาก PDF"`
        });
    } catch (err) {
        console.error(err);
        creditNoteShowNotice({ tone: 'error', title: 'นำเข้าไฟล์ ASN ไม่สำเร็จ', message: err.message });
    }
}

// ---- Read the PDF totals ----

function creditNoteFillMissingHtml(missing) {
    if (!missing.length) return '';
    const items = missing.slice(0, CN_FILL_SAMPLE).map(doc => `<li><strong class="tabular-num">${escapeHtml(doc.doc_no)}</strong> ${doc.rows.toLocaleString()} แถว`
        + `<span class="text-muted"> · ${escapeHtml(doc.where.join(', '))}${doc.note !== CN_FILL_MISSING_TEXT ? ' · ' + escapeHtml(doc.note) : ''}</span></li>`).join('');
    const more = missing.length > CN_FILL_SAMPLE ? `<li class="text-muted">และอีก ${(missing.length - CN_FILL_SAMPLE).toLocaleString()} ใบ (ดูทั้งหมดใน Export ไม่พบใน PDF)</li>` : '';
    return `<section class="cn-notice-section">
        <div class="cn-notice-head text-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i>ไม่พบใน PDF <span class="tabular-num">${missing.length.toLocaleString()}</span> ใบ</div>
        <ul class="cn-notice-list">${items}${more}</ul>
    </section>`;
}

async function creditNoteFillReadPdf() {
    if (creditNoteFill.rows.length === 0) return;
    showLoadingOverlay('กำลังอ่านยอดจาก PDF...', 'จับคู่เลขใบลดหนี้ของทุกแถวกับใบลดหนี้ PDF ที่ดึงข้อมูลไว้');
    try {
        const json = await creditNoteApi('job_cn_fill_pdf', { job_id: CURRENT_JOB_ID });
        creditNoteFillSetData(json);
        const summary = json.summary;
        const missing = summary.missing;
        const lines = summary.lines;
        const docs = summary.documents;
        if (missing.length) creditNoteFillSetFilter('MISMATCH');
        creditNoteShowNotice({
            tone: missing.length === 0 ? 'success' : (lines.FOUND > 0 ? 'warning' : 'error'),
            title: missing.length === 0 ? 'อ่านยอดจาก PDF ครบทุกใบ' : `ไม่พบใน PDF ${missing.length.toLocaleString()} ใบ`,
            message: `ใส่ยอดแล้ว ${docs.FOUND.toLocaleString()} ใบ (${lines.FOUND.toLocaleString()} แถว)`
                + (missing.length ? ` ไม่พบใน PDF ${docs.MISSING.toLocaleString()} ใบ (${lines.MISSING.toLocaleString()} แถว)` : '')
                + ` จากใบลดหนี้ PDF ${Number(json.pdf_documents || 0).toLocaleString()} ใบที่ดึงข้อมูลไว้`
                + (json.pdf_documents ? '' : ' (ยังไม่ได้ดึงข้อมูล PDF ในแท็บ 1)'),
            detailHtml: creditNoteFillMissingHtml(missing),
            action: missing.length
                ? { label: '<i class="fa-solid fa-file-excel me-1"></i> Export ไม่พบใน PDF (.xlsx)', onClick: creditNoteFillExportMissing }
                : null
        });
    } catch (err) {
        console.error(err);
        creditNoteShowNotice({ tone: 'error', title: 'อ่านยอดจาก PDF ไม่สำเร็จ', message: err.message });
    }
}

// ---- Exports ----

const CN_FILL_MISSING_LABELS = ['ไฟล์', 'ชีต', 'แถวที่', 'เลขใบลดหนี้', 'บาร์โค้ด', 'หมายเหตุ'];

async function creditNoteFillExportMissing() {
    const rows = creditNoteFill.rows.filter(row => row.pdf_status === 'MISSING').map(row => {
        const sheet = creditNoteFillSheetOf(row);
        const values = [sheet ? sheet.file.file_name : '', sheet ? sheet.name : '', row.row_no, row.doc_no, row.barcode, creditNoteFillRemark(row)];
        return Object.fromEntries(CN_FILL_MISSING_LABELS.map((label, i) => [label, values[i]]));
    });
    if (rows.length === 0) return;
    try {
        await creditNoteWriteExcel(rows, CN_FILL_MISSING_LABELS, [], 'ไม่พบใน PDF',
            `Job_${CURRENT_JOB_ID}_${CN_JOB_NAME_CLEAN}_ASN_ไม่พบใน_PDF_${new Date().toISOString().slice(0, 10)}.xlsx`);
    } catch (err) {
        console.error(err);
        creditNoteShowNotice({ tone: 'error', title: 'Export ไม่สำเร็จ', message: err.message });
    }
}

// What to write in one kept sheet: the two new columns after its last column, the figures of the lines read from
// the PDF and the lines not found (a line not read yet stays empty)
function creditNoteFillPlanFromRows(sheet, rows) {
    return {
        headerRow: sheet.header_row,
        columns: [
            { col: sheet.last_col + 1, header: CN_FILL_HEADERS.cost, field: 'cost' },
            { col: sheet.last_col + 2, header: CN_FILL_HEADERS.vat, field: 'vat' }
        ],
        cells: rows.filter(row => row.pdf_status === 'FOUND').map(row => ({ row: row.row_no, cost: row.pdf_cost || 0, vat: row.pdf_vat || 0 })),
        missingRows: rows.filter(row => row.pdf_status === 'MISSING').map(row => ({ row: row.row_no }))
    };
}

async function creditNoteFillExportFile(file) {
    const res = await fetch(`cn_asn_file.php?job_id=${CURRENT_JOB_ID}&file_id=${file.id}`);
    if (!res.ok) throw new Error(await res.text() || `โหลดไฟล์ ${file.file_name} ไม่ได้ (HTTP ${res.status})`);
    const buffer = new Uint8Array(await res.arrayBuffer());
    const cfb = XLSX.CFB.read(buffer, { type: 'buffer' });
    const paths = creditNoteZipSheetPaths(cfb);
    const styles = creditNoteZipAddStyles(cfb);
    let formulaRemoved = false;
    file.sheets.forEach(sheet => {
        if (!paths[sheet.name]) throw new Error(`ไม่พบชีต ${sheet.name} ในไฟล์ ${file.file_name}`);
        const plan = creditNoteFillPlanFromRows(sheet, creditNoteFill.rows.filter(row => row.sheet_id === sheet.id));
        formulaRemoved = creditNoteFillPatchSheet(cfb, paths[sheet.name], plan, styles) || formulaRemoved;
    });
    if (formulaRemoved) creditNoteZipDropCalcChain(cfb);
    const out = XLSX.CFB.write(cfb, { type: 'base64', fileType: 'zip', compression: true });
    creditNoteDownloadBytes(out, file.file_name.replace(/\.xlsx$/i, '') + '_PDF_Amount.xlsx');
}

async function creditNoteFillExport() {
    const files = creditNoteFill.files;
    if (files.length === 0) return;
    const failed = [];
    try {
        await ensureXlsxLoaded();
        for (let i = 0; i < files.length; i++) {
            showLoadingOverlay('กำลังสร้างไฟล์ ASN พร้อมยอดจาก PDF...', `${files[i].file_name} (${i + 1}/${files.length})`);
            try {
                await creditNoteFillExportFile(files[i]);
            } catch (err) {
                console.error(err);
                failed.push(`${files[i].file_name}: ${err.message}`);
            }
        }
    } finally {
        hideLoadingOverlay();
    }
    const pending = creditNoteFill.rows.filter(row => !row.pdf_status).length;
    if (failed.length || pending) {
        creditNoteShowNotice({
            tone: failed.length ? 'error' : 'warning',
            title: failed.length ? 'Export ไฟล์ ASN ไม่ครบ' : 'Export ไฟล์ ASN แล้ว',
            message: (failed.length ? failed.join(' / ') : `ดาวน์โหลด ${files.length.toLocaleString()} ไฟล์ (ชื่อลงท้าย _PDF_Amount.xlsx)`)
                + (pending ? ` มี ${pending.toLocaleString()} แถวที่ยังไม่ได้อ่านยอดจาก PDF ช่องของแถวเหล่านี้ว่าง` : '')
        });
    }
}

// ---- Clear ----

function creditNoteFillOpenClear() {
    const f = creditNoteFill;
    if (f.files.length === 0) {
        creditNoteShowNotice({ tone: 'info', title: 'ล้างข้อมูล', message: 'ยังไม่ได้นำเข้าไฟล์ ASN ในแท็บนี้' });
        return;
    }
    creditNoteShowNotice({
        tone: 'warning',
        title: 'ล้างข้อมูลใส่ยอด PDF ลง ASN',
        message: `ลบไฟล์ ASN ${f.files.length.toLocaleString()} ไฟล์ (${f.rows.length.toLocaleString()} แถว) และยอดที่อ่านจาก PDF ออกจากแท็บนี้ ใบลดหนี้ PDF และข้อมูลในแท็บ 2 ไม่ถูกลบ`,
        action: { label: '<i class="fa-solid fa-trash-can me-1"></i> ล้างข้อมูล', onClick: creditNoteFillConfirmClear }
    });
}

// The notice that asked is still closing when its action runs; the next notice waits for it, or Bootstrap drops it
function creditNoteFillNoticeClosed() {
    const modal = document.getElementById('creditNoteNoticeModal');
    if (!modal.classList.contains('show')) return Promise.resolve();
    return new Promise(resolve => modal.addEventListener('hidden.bs.modal', () => resolve(), { once: true }));
}

async function creditNoteFillConfirmClear() {
    await creditNoteFillNoticeClosed();
    try {
        const json = await creditNoteApi('job_cn_fill_clear', { job_id: CURRENT_JOB_ID });
        creditNoteFill.filter = 'ALL';
        await creditNoteFillLoad();
        creditNoteShowNotice({ tone: 'success', title: 'ล้างข้อมูลแล้ว', message: `ลบไฟล์ ASN ${Number(json.files).toLocaleString()} ไฟล์ ${Number(json.rows).toLocaleString()} แถว` });
    } catch (err) {
        creditNoteShowNotice({ tone: 'error', title: 'ล้างข้อมูลไม่สำเร็จ', message: err.message });
    }
}

// ---- The tab ----

async function creditNoteFillEnter() {
    if (!creditNoteFill.loaded) {
        await creditNoteFillLoad().catch(err => creditNoteLoadError('credit-note-fill-tbody', CN_FILL_COLUMNS.length, err));
    } else if (creditNoteFill.render.parked) {
        creditNoteFillRender();
    }
}

function creditNoteFillInit() {
    document.getElementById('credit-note-fill-choose').addEventListener('click', () => document.getElementById('credit-note-fill-file').click());
    document.getElementById('credit-note-fill-file').addEventListener('change', creditNoteFillChoose);
    document.getElementById('creditNoteFillImportList').addEventListener('change', creditNoteFillImportCount);
    document.getElementById('creditNoteFillImportAll').addEventListener('change', e => {
        creditNoteFillImportBoxes().forEach(box => { box.checked = e.target.checked; });
        creditNoteFillImportCount();
    });
    document.getElementById('creditNoteFillImportConfirm').addEventListener('click', creditNoteFillRunImport);
    document.getElementById('credit-note-fill-read').addEventListener('click', creditNoteFillReadPdf);
    document.getElementById('credit-note-fill-export').addEventListener('click', creditNoteFillExport);
    document.getElementById('credit-note-fill-export-missing').addEventListener('click', creditNoteFillExportMissing);
    document.getElementById('credit-note-fill-clear').addEventListener('click', creditNoteFillOpenClear);
    document.getElementById('credit-note-fill-missing-show').addEventListener('click', () => creditNoteFillSetFilter('MISMATCH'));
    document.querySelectorAll('#credit-note-fill-filters .recon-filter-btn').forEach(btn => {
        btn.addEventListener('click', () => creditNoteFillSetFilter(btn.dataset.filter));
    });
    creditNoteWireSearch('credit-note-fill-search', 'credit-note-fill-clear-search', query => {
        creditNoteFill.query = query;
        creditNoteFillApplyView();
    });
    document.querySelectorAll('#credit-note-fill-table thead th').forEach(th => {
        th.addEventListener('click', e => {
            if (e.target.classList.contains('resizer')) return;
            creditNoteFillSortBy(th);
        });
    });
    initColumnResizers('#credit-note-fill-table');
}

// ---- The .xlsx package: read with XLSX.CFB, edited as XML with DOMParser, written back as a zip ----

const CN_XML_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
const CN_XML_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

function creditNoteZipText(cfb, path) {
    const entry = XLSX.CFB.find(cfb, path);
    return entry && entry.content ? new TextDecoder('utf-8').decode(new Uint8Array(entry.content)) : null;
}

function creditNoteZipPut(cfb, path, text) {
    const entry = XLSX.CFB.find(cfb, path);
    const bytes = new TextEncoder().encode(text);
    entry.content = bytes;
    entry.size = bytes.length;
}

function creditNoteXmlParse(text) {
    const doc = new DOMParser().parseFromString(text, 'application/xml');
    if (doc.getElementsByTagName('parsererror').length) throw new Error('อ่านโครงสร้างไฟล์ Excel ไม่ได้');
    return doc;
}

function creditNoteXmlText(doc) {
    const xml = new XMLSerializer().serializeToString(doc);
    return xml.startsWith('<?xml') ? xml : '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n' + xml;
}

function creditNoteXmlChildren(el, name) {
    return Array.from(el.childNodes).filter(node => node.nodeType === 1 && node.localName === name);
}

// The path of each sheet by name, from xl/workbook.xml and its relationships
function creditNoteZipSheetPaths(cfb) {
    const workbook = creditNoteXmlParse(creditNoteZipText(cfb, '/xl/workbook.xml'));
    const rels = creditNoteXmlParse(creditNoteZipText(cfb, '/xl/_rels/workbook.xml.rels'));
    const targets = {};
    Array.from(rels.getElementsByTagName('Relationship')).forEach(rel => {
        const target = rel.getAttribute('Target');
        targets[rel.getAttribute('Id')] = target.startsWith('/') ? target : '/xl/' + target;
    });
    const paths = {};
    Array.from(workbook.getElementsByTagNameNS(CN_XML_MAIN, 'sheet')).forEach(sheet => {
        paths[sheet.getAttribute('name')] = targets[sheet.getAttributeNS(CN_XML_REL, 'id')];
    });
    return paths;
}

// Adds the red font, the yellow fill and two cell formats (amount 0.00, header) to xl/styles.xml; their indexes
function creditNoteZipAddStyles(cfb) {
    const doc = creditNoteXmlParse(creditNoteZipText(cfb, '/xl/styles.xml'));
    const sheet = doc.documentElement;
    const make = name => doc.createElementNS(CN_XML_MAIN, name);
    const list = name => {
        let el = creditNoteXmlChildren(sheet, name)[0];
        if (!el) throw new Error(`ไฟล์ Excel ไม่มี ${name} ใน styles.xml`);
        return el;
    };
    const append = (parent, child) => {
        parent.appendChild(child);
        const count = creditNoteXmlChildren(parent, child.localName).length;
        parent.setAttribute('count', String(count));
        return count - 1;
    };

    const fonts = list('fonts');
    const base = creditNoteXmlChildren(fonts, 'font')[0];
    const font = base ? base.cloneNode(true) : make('font');
    creditNoteXmlChildren(font, 'color').forEach(el => font.removeChild(el));
    const color = make('color');
    color.setAttribute('rgb', CN_FILL_FONT_RGB);
    // The schema orders font children; color goes before name, family, charset and scheme
    const after = creditNoteXmlChildren(font, 'name')[0] || creditNoteXmlChildren(font, 'family')[0]
        || creditNoteXmlChildren(font, 'charset')[0] || creditNoteXmlChildren(font, 'scheme')[0] || null;
    font.insertBefore(color, after);
    const fontId = append(fonts, font);

    const fill = make('fill');
    const pattern = make('patternFill');
    pattern.setAttribute('patternType', 'solid');
    const fg = make('fgColor');
    fg.setAttribute('rgb', CN_FILL_FILL_RGB);
    const bg = make('bgColor');
    bg.setAttribute('indexed', '64');
    pattern.appendChild(fg);
    pattern.appendChild(bg);
    fill.appendChild(pattern);
    const fillId = append(list('fills'), fill);

    const xfs = list('cellXfs');
    const xf = (numFmtId, fill) => {
        const el = make('xf');
        [['numFmtId', numFmtId], ['fontId', fontId], ['fillId', fill], ['borderId', 0], ['xfId', 0],
            ['applyNumberFormat', 1], ['applyFont', 1], ['applyFill', 1]].forEach(([k, v]) => el.setAttribute(k, String(v)));
        return append(xfs, el);
    };
    // missing: red text with no fill, so a row not found stands apart from a filled one
    const styles = { amount: xf(2, fillId), header: xf(0, fillId), missing: xf(0, 0) };
    creditNoteZipPut(cfb, '/xl/styles.xml', creditNoteXmlText(doc));
    return styles;
}

function creditNoteColNumber(letters) {
    return letters.split('').reduce((n, ch) => n * 26 + ch.charCodeAt(0) - 64, 0) - 1;
}

// Writes the plan into one sheet XML; true when a formula was replaced by a value (the calc chain must go)
function creditNoteFillPatchSheet(cfb, path, plan, styles) {
    const doc = creditNoteXmlParse(creditNoteZipText(cfb, path));
    const make = name => doc.createElementNS(CN_XML_MAIN, name);
    const sheetData = doc.getElementsByTagNameNS(CN_XML_MAIN, 'sheetData')[0];
    let formulaRemoved = false;
    const sharedMasters = new Set();

    // Rows and cells by number, giving an r to those written without one (Excel always writes it, other tools may not)
    const rows = new Map();
    let rowNum = 0;
    creditNoteXmlChildren(sheetData, 'row').forEach(row => {
        rowNum = row.hasAttribute('r') ? Number(row.getAttribute('r')) : rowNum + 1;
        row.setAttribute('r', String(rowNum));
        rows.set(rowNum, row);
    });
    const cellCol = cell => creditNoteColNumber(/^[A-Z]+/.exec(cell.getAttribute('r'))[0]);
    const prepareCells = (row, n) => {
        let col = -1;
        creditNoteXmlChildren(row, 'c').forEach(cell => {
            col = cell.hasAttribute('r') ? cellCol(cell) : col + 1;
            if (!cell.hasAttribute('r')) cell.setAttribute('r', XLSX.utils.encode_col(col) + n);
        });
    };
    rows.forEach(prepareCells);

    const getRow = n => {
        if (rows.has(n)) return rows.get(n);
        const row = make('row');
        row.setAttribute('r', String(n));
        const before = creditNoteXmlChildren(sheetData, 'row').find(el => Number(el.getAttribute('r')) > n) || null;
        sheetData.insertBefore(row, before);
        rows.set(n, row);
        return row;
    };
    const getCell = (n, col) => {
        const row = getRow(n);
        const cells = creditNoteXmlChildren(row, 'c');
        const found = cells.find(cell => cellCol(cell) === col);
        if (found) return found;
        const cell = make('c');
        cell.setAttribute('r', XLSX.utils.encode_col(col) + n);
        row.insertBefore(cell, cells.find(el => cellCol(el) > col) || null);
        return cell;
    };
    const clear = cell => {
        creditNoteXmlChildren(cell, 'f').forEach(f => {
            formulaRemoved = true;
            if (f.getAttribute('t') === 'shared' && f.hasAttribute('ref')) sharedMasters.add(f.getAttribute('si'));
        });
        while (cell.firstChild) cell.removeChild(cell.firstChild);
        cell.removeAttribute('t');
        cell.removeAttribute('cm');
        cell.removeAttribute('vm');
    };
    const setNumber = (n, col, value) => {
        const cell = getCell(n, col);
        clear(cell);
        cell.setAttribute('s', String(styles.amount));
        const v = make('v');
        v.textContent = String(Math.round(value * 100) / 100);
        cell.appendChild(v);
    };
    const setText = (n, col, text, style) => {
        const cell = getCell(n, col);
        cell.setAttribute('s', String(style));
        clear(cell);
        cell.setAttribute('t', 'inlineStr');
        const is = make('is');
        const t = make('t');
        t.textContent = text;
        is.appendChild(t);
        cell.appendChild(is);
    };

    plan.columns.forEach(column => setText(plan.headerRow, column.col, column.header, styles.header));
    plan.cells.forEach(cell => plan.columns.forEach(column => setNumber(cell.row, column.col, cell[column.field])));
    plan.missingRows.forEach(missing => setText(missing.row, plan.columns[0].col, CN_FILL_MISSING_TEXT, styles.missing));

    // A cell that took over from a shared formula master leaves its followers without a formula to copy: they keep
    // their last value instead
    if (sharedMasters.size) {
        Array.from(doc.getElementsByTagNameNS(CN_XML_MAIN, 'f')).forEach(f => {
            if (f.getAttribute('t') === 'shared' && !f.hasAttribute('ref') && sharedMasters.has(f.getAttribute('si'))) {
                f.parentNode.removeChild(f);
            }
        });
    }

    // The used range grows by the appended columns
    const dimension = doc.getElementsByTagNameNS(CN_XML_MAIN, 'dimension')[0];
    if (dimension) {
        const range = XLSX.utils.decode_range(dimension.getAttribute('ref'));
        const lastCol = Math.max(...plan.columns.map(column => column.col));
        const lastRow = Math.max(plan.headerRow, ...plan.cells.map(cell => cell.row)) - 1;
        range.e.c = Math.max(range.e.c, lastCol);
        range.e.r = Math.max(range.e.r, lastRow);
        dimension.setAttribute('ref', XLSX.utils.encode_range(range));
    }

    creditNoteZipPut(cfb, path, creditNoteXmlText(doc));
    return formulaRemoved;
}

// Excel rebuilds the calculation chain when it is missing; one that names a cell no longer holding a formula makes
// Excel repair the file
function creditNoteZipDropCalcChain(cfb) {
    if (!XLSX.CFB.find(cfb, '/xl/calcChain.xml')) return;
    XLSX.CFB.utils.cfb_del(cfb, '/xl/calcChain.xml');
    const types = creditNoteXmlParse(creditNoteZipText(cfb, '/[Content_Types].xml'));
    Array.from(types.getElementsByTagName('Override')).forEach(el => {
        if (el.getAttribute('PartName') === '/xl/calcChain.xml') el.parentNode.removeChild(el);
    });
    creditNoteZipPut(cfb, '/[Content_Types].xml', creditNoteXmlText(types));
    const rels = creditNoteXmlParse(creditNoteZipText(cfb, '/xl/_rels/workbook.xml.rels'));
    Array.from(rels.getElementsByTagName('Relationship')).forEach(el => {
        if (/calcChain\.xml$/.test(el.getAttribute('Target'))) el.parentNode.removeChild(el);
    });
    creditNoteZipPut(cfb, '/xl/_rels/workbook.xml.rels', creditNoteXmlText(rels));
}

function creditNoteDownloadBytes(base64, fileName) {
    const bin = atob(base64);
    const bytes = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
    const url = URL.createObjectURL(new Blob([bytes], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = fileName;
    document.body.appendChild(a);
    a.click();
    setTimeout(() => {
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }, 500);
}
