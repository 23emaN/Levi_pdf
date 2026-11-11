const INITIAL_RENDER_ROWS = 80;
const CHUNK_RENDER_ROWS = 220;

let detailedData = [];
let displayData = [];
let isRunning = false;
let currentSort = { column: null, direction: 'asc' };

const WORKSPACE_TABS = {
    pdf: { button: 'btn-tab-pdf', section: 'view-pdf-section', label: '1. ดึงข้อมูลจาก PDF' },
    asn: { button: 'btn-tab-asn', section: 'view-asn-section', label: '2. เปรียบเทียบกับไฟล์ ASN' },
    dashboard: { button: 'btn-tab-dashboard', section: 'view-dashboard-section', label: '3. ภาพรวม' }
};

let activeTab = 'pdf';
let pdfRenderFrameId = null;
let pdfRenderToken = 0;
let pdfDomDirty = false;

let reconRenderFrameId = null;
let reconRenderToken = 0;
let reconDomDirty = false;

let scanResult = null;
let duplicateModal = null;
let clearDataModal = null;
let noticeModal = null;
let confirmImportAsnModal = null;
let confirmReconcileModal = null;
let confirmFixModal = null;
let pendingFixInvoiceTarget = null;
let pendingAsnFiles = [];

let clearModalTarget = 'pdf';
let clearModalSourceRows = [];
let clearModalFilteredRows = [];
let clearModalSelectedIds = new Set();
let clearModalCurrentPage = 1;
let clearModalPageSize = 25;

let reconData = null;
let pendingReconcilePromise = null;
let currentReconFilter = 'ALL';
let reconDisplayData = [];
let currentReconSort = { column: null, direction: 'asc' };
let currentAsnWorkbook = null;
let currentAsnFileName = '';
let cachedAsnSheets = {};

function preparePdfRow(r) {
    if (!r._searchStr) {
        r._searchStr = [
            r.filename, r.tax_invoice, r.barcode, r.product_sz, r.description,
            r.address, r.tax_id, r.ship_to_party, r.term_a, r.term_b, r.amount
        ].filter(Boolean).join(' ').toLowerCase();
    }
    return r;
}

// What a status looks like in the ASN table and the dashboard table. FIXED is display only: the stored
// status stays MATCH and the server flags the invoice as fixed. Rank orders the Status column sort, worst first
// (PENDING_ASN came in between, at 0.5, so the sort values of the other statuses did not move). Tone names the
// colour of the status (app.css defines each once, as --status-<tone>-color): the badge is status-<tone>, every
// line of the remark remark-<tone>; remarkClass weighs the unlabelled remark of a bill without a problem.
const STATUS_DISPLAY = {
    MISMATCH: { label: 'ผิดพลาด', icon: 'fa-triangle-exclamation', tone: 'mismatch', rank: 0 },
    PENDING_ASN: { label: 'รอแก้ไข ASN', icon: 'fa-file-circle-exclamation', tone: 'pending-asn', rank: 0.5 },
    PENDING_FIX: { label: 'รอแก้ไข PDF', icon: 'fa-wrench', tone: 'pending-fix', rank: 1 },
    FIXED: { label: 'แก้ไขแล้ว', icon: 'fa-wrench', tone: 'fixed', rank: 2, remarkClass: 'fw-medium' },
    MATCH: { label: 'สมบูรณ์', icon: 'fa-check', tone: 'match', rank: 3, remarkClass: 'fw-medium' },
    PENDING: { label: 'รอเทียบกับ PDF', icon: 'fa-clock', tone: 'waiting', rank: 4 }
};

function displayStatus(status, fixed) {
    return status === 'MATCH' && fixed ? 'FIXED' : status;
}

function statusBadgeHtml(key) {
    const s = STATUS_DISPLAY[key];
    if (!s) return '';
    return `<span class="pill-badge status-${s.tone}"><i class="fa-solid ${s.icon} me-1"></i>${s.label}</span>`;
}

function reconRowStatus(r) {
    return displayStatus(r.status, r.fixed);
}

// The statuses a bill had before its current one (row.was, codes of STATUS_DISPLAY, oldest first) as one text
function previousStatusText(was) {
    return (was || []).map(code => (STATUS_DISPLAY[code] || { label: code }).label).join(', ');
}

// What changed a bill's status, as row.history names it (BillHistory on the server records one row per change)
const HISTORY_EVENTS = {
    compare: 'เทียบ',
    asn_import: 'นำเข้า ASN',
    asn_delete: 'ลบแถว ASN',
    vbrp_import: 'นำเข้า VBRP',
    vbrp_clear: 'ล้าง VBRP',
    fix: 'แก้ไข'
};

// The server writes the time in UTC (SQLite CURRENT_TIMESTAMP); it is shown in the time of this computer
function historyTime(at) {
    const date = new Date(String(at || '').replace(' ', 'T') + 'Z');
    if (isNaN(date.getTime())) return String(at || '');
    const two = n => String(n).padStart(2, '0');
    return `${two(date.getDate())}/${two(date.getMonth() + 1)}/${date.getFullYear()} ${two(date.getHours())}:${two(date.getMinutes())}`;
}

// One line per change of row.history, oldest first: "28/10/2026 15:05 แก้ไข: รอแก้ไข PDF → แก้ไขแล้ว", the problem of
// the bill at that time on the next line
function historyText(history) {
    return (history || []).map(h => {
        const change = (h.from ? `${previousStatusText([h.from])} → ` : '') + previousStatusText([h.to]);
        const line = `${historyTime(h.at)} ${HISTORY_EVENTS[h.event] || h.event}: ${change}`;
        return h.detail ? `${line}\n    ${h.detail}` : line;
    }).join('\n');
}

const REMARK_LABELS = { problem: 'ปัญหา : ', solution: 'วิธีแก้ไข : ' };

function remarkLines(parts, labelled, statusKey) {
    const className = `remark-${STATUS_DISPLAY[statusKey].tone}`;
    return ['problem', 'solution'].filter(part => parts[part])
        .map(part => ({ part, label: labelled ? REMARK_LABELS[part] : '', text: parts[part], className }));
}

// What the compare adds under the remark, one sentence each, in a quieter tone: another source (ASN or VBRP) that does
// not match, or what the PDF of a bill waiting for the ASN still needs after that. Excel puts these lines after the problem.
function sourceNoteLines(notes) {
    return (notes || []).map(text => ({ part: 'note', label: '', text, className: 'remark-note' }));
}

// Where a complete or fixed bill came from ("เดิม: รอแก้ไข ASN, รอแก้ไข PDF"), in the note style; Excel has it in its own
// Previous status column, so its part is neither the problem nor a note
function previousStatusLines(was) {
    const text = previousStatusText(was);
    return text ? [{ part: 'was', label: '', text: `เดิม: ${text}`, className: 'remark-note' }] : [];
}

function reconRemark(r) {
    const key = reconRowStatus(r);
    const notes = sourceNoteLines(r.sources ? r.sources.notes : null);
    switch (key) {
        case 'MATCH': return remarkLines({ problem: r.reason }, false, key).concat(previousStatusLines(r.was), notes);
        case 'FIXED': return remarkLines({ solution: r.fix_note }, false, key).concat(previousStatusLines(r.was), notes);
        default: return remarkLines({ problem: r.reason, solution: r.action }, true, key).concat(notes);
    }
}

function remarkExcelProblem(lines) {
    return [remarkPart(lines, 'problem'), remarkPart(lines, 'note')].filter(Boolean).join('\n');
}

// The three sources of the Tax Invoice compare, in the order of the PDF | ASN | VBRP columns of both tables
const VERDICT_SOURCES = [
    { key: 'pdf', label: 'PDF' },
    { key: 'asn', label: 'ASN' },
    { key: 'vbrp', label: 'VBRP' }
];

// One source's verdict: the mark in its cell, the word of its tooltip, its Excel text. The colours are the status
// colours (verdict-ok, verdict-wrong in app.css); rank sorts a wrong source first.
const VERDICT_DISPLAY = {
    wrong: { mark: '✗', word: 'ไม่ตรง', excel: 'ไม่ตรง', className: 'verdict-wrong', rank: 0 },
    ok: { mark: '✓', word: 'ตรง', excel: 'ตรง', className: 'verdict-ok', rank: 1 },
    na: { mark: '-', word: 'ไม่ได้ตัดสิน', excel: '-', className: 'text-null-subtle', rank: 2 }
};

// The checks of a line (sources.verdict), named as the server's notes name them
const VERDICT_CHECKS = [
    { key: 'qty', label: 'จำนวน' },
    { key: 'amount', label: 'ยอดก่อน VAT' },
    { key: 'vat', label: 'VAT' }
];

function verdictOf(verdicts, sourceKey) {
    const value = verdicts ? verdicts[sourceKey] : null;
    return VERDICT_DISPLAY[value] ? value : 'na';
}

function verdictCellHtml(verdicts, source, detail = []) {
    const shown = VERDICT_DISPLAY[verdictOf(verdicts, source.key)];
    const title = verdicts ? [`${source.label}: ${shown.word}`].concat(detail).join('\n') : 'ยังไม่มีผลเทียบกับ VBRP';
    return `<td class="text-center verdict-cell" title="${escapeHtml(title)}"><span class="${shown.className}">${shown.mark}</span></td>`;
}

function remarkPart(lines, part) {
    return lines.filter(line => line.part === part).map(line => line.text).join('\n');
}

function remarkHtml(lines) {
    return lines.map(line => `<div class="${line.className}">${line.label ? `<span class="remark-label">${escapeHtml(line.label)}</span>` : ''}${escapeHtml(line.text)}</div>`).join('');
}

function remarkText(lines) {
    return lines.map(line => line.label + line.text).join('\n');
}

function setStatusBarPdf(rows) {
    const files = new Set();
    rows.forEach(r => {
        if (r.filename) files.add(r.filename);
    });
    setStatusBarCount('footer-files-count', files.size);
    setStatusBarCount('footer-items-count', rows.length);
}

function setStatusBarAsn(counts) {
    if (!counts) return;
    setStatusBarCount('footer-asn-files-count', counts.files);
    setStatusBarCount('footer-asn-rows-count', counts.rows);
    const item = document.getElementById('footer-asn-rows-item');
    if (item) item.dataset.rows = Number(counts.rows || 0);
    setAsnStatusBarTitle();
}

function asnRowCount() {
    const item = document.getElementById('footer-asn-rows-item');
    return item ? Number(item.dataset.rows || 0) : 0;
}

function setAsnStatusBarTitle() {
    const item = document.getElementById('footer-asn-rows-item');
    if (!item) return;
    const summary = reconData && reconData.summary;
    const rows = summary ? Number(summary.total_asn_records || 0) : Number(item.dataset.rows || 0);
    let title = '';
    if (rows > 0) {
        title = `${rows.toLocaleString()} แถวที่นำเข้า`;
        if (!summary) {
            title += ' บาร์โค้ดซ้ำในบิลเดียวกันจะถูกรวมเป็นรายการเดียวในตารางหลังเทียบกับ PDF';
        } else if (Number(summary.total_asn_groups) < rows) {
            title += ` รวมบาร์โค้ดซ้ำในบิลเดียวกันเป็น ${Number(summary.total_asn_groups).toLocaleString()} รายการในตาราง`;
        }
    }
    if (title) item.title = title;
    else item.removeAttribute('title');
}

function setStatusBarCount(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = Number(value || 0).toLocaleString();
}

function cancelPdfRenderSchedule() {
    pdfRenderToken++;
    if (pdfRenderFrameId !== null) {
        cancelAnimationFrame(pdfRenderFrameId);
        pdfRenderFrameId = null;
    }
}

function cancelReconRenderSchedule() {
    reconRenderToken++;
    if (reconRenderFrameId !== null) {
        cancelAnimationFrame(reconRenderFrameId);
        reconRenderFrameId = null;
    }
}

function getSavedTabForJob() {
    try {
        const params = new URLSearchParams(window.location.search);
        const urlTab = params.get('tab');
        if (WORKSPACE_TABS[urlTab]) {
            return urlTab;
        }
        const saved = localStorage.getItem('workspace_tab_job_' + CURRENT_JOB_ID);
        if (WORKSPACE_TABS[saved]) {
            return saved;
        }
    } catch (e) {}
    return 'pdf';
}

function persistActiveTab(tab) {
    const normalized = WORKSPACE_TABS[tab] ? tab : 'pdf';
    try {
        localStorage.setItem('workspace_tab_job_' + CURRENT_JOB_ID, normalized);
    } catch (e) {}
    try {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', normalized);
        window.history.replaceState({ tab: normalized }, '', url.toString());
    } catch (e) {}
}

