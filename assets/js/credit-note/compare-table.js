// The rows of the compare grid (tab 2): statuses, remarks, cells, search, sort and Excel. Nothing here touches the
// DOM, so tests/credit_note_compare.js runs it in Node.

// What a status looks like in the compare grid and the overview table. tone names the colour (app.css defines each
// once as --status-<tone>-color): the badge is status-<tone>, every remark line remark-<tone>. rank orders the Status
// sort, the worst first.
const CN_STATUS_DISPLAY = {
    MISMATCH: { label: 'ผิดพลาด', icon: 'fa-triangle-exclamation', tone: 'mismatch', rank: 0 },
    MATCH: { label: 'สมบูรณ์', icon: 'fa-check', tone: 'match', rank: 1 },
    PENDING: { label: 'รอใส่ยอด PDF', icon: 'fa-clock', tone: 'waiting', rank: 2 }
};

const CN_REMARK_LABELS = { problem: 'ปัญหา : ', solution: 'วิธีแก้ไข : ' };

// A wrong line names its problems on one red line, a complete one says so, a line waiting for the compare says what
// to do (the overview table leaves the button out: it sits on the compare tab)
const CN_REMARKS = {
    MISMATCH: problem => ({ labelled: true, problem }),
    MATCH: () => ({ labelled: false, problem: 'ข้อมูลถูกต้อง' }),
    PENDING: (problem, withSolution) => ({
        labelled: true,
        problem: 'ยังไม่ได้ใส่ยอดจาก PDF ลงไฟล์ ASN',
        solution: withSolution ? 'ไปที่แท็บ "2. ใส่ยอด PDF ลง ASN" นำเข้าไฟล์ ASN แล้วกด "อ่านยอดจาก PDF"' : ''
    })
};

function creditNoteRemarkLines(status, problem, withSolution) {
    const display = CN_STATUS_DISPLAY[status];
    if (!display) return [];
    const parts = CN_REMARKS[status](problem, withSolution);
    return ['problem', 'solution'].filter(part => parts[part])
        .map(part => ({ part, label: parts.labelled ? CN_REMARK_LABELS[part] : '', text: parts[part], className: `remark-${display.tone}` }));
}

function creditNoteRemarkHtml(lines) {
    return lines.map(line => `<div class="${line.className}">${line.label ? `<span class="remark-label">${escapeHtml(line.label)}</span>` : ''}${escapeHtml(line.text)}</div>`).join('');
}

function creditNoteRemarkText(lines) {
    return lines.map(line => line.label + line.text).join('\n');
}

function creditNoteStatusBadge(status) {
    const s = CN_STATUS_DISPLAY[status];
    if (!s) return '';
    return `<span class="pill-badge status-${s.tone}"><i class="fa-solid ${s.icon} me-1"></i>${s.label}</span>`;
}

// The two bases of the money compare (decision 3 of the contract): which PDF total stands against the ASN amount
const CN_AMOUNT_BASIS = {
    cost: { pdfKey: 'doc_excl_vat', pdfName: 'Total Amt Excluding VAT ของใบลดหนี้', asnName: 'Total Cost Ex VAT ของใบลดหนี้ใน ASN', excel: 'Total Cost Ex VAT', hasVat: true },
    retail: { pdfKey: 'doc_total_amount', pdfName: 'Total Amount ของใบลดหนี้', asnName: 'ผลรวม Quantity × Unit Ret ของใบลดหนี้ใน ASN', excel: 'Quantity x Unit Ret', hasVat: false }
};
const CN_NO_ASN_VAT = 'ชีตนี้ไม่มีคอลัมน์ Total Cost Ex VAT จึงไม่มี VAT ใน ASN และไม่เทียบ VAT';
const CN_NULL_CELL = '<span class="text-null-subtle">–</span>';

function creditNoteBasis(row) {
    return CN_AMOUNT_BASIS[row.amount_kind] || null;
}

function creditNotePdfAmount(row) {
    const basis = creditNoteBasis(row);
    return row.pdf && basis ? creditNoteAmount(row.pdf[basis.pdfKey]) : null;
}

function creditNoteAsnVat(row) {
    const basis = creditNoteBasis(row);
    return row.asn && basis && basis.hasVat ? creditNoteAmount(row.asn.total_vat) : null;
}

function creditNoteText(value) {
    return creditNoteIsBlank(value) ? null : String(value);
}

// One line per row: a long file name loses its middle, never the end that tells files apart
const CN_SOURCE_FILE_TAIL = 16;

function creditNoteSourceFileHtml(files) {
    if (!files || files.length === 0) return CN_NULL_CELL;
    const name = files[0];
    const more = files.length > 1 ? `<span class="source-file-more">+${files.length - 1} ไฟล์</span>` : '';
    return `<span class="source-file" title="${escapeHtml(files.join('\n'))}"><span class="source-file-name">`
        + `<span class="source-file-head">${escapeHtml(name.slice(0, -CN_SOURCE_FILE_TAIL))}</span>`
        + `<span class="source-file-tail">${escapeHtml(name.slice(-CN_SOURCE_FILE_TAIL))}</span></span>${more}</span>`;
}

function creditNoteMoneyHtml(value, className, title) {
    if (value === null) return CN_NULL_CELL;
    return `<span class="${className}"${title ? ` title="${escapeHtml(title)}"` : ''}>${formatMoney(value)}</span>`;
}

function creditNoteMatchClass(ok) {
    return ok === false ? 'cell-mismatch' : (ok === true ? 'cell-match' : '');
}

// The cells of the compare grid by column key (CN_COMPARE_COLUMNS in credit_note.php puts them in order). value(row)
// is what the column sorts on (null sorts last), and what search and Excel use unless the cell says otherwise.
const CN_COMPARE_CELLS = {
    '#': {
        value: r => r._order,
        html: (r, n) => `<td class="text-center tabular-num text-muted">${n}</td>`,
        excel: (r, n, col) => ({ [col.label]: n })
    },
    status: {
        value: r => (CN_STATUS_DISPLAY[r.status] || CN_STATUS_DISPLAY.MISMATCH).rank,
        html: r => `<td class="text-center">${creditNoteStatusBadge(r.status)}</td>`,
        excel: (r, n, col) => ({ [col.label]: (CN_STATUS_DISPLAY[r.status] || { label: r.status }).label })
    },
    doc_no: {
        value: r => creditNoteText((r.pdf && r.pdf.doc_no) || (r.asn && r.asn.doc_no)),
        html: r => `<td class="tabular-num fw-semibold text-dark">${escapeHtml(CN_COMPARE_CELLS.doc_no.value(r)) || CN_NULL_CELL}</td>`,
        search: true
    },
    source_file: {
        value: r => creditNoteText((r.source_files || [])[0]),
        html: r => `<td>${creditNoteSourceFileHtml(r.source_files)}</td>`,
        search: r => (r.source_files || []).join(' '),
        excel: (r, n, col) => ({ [col.label]: (r.source_files || []).join(', ') || null })
    },
    barcode: {
        value: r => creditNoteText((r.pdf && r.pdf.barcode) || (r.asn && r.asn.ean_upc)),
        html: r => `<td class="tabular-num text-primary fw-bold">${escapeHtml(CN_COMPARE_CELLS.barcode.value(r)) || CN_NULL_CELL}</td>`,
        search: true
    },
    description: {
        value: r => creditNoteText(r.pdf && r.pdf.description),
        html: r => {
            const text = escapeHtml(CN_COMPARE_CELLS.description.value(r));
            return `<td title="${text}">${text || CN_NULL_CELL}</td>`;
        },
        search: true
    },
    pdf_store: {
        value: r => creditNoteText(r.pdf && r.pdf.store_no),
        html: r => `<td class="text-center tabular-num">${escapeHtml(CN_COMPARE_CELLS.pdf_store.value(r)) || CN_NULL_CELL}</td>`,
        search: true
    },
    asn_store: {
        value: r => creditNoteText(r.asn && r.asn.dest_store_no),
        html: r => `<td class="text-center tabular-num">${escapeHtml(CN_COMPARE_CELLS.asn_store.value(r)) || CN_NULL_CELL}</td>`,
        search: true
    },
    pdf_qty: {
        value: r => r.pdf ? creditNoteAmount(r.pdf.units) : null,
        html: r => {
            const qty = CN_COMPARE_CELLS.pdf_qty.value(r);
            return `<td class="text-center tabular-num fw-bold">${qty === null ? CN_NULL_CELL : qty.toLocaleString()}</td>`;
        }
    },
    asn_qty: {
        value: r => r.asn ? creditNoteAmount(r.asn.quantity) : null,
        html: r => {
            const qty = CN_COMPARE_CELLS.asn_qty.value(r);
            if (qty === null) return `<td class="text-center tabular-num fw-bold">${CN_NULL_CELL}</td>`;
            const pdfQty = CN_COMPARE_CELLS.pdf_qty.value(r);
            const tone = pdfQty === null ? '' : creditNoteMatchClass(pdfQty === qty);
            const rows = r.asn.row_count > 1 ? ` <small class="text-muted fw-normal">(${Number(r.asn.row_count).toLocaleString()} แถว)</small>` : '';
            return `<td class="text-center tabular-num fw-bold"><span class="${tone}">${qty.toLocaleString()}${rows}</span></td>`;
        }
    },
    pdf_amount: {
        value: creditNotePdfAmount,
        html: r => {
            const basis = creditNoteBasis(r);
            return `<td class="text-end tabular-num fw-semibold text-dark">${creditNoteMoneyHtml(creditNotePdfAmount(r), '', basis && basis.pdfName)}</td>`;
        },
        search: true
    },
    asn_amount: {
        value: r => r.asn ? creditNoteAmount(r.asn.doc_amount) : null,
        html: r => {
            const basis = creditNoteBasis(r);
            return `<td class="text-end tabular-num fw-semibold">${creditNoteMoneyHtml(CN_COMPARE_CELLS.asn_amount.value(r), creditNoteMatchClass(r.amount_ok), basis && basis.asnName)}</td>`;
        },
        search: true,
        excel: (r, n, col) => {
            const basis = creditNoteBasis(r);
            return { [col.label]: CN_COMPARE_CELLS.asn_amount.value(r), 'Amount Basis': basis ? basis.excel : null };
        }
    },
    vat: {
        value: r => r.pdf ? creditNoteAmount(r.pdf.doc_vat) : null,
        html: r => {
            const pdfVat = CN_COMPARE_CELLS.vat.value(r);
            const asnVat = creditNoteAsnVat(r);
            const basis = creditNoteBasis(r);
            const open = `<td class="text-end tabular-num small text-secondary"${basis && !basis.hasVat ? ` title="${escapeHtml(CN_NO_ASN_VAT)}"` : ''}>`;
            if (pdfVat === null && asnVat === null) return `${open}${CN_NULL_CELL}</td>`;
            const text = `${pdfVat === null ? '–' : formatMoney(pdfVat)} / ${asnVat === null ? '–' : formatMoney(asnVat)}`;
            return `${open}<span class="${r.vat_ok === false ? 'cell-mismatch' : ''}">${text}</span></td>`;
        },
        search: r => [creditNoteMoneySearch(CN_COMPARE_CELLS.vat.value(r)), creditNoteMoneySearch(creditNoteAsnVat(r))].join(' '),
        excel: r => ({ 'VAT (PDF)': CN_COMPARE_CELLS.vat.value(r), 'VAT (ASN)': creditNoteAsnVat(r) })
    },
    remark: {
        value: r => creditNoteRemarkText(r._remark) || null,
        html: r => {
            const tone = r._remark.some(line => line.label) ? '' : 'fw-medium';
            return `<td class="asn-remark ${tone}">${r._remark.length ? creditNoteRemarkHtml(r._remark) : CN_NULL_CELL}</td>`;
        },
        search: true
    }
};

const CN_COMPARE_MONEY = ['Amount (PDF)', 'Amount (ASN)', 'VAT (PDF)', 'VAT (ASN)'];

function creditNoteCompareRowHtml(row, rowNum) {
    return `<tr>${CN_COMPARE_COLUMNS.map(col => CN_COMPARE_CELLS[col.key].html(row, rowNum)).join('')}</tr>`;
}

function creditNoteCompareSearchText(row) {
    return CN_COMPARE_COLUMNS.map(col => {
        const cell = CN_COMPARE_CELLS[col.key];
        if (!cell.search) return '';
        if (typeof cell.search === 'function') return cell.search(row);
        const value = cell.value(row);
        return col.type === 'currency' ? creditNoteMoneySearch(value) : (value === null ? '' : String(value));
    }).filter(Boolean).join(' ').toLowerCase();
}

// _order keeps the server's order for #, _remark the remark lines, _search what the search box matches
function creditNoteComparePrepare(row, order) {
    row._order = order;
    row._remark = creditNoteRemarkLines(row.status, row.reason, true);
    row._search = creditNoteCompareSearchText(row);
    return row;
}

function creditNoteCompareVisibleRows(rows, filter, query, sort) {
    const words = creditNoteSearchWords(query);
    const cell = CN_COMPARE_CELLS[sort.key] || CN_COMPARE_CELLS['#'];
    return rows.filter(r => (filter === 'ALL' || r.status === filter) && words.every(word => r._search.includes(word)))
        .sort(creditNoteOrderBy(cell.value, sort.direction));
}

function creditNoteCompareCounts(rows) {
    const counts = { ALL: rows.length, MATCH: 0, MISMATCH: 0, PENDING: 0 };
    rows.forEach(r => {
        counts[r.status] = (counts[r.status] || 0) + 1;
    });
    return counts;
}

// The key both sides match on: letters and digits only, so 93251539-1 and 932515391 are one credit note
function creditNoteDocKey(no) {
    return String(no || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
}

// ASN lines before the compare, one grid row each, shaped like a compared row. The document amount follows its basis
// as the compare takes it: a cost sheet repeats the document's Total Cost Ex VAT on every line (the first positive
// one counts), a retail sheet sums quantity x Unit Ret over the document's lines.
function creditNotePendingRows(records) {
    const amounts = {};
    records.forEach(r => {
        const key = creditNoteDocKey(r.invoice_no);
        const amount = amounts[key] || 0;
        amounts[key] = r.amount_kind === 'retail'
            ? amount + (Number(r.quantity) || 0) * (Number(r.selling_price) || 0)
            : (amount > 0 ? amount : Math.max(0, Number(r.total_cost_ex_vat) || 0));
    });
    return records.map(r => ({
        status: 'PENDING', reason: '', action: '', amount_ok: null, vat_ok: null,
        amount_kind: r.amount_kind === 'retail' ? 'retail' : 'cost',
        source_files: [],
        pdf: null,
        asn: {
            doc_no: String(r.invoice_no || ''),
            ean_upc: String(r.ean_upc || ''),
            dest_store_no: String(r.dest_store_no || ''),
            quantity: Number(r.quantity) || 0,
            row_count: 1,
            selling_price: Number(r.selling_price) || 0,
            doc_amount: Math.round(amounts[creditNoteDocKey(r.invoice_no)] * 100) / 100,
            total_vat: Number(r.total_vat) || 0
        }
    }));
}

// The imported sources of job_cn_asn_records in the order they came in, with the ids of their rows and how many
// credit notes they hold, for the clear dialog
function creditNoteAsnSources(records) {
    const bySource = new Map();
    records.forEach(r => {
        const name = String(r.source_file || '');
        if (!bySource.has(name)) bySource.set(name, { source: name, ids: [], rows: 0, docs: new Set() });
        const entry = bySource.get(name);
        entry.ids.push(Number(r.id));
        entry.rows++;
        entry.docs.add(creditNoteDocKey(r.invoice_no));
    });
    return Array.from(bySource.values()).map(entry => ({ source: entry.source, ids: entry.ids, rows: entry.rows, documents: entry.docs.size }));
}

// The ASN label of the toolbar: the one source in full, or "4 ชีต" with the server's full list on hover, so four long
// sheet names never push the toolbar to three lines
function creditNoteAsnLabel(label, counts) {
    const files = Number((counts && counts.files) || 0);
    if (!label && files === 0) return { text: 'ยังไม่ได้เลือกไฟล์ ASN', title: 'ยังไม่ได้เลือกไฟล์ ASN' };
    const full = label || `${files.toLocaleString()} ชีต`;
    return { text: files > 1 ? `${files.toLocaleString()} ชีต` : full, title: full };
}

// The money rule as the server applies it: CN_TOLERANCES carries CreditNoteReconciler::AMOUNT_TOLERANCE and
// VAT_TOLERANCE from the page, so the wording follows the server if a tolerance ever changes
function creditNoteMoneyRule(tolerance) {
    return Number(tolerance) > 0 ? `ต่างกันได้ไม่เกิน ${formatMoney(tolerance)} บาท` : 'ต้องตรงกันทุกสตางค์';
}

// select: no ASN in the Job; compare: ASN imported, not compared yet; done: compared. app.css fills the button of the
// step (#credit-note-asn-section[data-step]).
function creditNoteCompareStep(asnRows, compared) {
    if (!asnRows) return 'select';
    return compared ? 'done' : 'compare';
}

// Amounts are numbers, a missing value is an empty cell; the Amount Basis column says which ASN figure it is
function creditNoteCompareExcelRows(rows) {
    return rows.map((r, i) => Object.assign({}, ...CN_COMPARE_COLUMNS.map(col => {
        const cell = CN_COMPARE_CELLS[col.key];
        return cell.excel ? cell.excel(r, i + 1, col) : { [col.label]: cell.value(r) };
    })));
}

async function creditNoteCompareExportExcel(rows) {
    if (!rows || rows.length === 0) return;
    const sheetRows = creditNoteCompareExcelRows(rows);
    await creditNoteWriteExcel(sheetRows, Object.keys(sheetRows[0]), CN_COMPARE_MONEY, 'Credit_Note_Compare',
        `Job_${CURRENT_JOB_ID}_${CN_JOB_NAME_CLEAN}_Credit_Note_Compare.xlsx`);
}
