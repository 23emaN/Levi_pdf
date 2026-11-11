let dashboardRequestToken = 0;

const dashboardTable = {
    rows: [],
    emptyMessage: '',
    status: 'ALL',
    errorCategory: 'ALL',
    query: '',
    sort: { column: 'store', direction: 'asc' }
};

const DASH_ERROR_CATEGORIES = [
    { key: 'ALL', label: 'ทุกสาเหตุผิดพลาด', sheetName: 'ผิดพลาด-ทั้งหมด' },
    { key: 'NO_VBRP', label: 'ไม่พบใน VBRP', sheetName: 'ผิดพลาด-ไม่พบใน VBRP' },
    { key: 'AMOUNT_MISMATCH', label: 'ยอดรวมไม่ตรง', sheetName: 'ผิดพลาด-ยอดรวมไม่ตรง' },
    { key: 'PDF_MISSING', label: 'ไม่พบไฟล์ PDF / บาร์โค้ดตกหล่น', sheetName: 'ผิดพลาด-ไม่พบไฟล์ PDF' },
    { key: 'FOOTER_MISMATCH', label: 'ยอดท้ายบิลไม่ตรงรายการในบิล', sheetName: 'ผิดพลาด-ยอดท้ายบิลไม่ตรง' },
    { key: 'QTY_MISMATCH', label: 'จำนวนชิ้นไม่ตรง', sheetName: 'ผิดพลาด-จำนวนชิ้นไม่ตรง' },
    { key: 'STORE_MISMATCH', label: 'รหัสสาขาไม่ตรง', sheetName: 'ผิดพลาด-รหัสสาขาไม่ตรง' }
];

function classifyDashboardError(row) {
    if (row._status !== 'MISMATCH' || !row.verdict) {
        return { key: '', label: '' };
    }
    const prob = String(row.problem || '');
    const v = row.verdict || {};
    const noVbrp = prob.includes('ไม่พบใน VBRP') || (!row.vbrp && v.vbrp === 'na');

    if (noVbrp) {
        return { key: 'NO_VBRP', label: 'ไม่พบใน VBRP' };
    }
    if (prob.includes('ไม่พบไฟล์ PDF ของบิลนี้') || prob.includes('ไม่พบใน PDF')) {
        return { key: 'PDF_MISSING', label: 'ไม่พบไฟล์ PDF / บาร์โค้ดตกหล่น' };
    }
    if (prob.includes('รหัสสาขาไม่ตรง')) {
        return { key: 'STORE_MISMATCH', label: 'รหัสสาขาไม่ตรง' };
    }
    if (prob.includes('จำนวนไม่ตรง')) {
        return { key: 'QTY_MISMATCH', label: 'จำนวนชิ้นไม่ตรง' };
    }
    if (prob.includes('ยอดรวมท้ายบิลไม่ตรงกับรายการของบิลนี้') || prob.includes('ยอดรวมท้ายบิลเป็นของบิล')) {
        return { key: 'FOOTER_MISMATCH', label: 'ยอดท้ายบิลไม่ตรงรายการในบิล' };
    }
    return { key: 'AMOUNT_MISMATCH', label: 'ยอดรวมไม่ตรง' };
}

function prepareDashboardRow(row) {
    row._status = displayStatus(row.status, row.fixed);
    const errCat = classifyDashboardError(row);
    row._errKey = errCat.key;
    row._errLabel = errCat.label;
    row._remark = remarkLines({ solution: row.note }, false, 'FIXED')
        .concat(remarkLines({ problem: row.problem, solution: row.solution }, true, row._status), sourceNoteLines(row.notes));
    row._was = previousStatusText(row.was);
    row._search = [
        row.store_no, row.branch_code, row.invoice_no, ...(row.source_files || []), dashboardCostText(row),
        STATUS_DISPLAY[row._status].label, row._errLabel, row._was, remarkText(row._remark), row.note_detail
    ].filter(Boolean).join(' ').toLowerCase();
    return row;
}

function dashboardCostText(row) {
    return row.total_cost_ex_vat === null || row.total_cost_ex_vat === undefined ? null : formatMoney(row.total_cost_ex_vat);
}

function compareDashboardStore(a, b, sign = 1) {
    const storeA = a.store_no || '';
    const storeB = b.store_no || '';
    if (storeA === '' || storeB === '') {
        if (storeA !== storeB) return storeA === '' ? 1 : -1;
    } else {
        const byStore = thCollator.compare(storeA, storeB);
        if (byStore !== 0) return sign * byStore;
    }
    const branchA = a.branch_code || '';
    const branchB = b.branch_code || '';
    const byBranch = thCollator.compare(branchA, branchB);
    if (byBranch !== 0) return sign * byBranch;
    return thCollator.compare(a.invoice_no, b.invoice_no);
}

function dashboardSearchWords() {
    return dashboardTable.query.toLowerCase().split(/\s+/).filter(Boolean);
}

function isDashboardFiltered() {
    return dashboardTable.status !== 'ALL' || dashboardTable.errorCategory !== 'ALL' || dashboardSearchWords().length > 0;
}

function visibleDashboardRows() {
    const words = dashboardSearchWords();
    const rows = dashboardTable.rows.filter(row =>
        (dashboardTable.status === 'ALL' || row._status === dashboardTable.status)
        && (dashboardTable.status !== 'MISMATCH' || dashboardTable.errorCategory === 'ALL' || row._errKey === dashboardTable.errorCategory)
        && words.every(word => row._search.includes(word)));
    return sortDashboardRows(rows);
}

async function loadDashboard() {
    const token = ++dashboardRequestToken;
    setDashboardLoading(true);
    try {
        const res = await fetch(`api.php?action=get_job_dashboard&job_id=${CURRENT_JOB_ID}`);
        const json = await res.json();
        if (token !== dashboardRequestToken) return;
        if (json.status !== 'success') {
            throw new Error(json.message || 'โหลดภาพรวมไม่สำเร็จ');
        }
        renderDashboard(json.data);
    } catch (err) {
        if (token !== dashboardRequestToken) return;
        renderDashboard(null, err.message);
    } finally {
        if (token === dashboardRequestToken) {
            setDashboardLoading(false);
        }
    }
}

function setDashboardLoading(isLoading) {
    const section = document.getElementById('view-dashboard-section');
    const icon = document.querySelector('#btn-dashboard-refresh i');
    section.classList.toggle('is-loading', isLoading);
    document.getElementById('btn-dashboard-refresh').disabled = isLoading;
    icon.classList.toggle('fa-spin', isLoading);
}

function formatPercent(value, total) {
    if (!total) return '–';
    return (value * 100 / total).toLocaleString('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%';
}

function setDashboardBar(barId, parts, total) {
    const bar = document.getElementById(barId);
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

function setDashboardState(kind, text, iconClass) {
    const state = document.getElementById('dashboard-state');
    state.className = `dash-state is-${kind}`;
    state.innerHTML = `<i class="fa-solid ${iconClass}"></i> `;
    state.appendChild(document.createTextNode(text));
}

function renderDashboard(data, errorMessage) {
    const fields = ['total', 'complete', 'needs_fix', 'not_fixed', 'fixed', 'pending_asn', 'error'];
    const known = !!data && data.compared;
    // pending_asn comes only with VBRP rows: a compared Job without them has no bill waiting for the ASN
    const counts = data ? Object.assign({ pending_asn: known ? 0 : null }, data) : null;
    fields.forEach(field => {
        const el = document.getElementById(`dash-${field}`);
        const value = counts ? counts[field] : null;
        el.textContent = value === null || value === undefined ? '–' : value.toLocaleString();
    });

    const total = data ? data.total : 0;
    ['complete', 'needs_fix', 'pending_asn', 'error'].forEach(field => {
        document.querySelector(`[data-pct="${field}"]`).textContent = known ? formatPercent(counts[field], total) : '–';
    });

    const errorAfterFix = known ? data.error_after_fix : 0;
    const fixedRight = known ? data.fixed - errorAfterFix : 0;
    const parts = known ? { complete: data.complete, fixed: fixedRight, not_fixed: data.not_fixed, pending_asn: counts.pending_asn, error: data.error } : {};
    setDashboardBar('dash-bar', parts, total);
    setDashboardBar('dash-mini-bar', known ? { not_fixed: data.not_fixed, fixed: data.fixed } : {}, total);
    document.querySelectorAll('[data-legend]').forEach(el => {
        const value = parts[el.dataset.legend];
        el.textContent = value === undefined ? '–' : value.toLocaleString();
    });

    const strip = document.getElementById('dash-verdict-strip');
    const stripHtml = dashboardVerdictStripHtml(data ? data.verdict_counts : null);
    strip.innerHTML = stripHtml;
    strip.classList.toggle('d-none', stripHtml === '');

    const errorNote = document.getElementById('dash-error-note');
    errorNote.textContent = errorAfterFix > 0 ? `ในนี้ ${errorAfterFix.toLocaleString()} เลขผ่านการแก้ไขแล้วแต่ข้อมูลยังไม่ตรงกับ ASN` : '';
    errorNote.classList.toggle('d-none', errorAfterFix === 0);

    if (errorMessage) {
        setDashboardState('error', 'โหลดไม่สำเร็จ', 'fa-circle-exclamation');
    } else if (data && data.total === 0) {
        setDashboardState('muted', 'ยังไม่มีข้อมูล ASN', 'fa-circle-minus');
    } else if (data && !data.compared) {
        setDashboardState('warn', 'ยังไม่ได้เทียบกับ PDF', 'fa-clock');
    } else {
        setDashboardState('ok', 'เทียบกับ PDF แล้ว', 'fa-circle-check');
    }

    const notice = document.getElementById('dashboard-notice');
    let message = '';
    if (errorMessage) {
        message = errorMessage;
    } else if (data && data.total === 0) {
        message = 'ยังไม่มีข้อมูล ASN ใน Job นี้ นำเข้าไฟล์ ASN ที่หน้าเปรียบเทียบก่อน';
    } else if (data && !data.compared) {
        message = 'ยังไม่ได้เทียบกับ PDF จึงแสดงได้แค่จำนวนเลขบิลทั้งหมด ตัวเลขอื่นจะขึ้นหลังกดเทียบกับ PDF';
    }
    document.getElementById('dashboard-notice-text').textContent = message;
    document.getElementById('dashboard-notice-icon').className = errorMessage ? 'fa-solid fa-circle-exclamation' : 'fa-solid fa-circle-info text-primary';
    document.getElementById('dashboard-notice-action').classList.toggle('d-none', !!errorMessage);
    notice.classList.toggle('is-error', !!errorMessage);
    notice.classList.toggle('d-none', message === '');

    document.getElementById('dashboard-updated').textContent =
        errorMessage ? '' : 'อัปเดตล่าสุด ' + new Date().toLocaleTimeString('th-TH', { hour12: false });

    dashboardTable.rows = (data && data.invoices ? data.invoices : []).map(prepareDashboardRow).sort((a, b) => compareDashboardStore(a, b));
    dashboardTable.emptyMessage = 'ยังไม่มีผลเทียบกับ PDF ตารางจะขึ้นหลังกดเทียบกับ PDF';
    if (errorMessage) {
        dashboardTable.emptyMessage = 'โหลดข้อมูลไม่สำเร็จ';
    } else if (data && data.total === 0) {
        dashboardTable.emptyMessage = 'ยังไม่มีข้อมูล ASN';
    }
    renderDashboardTable();
}

// The PDF | ASN | VBRP columns of the table by data-col: one bill's verdict (row.verdict, the worst of its lines)
const DASH_VERDICT_COLUMNS = {
    verdict_pdf: VERDICT_SOURCES[0],
    verdict_asn: VERDICT_SOURCES[1],
    verdict_vbrp: VERDICT_SOURCES[2]
};

function dashboardColValue(row, col) {
    switch (col) {
        case 'store': return row.store_no || '';
        case 'branch_code': return row.branch_code || '';
        case 'invoice_no': return row.invoice_no;
        case 'source_file': return (row.source_files || [])[0] || '';
        case 'units': return row.units;
        case 'total_cost_ex_vat': return row.total_cost_ex_vat;
        case 'pages': return row.pages;
        case 'status': return STATUS_DISPLAY[row._status].rank;
        case 'was': return row._was;
        case 'note': return remarkText(row._remark);
        default: return DASH_VERDICT_COLUMNS[col] ? VERDICT_DISPLAY[verdictOf(row.verdict, DASH_VERDICT_COLUMNS[col].key)].rank : '';
    }
}

function sortDashboardRows(rows) {
    const { column, direction } = dashboardTable.sort;
    if (column === '#') return direction === 'asc' ? rows : rows.reverse();

    const numeric = ['units', 'total_cost_ex_vat', 'pages', 'status'].includes(column) || !!DASH_VERDICT_COLUMNS[column];
    const sign = direction === 'asc' ? 1 : -1;
    if (column === 'store') return rows.sort((a, b) => compareDashboardStore(a, b, sign));
    return rows.sort((a, b) => {
        const valA = dashboardColValue(a, column);
        const valB = dashboardColValue(b, column);
        if (numeric && (valA === null || valB === null)) {
            return valA === valB ? 0 : (valA === null ? 1 : -1);
        }
        return sign * (numeric ? valA - valB : thCollator.compare(String(valA), String(valB)));
    });
}

function dashboardSourceFilesHtml(files) {
    if (!files || files.length === 0) return dashboardDash(null);
    const shown = files.length > 2 ? files.slice(0, 1) : files;
    const names = shown.map(name => `<span class="dash-source-name">${escapeHtml(name).replace(/-/g, '-<wbr>')}</span>`).join('');
    const more = files.length > 2 ? `<span class="source-file-more">+${files.length - 1} ไฟล์</span>` : '';
    return `<div class="dash-source" title="${escapeHtml(files.join('\n'))}">${names}${more}</div>`;
}

function dashboardDash(content) {
    return content === null || content === undefined || content === '' ? '<span class="text-null-subtle">–</span>' : content;
}

function renderDashboardChips() {
    const counts = { ALL: dashboardTable.rows.length };
    const errCounts = { ALL: 0 };
    dashboardTable.rows.forEach(row => {
        counts[row._status] = (counts[row._status] || 0) + 1;
        if (row._status === 'MISMATCH' && row._errKey) {
            errCounts.ALL++;
            errCounts[row._errKey] = (errCounts[row._errKey] || 0) + 1;
        }
    });
    document.querySelectorAll('#dashFilterGroup .recon-filter-btn').forEach(btn => {
        const count = counts[btn.dataset.filter] || 0;
        const el = btn.querySelector('.recon-count');
        el.textContent = count.toLocaleString();
        el.classList.toggle('is-zero', count === 0);
        btn.classList.toggle('active', btn.dataset.filter === dashboardTable.status);
    });
    document.querySelectorAll('[data-export-count]').forEach(badge => {
        const count = counts[badge.dataset.exportCount] || 0;
        badge.textContent = count.toLocaleString();
        const item = badge.closest('.dropdown-item');
        if (item) {
            item.classList.toggle('disabled', count === 0);
            item.style.opacity = count === 0 ? '0.45' : '1';
        }
    });

    const subBar = document.getElementById('dashErrorSubfilters');
    if (subBar) {
        if (dashboardTable.status === 'MISMATCH' && errCounts.ALL > 0) {
            subBar.classList.remove('d-none');
            subBar.innerHTML = '<span class="dash-err-sub-title"><i class="fa-solid fa-filter me-1"></i>แยกตามสาเหตุผิดพลาด:</span>' +
                DASH_ERROR_CATEGORIES
                    .filter(cat => cat.key === 'ALL' || (errCounts[cat.key] || 0) > 0)
                    .map(cat => {
                        const c = errCounts[cat.key] || 0;
                        const active = dashboardTable.errorCategory === cat.key ? ' active' : '';
                        return `<button type="button" class="dash-err-chip${active}" onclick="setDashboardErrorCategory('${cat.key}')">${escapeHtml(cat.label)} <span class="dash-err-chip-count tabular-num">${c.toLocaleString()}</span></button>`;
                    }).join('');
        } else {
            subBar.classList.add('d-none');
            subBar.innerHTML = '';
        }
    }
}

function setDashboardErrorCategory(catKey) {
    if (dashboardTable.errorCategory === catKey) return;
    dashboardTable.errorCategory = catKey;
    renderDashboardTable();
}

function renderDashboardTable() {
    const tbody = document.getElementById('dash-tbody');
    const total = dashboardTable.rows.length;
    const rows = visibleDashboardRows();

    renderDashboardChips();
    document.getElementById('btn-export-dash').disabled = rows.length === 0;
    document.getElementById('dash-table-count').textContent = total === 0 ? '–'
        : (isDashboardFiltered() ? `${rows.length.toLocaleString()} จาก ${total.toLocaleString()} เลขบิล` : `${total.toLocaleString()} เลขบิล`);

    if (rows.length === 0) {
        const message = total === 0 ? dashboardTable.emptyMessage : 'ไม่พบเลขบิลที่ตรงกับตัวกรอง';
        const columns = document.querySelectorAll('#dash-table thead th').length;
        tbody.innerHTML = `<tr><td colspan="${columns}" class="dash-table-empty">${escapeHtml(message)}</td></tr>`;
        return;
    }

    tbody.innerHTML = rows.map(dashboardRowHtml).join('');
}

function dashboardRowHtml(row, index) {
    const store = row.store_no
        ? `<span class="fw-semibold tabular-num">${escapeHtml(row.store_no)}</span>`
        : '<span class="text-null-subtle">–</span>';
    const branch = row.branch_code
        ? `<span class="tabular-num">${escapeHtml(row.branch_code)}</span>`
        : '<span class="text-null-subtle">–</span>';
    const errBadge = row._errLabel
        ? `<div class="dash-err-tag"><i class="fa-solid fa-tag me-1"></i>${escapeHtml(row._errLabel)}</div>`
        : '';
    const note = (row._remark.length || errBadge)
        ? `${errBadge}<div class="dash-note-text" title="${escapeHtml(row.note_detail || remarkText(row._remark))}">${remarkHtml(row._remark)}</div>`
        : '';
    const history = row._was ? historyText(row.history) : '';
    return `<tr>
            <td class="text-center tabular-num text-muted">${index + 1}</td>
            <td>${store}</td>
            <td class="text-center">${branch}</td>
            <td class="tabular-num fw-semibold text-dark">${escapeHtml(row.invoice_no)}</td>
            <td class="dash-source-cell">${dashboardSourceFilesHtml(row.source_files)}</td>
            <td class="text-center tabular-num fw-bold">${dashboardDash(row.units === null ? null : row.units.toLocaleString())}</td>
            <td class="text-end tabular-num">${dashboardDash(dashboardCostText(row))}</td>
            <td class="text-center tabular-num">${dashboardDash(row.pages)}</td>
            <td class="text-center">${statusBadgeHtml(row._status)}</td>
            <td class="dash-was"${history ? ` title="${escapeHtml(history)}"` : ''}>${escapeHtml(row._was)}</td>
            ${VERDICT_SOURCES.map(source => verdictCellHtml(row.verdict, source)).join('')}
            <td class="dash-note">${note}</td>
        </tr>`;
}

// The strip under the cards: how many bills each source got wrong; '' (hidden) while no bill is covered by VBRP
function dashboardVerdictStripHtml(counts) {
    if (!counts) return '';
    return '<span class="dash-verdict-label"><i class="fa-solid fa-code-compare me-1"></i>เทียบสามแหล่ง</span>'
        + VERDICT_SOURCES.map(source => {
            const c = counts[source.key] || {};
            const wrong = Number(c.wrong || 0);
            const title = `${source.label}: ตรง ${Number(c.ok || 0).toLocaleString()} บิล / ไม่ตรง ${wrong.toLocaleString()} บิล / ไม่ได้ตัดสิน ${Number(c.na || 0).toLocaleString()} บิล`;
            return `<span class="dash-verdict-item${wrong > 0 ? ' has-wrong' : ''}" title="${escapeHtml(title)}">${source.label} ผิด <b class="tabular-num">${wrong.toLocaleString()}</b> บิล</span>`;
        }).join('<span class="dash-verdict-sep">/</span>');
}

function setDashboardFilter(status) {
    if (dashboardTable.status === status && dashboardTable.errorCategory === 'ALL') return;
    dashboardTable.status = status;
    dashboardTable.errorCategory = 'ALL';
    renderDashboardTable();
}

function formatWorksheetFromRows(sheetRows, moneyColumns) {
    const ws = XLSX.utils.json_to_sheet(sheetRows);
    if (sheetRows.length === 0) return ws;
    const headerKeys = Object.keys(sheetRows[0]);
    (moneyColumns || []).forEach(key => {
        const col = headerKeys.indexOf(key);
        if (col < 0) return;
        for (let r = 1; r <= sheetRows.length; r++) {
            const cell = ws[XLSX.utils.encode_cell({ r, c: col })];
            if (cell && typeof cell.v === 'number') cell.z = '#,##0.00';
        }
    });
    ws['!cols'] = headerKeys.map(k => {
        let maxLen = k.length;
        sheetRows.forEach(r => {
            const val = String(r[k] === null || r[k] === undefined ? '' : r[k]);
            if (val.length > maxLen) maxLen = Math.min(val.length, 55);
        });
        return { wch: maxLen + 4 };
    });
    return ws;
}

function sumDashField(list, fn) {
    return Math.round(list.reduce((acc, r) => acc + (Number(fn(r)) || 0), 0) * 100) / 100;
}

function buildReportMatchWorkbook(rows) {
    const data = rows.map((row, i) => ({
        'ลำดับ (#)': i + 1,
        'รหัสสาขา (Store No.)': row.store_no,
        'รหัสสาขาภาษี (Branch Code)': row.branch_code || null,
        'เลขที่บิล (Tax Invoice No.)': row.invoice_no,
        'ไฟล์ PDF ต้นฉบับ': (row.source_files || []).join('; ') || null,
        'จำนวนชิ้น (Qty)': row.units,
        'ยอดก่อน VAT (Excl. VAT)': row.total_cost_ex_vat
    }));
    data.push({
        'ลำดับ (#)': '',
        'รหัสสาขา (Store No.)': 'รวมทั้งหมด',
        'รหัสสาขาภาษี (Branch Code)': '',
        'เลขที่บิล (Tax Invoice No.)': `${rows.length.toLocaleString()} บิล`,
        'ไฟล์ PDF ต้นฉบับ': '',
        'จำนวนชิ้น (Qty)': sumDashField(rows, r => r.units),
        'ยอดก่อน VAT (Excl. VAT)': sumDashField(rows, r => r.total_cost_ex_vat)
    });
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, formatWorksheetFromRows(data, ['ยอดก่อน VAT (Excl. VAT)']), 'สมบูรณ์');
    return wb;
}

function buildReportFixedWorkbook(rows) {
    const data = rows.map((row, i) => ({
        'ลำดับ (#)': i + 1,
        'รหัสสาขา (Store No.)': row.store_no,
        'รหัสสาขาภาษี (Branch Code)': row.branch_code || null,
        'เลขที่บิล (Tax Invoice No.)': row.invoice_no,
        'ไฟล์ PDF ต้นฉบับ': (row.source_files || []).join('; ') || null,
        'จำนวนชิ้น (Qty)': row.units,
        'ยอดก่อน VAT (Excl. VAT)': row.total_cost_ex_vat,
        'สิ่งที่แก้ไขแล้ว': row.note || null
    }));
    data.push({
        'ลำดับ (#)': '',
        'รหัสสาขา (Store No.)': 'รวมทั้งหมด',
        'รหัสสาขาภาษี (Branch Code)': '',
        'เลขที่บิล (Tax Invoice No.)': `${rows.length.toLocaleString()} บิล`,
        'ไฟล์ PDF ต้นฉบับ': '',
        'จำนวนชิ้น (Qty)': sumDashField(rows, r => r.units),
        'ยอดก่อน VAT (Excl. VAT)': sumDashField(rows, r => r.total_cost_ex_vat),
        'สิ่งที่แก้ไขแล้ว': ''
    });
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, formatWorksheetFromRows(data, ['ยอดก่อน VAT (Excl. VAT)']), 'แก้ไขแล้ว');
    return wb;
}

function buildReportPendingPdfWorkbook(rows) {
    const data = rows.map((row, i) => ({
        'ลำดับ (#)': i + 1,
        'รหัสสาขา (Store No.)': row.store_no,
        'รหัสสาขาภาษี (Branch Code)': row.branch_code || null,
        'เลขที่บิล (Tax Invoice No.)': row.invoice_no,
        'ไฟล์ PDF ต้นฉบับ': (row.source_files || []).join('; ') || null,
        'จำนวนหน้า': row.pages,
        'สาเหตุที่ต้องแก้ใน PDF': row.problem || null,
        'วิธีแก้ไข': row.solution || null
    }));
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, formatWorksheetFromRows(data, []), 'รอแก้ไข PDF');
    return wb;
}

function extractPdfCostFromProblem(problemText) {
    const m = String(problemText || '').match(/PDF\s+([\d,]+\.\d{2})/);
    return m ? Number(m[1].replace(/,/g, '')) : null;
}

function judgeThreeCosts(pdfCost, asnCost, vbrpCost) {
    const present = [
        { key: 'pdf', val: pdfCost },
        { key: 'asn', val: asnCost },
        { key: 'vbrp', val: vbrpCost }
    ].filter(x => x.val !== null && x.val !== undefined && !Number.isNaN(x.val));
    const res = { pdf: null, asn: null, vbrp: null };
    if (present.length < 2) return res;
    present.forEach(item => {
        let match = false;
        present.forEach(other => {
            if (other.key !== item.key && Math.round(Math.abs(item.val - other.val) * 100) / 100 <= 1.0) {
                match = true;
            }
        });
        res[item.key] = match ? 'green' : 'red';
    });
    if (present.length === 2 && !Object.values(res).includes('green')) {
        if (res.vbrp !== null) res.vbrp = 'green';
        else if (res.asn !== null) res.asn = 'green';
    }
    return res;
}

function buildReportPendingAsnWorkbook(rows) {
    const aoa = [
        ['ลำดับ (#)', 'รหัสสาขา (Store No.)', 'รหัสสาขาภาษี (Branch Code)', 'เลขที่บิล (Tax Invoice No.)', 'ยอดที่ต้องใช้เทียบ', '', '', 'จุดที่ต้องแก้ในไฟล์ ASN'],
        ['', '', '', '', 'ยอดก่อน VAT (PDF)', 'ยอดก่อน VAT (ASN)', 'ยอดก่อน VAT (VBRP)', '']
    ];
    const cellStyles = {};
    ['A1', 'B1', 'C1', 'D1', 'E1', 'F1', 'G1', 'H1', 'A2', 'B2', 'C2', 'D2', 'E2', 'F2', 'G2', 'H2'].forEach(ref => {
        cellStyles[ref] = 4; // header style
    });

    rows.forEach((row, i) => {
        const excelRow = i + 3;
        const pdfCost = extractPdfCostFromProblem(row.problem);
        const asnCost = row.total_cost_ex_vat;
        const vbrpCost = row.vbrp ? row.vbrp.cost : null;
        const colors = judgeThreeCosts(pdfCost, asnCost, vbrpCost);

        aoa.push([
            i + 1,
            row.store_no,
            row.branch_code || null,
            row.invoice_no,
            pdfCost !== null ? pdfCost : '-',
            asnCost !== null ? asnCost : '-',
            vbrpCost !== null ? vbrpCost : '-',
            row.problem || null
        ]);

        if (colors.pdf) cellStyles[`E${excelRow}`] = colors.pdf === 'green' ? 2 : 3;
        if (colors.asn) cellStyles[`F${excelRow}`] = colors.asn === 'green' ? 2 : 3;
        if (colors.vbrp) cellStyles[`G${excelRow}`] = colors.vbrp === 'green' ? 2 : 3;
    });

    const ws = XLSX.utils.aoa_to_sheet(aoa);
    ws['!merges'] = [
        { s: { r: 0, c: 0 }, e: { r: 1, c: 0 } },
        { s: { r: 0, c: 1 }, e: { r: 1, c: 1 } },
        { s: { r: 0, c: 2 }, e: { r: 1, c: 2 } },
        { s: { r: 0, c: 3 }, e: { r: 1, c: 3 } },
        { s: { r: 0, c: 4 }, e: { r: 0, c: 6 } },
        { s: { r: 0, c: 7 }, e: { r: 1, c: 7 } }
    ];
    for (let r = 2; r < aoa.length; r++) {
        [4, 5, 6].forEach(c => {
            const cell = ws[XLSX.utils.encode_cell({ r, c })];
            if (cell && typeof cell.v === 'number') cell.z = '#,##0.00';
        });
    }
    ws['!cols'] = [
        { wch: 12 },
        { wch: 18 },
        { wch: 20 },
        { wch: 22 },
        { wch: 22 },
        { wch: 22 },
        { wch: 22 },
        { wch: 62 }
    ];

    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'รอแก้ไข ASN');
    wb._customCellStyles = { sheetPath: '/xl/worksheets/sheet1.xml', map: cellStyles };
    return wb;
}

function utf8EncodeBytes(str) {
    const utf8 = unescape(encodeURIComponent(str));
    const arr = new Array(utf8.length);
    for (let i = 0; i < utf8.length; i++) arr[i] = utf8.charCodeAt(i);
    return arr;
}

function utf8DecodeBytes(bytes) {
    let bin = '';
    for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
    return decodeURIComponent(escape(bin));
}

function saveWorkbookWithStyles(wb, filename) {
    if (!wb._customCellStyles || !XLSX.CFB || typeof atob !== 'function' || typeof Blob !== 'function') {
        XLSX.writeFile(wb, filename);
        return;
    }
    const b64 = XLSX.write(wb, { bookType: 'xlsx', type: 'base64' });
    const cfb = XLSX.CFB.read(b64, { type: 'base64' });
    const stylesEntry = XLSX.CFB.find(cfb, '/xl/styles.xml');
    const sheetEntry = XLSX.CFB.find(cfb, wb._customCellStyles.sheetPath);

    if (stylesEntry && sheetEntry) {
        const customStylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' +
            '<fonts count="4">' +
            '<font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/></font>' +
            '<font><b/><sz val="11"/><color rgb="FF15803D"/><name val="Calibri"/><family val="2"/></font>' +
            '<font><b/><sz val="11"/><color rgb="FFB91C1C"/><name val="Calibri"/><family val="2"/></font>' +
            '<font><b/><sz val="11"/><color rgb="FF1E293B"/><name val="Calibri"/><family val="2"/></font>' +
            '</fonts>' +
            '<fills count="5">' +
            '<fill><patternFill patternType="none"/></fill>' +
            '<fill><patternFill patternType="gray125"/></fill>' +
            '<fill><patternFill patternType="solid"><fgColor rgb="FFDCFCE7"/><bgColor indexed="64"/></patternFill></fill>' +
            '<fill><patternFill patternType="solid"><fgColor rgb="FFFEE2E2"/><bgColor indexed="64"/></patternFill></fill>' +
            '<fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/><bgColor indexed="64"/></patternFill></fill>' +
            '</fills>' +
            '<borders count="2">' +
            '<border><left/><right/><top/><bottom/><diagonal/></border>' +
            '<border>' +
            '<left style="thin"><color rgb="FFCBD5E1"/></left>' +
            '<right style="thin"><color rgb="FFCBD5E1"/></right>' +
            '<top style="thin"><color rgb="FFCBD5E1"/></top>' +
            '<bottom style="thin"><color rgb="FFCBD5E1"/></bottom>' +
            '<diagonal/>' +
            '</border>' +
            '</borders>' +
            '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' +
            '<cellXfs count="5">' +
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' +
            '<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>' +
            '<xf numFmtId="4" fontId="1" fillId="2" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>' +
            '<xf numFmtId="4" fontId="2" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>' +
            '<xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' +
            '</cellXfs>' +
            '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>' +
            '</styleSheet>';

        const encStyles = utf8EncodeBytes(customStylesXml);
        stylesEntry.content = encStyles;
        stylesEntry.size = encStyles.length;

        let sheetXml = utf8DecodeBytes(sheetEntry.content);
        const styleMap = wb._customCellStyles.map;
        sheetXml = sheetXml.replace(/<c r="([A-Z]+\d+)"([^>]*)>/g, (full, ref, attrs) => {
            const st = styleMap[ref];
            if (st === undefined) return full;
            const cleaned = attrs.replace(/\s+s="\d+"/, '');
            return `<c r="${ref}" s="${st}"${cleaned}>`;
        });
        const encSheet = utf8EncodeBytes(sheetXml);
        sheetEntry.content = encSheet;
        sheetEntry.size = encSheet.length;
    }

    const outB64 = XLSX.CFB.write(cfb, { type: 'base64', fileType: 'zip' });
    const bin = atob(outB64);
    const bytes = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
    const blob = new Blob([bytes], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    setTimeout(() => {
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }, 500);
}

function buildMismatchSheetRows(list) {
    return list.map((row, i) => {
        const v = row.verdict || {};
        const pdfAsnMatch = (v.pdf === 'ok' && v.asn === 'ok') ? 'ตรงกัน' : 'ไม่ตรงกัน';
        const detailParts = [row.problem].concat(row.notes || []).filter(Boolean);
        return {
            'ลำดับ (#)': i + 1,
            'รหัสสาขา (Store No.)': row.store_no,
            'รหัสสาขาภาษี (Branch Code)': row.branch_code || null,
            'เลขที่บิล (Tax Invoice No.)': row.invoice_no,
            'ไฟล์ PDF ต้นฉบับ': (row.source_files || []).join('; ') || null,
            'สาเหตุผิดพลาด': row._errLabel || null,
            'ผลเทียบ PDF กับ ASN': pdfAsnMatch,
            'รายละเอียดปัญหา': detailParts.join(' | ') || null
        };
    });
}

function buildReportMismatchWorkbook(rows) {
    const wb = XLSX.utils.book_new();
    const totalErr = rows.length;
    const aoa = [
        ['สรุปแยกตามสาเหตุความผิดพลาด (Error Summary)'],
        ['สาเหตุความผิดพลาด', 'จำนวนบิล']
    ];
    DASH_ERROR_CATEGORIES.filter(cat => cat.key !== 'ALL').forEach(cat => {
        const group = rows.filter(r => r._errKey === cat.key);
        if (group.length === 0) return;
        aoa.push([
            cat.label,
            group.length
        ]);
    });
    aoa.push([
        'รวมบิลที่ผิดพลาดทั้งหมด',
        totalErr
    ]);
    const wsSummary = XLSX.utils.aoa_to_sheet(aoa);
    wsSummary['!cols'] = [{ wch: 36 }, { wch: 16 }];
    XLSX.utils.book_append_sheet(wb, wsSummary, 'สรุปสาเหตุผิดพลาด');

    XLSX.utils.book_append_sheet(wb, formatWorksheetFromRows(buildMismatchSheetRows(rows), []), 'รวมผิดพลาดทั้งหมด');

    DASH_ERROR_CATEGORIES.filter(cat => cat.key !== 'ALL').forEach(cat => {
        const group = rows.filter(r => r._errKey === cat.key);
        if (group.length > 0) {
            XLSX.utils.book_append_sheet(wb, formatWorksheetFromRows(buildMismatchSheetRows(group), []), cat.sheetName);
        }
    });
    return wb;
}

const DASH_REPORTS = {
    MATCH: { fileSuffix: '1_สมบูรณ์', build: buildReportMatchWorkbook },
    FIXED: { fileSuffix: '2_แก้ไขแล้ว', build: buildReportFixedWorkbook },
    PENDING_FIX: { fileSuffix: '3_รอแก้ไข_PDF', build: buildReportPendingPdfWorkbook },
    PENDING_ASN: { fileSuffix: '4_รอแก้ไข_ASN', build: buildReportPendingAsnWorkbook },
    MISMATCH: { fileSuffix: '5_ผิดพลาด', build: buildReportMismatchWorkbook }
};

async function exportDashboardReport(type) {
    const allRows = dashboardTable.rows.slice();
    if (allRows.length === 0) return;
    await ensureXlsxLoaded();

    const targets = type === 'ALL_SEPARATE'
        ? Object.keys(DASH_REPORTS)
        : [type || (dashboardTable.status !== 'ALL' ? dashboardTable.status : 'MISMATCH')];

    for (let i = 0; i < targets.length; i++) {
        const key = targets[i];
        const cfg = DASH_REPORTS[key];
        if (!cfg) continue;
        const rows = allRows.filter(r => r._status === key);
        if (rows.length === 0) continue;
        const wb = cfg.build(rows);
        saveWorkbookWithStyles(wb, `Job_${CURRENT_JOB_ID}_${CURRENT_JOB_NAME_CLEAN}_Report_${cfg.fileSuffix}.xlsx`);
        if (targets.length > 1 && i < targets.length - 1) {
            await new Promise(resolve => setTimeout(resolve, 250));
        }
    }
}

async function exportDashboardToExcel() {
    const rows = visibleDashboardRows();
    if (rows.length === 0) return;
    await ensureXlsxLoaded();

    const sheetRows = rows.map((row, i) => ({
        '#': i + 1,
        'Dest Store No.': row.store_no,
        'Branch Code': row.branch_code,
        'Tax Invoice No.': row.invoice_no,
        'Source File': (row.source_files || []).join('; ') || null,
        'Total Qty': row.units,
        'Total Cost Ex VAT': row.total_cost_ex_vat,
        'Amt Excl VAT (VBRP)': row.vbrp ? row.vbrp.cost : null,
        'Pages': row.pages,
        'Status': STATUS_DISPLAY[row._status].label,
        'Previous status': row._was || null,
        ...Object.fromEntries(VERDICT_SOURCES.map(source => [source.label, VERDICT_DISPLAY[verdictOf(row.verdict, source.key)].excel])),
        'Remark': remarkText(row._remark) || null
    }));

    const ws = XLSX.utils.json_to_sheet(sheetRows);
    const headerKeys = Object.keys(sheetRows[0]);
    ['Total Cost Ex VAT', 'Amt Excl VAT (VBRP)'].forEach(key => {
        const col = headerKeys.indexOf(key);
        for (let r = 1; r <= sheetRows.length; r++) {
            const cell = ws[XLSX.utils.encode_cell({ r, c: col })];
            if (cell) cell.z = '#,##0.00';
        }
    });
    ws['!cols'] = headerKeys.map(k => {
        let maxLen = k.length;
        sheetRows.forEach(r => {
            const val = String(r[k] === null ? '' : r[k]);
            if (val.length > maxLen) maxLen = Math.min(val.length, 50);
        });
        return { wch: maxLen + 3 };
    });

    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Invoice_Summary');
    XLSX.writeFile(wb, `Job_${CURRENT_JOB_ID}_${CURRENT_JOB_NAME_CLEAN}_Invoice_Summary.xlsx`);
}

const dashSearch = document.getElementById('dash-search');
const clearDashSearchBtn = document.getElementById('btn-clear-dash-search');
const debouncedDashSearch = debounce(() => {
    dashboardTable.query = dashSearch.value;
    renderDashboardTable();
}, 100);
dashSearch.addEventListener('input', () => {
    clearDashSearchBtn.style.display = dashSearch.value ? 'block' : 'none';
    debouncedDashSearch();
});
clearDashSearchBtn.addEventListener('click', () => {
    dashSearch.value = '';
    clearDashSearchBtn.style.display = 'none';
    dashboardTable.query = '';
    renderDashboardTable();
    dashSearch.focus();
});

document.querySelectorAll('#dash-table thead th').forEach(th => {
    th.addEventListener('click', () => {
        const col = th.getAttribute('data-col');
        if (!col || dashboardTable.rows.length === 0) return;

        const sort = dashboardTable.sort;
        if (sort.column === col) {
            sort.direction = sort.direction === 'asc' ? 'desc' : 'asc';
        } else {
            dashboardTable.sort = { column: col, direction: 'asc' };
        }

        resetSortIcons('#dash-table');
        th.classList.add('sorted');
        const icon = th.querySelector('.sort-icon');
        if (icon) {
            icon.className = dashboardTable.sort.direction === 'asc' ? 'fa-solid fa-sort-up sort-icon' : 'fa-solid fa-sort-down sort-icon';
        }
        renderDashboardTable();
    });
});
