// Runs the scripts of workspace.php tabs 2 and 3 (state, asn-import, vbrp-import, asn-table, asn-fix-export, dashboard)
// on rows of the three-source compare and prints one { name, ok, detail } per check as JSON. job_safety_test.php reports
// them. Rows without `sources` are held to tests/fixtures/workspace_rows_before_vbrp.json, which the scripts wrote
// before VBRP existed (only the PENDING_FIX label was renamed since, to รอแก้ไข PDF). Usage: node tests/vbrp_view.js
// <rendered workspace.php>
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const page = fs.readFileSync(process.argv[2], 'utf8');
const columns = /const ASN_COLUMNS = (.*?);\n/.exec(page)[1];
const before = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures', 'workspace_rows_before_vbrp.json'), 'utf8'));
const XLSX = require(path.join(__dirname, '..', 'assets', 'vendor', 'xlsx.full.min.js'));

const elements = {};
const element = id => elements[id] || (elements[id] = {
    id, innerHTML: '', textContent: '', disabled: false, title: '', dataset: {}, style: {},
    addEventListener() {}, querySelectorAll: () => [], querySelector: () => element(`${id} >`),
    classList: { add() {}, remove() {}, toggle() {} }, removeAttribute() {}
});
const sheets = [];
const context = vm.createContext({
    console, Intl, setTimeout, clearTimeout,
    document: { getElementById: element, querySelectorAll: () => [], querySelector: () => element('query'), addEventListener() {} },
    XLSX: Object.assign({}, XLSX, {
        utils: Object.assign({}, XLSX.utils, { json_to_sheet: rows => { sheets.push(rows); return XLSX.utils.json_to_sheet(rows); } }),
        writeFile() {}
    })
});
vm.runInContext(`const CURRENT_JOB_ID = 1; const CURRENT_JOB_NAME_CLEAN = 'T'; const ASN_COLUMNS = ${columns};`, context);
for (const file of ['ui-utils.js', 'workspace/state.js', 'workspace/asn-import.js', 'workspace/vbrp-import.js', 'workspace/asn-table.js', 'workspace/asn-fix-export.js', 'workspace/dashboard.js']) {
    vm.runInContext(fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', file), 'utf8'), context, { filename: file });
}
const run = (code, values = {}) => {
    Object.assign(context, values);
    return vm.runInContext(code, context);
};

const results = [];
const check = (name, ok, detail) => results.push({ name, ok: !!ok, detail: ok ? '' : JSON.stringify(detail) });
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const cellsOf = html => html.match(/<td\b[^>]*>[\s\S]*?<\/td>/g) || [];
const verdictShown = html => {
    const m = /<td class="text-center verdict-cell" title="([^"]*)"><span class="([^"]*)">(.*?)<\/span><\/td>/.exec(html);
    return m ? { title: m[1].replace(/&quot;/g, '"'), className: m[2], mark: m[3] } : html;
};

const NEW_KEYS = ['vbrp_qty', 'vbrp_price', 'vbrp_amt', 'vbrp_vat', 'verdict_pdf', 'verdict_asn', 'verdict_vbrp'];
const keys = run('ASN_COLUMNS.map(col => col.key)');
check(
    'the ASN grid keeps every column it had, in its order, with Qty (VBRP) after Qty (ASN), Sell Price (VBRP) after Sell Price (ASN), Amt Excl VAT (VBRP) after Cost Ex VAT (ASN), VAT (VBRP) after the VAT column, then PDF, ASN, VBRP before Remark',
    same(keys.filter(k => !NEW_KEYS.includes(k)), before.golden.columns)
        && same(keys.slice(keys.indexOf('asn_qty'), keys.indexOf('asn_qty') + 2), ['asn_qty', 'vbrp_qty'])
        && same(keys.slice(keys.indexOf('asn_price'), keys.indexOf('asn_price') + 2), ['asn_price', 'vbrp_price'])
        && same(keys.slice(keys.indexOf('asn_amt'), keys.indexOf('asn_amt') + 2), ['asn_amt', 'vbrp_amt'])
        && same(keys.slice(keys.indexOf('vat_amt'), keys.indexOf('remark') + 1), ['vat_amt', 'vbrp_vat', 'verdict_pdf', 'verdict_asn', 'verdict_vbrp', 'remark']),
    keys
);

const legacy = run(`reconRows.concat(rawRecords.map(prepareRawAsnRow))`, JSON.parse(JSON.stringify(before.input)));
const legacyView = run(`legacy.map((r, i) => {
    const view = reconRowView(r, i + 1);
    const cells = {}, sorts = {};
    ASN_COLUMNS.forEach(col => {
        cells[col.key] = ASN_CELLS[col.key].html(view);
        if (ASN_CELLS[col.key].sort) sorts[col.key] = ASN_CELLS[col.key].sort(r);
    });
    return { cells, sorts, search: prepareReconRow(r)._searchStr };
})`, { legacy });
const legacyDiff = [];
legacyView.forEach((row, i) => {
    const old = before.golden.recon[i];
    before.golden.columns.forEach(key => {
        if (row.cells[key] !== old.cells[key]) legacyDiff.push([i, key, old.cells[key], row.cells[key]]);
        if (row.sorts[key] !== old.sorts[key]) legacyDiff.push([i, `sort ${key}`, old.sorts[key], row.sorts[key]]);
    });
    if (row.search !== old.search) legacyDiff.push([i, 'search', old.search, row.search]);
    NEW_KEYS.forEach(key => {
        const shown = key.startsWith('verdict_') ? verdictShown(row.cells[key]) : row.cells[key];
        const want = key.startsWith('verdict_')
            ? { title: 'ยังไม่มีผลเทียบกับ VBRP', className: 'text-null-subtle', mark: '-' }
            : /^<td class="[^"]*"><span class="text-null-subtle">-<\/span><\/td>$/;
        if (want instanceof RegExp ? !want.test(shown) : !same(shown, want)) legacyDiff.push([i, key, shown]);
    });
});
check(
    'a row without sources (before the compare, or a bill VBRP does not cover) shows every old cell of the grid, its sort value and its search text exactly as before VBRP, and "-" in the four VBRP and three verdict cells',
    legacyView.length === 7 && legacyDiff.length === 0,
    legacyDiff.slice(0, 3)
);

const legacyExcel = run('reconExcelRows(legacy)', { legacy });
const excelKeys = ['#', 'Status', 'Previous status', 'Tax Invoice No.', 'Print', 'Source File', 'Barcode / EAN', 'Description', 'Store (PDF)', 'Store (ASN)', 'Qty (PDF)', 'Qty (ASN)', 'Qty (VBRP)',
    'RSP Price (PDF)', 'Sell Price (ASN)', 'Sell Price (VBRP)', 'Amt Excl VAT (PDF)', 'Cost Ex VAT (ASN)', 'Amt Excl VAT (VBRP)', 'VAT (PDF)', 'VAT (ASN)', 'VAT (VBRP)', 'PDF', 'ASN', 'VBRP', 'Problem', 'Solution'];
const excelDiff = legacyExcel.map((row, i) => {
    const old = before.golden.reconExcel[i];
    const kept = Object.keys(old).every(k => row[k] === old[k]);
    const added = ['Qty (VBRP)', 'Sell Price (VBRP)', 'Amt Excl VAT (VBRP)', 'VAT (VBRP)', 'Previous status'].every(k => row[k] === '') && ['PDF', 'ASN', 'VBRP'].every(k => row[k] === '-');
    return kept && added && same(Object.keys(row), excelKeys) ? null : [i, old, row];
}).filter(Boolean);
check(
    'the Excel rows of rows without sources keep every old column and value, with the VBRP columns and Previous status (after Status) empty and PDF, ASN, VBRP "-", in the order of the grid',
    legacyExcel.length === 7 && excelDiff.length === 0,
    excelDiff.slice(0, 1)
);

const legacyDash = run(`dashboardTable.rows = dashRows.map(prepareDashboardRow); visibleDashboardRows().map(dashboardRowHtml)`, { dashRows: JSON.parse(JSON.stringify(before.input.dashRows)) });
const oldDashRows = before.golden.dashboardTbody.split('</tr>').filter(part => part.includes('<td'));
const dashDiff = legacyDash.map((html, i) => {
    const cells = cellsOf(html);
    const verdictCells = cells.slice(9, 12).map(verdictShown);
    const kept = cells.slice(0, 8).concat(cells.slice(12));
    return same(kept, cellsOf(oldDashRows[i])) && cells[8] === '<td class="dash-was"></td>'
        && verdictCells.every(c => c.mark === '-' && c.className === 'text-null-subtle') ? null : [i, cellsOf(oldDashRows[i]), cells];
}).filter(Boolean);
check(
    'a dashboard bill without a verdict or a history keeps every cell it had before VBRP, with an empty Previous status cell after Status and three "-" verdict cells before Remark',
    legacyDash.length === 3 && oldDashRows.length === 3 && dashDiff.length === 0,
    dashDiff.slice(0, 1)
);

const file = 'RM Document-Customer Billing(PDF)-0093248051 3.pdf';
const line = (status, reason, sources, extra) => Object.assign({
    status, fixed: false, reason, action: '', amount_ok: null, can_fix: false, source_files: [file],
    pdf: { tax_invoice: '93251539-1', barcode: '8859384715612', description: 'JEANS', store_code: '10114', branch_code: '00114', units: 2, unit_price: '2,790.00', inv_amt_excl_vat: '20,456.74', amt_excl_vat: 'Null', amount: '5,580.00', inv_vat_amount: '1,431.97', vat_amount: 'Null' },
    asn: { invoice_no: '93251539-1', ean_upc: '8859384715612', dest_store_no: '10114', quantity: 2, row_count: 1, selling_price: 2792, total_cost_ex_vat: 35177.6, total_vat: 1431.97 },
    sources
}, extra || {});
const all = (pdf, asn, vbrp) => ({ pdf, asn, vbrp });
const asnAmountNote = 'ASN ไม่ตรง: ยอดก่อน VAT 35,177.60 (PDF: 20,456.74 / VBRP: 20,456.74)';
const vbrpQtyNote = 'VBRP ไม่ตรง: จำนวน 0 ชิ้น (PDF: 3 ชิ้น / ASN: 3 ชิ้น)';
const covered = [
    line('MATCH', 'ข้อมูลถูกต้อง', {
        vbrp: { qty: 2, price: 2792.0, amount: 20456.74, vat: 1431.97 },
        verdict: { qty: all('ok', 'ok', 'ok'), amount: all('ok', 'wrong', 'ok'), vat: all('ok', 'ok', 'ok') },
        overall: all('ok', 'wrong', 'ok'),
        notes: [asnAmountNote]
    }),
    line('MISMATCH', 'จำนวนไม่ตรง (PDF: 4 ชิ้น / ASN: 2 ชิ้น / VBRP: 2 ชิ้น)', {
        vbrp: { qty: 2, price: 2792.0, amount: 20456.74, vat: 1431.97 },
        verdict: { qty: all('wrong', 'ok', 'ok'), amount: all('ok', 'ok', 'ok'), vat: all('ok', 'ok', 'ok') },
        overall: all('wrong', 'ok', 'ok'),
        notes: []
    }),
    line('MATCH', 'ข้อมูลถูกต้อง', {
        vbrp: { qty: 0, price: null, amount: 100.5, vat: null },
        verdict: { qty: all('ok', 'ok', 'wrong'), amount: all('ok', 'ok', 'ok'), vat: all('na', 'na', 'na') },
        overall: all('ok', 'ok', 'wrong'),
        notes: [vbrpQtyNote, 'ASN มีบาร์โค้ดนี้แต่ PDF และ VBRP ไม่มี']
    }, { fixed: true, fix_note: 'แยกหน้า 2 ของ b.pdf' }),
    line('MATCH', 'ข้อมูลถูกต้อง', {
        vbrp: { qty: null, price: null, amount: null, vat: null },
        verdict: { qty: all('na', 'na', 'na'), amount: all('na', 'na', 'na'), vat: all('na', 'na', 'na') },
        overall: all('na', 'na', 'na'),
        notes: []
    }),
    line('MISMATCH', 'ยอดรวมไม่ตรง (PDF: 20,456.74 / ASN: 35,177.60 / VBRP: -)', null)
];
const view = run(`covered.map((r, i) => {
    const v = reconRowView(r, i + 1);
    const cells = {};
    ASN_COLUMNS.forEach(col => { cells[col.key] = ASN_CELLS[col.key].html(v); });
    return cells;
})`, { covered });

const marks = view.map(cells => ['verdict_pdf', 'verdict_asn', 'verdict_vbrp'].map(key => verdictShown(cells[key])));
const markOf = shown => `${shown.mark} ${shown.className}`;
check(
    'a covered line shows each source as ✓ in green (ok), ✗ in red (wrong) or "-" (not judged), from sources.overall; a bill VBRP does not cover shows "-" for all three',
    same(marks.map(row => row.map(markOf)), [
        ['✓ verdict-ok', '✗ verdict-wrong', '✓ verdict-ok'],
        ['✗ verdict-wrong', '✓ verdict-ok', '✓ verdict-ok'],
        ['✓ verdict-ok', '✓ verdict-ok', '✗ verdict-wrong'],
        ['- text-null-subtle', '- text-null-subtle', '- text-null-subtle'],
        ['- text-null-subtle', '- text-null-subtle', '- text-null-subtle']
    ]),
    marks.map(row => row.map(markOf))
);
check(
    'the tooltip of a verdict cell names the source and its verdict, then what each check (quantity, amount before VAT, VAT; the selling price is none) said about that source',
    marks[0][1].title === 'ASN: ไม่ตรง\nจำนวน: ตรง\nยอดก่อน VAT: ไม่ตรง\nVAT: ตรง'
        && marks[0][0].title === 'PDF: ตรง\nจำนวน: ตรง\nยอดก่อน VAT: ตรง\nVAT: ตรง'
        && marks[2][2].title === 'VBRP: ไม่ตรง\nจำนวน: ไม่ตรง\nยอดก่อน VAT: ตรง\nVAT: ไม่ได้ตัดสิน'
        && marks[4][0].title === 'ยังไม่มีผลเทียบกับ VBRP',
    [marks[0][1].title, marks[0][0].title, marks[2][2].title, marks[4][0].title]
);

const figureText = html => html.replace(/<[^>]+>/g, '').trim();
const figures = view.map(cells => ['vbrp_qty', 'vbrp_price', 'vbrp_amt', 'vbrp_vat'].map(key => figureText(cells[key])));
const redFigures = view.map(cells => ['vbrp_qty', 'vbrp_price', 'vbrp_amt', 'vbrp_vat'].filter(key => cells[key].includes('cell-mismatch')));
check(
    'the VBRP cells show the line quantity, selling price, bill amount before VAT and bill VAT of sources.vbrp (money with two decimals), "-" for a figure VBRP has none of, and mark red only a figure its check judges VBRP wrong on (the selling price has none)',
    same(figures, [['2', '2,792.00', '20,456.74', '1,431.97'], ['2', '2,792.00', '20,456.74', '1,431.97'], ['0', '-', '100.50', '-'], ['-', '-', '-', '-'], ['-', '-', '-', '-']])
        && same(redFigures, [[], [], ['vbrp_qty'], [], []]),
    [figures, redFigures]
);

const remarks = run(`covered.map(r => ({ html: ASN_CELLS.remark.html(reconRowView(r, 1)), search: prepareReconRow(r)._searchStr, excel: reconExcelRows([r])[0] }))`, { covered });
const remarkLinesOf = html => [...html.matchAll(/<div class="([^"]*)">(?:<span class="remark-label">[^<]*<\/span>)?([^<]*)<\/div>/g)].map(m => [m[1], m[2]]);
check(
    'the Remark shows the reason (or the fix note) in the colour of the status, then each note of sources.notes on a line of its own in the quieter remark-note style',
    same(remarkLinesOf(remarks[0].html), [['remark-match', 'ข้อมูลถูกต้อง'], ['remark-note', asnAmountNote]])
        && same(remarkLinesOf(remarks[1].html), [['remark-mismatch', 'จำนวนไม่ตรง (PDF: 4 ชิ้น / ASN: 2 ชิ้น / VBRP: 2 ชิ้น)']])
        && same(remarkLinesOf(remarks[2].html), [['remark-fixed', 'แยกหน้า 2 ของ b.pdf'], ['remark-note', vbrpQtyNote], ['remark-note', 'ASN มีบาร์โค้ดนี้แต่ PDF และ VBRP ไม่มี']]),
    remarks.slice(0, 3).map(r => remarkLinesOf(r.html))
);
check(
    'the search text of a line holds its notes, so typing part of a note finds the line',
    remarks[0].search.includes('asn ไม่ตรง: ยอดก่อน vat 35,177.60') && remarks[2].search.includes('vbrp ไม่ตรง: จำนวน 0 ชิ้น') && !remarks[1].search.includes('ไม่ตรง:'),
    remarks.map(r => r.search)
);
check(
    'the Excel row of a covered line has its VBRP figures as numbers, the verdicts as ตรง / ไม่ตรง / -, and the notes appended to Problem after the reason',
    remarks[0].excel['Qty (VBRP)'] === 2 && remarks[0].excel['Amt Excl VAT (VBRP)'] === 20456.74 && remarks[2].excel['Sell Price (VBRP)'] === ''
        && same(['PDF', 'ASN', 'VBRP'].map(k => remarks[0].excel[k]), ['ตรง', 'ไม่ตรง', 'ตรง'])
        && same(['PDF', 'ASN', 'VBRP'].map(k => remarks[3].excel[k]), ['-', '-', '-'])
        && remarks[0].excel.Problem === `ข้อมูลถูกต้อง\n${asnAmountNote}`
        && remarks[2].excel.Problem === `${vbrpQtyNote}\nASN มีบาร์โค้ดนี้แต่ PDF และ VBRP ไม่มี` && remarks[2].excel.Solution === 'แยกหน้า 2 ของ b.pdf'
        && remarks[1].excel.Problem === 'จำนวนไม่ตรง (PDF: 4 ชิ้น / ASN: 2 ชิ้น / VBRP: 2 ชิ้น)',
    remarks.map(r => r.excel)
);

const order = run(`['verdict_pdf', 'verdict_asn', 'verdict_vbrp'].map(key => covered.map((r, i) => [ASN_CELLS[key].sort(r), i]).sort((a, b) => a[0] - b[0] || a[1] - b[1]).map(p => p[1]))`, { covered });
check(
    'sorting a verdict column ascending puts the lines that source got wrong first, then the ones it got right, then the ones not judged or without VBRP',
    same(order, [[1, 0, 2, 3, 4], [0, 1, 2, 3, 4], [2, 0, 1, 3, 4]]),
    order
);

const steps = run(`[
    asnToolbarStep(null, 5, 0),
    asnToolbarStep({ summary: null, results: [] }, 5, 0),
    asnToolbarStep({ summary: null, results: [{}] }, 0, 0),
    asnToolbarStep({ summary: null, results: [{}] }, 5, 0),
    asnToolbarStep({ summary: { pending_fix: 2 }, results: [{}] }, 0, 3),
    asnToolbarStep({ summary: { pending_fix: 0 }, results: [{}] }, 5, 3),
    asnToolbarStep({ summary: { pending_fix: 0 }, results: [{}] }, 0, 0)
]`);
check(
    'the toolbar step goes ASN (select), then VBRP (vbrp), then compare, then fix, print or done; a Job compared before keeps its fix, print or done step without VBRP',
    same(steps, ['select', 'select', 'vbrp', 'compare', 'fix', 'print', 'done']),
    steps
);
const blockers = run(`[compareBlocker(0, 0), compareBlocker(10, 0), compareBlocker(0, 10), compareBlocker(10, 10)]`);
check(
    'the compare button stays disabled until the Job has ASN rows and VBRP rows, and its title names what is still missing',
    same(blockers, ['นำเข้าไฟล์ ASN และ VBRP ก่อน จึงจะเทียบกับ PDF ได้', 'นำเข้าไฟล์ VBRP ก่อน จึงจะเทียบกับ PDF ได้', 'นำเข้าไฟล์ ASN ก่อน จึงจะเทียบกับ PDF ได้', '']),
    blockers
);

const texts = run(`({
    pill: vbrpPillText({ rows: 1893, invoices: 373 }),
    empty: vbrpPillText({ rows: 0, invoices: 0 }),
    toast: vbrpImportToast({ rows: 1893, invoices: 373, replaced_invoices: [], ignored_sheets: [] }),
    replaced: vbrpImportToast({ rows: 1893, invoices: 373, replaced_invoices: ['93250512-1', '93250519-1'], ignored_sheets: ['Sheet2'] }),
    coverage: vbrpCoverageLines({ covered_bills: 370, uncovered_bills: 3 }),
    fullCoverage: vbrpCoverageLines({ covered_bills: 373, uncovered_bills: 0 }),
    noVbrp: vbrpCoverageLines(undefined)
})`);
check(
    'the VBRP pill reads "VBRP: n บิล / n แถว" or "ยังไม่ได้นำเข้า VBRP", the import toast "นำเข้า VBRP n แถว n บิล" with the replaced bills and skipped sheets when any, and the compare notice adds the bills VBRP covers and does not',
    texts.pill === 'VBRP: 373 บิล / 1,893 แถว' && texts.empty === 'ยังไม่ได้นำเข้า VBRP'
        && texts.toast === 'นำเข้า VBRP 1,893 แถว 373 บิล' && texts.replaced === 'นำเข้า VBRP 1,893 แถว 373 บิล แทนที่ 2 บิลเดิม (ข้ามชีต Sheet2)'
        && same(texts.coverage, ['• บิลที่มีใน VBRP: 370 บิล', '• บิลที่ไม่มีใน VBRP (เทียบแค่ PDF กับ ASN): 3 บิล'])
        && same(texts.fullCoverage, ['• บิลที่มีใน VBRP: 373 บิล']) && same(texts.noVbrp, []),
    texts
);

const strip = run(`({
    shown: dashboardVerdictStripHtml({ pdf: { ok: 360, wrong: 6, na: 7 }, asn: { ok: 51, wrong: 322, na: 0 }, vbrp: { ok: 373, wrong: 0, na: 0 } }),
    hidden: dashboardVerdictStripHtml(null)
})`);
const stripItems = [...strip.shown.matchAll(/<span class="dash-verdict-item( has-wrong)?" title="([^"]*)">(.*?)<\/span>/g)]
    .map(m => [m[3].replace(/<[^>]+>/g, ''), !!m[1], m[2]]);
check(
    'the strip under the dashboard cards reads "PDF ผิด n บิล / ASN ผิด n บิล / VBRP ผิด n บิล" from verdict_counts, marks red only a count above zero, gives each part the ok / wrong / not judged counts on hover, and is empty (hidden) when verdict_counts is null',
    same(stripItems, [
        ['PDF ผิด 6 บิล', true, 'PDF: ตรง 360 บิล / ไม่ตรง 6 บิล / ไม่ได้ตัดสิน 7 บิล'],
        ['ASN ผิด 322 บิล', true, 'ASN: ตรง 51 บิล / ไม่ตรง 322 บิล / ไม่ได้ตัดสิน 0 บิล'],
        ['VBRP ผิด 0 บิล', false, 'VBRP: ตรง 373 บิล / ไม่ตรง 0 บิล / ไม่ได้ตัดสิน 0 บิล']
    ]) && (strip.shown.match(/dash-verdict-sep/g) || []).length === 2 && strip.hidden === '',
    [stripItems, strip.hidden]
);

const bills = [
    Object.assign({}, before.input.dashRows[0], { verdict: all('ok', 'wrong', 'ok'), vbrp: { cost: 20456.74, vat: 1431.97, lines: 2 }, notes: [asnAmountNote] }),
    Object.assign({}, before.input.dashRows[1], { verdict: all('wrong', 'ok', 'na'), vbrp: { cost: 100.5, vat: 7.04, lines: 1 }, notes: [] }),
    Object.assign({}, before.input.dashRows[2], { verdict: null, vbrp: null, notes: [] })
];
const dash = run(`(async () => {
    const prepared = bills.map(prepareDashboardRow);
    const html = prepared.map(dashboardRowHtml);
    dashboardTable.rows = prepared;
    await exportDashboardToExcel();
    return { html, search: prepared.map(row => row._search) };
})()`, { bills: JSON.parse(JSON.stringify(bills)) });
dash.then(result => {
    const billMarks = result.html.map(html => cellsOf(html).slice(9, 12).map(verdictShown).map(markOf));
    const noteLines = remarkLinesOf(cellsOf(result.html[0])[12]);
    check(
        'a dashboard bill shows its PDF, ASN, VBRP verdicts (row.verdict) after Status and its notes under the remark in the remark-note style; the search finds a note',
        same(billMarks, [['✓ verdict-ok', '✗ verdict-wrong', '✓ verdict-ok'], ['✗ verdict-wrong', '✓ verdict-ok', '- text-null-subtle'], ['- text-null-subtle', '- text-null-subtle', '- text-null-subtle']])
            && same(noteLines, [['remark-note', asnAmountNote]]) && result.search[0].includes('asn ไม่ตรง: ยอดก่อน vat'),
        [billMarks, noteLines]
    );
    const excel = sheets[sheets.length - 1];
    const byInvoice = Object.fromEntries(excel.map(row => [row['Tax Invoice No.'], row]));
    const legacyBefore = Object.fromEntries(before.golden.dashboardExcel.map(row => [row['Tax Invoice No.'], row]));
    check(
        'the dashboard Excel keeps every old column and value, adds Amt Excl VAT (VBRP) after Total Cost Ex VAT, Previous status (empty without a history) after Status and PDF, ASN, VBRP after it (ตรง / ไม่ตรง / -), and appends the notes to Remark',
        same(Object.keys(excel[0]), ['#', 'Dest Store No.', 'Branch Code', 'Tax Invoice No.', 'Source File', 'Total Qty', 'Total Cost Ex VAT', 'Amt Excl VAT (VBRP)', 'Pages', 'Status', 'Previous status', 'PDF', 'ASN', 'VBRP', 'Remark'])
            && excel.every(row => row['Previous status'] === null)
            && byInvoice['93251539-1']['Amt Excl VAT (VBRP)'] === 20456.74 && byInvoice['93251540-1']['Amt Excl VAT (VBRP)'] === null
            && same(['PDF', 'ASN', 'VBRP'].map(k => byInvoice['93251539-2'][k]), ['ไม่ตรง', 'ตรง', '-'])
            && same(['PDF', 'ASN', 'VBRP'].map(k => byInvoice['93251540-1'][k]), ['-', '-', '-'])
            && byInvoice['93251539-1'].Remark === asnAmountNote
            && ['93251539-2', '93251540-1'].every(inv => Object.keys(legacyBefore[inv]).every(k => k === '#' || byInvoice[inv][k] === legacyBefore[inv][k])),
        excel
    );
    process.stdout.write(JSON.stringify(results));
});
