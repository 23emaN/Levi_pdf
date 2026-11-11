// Runs the compare and overview code of credit_note.php (assets/js/credit-note/asn-sheets.js, compare-table.js,
// dashboard.js) and prints what a user would see: the sheets the picker offers for the real ASN workbook and the
// records each credit note sheet gives, the cells of compared rows of both money bases, the order after a sort, the
// rows a search keeps, and the Excel files read back. portal_test.php asserts on the printed facts.
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const page = fs.readFileSync(process.argv[2], 'utf8');
const pageConst = name => new RegExp(`const ${name} = (.*?);\\n`).exec(page)[1];
const root = path.join(__dirname, '..');
const XLSX = require(path.join(root, 'assets', 'vendor', 'xlsx.full.min.js'));
const written = [];

const context = vm.createContext({
    console, Intl,
    XLSX: Object.assign({}, XLSX, { writeFile: (wb, name) => { written.push({ wb, name }); } })
});
vm.runInContext(['CURRENT_JOB_ID', 'CN_JOB_NAME_CLEAN', 'CN_COLUMNS', 'CN_COMPARE_COLUMNS', 'CN_DASH_COLUMNS', 'CN_TOLERANCES']
    .map(name => `const ${name} = ${pageConst(name)};`).join('\n'), context);
for (const file of ['ui-utils.js', 'workspace/asn-import.js', 'credit-note/table.js', 'credit-note/asn-sheets.js', 'credit-note/compare-table.js', 'credit-note/dashboard.js']) {
    vm.runInContext(fs.readFileSync(path.join(root, 'assets', 'js', file), 'utf8'), context, { filename: file });
}
const run = code => vm.runInContext(code, context);

const workbookName = fs.readdirSync(path.join(root, 'tests', 'fixtures')).find(name => /^ASN S4 .*\.xlsx$/.test(name));
context.workbook = XLSX.read(fs.readFileSync(path.join(root, 'tests', 'fixtures', workbookName)), { type: 'buffer' });
context.workbookName = workbookName;

const cells = html => [...html.matchAll(/<td(?: class="([^"]*)")?(?: title="([^"]*)")?>(.*?)<\/td>/gs)].map(m => ({
    classes: (m[1] || '').split(/\s+/).filter(Boolean),
    title: m[2] === undefined ? null : m[2],
    text: m[3].replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim().replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&amp;/g, '&'),
    html: m[3]
}));

const facts = {};

facts.sheets = run(`creditNoteWorkbookSheets(workbook).map(s => ({ name: s.name, rows: s.records.length, kind: s.kind, hint: s.hint, option: creditNoteSheetOptionText(s) }))`);
facts.detectionParity = run(`workbook.SheetNames.filter(name => {
    const rows = XLSX.utils.sheet_to_json(workbook.Sheets[name], { header: 1 });
    const ti = detectSheetColumns(rows);
    const cn = creditNoteDetectSheetColumns(rows);
    if (!ti || !cn) return ti !== cn;
    const map = Object.assign({}, cn.colMap);
    delete map.invoice_date;
    return JSON.stringify(ti) !== JSON.stringify({ headerRowIdx: cn.headerRowIdx, colMap: map });
})`);
facts.detectedSheets = run('workbook.SheetNames.length');
facts.creditNoteSheets = run(`['CDS ZG2T (ZCR) done', 'RBS ZG2T (ZCR) done', 'CDS ZFP3 (ZKR) done 11.10.2026', 'RBS ZFP3 (ZKR) done 13.10.2026'].map(name => {
    const sheet = creditNoteWorkbookSheets(workbook).find(s => s.name === name);
    const keys = new Set(sheet.records.map(r => Object.keys(r).join(',')));
    return {
        name, rows: sheet.records.length,
        documents: new Set(sheet.records.map(r => r.invoice_no)).size,
        keys: Array.from(keys),
        kinds: Array.from(new Set(sheet.records.map(r => r.amount_kind))),
        first: sheet.records[0],
        source: creditNoteAsnSource(workbookName, name)
    };
})`);

context.compared = [
    { status: 'MATCH', reason: '', action: '', amount_ok: true, vat_ok: true, amount_kind: 'cost', source_files: ['5013_93248267-1_DN.issue.at_20261011_5013-CENTRAL BANGNA 10116.pdf'],
        pdf: { doc_no: '93248267-1', barcode: '5401221356665', description: '501 ORIGINAL <BLUE>', store_no: '10116', units: 1, unit_price: 951.39, amount: 951.39, format_type: 'Standard CN', pages: '1', doc_total_amount: 6800, doc_excl_vat: 6271.4, doc_vat: 438.99, doc_incl_vat: 6710.39 },
        asn: { doc_no: '93248267-1', ean_upc: '5401221356665', dest_store_no: '10116', quantity: 1, row_count: 1, selling_price: 951.39, doc_amount: 6271.4, total_vat: 438.99 } },
    { status: 'MISMATCH', reason: 'จำนวนไม่ตรง (PDF: 2 ชิ้น / ASN: 3 ชิ้น)', action: '', amount_ok: true, vat_ok: true, amount_kind: 'cost', source_files: ['5013_93248267-1_DN.issue.at_20261011_5013-CENTRAL BANGNA 10116.pdf', 'copy.pdf'],
        pdf: { doc_no: '93248267-1', barcode: '5401221356666', description: '502 TAPER', store_no: '10116', units: 2, unit_price: 100, amount: 200, format_type: 'Standard CN', pages: '1', doc_total_amount: 6800, doc_excl_vat: 6271.4, doc_vat: 438.99, doc_incl_vat: 6710.39 },
        asn: { doc_no: '93248267-1', ean_upc: '5401221356666', dest_store_no: '10116', quantity: 3, row_count: 2, selling_price: 100, doc_amount: 6271.4, total_vat: 438.99 } },
    { status: 'MISMATCH', reason: 'ยอดรวมไม่ตรง (PDF: 2,380.00 / ASN: 2,390.00)', action: '', amount_ok: false, vat_ok: null, amount_kind: 'retail', source_files: ['5002_93240270_DN.pdf'],
        pdf: { doc_no: '93240270', barcode: '5401180818211', description: 'TRUCKER JACKET', store_no: '10103', units: 1, unit_price: 2380, amount: 2380, format_type: 'Standard CN', pages: '1', doc_total_amount: 2380, doc_excl_vat: 2224.3, doc_vat: 155.7, doc_incl_vat: 2380 },
        asn: { doc_no: '93240270', ean_upc: '5401180818211', dest_store_no: '10103', quantity: 1, row_count: 1, selling_price: 2390, doc_amount: 2390, total_vat: 0 } },
    { status: 'MISMATCH', reason: 'ไม่พบใบลดหนี้นี้ใน PDF', action: '', amount_ok: null, vat_ok: null, amount_kind: 'retail', source_files: [],
        pdf: null,
        asn: { doc_no: '93240300', ean_upc: '6901462126830', dest_store_no: '20186', quantity: 2, row_count: 1, selling_price: 750, doc_amount: 1500, total_vat: 0 } },
    { status: 'MISMATCH', reason: 'บาร์โค้ด 8850000000001 มีใน PDF แต่ไม่มีใน ASN (4 ชิ้น)', action: '', amount_ok: true, vat_ok: true, amount_kind: 'cost', source_files: ['e.pdf'],
        pdf: { doc_no: '93250001-1', barcode: '8850000000001', description: 'BELT', store_no: null, units: 4, unit_price: 125, amount: 500, format_type: 'Standard CN', pages: '1-2', doc_total_amount: 500, doc_excl_vat: 467.29, doc_vat: 32.71, doc_incl_vat: 500 },
        asn: { doc_no: '93250001-1', ean_upc: null, dest_store_no: '10150', quantity: null, row_count: null, selling_price: null, doc_amount: 467.29, total_vat: 32.71 } }
];
run('compared.forEach((row, i) => creditNoteComparePrepare(row, i))');
const order = rows => rows.map(r => r._order);
const visible = (filter, query, key, direction) => order(run(`creditNoteCompareVisibleRows(compared, '${filter}', ${JSON.stringify(query)}, { key: '${key}', direction: '${direction}' })`));

facts.compare = {
    headers: run('CN_COMPARE_COLUMNS.map(col => col.label)'),
    rows: [0, 1, 2, 3, 4].map(i => cells(run(`creditNoteCompareRowHtml(compared[${i}], ${i + 1})`))),
    counts: run('creditNoteCompareCounts(compared)'),
    sorted: {
        statusAsc: visible('ALL', '', 'status', 'asc'),
        pdfQtyAsc: visible('ALL', '', 'pdf_qty', 'asc'),
        pdfQtyDesc: visible('ALL', '', 'pdf_qty', 'desc'),
        asnAmountDesc: visible('ALL', '', 'asn_amount', 'desc'),
        docAsc: visible('ALL', '', 'doc_no', 'asc'),
        numberDesc: visible('ALL', '', '#', 'desc')
    },
    searched: {
        docNo: visible('ALL', '93240270', '#', 'asc'),
        remark: visible('ALL', 'ไม่พบใบลดหนี้', '#', 'asc'),
        amountWithComma: visible('ALL', '6,271.40', '#', 'asc'),
        amountPlain: visible('ALL', '6271.40', '#', 'asc'),
        twoWords: visible('ALL', 'ไม่ตรง  2,390.00', '#', 'asc'),
        anyCase: visible('ALL', 'blue', '#', 'asc'),
        nothing: visible('ALL', 'zzz', '#', 'asc')
    },
    filtered: {
        mismatch: visible('MISMATCH', '', '#', 'asc'),
        match: visible('MATCH', '', '#', 'asc'),
        pendingNone: visible('PENDING', '', '#', 'asc'),
        mismatchSearched: visible('MISMATCH', '93248267', '#', 'asc')
    },
    steps: run(`[creditNoteCompareStep(0, false), creditNoteCompareStep(5, false), creditNoteCompareStep(5, true), creditNoteCompareStep(0, true)]`)
};

context.records = [
    { id: 11, source_file: 'wb.xlsx / CDS ZG2T (ZCR) done', invoice_no: '93240270', ean_upc: '5401180818211', dest_store_no: '10103', quantity: 1, selling_price: 2390, total_cost_ex_vat: 0, total_vat: 0, amount_kind: 'retail' },
    { id: 12, source_file: 'wb.xlsx / CDS ZG2T (ZCR) done', invoice_no: '93240270', ean_upc: '5401180818212', dest_store_no: '10103', quantity: 2, selling_price: 1000.005, total_cost_ex_vat: 0, total_vat: 0, amount_kind: 'retail' },
    { id: 13, source_file: 'wb.xlsx / CDS ZFP3 (ZKR) done 11.10.2026', invoice_no: '93248267-1', ean_upc: '5401221356665', dest_store_no: '10116', quantity: 1, selling_price: 951.39, total_cost_ex_vat: 6271.4, total_vat: 438.99, amount_kind: 'cost' },
    { id: 14, source_file: 'wb.xlsx / CDS ZFP3 (ZKR) done 11.10.2026', invoice_no: '93248267-1', ean_upc: '5401221356666', dest_store_no: '10116', quantity: 3, selling_price: 100, total_cost_ex_vat: 6271.4, total_vat: 438.99, amount_kind: 'cost' },
    { id: 15, source_file: 'wb.xlsx / CDS ZFP3 (ZKR) done 11.10.2026', invoice_no: '93248268-1', ean_upc: '5401221356667', dest_store_no: '10116', quantity: 1, selling_price: 10, total_cost_ex_vat: 99.5, total_vat: 6.97, amount_kind: 'cost' },
    { id: 16, source_file: 'wb.xlsx / CDS ZFP3 (ZKR) done 11.10.2026', invoice_no: '93248269-1', ean_upc: '5401221356668', dest_store_no: '10116', quantity: 1, selling_price: 10, total_cost_ex_vat: 0, total_vat: 0, amount_kind: 'cost' },
    { id: 17, source_file: 'wb.xlsx / CDS ZFP3 (ZKR) done 11.10.2026', invoice_no: '93248269-1', ean_upc: '5401221356669', dest_store_no: '10116', quantity: 1, selling_price: 10, total_cost_ex_vat: 250, total_vat: 17.5, amount_kind: 'cost' }
];
run('pending = creditNotePendingRows(records).map((row, i) => creditNoteComparePrepare(row, i))');
facts.pending = {
    statuses: run('pending.map(r => r.status)'),
    docAmounts: run('pending.map(r => r.asn.doc_amount)'),
    rows: [0, 2].map(i => cells(run(`creditNoteCompareRowHtml(pending[${i}], ${i + 1})`))),
    sources: run('creditNoteAsnSources(records)')
};

facts.remarks = run(`({
    match: creditNoteRemarkLines('MATCH', '', true),
    mismatch: creditNoteRemarkLines('MISMATCH', 'จำนวนไม่ตรง (PDF: 2 ชิ้น / ASN: 3 ชิ้น), ยอดรวมไม่ตรง (PDF: 11.00 / ASN: 10.00)', true),
    pendingCompare: creditNoteRemarkLines('PENDING', '', true),
    pendingOverview: creditNoteRemarkLines('PENDING', '', false)
})`);
facts.labels = run(`[
    creditNoteAsnLabel(null, { files: 0, rows: 0 }),
    creditNoteAsnLabel('wb.xlsx / CDS ZG2T (ZCR) done', { files: 1, rows: 1890 }),
    creditNoteAsnLabel('4 ชีต: wb.xlsx / a, wb.xlsx / b, wb.xlsx / c, wb.xlsx / d', { files: 4, rows: 4204 })
]`);
facts.rules = run('[creditNoteMoneyRule(CN_TOLERANCES.amount), creditNoteMoneyRule(CN_TOLERANCES.vat), creditNoteMoneyRule(1), creditNoteMoneyRule(0.15)]');
facts.dates = run(`[
    creditNoteDocDate({ doc_date_iso: '2026-04-06', doc_date: '06.04.2026' }),
    creditNoteDocDate({ doc_date: '11-04-2026' }),
    creditNoteDocDate({ doc_date: '-' }),
    creditNoteSheetDate(46139),
    creditNoteSheetDate(' 27/04/2026 ')
]`);

context.dashDocs = [
    { store_no: '10103', doc_no: '93240270', source_files: ['5002_93240270_DN.pdf'], units: 1, amount: 2390, amount_kind: 'retail', pages: 2, status: 'MISMATCH', problem: 'ยอดรวมไม่ตรง (PDF: 2,380.00 / ASN: 2,390.00)' },
    { store_no: '10103', doc_no: '93240271', source_files: ['a.pdf', 'b.pdf', 'c.pdf'], units: 1, amount: 990, amount_kind: 'retail', pages: 1, status: 'MATCH', problem: '' },
    { store_no: '10116', doc_no: '93248267-1', source_files: ['5013_93248267-1_DN.pdf'], units: 4, amount: 6271.4, amount_kind: 'cost', pages: 1, status: 'MISMATCH', problem: 'จำนวนไม่ตรง (PDF: 2 ชิ้น / ASN: 3 ชิ้น)' },
    { store_no: '', doc_no: '93240300', source_files: [], units: 2, amount: 1500, amount_kind: 'retail', pages: null, status: 'MISMATCH', problem: 'ไม่พบใบลดหนี้นี้ใน PDF' }
];
run('dashDocs.forEach((row, i) => creditNoteDashPrepare(row, i))');
const dashVisible = (status, query, key, direction) => run(`creditNoteDashVisibleRows(dashDocs, '${status}', ${JSON.stringify(query)}, { key: '${key}', direction: '${direction}' })`).map(r => r.doc_no);
facts.dashboard = {
    headers: run('CN_DASH_COLUMNS.map(col => col.label)'),
    rows: [0, 1, 3].map(i => cells(run(`creditNoteDashRowHtml(dashDocs[${i}], ${i + 1})`))),
    sorted: {
        storeAsc: dashVisible('ALL', '', 'store_no', 'asc'),
        storeDesc: dashVisible('ALL', '', 'store_no', 'desc'),
        amountDesc: dashVisible('ALL', '', 'amount', 'desc'),
        statusAsc: dashVisible('ALL', '', 'status', 'asc')
    },
    searched: {
        missing: dashVisible('ALL', 'ไม่พบ', 'store_no', 'asc'),
        status: dashVisible('ALL', 'สมบูรณ์', 'store_no', 'asc'),
        amount: dashVisible('ALL', '6,271.40', 'store_no', 'asc'),
        file: dashVisible('ALL', 'b.pdf', 'store_no', 'asc')
    },
    filtered: { match: dashVisible('MATCH', '', 'store_no', 'asc'), mismatchWithWord: dashVisible('MISMATCH', '10103', 'store_no', 'asc') }
};

const readBack = file => {
    const book = XLSX.read(XLSX.write(file.wb, { type: 'buffer', bookType: 'xlsx' }), { type: 'buffer', cellNF: true });
    const sheet = book.Sheets[book.SheetNames[0]];
    const range = XLSX.utils.decode_range(sheet['!ref']);
    const grid = [];
    for (let r = range.s.r; r <= range.e.r; r++) {
        const row = [];
        for (let c = range.s.c; c <= range.e.c; c++) {
            const cell = sheet[XLSX.utils.encode_cell({ r, c })];
            row.push(cell ? { t: cell.t, v: cell.v, z: cell.z || null } : null);
        }
        grid.push(row);
    }
    return { file: file.name, sheets: book.SheetNames, grid };
};

(async () => {
    await run(`creditNoteCompareExportExcel(creditNoteCompareVisibleRows(compared, 'ALL', '', { key: 'status', direction: 'asc' }))`);
    await run(`creditNoteDashExportExcel(creditNoteDashVisibleRows(dashDocs, 'ALL', '', { key: 'store_no', direction: 'asc' }))`);
    facts.compare.excel = readBack(written[0]);
    facts.dashboard.excel = readBack(written[1]);
    process.stdout.write(JSON.stringify(facts));
})();
