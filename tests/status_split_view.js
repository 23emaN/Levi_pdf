// Runs the scripts of workspace.php tabs 2 and 3 on rows of the status split (รอแก้ไข PDF / รอแก้ไข ASN, and the
// statuses a bill had before) and prints one { name, ok, detail } per check as JSON; job_safety_test.php reports them.
// Usage: node tests/status_split_view.js <rendered workspace.php>
process.env.TZ = 'Asia/Bangkok';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const page = fs.readFileSync(process.argv[2], 'utf8');
const columns = /const ASN_COLUMNS = (.*?);\n/.exec(page)[1];
const before = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures', 'workspace_rows_before_vbrp.json'), 'utf8'));
const XLSX = require(path.join(__dirname, '..', 'assets', 'vendor', 'xlsx.full.min.js'));

const classList = () => {
    const set = new Set();
    return {
        set,
        add: (...names) => names.forEach(n => set.add(n)),
        remove: (...names) => names.forEach(n => set.delete(n)),
        toggle: (name, force) => ((force === undefined ? !set.has(name) : force) ? set.add(name) : set.delete(name)),
        contains: name => set.has(name)
    };
};
const node = (extra = {}) => Object.assign({
    innerHTML: '', textContent: '', disabled: false, title: '', className: '', value: '', dataset: {}, style: {},
    classList: classList(), addEventListener() {}, removeAttribute() {}, appendChild(child) { this.textContent += child.textContent || ''; },
    querySelectorAll: () => [], querySelector: () => node()
}, extra);

// The parts of the page the scripts look up by selector, read from the rendered HTML
const block = (startPattern, endTag) => {
    const start = page.search(startPattern);
    return start < 0 ? '' : page.slice(start, page.indexOf(endTag, start));
};
const segs = barId => [...block(new RegExp(`id="${barId}"`), '</div>').matchAll(/data-seg="(\w+)"/g)].map(m => node({ dataset: { seg: m[1] } }));
const legends = [...page.matchAll(/data-legend="(\w+)"/g)].map(m => node({ dataset: { legend: m[1] } }));
const chipsOf = groupId => [...block(new RegExp(`id="${groupId}"`), '</div>').matchAll(/<button\b[^>]*data-filter="(\w+)"[^>]*>([\s\S]*?)<\/button>/g)]
    .map(m => ({ filter: m[1], label: m[2].replace(/<span[\s\S]*?<\/span>/g, '').replace(/<[^>]+>/g, '').trim(), html: m[0] }));
const dashChips = chipsOf('dashFilterGroup').map(chip => {
    const count = node();
    return node({ dataset: { filter: chip.filter }, querySelector: () => count, count });
});
const bars = { 'dash-bar': segs('dash-bar'), 'dash-mini-bar': segs('dash-mini-bar') };
const elements = {};
const element = id => elements[id] || (elements[id] = node({ id, querySelectorAll: sel => (sel === '.dash-seg' && bars[id]) || [] }));
const selected = {};
const notices = [];
const requests = [];
let answers = {};
const sheets = [];
const context = vm.createContext({
    console, Intl, setTimeout, clearTimeout, FormData: class { constructor() { this.fields = {}; } append(k, v) { this.fields[k] = v; } },
    document: {
        getElementById: element,
        querySelector: sel => selected[sel] || (selected[sel] = node()),
        querySelectorAll: sel => (sel === '[data-legend]' ? legends : sel === '#dashFilterGroup .recon-filter-btn' ? dashChips : []),
        createTextNode: text => ({ textContent: text }),
        addEventListener() {}
    },
    bootstrap: { Modal: class { show() {} hide() {} static getOrCreateInstance() { return { show() {}, hide() {} }; } } },
    fetch: async (url, options) => {
        requests.push({ url, fields: options && options.body ? options.body.fields : null });
        const action = /action=(\w+)/.exec(url)[1];
        return { json: async () => JSON.parse(JSON.stringify(answers[action])) };
    },
    XLSX: Object.assign({}, XLSX, {
        utils: Object.assign({}, XLSX.utils, { json_to_sheet: rows => { sheets.push(rows); return XLSX.utils.json_to_sheet(rows); } }),
        writeFile() {}
    })
});
vm.runInContext(`const CURRENT_JOB_ID = 1; const CURRENT_JOB_NAME_CLEAN = 'T'; const ASN_COLUMNS = ${columns};`, context);
for (const file of ['ui-utils.js', 'workspace/state.js', 'workspace/asn-import.js', 'workspace/vbrp-import.js', 'workspace/asn-table.js', 'workspace/asn-fix-export.js', 'workspace/print.js', 'workspace/dashboard.js']) {
    vm.runInContext(fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', file), 'utf8'), context, { filename: file });
}
vm.runInContext(`function showNoticeModal(title, message, detail, type) { notices.push({ title, message, detail, type }); }
    async function loadJobItemsFromDb() {}
    async function loadStats() {}`, context);
context.notices = notices;
const run = (code, values = {}) => {
    Object.assign(context, values);
    return vm.runInContext(code, context);
};

const results = [];
const check = (name, ok, detail) => results.push({ name, ok: !!ok, detail: ok ? '' : JSON.stringify(detail) });
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const cellsOf = html => html.match(/<td\b[^>]*>[\s\S]*?<\/td>/g) || [];
const textOf = html => html.replace(/<[^>]+>/g, '').replace(/&quot;/g, '"').trim();
const remarkLinesOf = html => [...html.matchAll(/<div class="([^"]*)">(?:<span class="remark-label">([^<]*)<\/span>)?([^<]*)<\/div>/g)].map(m => [m[1], m[2] || '', m[3]]);

const ASN_REASON = 'ASN ยอดก่อน VAT ไม่ตรง (ASN 35,177.60 / PDF 20,456.74 = VBRP 20,456.74)';
const ASN_ACTION = 'แก้ข้อมูลใน ASN แล้วนำเข้า ASN ใหม่';
const FOLLOW_UP = 'หลังแก้ ASN แล้วบิลนี้ยังต้องแก้ไข PDF: เลขที่พิมพ์ในบิล PDF คือ 93251541-2 แต่ใน ASN เป็น 93251541-1';
const VBRP_NOTE = 'VBRP ไม่ตรง: จำนวน 0 ชิ้น (PDF: 3 ชิ้น / ASN: 3 ชิ้น)';
const all = (pdf, asn, vbrp) => ({ pdf, asn, vbrp });
const sources = (asn, notes) => ({
    vbrp: { qty: 2, price: 2792, amount: 20456.74, vat: 1431.97 },
    verdict: { qty: all('ok', 'ok', 'ok'), amount: all('ok', asn, 'ok'), vat: all('ok', 'ok', 'ok') },
    overall: all('ok', asn, 'ok'),
    notes
});
const line = (inv, barcode, status, extra) => Object.assign({
    status, fixed: false, reason: 'ข้อมูลถูกต้อง', action: '', amount_ok: true, can_fix: false, source_files: ['RM Document-Customer Billing(PDF)-0093248051 3.pdf'],
    pdf: { tax_invoice: inv, barcode, description: 'JEANS', store_code: '10114', units: 2, unit_price: '2,790.00', inv_amt_excl_vat: '20,456.74', inv_vat_amount: '1,431.97' },
    asn: { invoice_no: inv, ean_upc: barcode, dest_store_no: '10114', quantity: 2, row_count: 1, selling_price: 2792, total_cost_ex_vat: 20456.74, total_vat: 1431.97 },
    sources: sources('ok', [])
}, extra || {});
const waitingAsn = { reason: ASN_REASON, action: ASN_ACTION, amount_ok: false, sources: sources('wrong', []) };
const rows = [
    line('93251539-1', '8859384715612', 'PENDING_ASN', waitingAsn),
    line('93251539-1', '8859384715613', 'PENDING_ASN', waitingAsn),
    line('93251541-1', '8859384715614', 'PENDING_ASN', Object.assign({}, waitingAsn, { sources: sources('wrong', [FOLLOW_UP]) })),
    line('93251540-1', '8859384715615', 'PENDING_FIX', { reason: 'เลขที่พิมพ์ในบิล PDF คือ 93251540-2 แต่ใน ASN เป็น 93251540-1', action: 'รวมหน้าต่อเนื่องเข้ากับบิลนี้', can_fix: true }),
    line('93251540-1', '8859384715616', 'PENDING_FIX', { reason: 'เลขที่พิมพ์ในบิล PDF คือ 93251540-2 แต่ใน ASN เป็น 93251540-1', action: 'รวมหน้าต่อเนื่องเข้ากับบิลนี้', can_fix: true }),
    line('93251542-1', '8859384715617', 'PENDING_FIX', { reason: 'ไฟล์นี้รวมหลายบิล', action: 'แยกไฟล์', can_fix: true }),
    line('93251543-1', '8859384715618', 'MATCH', { was: ['PENDING_ASN', 'PENDING_FIX'], sources: sources('ok', [VBRP_NOTE]) }),
    line('93251544-1', '8859384715619', 'MATCH', { fixed: true, fix_note: 'แยกหน้า 2 ของ b.pdf', was: ['PENDING_FIX'], printed_round: 1, sources: sources('ok', [VBRP_NOTE]) }),
    line('93251545-1', '8859384715620', 'MISMATCH', { reason: 'จำนวนไม่ตรง (PDF: 4 ชิ้น / ASN: 2 ชิ้น / VBRP: 2 ชิ้น)', was: ['PENDING_ASN'] }),
    line('93251546-1', '8859384715621', 'MATCH'),
    line('93251547-1', '8859384715622', 'PENDING_ASN', Object.assign({}, waitingAsn, { was: ['PENDING_FIX'] }))
];
const reconcile = { summary: { pending_fix: 3, pending_asn: 4, matched: 3, mismatch: 1 }, results: rows };
const fresh = () => JSON.parse(JSON.stringify(rows));

const registry = run(`['MATCH', 'FIXED', 'PENDING_FIX', 'PENDING_ASN', 'MISMATCH'].map(key => [key, STATUS_DISPLAY[key].label, STATUS_DISPLAY[key].tone, statusBadgeHtml(key)])`);
check(
    'the status registry names รอแก้ไข PDF (amber, wrench) and the new รอแก้ไข ASN in its own tone, and each of the five display statuses gets a badge of its tone and label',
    same(registry.map(r => r.slice(0, 3)), [['MATCH', 'สมบูรณ์', 'match'], ['FIXED', 'แก้ไขแล้ว', 'fixed'], ['PENDING_FIX', 'รอแก้ไข PDF', 'pending-fix'], ['PENDING_ASN', 'รอแก้ไข ASN', 'pending-asn'], ['MISMATCH', 'ผิดพลาด', 'mismatch']])
        && registry[2][3] === '<span class="pill-badge status-pending-fix"><i class="fa-solid fa-wrench me-1"></i>รอแก้ไข PDF</span>'
        && registry[3][3] === '<span class="pill-badge status-pending-asn"><i class="fa-solid fa-file-circle-exclamation me-1"></i>รอแก้ไข ASN</span>',
    registry
);

const statusOrder = run(`statusRows.map((r, i) => [ASN_CELLS.status.sort(r), i]).sort((a, b) => a[0] - b[0] || a[1] - b[1]).map(p => reconRowStatus(statusRows[p[1]]))`, {
    statusRows: [{ status: 'MATCH' }, { status: 'PENDING' }, { status: 'PENDING_FIX' }, { status: 'MISMATCH' }, { status: 'MATCH', fixed: true }, { status: 'PENDING_ASN' }]
});
check(
    'sorting the Status column ascending puts the worst first: ผิดพลาด, รอแก้ไข ASN, รอแก้ไข PDF, แก้ไขแล้ว, สมบูรณ์, then the rows not compared yet',
    same(statusOrder, ['MISMATCH', 'PENDING_ASN', 'PENDING_FIX', 'FIXED', 'MATCH', 'PENDING']),
    statusOrder
);

const chipOrder = id => chipsOf(id).map(chip => [chip.filter, chip.label]);
const wantChips = [['ALL', 'ทั้งหมด'], ['MATCH', 'สมบูรณ์'], ['FIXED', 'แก้ไขแล้ว'], ['PENDING_FIX', 'รอแก้ไข PDF'], ['PENDING_ASN', 'รอแก้ไข ASN'], ['MISMATCH', 'ผิดพลาด']];
const asnChip = chipsOf('reconFilterGroup').find(chip => chip.filter === 'PENDING_ASN') || { html: '' };
check(
    'the filter buttons of tab 2 and tab 3 read ทั้งหมด, สมบูรณ์, แก้ไขแล้ว, รอแก้ไข PDF, รอแก้ไข ASN, ผิดพลาด in that order, and the รอแก้ไข ASN button says to fix the ASN and import it again',
    same(chipOrder('reconFilterGroup'), wantChips) && same(chipOrder('dashFilterGroup'), wantChips)
        && asnChip.html.includes('id="count-pending-asn"') && asnChip.html.includes('title="ASN ไม่ตรง แก้ข้อมูลใน ASN แล้วนำเข้า ASN ใหม่ ปุ่มแก้ไขและสั่งพิมพ์ใช้ไม่ได้จนกว่าจะตรง"'),
    [chipOrder('reconFilterGroup'), chipOrder('dashFilterGroup')]
);

const bare = page.match(/รอแก้ไข(?! PDF| ASN)/g);
check(
    'no text of the page says รอแก้ไข alone: every one names PDF or ASN',
    bare === null,
    bare
);

run(`reconData = data; reconData.results.forEach(prepareReconRow); renderReconSummary();`, { data: { summary: reconcile.summary, results: fresh() } });
const counts = ['count-all', 'count-match', 'count-fixed', 'count-pending-fix', 'count-pending-asn', 'count-mismatch'].map(id => element(id).textContent);
check(
    'the counts of tab 2 count lines per status (รอแก้ไข ASN on its own), and the fix button counts the รอแก้ไข PDF lines only',
    same(counts, ['11', '2', '1', '3', '4', '1']) && element('btn-fix-all-count').textContent === '3' && element('btn-fix-all-pending').style.display === 'inline-flex',
    [counts, element('btn-fix-all-count').textContent]
);

const filtered = run(`setReconFilter('PENDING_ASN', null); reconDisplayData.map(r => r.pdf.barcode)`);
const filteredPdf = run(`setReconFilter('PENDING_FIX', null); reconDisplayData.map(r => r.pdf.barcode)`);
check(
    'the รอแก้ไข ASN filter shows only the lines waiting for the ASN, and the รอแก้ไข PDF filter only the lines waiting for the fix',
    same(filtered, ['8859384715612', '8859384715613', '8859384715614', '8859384715622']) && same(filteredPdf, ['8859384715615', '8859384715616', '8859384715617']),
    [filtered, filteredPdf]
);

const viewOf = r => run(`(() => { const v = reconRowView(r, 1); return { status: ASN_CELLS.status.html(v), remark: ASN_CELLS.remark.html(v), manage: ASN_CELLS.manage.html(v), search: prepareReconRow(r)._searchStr, excel: reconExcelRows([r])[0] }; })()`, { r });
const views = fresh().map(viewOf);
check(
    'the Remark of a รอแก้ไข ASN line is the problem and the solution of the server in the purple of the status, labelled, with the grey note of what the PDF still needs after the ASN',
    same(remarkLinesOf(views[0].remark), [['remark-pending-asn', 'ปัญหา : ', ASN_REASON], ['remark-pending-asn', 'วิธีแก้ไข : ', ASN_ACTION]])
        && same(remarkLinesOf(views[2].remark), [['remark-pending-asn', 'ปัญหา : ', ASN_REASON], ['remark-pending-asn', 'วิธีแก้ไข : ', ASN_ACTION], ['remark-note', '', FOLLOW_UP]])
        && views[0].search.includes('asn ยอดก่อน vat ไม่ตรง (asn 35,177.60'),
    views.slice(0, 3).map(v => remarkLinesOf(v.remark))
);
check(
    'a รอแก้ไข ASN line has no fix button in Manage (the fix button cannot fix it), a รอแก้ไข PDF line keeps its button',
    textOf(views[0].manage) === '-' && !views[0].manage.includes('openFixSingleConfirmModal') && !views[10].manage.includes('<button')
        && views[3].manage.includes(`openFixSingleConfirmModal('93251540-1')`),
    [views[0].manage, views[3].manage]
);
check(
    'a complete or fixed line that waited before shows "เดิม: <statuses>" in the grey note style after its remark and before the source notes; a line still waiting or wrong, or without a history, shows none',
    same(remarkLinesOf(views[6].remark), [['remark-match', '', 'ข้อมูลถูกต้อง'], ['remark-note', '', 'เดิม: รอแก้ไข ASN, รอแก้ไข PDF'], ['remark-note', '', VBRP_NOTE]])
        && same(remarkLinesOf(views[7].remark), [['remark-fixed', '', 'แยกหน้า 2 ของ b.pdf'], ['remark-note', '', 'เดิม: รอแก้ไข PDF'], ['remark-note', '', VBRP_NOTE]])
        && [8, 9, 10].every(i => !views[i].remark.includes('เดิม:'))
        && views[6].search.includes('เดิม: รอแก้ไข asn, รอแก้ไข pdf'),
    [6, 7, 8, 9, 10].map(i => remarkLinesOf(views[i].remark))
);
const excelPick = v => [v.excel.Status, v.excel['Previous status'], v.excel.Problem, v.excel.Solution];
check(
    'the Excel of the grid has the status text and a Previous status column after Status (the earlier statuses of the line, empty when none); Problem keeps the reason and the notes, never the เดิม line',
    same(Object.keys(views[0].excel).slice(0, 3), ['#', 'Status', 'Previous status'])
        && same(excelPick(views[2]), ['รอแก้ไข ASN', '', `${ASN_REASON}\n${FOLLOW_UP}`, ASN_ACTION])
        && same(excelPick(views[6]), ['สมบูรณ์', 'รอแก้ไข ASN, รอแก้ไข PDF', `ข้อมูลถูกต้อง\n${VBRP_NOTE}`, ''])
        && same(excelPick(views[7]), ['แก้ไขแล้ว', 'รอแก้ไข PDF', VBRP_NOTE, 'แยกหน้า 2 ของ b.pdf'])
        && same(excelPick(views[10]).slice(0, 2), ['รอแก้ไข ASN', 'รอแก้ไข PDF']) && views[3].excel.Status === 'รอแก้ไข PDF',
    [2, 3, 6, 7, 10].map(i => excelPick(views[i]))
);

const ready = run(`(() => { reconData = { summary: {}, results: data }; return countReadyUnprintedInvoices(); })()`, { data: fresh() });
const steps = run(`[
    asnToolbarStep({ summary: { pending_fix: 0, pending_asn: 321 }, results: [{}] }, 5, 23),
    asnToolbarStep({ summary: { pending_fix: 0, pending_asn: 321 }, results: [{}] }, 5, 0),
    asnToolbarStep({ summary: { pending_fix: 28, pending_asn: 321 }, results: [{}] }, 5, 23)
]`);
check(
    'only complete bills, fixed ones included, count as ready to print (a bill waiting for the ASN never does), and the toolbar does not wait for the fix when only bills waiting for the ASN are left',
    ready === 2 && same(steps, ['print', 'done', 'fix']),
    [ready, steps]
);

const skipped = run(`[
    describeSkipped({ pending_fix: 28, pending_asn: 321, mismatch: 1, printed: 0 }),
    describeSkipped({ pending_fix: 2, mismatch: 0, printed: 5 }),
    describeSkipped({ pending_fix: 0, mismatch: 0, printed: 0 })
]`);
check(
    'the print modal lists what it leaves out: รอแก้ไข PDF, then รอแก้ไข ASN when the plan skipped any, ผิดพลาด and the bills printed before',
    same(skipped, ['ไม่รวม รอแก้ไข PDF 28 บิล | รอแก้ไข ASN 321 บิล | ผิดพลาด 1 บิล', 'ไม่รวม รอแก้ไข PDF 2 บิล | สั่งพิมพ์ไปแล้ว 5 บิล', '']),
    skipped
);

run(`reconData = { summary: {}, results: data }; openFixAllConfirmModal();`, { data: fresh() });
check(
    'the fix-all modal is titled บิลที่รอแก้ไข PDF ทั้งหมด and counts the bills and lines waiting for the fix, none of those waiting for the ASN',
    element('fixModalTitle').textContent === 'บิลที่รอแก้ไข PDF ทั้งหมด' && element('fixModalTargetText').textContent === '2 บิล (3 รายการสินค้า)',
    [element('fixModalTitle').textContent, element('fixModalTargetText').textContent]
);

const asnHint = 'ASN ไม่ตรง แก้ข้อมูลใน ASN แล้วนำเข้า ASN ใหม่ ปุ่มแก้ไขและสั่งพิมพ์ใช้ไม่ได้จนกว่าจะตรง';
const panels = [...page.matchAll(/<article class="dash-panel"[\s\S]*?<\/article>/g)].map(m => m[0]);
const panelOf = id => panels.find(p => p.includes(`id="${id}"`)) || '';
check(
    'tab 3 shows the card รอแก้ไข PDF ทั้งหมด (needs_fix, with its mini bar) and next to it the card รอแก้ไข ASN (pending_asn) in its own colour with the hint to fix the ASN and import it again',
    panelOf('dash-needs_fix').includes('<span class="dash-label">รอแก้ไข PDF ทั้งหมด</span>') && panelOf('dash-needs_fix').includes('id="dash-mini-bar"')
        && panelOf('dash-pending_asn').includes('<span class="dash-label">รอแก้ไข ASN</span>') && panelOf('dash-pending_asn').includes(`<div class="dash-hint">${asnHint}</div>`)
        && panelOf('dash-pending_asn').includes('data-pct="pending_asn"') && panelOf('dash-pending_asn').includes('--tone-fg: #7e22ce')
        && panels.indexOf(panelOf('dash-pending_asn')) === panels.indexOf(panelOf('dash-needs_fix')) + 1,
    panels.map(p => (/class="dash-label"[^>]*>([^<]*)</.exec(p) || [])[1])
);

const shownDashboard = data => {
    run('renderDashboard(data)', { data });
    return {
        numbers: ['total', 'complete', 'needs_fix', 'not_fixed', 'fixed', 'pending_asn', 'error'].map(f => element(`dash-${f}`).textContent),
        pct: ['complete', 'needs_fix', 'pending_asn', 'error'].map(f => context.document.querySelector(`[data-pct="${f}"]`).textContent),
        bar: bars['dash-bar'].map(seg => [seg.dataset.seg, seg.style.flexGrow]),
        legend: legends.map(el => [el.dataset.legend, el.textContent])
    };
};
const beforeFix = shownDashboard({ compared: true, total: 373, complete: 23, needs_fix: 28, not_fixed: 28, fixed: 0, pending_asn: 321, error: 1, error_after_fix: 0, verdict_counts: null, invoices: [] });
const afterFix = shownDashboard({ compared: true, total: 373, complete: 23, needs_fix: 28, not_fixed: 0, fixed: 28, pending_asn: 321, error: 1, error_after_fix: 0, verdict_counts: null, invoices: [] });
const noVbrp = shownDashboard({ compared: true, total: 373, complete: 227, needs_fix: 145, not_fixed: 145, fixed: 0, error: 1, error_after_fix: 0, verdict_counts: null, invoices: [] });
const notCompared = shownDashboard({ compared: false, total: 373, complete: null, needs_fix: null, not_fixed: null, fixed: null, error: null, error_after_fix: 0, verdict_counts: null, invoices: [] });
check(
    'the cards, the stacked bar and its legend of tab 3 add รอแก้ไข ASN (pending_asn) between รอแก้ไข PDF and ผิดพลาด: 23 / 28 / 321 / 1 of 373 before the fix, 28 fixed after it',
    same(beforeFix.numbers, ['373', '23', '28', '28', '0', '321', '1']) && same(beforeFix.pct, ['6.2%', '7.5%', '86.1%', '0.3%'])
        && same(beforeFix.bar, [['complete', 23], ['fixed', 0], ['not_fixed', 28], ['pending_asn', 321], ['error', 1]])
        && same(beforeFix.legend, [['complete', '23'], ['fixed', '0'], ['not_fixed', '28'], ['pending_asn', '321'], ['error', '1']])
        && same(afterFix.bar, [['complete', 23], ['fixed', 28], ['not_fixed', 0], ['pending_asn', 321], ['error', 1]]),
    [beforeFix, afterFix]
);
check(
    'a compared Job without VBRP rows (no pending_asn in the answer) shows 0 bills waiting for the ASN, and a Job not compared yet shows "–" as the other cards do',
    noVbrp.numbers[5] === '0' && noVbrp.pct[2] === '0.0%' && same(noVbrp.legend[3], ['pending_asn', '0']) && same(noVbrp.bar[3], ['pending_asn', 0])
        && same(noVbrp.numbers.slice(0, 5), ['373', '227', '145', '145', '0'])
        && notCompared.numbers[5] === '–' && notCompared.pct[2] === '–' && same(notCompared.legend[3], ['pending_asn', '–']),
    [noVbrp, notCompared]
);

const dashHead = block(/id="dash-table"/, '</thead>');
const dashHeaders = [...dashHead.matchAll(/<th\b([^>]*)>([\s\S]*?)<\/th>/g)].map(m => [textOf(m[2]), (/data-col="([^"]*)"/.exec(m[1]) || [])[1], (/title="([^"]*)"/.exec(m[1]) || [])[1] || '']);
const wasHeader = dashHeaders.find(h => h[1] === 'was') || [];
check(
    'the bill table of tab 3 has a Previous status column right after Status, whose tooltip names it สถานะก่อนแก้ไข',
    dashHeaders.map(h => h[0]).indexOf('Previous status') === dashHeaders.map(h => h[0]).indexOf('Status') + 1 && wasHeader[0] === 'Previous status' && wasHeader[2].startsWith('สถานะก่อนแก้ไข'),
    dashHeaders.map(h => h.slice(0, 2))
);

const base = before.input.dashRows[0];
const bill = (inv, status, extra) => Object.assign({}, base, { invoice_no: inv, status, fixed: false, note: '', note_detail: '', problem: '', solution: '', verdict: all('ok', 'ok', 'ok'), vbrp: { cost: 1, vat: 0.07, lines: 1 }, notes: [] }, extra);
const history = [
    { at: '2026-10-28 08:05:00', event: 'compare', from: null, to: 'PENDING_ASN', detail: ASN_REASON },
    { at: '2026-10-28 09:10:30', event: 'asn_import', from: 'PENDING_ASN', to: 'PENDING_FIX', detail: 'เลขที่พิมพ์ในบิล PDF คือ 93251548-2 แต่ใน ASN เป็น 93251548-1' },
    { at: '2026-10-28 09:12:00', event: 'fix', from: 'PENDING_FIX', to: 'FIXED', detail: '' }
];
const bills = [
    bill('93251548-1', 'MATCH', { fixed: true, note: 'แยกหน้า 2 ของ b.pdf', was: ['PENDING_ASN', 'PENDING_FIX'], history }),
    bill('93251549-1', 'PENDING_ASN', { problem: ASN_REASON, solution: ASN_ACTION, verdict: all('ok', 'wrong', 'ok'), notes: [FOLLOW_UP], history: [history[0]] }),
    bill('93251550-1', 'MATCH', { was: ['PENDING_ASN'], history: [history[0], { at: '2026-10-28 10:00:00', event: 'asn_delete', from: 'PENDING_ASN', to: 'MATCH', detail: '' }] }),
    bill('93251551-1', 'PENDING_FIX', { problem: 'ไฟล์นี้รวมหลายบิล', solution: 'แยกไฟล์' }),
    bill('93251552-1', 'MISMATCH', { problem: 'จำนวนไม่ตรง', verdict: all('wrong', 'ok', 'ok') }),
    bill('93251553-1', 'MATCH', {})
];
const dash = run(`(async () => {
    renderDashboard({ compared: true, total: 6, complete: 2, needs_fix: 2, not_fixed: 1, fixed: 1, pending_asn: 1, error: 1, error_after_fix: 0, verdict_counts: null, invoices: bills });
    const chips = Object.fromEntries(dashChipEls.map(btn => [btn.dataset.filter, btn.count.textContent]));
    const html = dashboardTable.rows.map(dashboardRowHtml);
    const search = Object.fromEntries(dashboardTable.rows.map(row => [row.invoice_no, row._search]));
    setDashboardFilter('PENDING_ASN');
    const asnOnly = visibleDashboardRows().map(row => row.invoice_no);
    setDashboardFilter('ALL');
    dashboardTable.sort = { column: 'was', direction: 'desc' };
    const byWas = visibleDashboardRows().map(row => row.invoice_no);
    dashboardTable.sort = { column: 'store', direction: 'asc' };
    await exportDashboardToExcel();
    return { chips, html, search, asnOnly, byWas };
})()`, { bills: JSON.parse(JSON.stringify(bills)), dashChipEls: dashChips });
dash.then(d => {
    const rowOf = inv => cellsOf(d.html.find(html => html.includes(`>${inv}</td>`)) || '');
    const wasCell = inv => {
        const cell = rowOf(inv)[8] || '';
        return { text: textOf(cell), title: ((/title="([^"]*)"/.exec(cell) || [])[1] || '').replace(/&quot;/g, '"'), cls: (/class="([^"]*)"/.exec(cell) || [])[1] };
    };
    check(
        'the filter buttons of tab 3 count the bills of each status, รอแก้ไข ASN included, and the รอแก้ไข ASN button shows only those bills',
        same(d.chips, { ALL: '6', MATCH: '2', FIXED: '1', PENDING_FIX: '1', PENDING_ASN: '1', MISMATCH: '1' }) && same(d.asnOnly, ['93251549-1']),
        [d.chips, d.asnOnly]
    );
    check(
        'a bill of tab 3 shows its earlier statuses in Previous status ("รอแก้ไข ASN, รอแก้ไข PDF", blank when none) and its badge after Status; the Remark of a bill waiting for the ASN holds the problem, the solution and the grey follow-up note',
        same(wasCell('93251548-1').text, 'รอแก้ไข ASN, รอแก้ไข PDF') && wasCell('93251550-1').text === 'รอแก้ไข ASN' && wasCell('93251549-1').text === ''
            && wasCell('93251553-1').text === '' && wasCell('93251548-1').cls === 'dash-was'
            && rowOf('93251549-1')[7].includes('status-pending-asn') && rowOf('93251549-1')[7].includes('รอแก้ไข ASN')
            && same(remarkLinesOf(rowOf('93251549-1')[12]), [['remark-pending-asn', 'ปัญหา : ', ASN_REASON], ['remark-pending-asn', 'วิธีแก้ไข : ', ASN_ACTION], ['remark-note', '', FOLLOW_UP]]),
        ['93251548-1', '93251549-1', '93251550-1', '93251553-1'].map(wasCell)
    );
    check(
        'hovering Previous status lists the history oldest first, one change a line, in this computer\'s time from the UTC the server writes: date, the Thai name of the event, the change, and the problem of that time under it',
        wasCell('93251548-1').title === [
            `28/10/2026 15:05 เทียบ: รอแก้ไข ASN\n    ${ASN_REASON}`,
            '28/10/2026 16:10 นำเข้า ASN: รอแก้ไข ASN → รอแก้ไข PDF\n    เลขที่พิมพ์ในบิล PDF คือ 93251548-2 แต่ใน ASN เป็น 93251548-1',
            '28/10/2026 16:12 แก้ไข: รอแก้ไข PDF → แก้ไขแล้ว'
        ].join('\n')
            && wasCell('93251550-1').title.endsWith('28/10/2026 17:00 ลบแถว ASN: รอแก้ไข ASN → สมบูรณ์')
            && wasCell('93251549-1').title === '' && !rowOf('93251549-1')[8].includes('title='),
        wasCell('93251548-1').title
    );
    const events = run(`Object.keys(HISTORY_EVENTS).map(key => [key, HISTORY_EVENTS[key]])`);
    check(
        'every event the server records has its Thai name: เทียบ, นำเข้า ASN, ลบแถว ASN, นำเข้า VBRP, ล้าง VBRP, แก้ไข',
        same(events, [['compare', 'เทียบ'], ['asn_import', 'นำเข้า ASN'], ['asn_delete', 'ลบแถว ASN'], ['vbrp_import', 'นำเข้า VBRP'], ['vbrp_clear', 'ล้าง VBRP'], ['fix', 'แก้ไข']]),
        events
    );
    check(
        'the search of tab 3 finds a bill by its earlier statuses, and sorting Previous status descending puts the bills with the longest text first and the ones without last',
        d.search['93251548-1'].includes('รอแก้ไข asn, รอแก้ไข pdf') && !d.search['93251553-1'].includes('รอแก้ไข')
            && same(d.byWas.slice(0, 2), ['93251548-1', '93251550-1']),
        [d.search, d.byWas]
    );
    const excel = sheets[sheets.length - 1];
    const byInvoice = Object.fromEntries(excel.map(row => [row['Tax Invoice No.'], row]));
    check(
        'the Excel of tab 3 has Previous status after Status with the same text as the table (empty when none) and รอแก้ไข ASN as the status of a bill waiting for the ASN',
        same(Object.keys(excel[0]).slice(9, 12), ['Status', 'Previous status', 'PDF'])
            && byInvoice['93251548-1']['Previous status'] === 'รอแก้ไข ASN, รอแก้ไข PDF' && byInvoice['93251548-1'].Status === 'แก้ไขแล้ว'
            && byInvoice['93251549-1']['Previous status'] === null && byInvoice['93251549-1'].Status === 'รอแก้ไข ASN' && byInvoice['93251551-1'].Status === 'รอแก้ไข PDF',
        excel.map(row => [row['Tax Invoice No.'], row.Status, row['Previous status']])
    );
    return compareAndFix();
}).then(() => process.stdout.write(JSON.stringify(results)));

async function compareAndFix() {
    answers = {
        get_job_reconcile: { status: 'success', asn_reconciled: 1, asn_filename: 'ASN.xlsx', asn_counts: { files: 1, rows: 11 }, data: { summary: reconcile.summary, results: fresh() } },
        fix_invoice: { status: 'success', data: { success: false, message: 'บิลนี้รอแก้ไข ASN แก้ไข PDF ไม่ได้ ต้องแก้ ASN แล้วนำเข้าใหม่ก่อน' } }
    };
    requests.length = 0;
    notices.length = 0;
    await run('executeConfirmedReconcile()');
    const compareUrls = requests.map(r => r.url);
    const compareNotice = notices[0] || {};
    requests.length = 0;
    await run('checkExistingReconcile()');
    await run('recompareAfterAsnOverwrite()');
    const loadUrls = requests.map(r => r.url);
    check(
        'only the compare button asks the server to record the compare in the history (event=compare); a page load and the compare after an ASN import do not',
        same(compareUrls, ['api.php?action=get_job_reconcile&job_id=1&force=1&event=compare'])
            && same(loadUrls, ['api.php?action=get_job_reconcile&job_id=1', 'api.php?action=get_job_reconcile&job_id=1&force=1']),
        [compareUrls, loadUrls]
    );
    check(
        'the notice after the compare counts the lines of each status, รอแก้ไข PDF and รอแก้ไข ASN apart',
        compareNotice.detail === 'ผลการเปรียบเทียบ:\n• สมบูรณ์: 2 รายการ\n• แก้ไขแล้ว: 1 รายการ\n• รอแก้ไข PDF: 3 รายการ\n• รอแก้ไข ASN: 4 รายการ\n• ผิดพลาด: 1 รายการ',
        compareNotice
    );

    requests.length = 0;
    notices.length = 0;
    run(`openFixSingleConfirmModal('93251547-1')`);
    await run('executeConfirmedFix()');
    check(
        'when the server refuses to fix a bill waiting for the ASN (success false), the page reloads the grid and shows the server\'s message instead of a success notice',
        same(requests.map(r => r.url), ['api.php?action=fix_invoice', 'api.php?action=get_job_reconcile&job_id=1']) && requests[0].fields.invoice_no === '93251547-1'
            && notices.length === 1 && notices[0].title === 'แก้ไขไม่ได้' && notices[0].message === 'บิลนี้รอแก้ไข ASN แก้ไข PDF ไม่ได้ ต้องแก้ ASN แล้วนำเข้าใหม่ก่อน' && notices[0].type === 'warning',
        [requests, notices]
    );
}
