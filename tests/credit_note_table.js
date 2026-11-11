// Runs the table code of credit_note.php (assets/js/credit-note/table.js) on a few documents and prints what a user
// would see: the cells of a row, the order after a sort, the rows a search keeps and the Excel file read back.
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const page = fs.readFileSync(process.argv[2], 'utf8');
const pageConst = name => new RegExp(`const ${name} = (.*?);\\n`).exec(page)[1];
const XLSX = require(path.join(__dirname, '..', 'assets', 'vendor', 'xlsx.full.min.js'));
let written = null;

const context = vm.createContext({
    console, Intl,
    XLSX: Object.assign({}, XLSX, { writeFile: (wb, name) => { written = { wb, name }; } })
});
vm.runInContext(`const CURRENT_JOB_ID = ${pageConst('CURRENT_JOB_ID')}; const CN_JOB_NAME_CLEAN = ${pageConst('CN_JOB_NAME_CLEAN')}; const CN_COLUMNS = ${pageConst('CN_COLUMNS')};`, context);
for (const file of ['ui-utils.js', 'credit-note/table.js']) {
    vm.runInContext(fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', file), 'utf8'), context, { filename: file });
}

// Document 1 was stored before the reader gave doc_date_iso and the right-hand totals (has_items false); 2, an
// Attachment Sheet, and 3 were read since but do not pass their own arithmetic; 4 prints its date with dashes
const docs = [
    { id: 1, filename: 'b.pdf', doc_no: '93250403-1', doc_date: '11.10.2026', customer: '5002-CENTRAL PHUKET 10121', format_type: 'Standard CN', pages: '1', orig_amount: null, corr_amount: null, diff_amount: null, vat_amount: 70, amount: 1070, has_items: false, read_ok: null },
    { id: 2, filename: 'a.pdf', doc_no: '93250520-2', doc_date: '02.11.2026', doc_date_iso: '2026-11-02', customer: '5024-CENTRAL MEGA BANGNA 10158', format_type: 'Attachment Sheet', pages: '1-3', orig_amount: 5000, corr_amount: 4000, diff_amount: 1000, vat_amount: 70, amount: 1234.5, excl_vat: 1153.74, vat: 80.76, incl_vat: 1234.5, has_items: true, read_ok: false },
    { id: 3, filename: 'c.pdf', doc_no: '-', doc_date: '-', doc_date_iso: null, customer: '-', format_type: 'Variance / Refund', pages: '2', orig_amount: 10, corr_amount: 8, diff_amount: 2, vat_amount: null, amount: null, excl_vat: null, vat: null, incl_vat: null, has_items: true, read_ok: false },
    { id: 4, filename: 'd <x>.pdf', doc_no: '93249881-1', doc_date: '30-09-2026', doc_date_iso: '2026-09-30', customer: '5035-CENTRAL SAMUI 10143', format_type: 'Standard CN', pages: '1', orig_amount: null, corr_amount: null, diff_amount: null, vat_amount: 7, amount: 107, excl_vat: 100, vat: 7, incl_vat: 107, has_items: true, read_ok: true }
];
context.docs = docs;
vm.runInContext('docs.forEach((doc, i) => creditNotePrepare(doc, i))', context);
const run = code => vm.runInContext(code, context);

const cells = html => [...html.matchAll(/<td(?: class="([^"]*)")?(?: title="[^"]*")?>(.*?)<\/td>/g)]
    .map(m => ({ classes: (m[1] || '').split(/\s+/).filter(Boolean), text: m[2].replace(/<[^>]+>/g, '').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&') , html: m[2] }));
const order = rows => rows.map(doc => doc.id);

const facts = {
    columns: run('CN_COLUMNS.map(col => col.label)'),
    row1: cells(run('creditNoteRowHtml(docs[0], 1)')),
    row2: cells(run('creditNoteRowHtml(docs[1], 2)')),
    reread: run('docs.map(creditNoteNeedsReread)'),
    row3: cells(run('creditNoteRowHtml(docs[2], 3)')),
    row4: cells(run('creditNoteRowHtml(docs[3], 4)')),
    sorted: {
        amountAsc: order(run(`creditNoteVisibleRows(docs, '', { key: 'amount', direction: 'asc' })`)),
        amountDesc: order(run(`creditNoteVisibleRows(docs, '', { key: 'amount', direction: 'desc' })`)),
        dateAsc: order(run(`creditNoteVisibleRows(docs, '', { key: 'doc_date', direction: 'asc' })`)),
        fileAsc: order(run(`creditNoteVisibleRows(docs, '', { key: 'filename', direction: 'asc' })`)),
        numberDesc: order(run(`creditNoteVisibleRows(docs, '', { key: '#', direction: 'desc' })`))
    },
    searched: {
        docNo: order(run(`creditNoteVisibleRows(docs, '93250520', { key: '#', direction: 'asc' })`)),
        twoWords: order(run(`creditNoteVisibleRows(docs, 'central  10121', { key: '#', direction: 'asc' })`)),
        typeAnyCase: order(run(`creditNoteVisibleRows(docs, 'attachment', { key: '#', direction: 'asc' })`)),
        amountWithComma: order(run(`creditNoteVisibleRows(docs, '1,234.50', { key: '#', direction: 'asc' })`)),
        amountPlain: order(run(`creditNoteVisibleRows(docs, '1070.00', { key: '#', direction: 'asc' })`)),
        dateAsShown: order(run(`creditNoteVisibleRows(docs, '30/09/2026', { key: '#', direction: 'asc' })`)),
        dateAsPrinted: order(run(`creditNoteVisibleRows(docs, '11.10.2026', { key: '#', direction: 'asc' })`)),
        nothing: order(run(`creditNoteVisibleRows(docs, 'zzz', { key: '#', direction: 'asc' })`))
    }
};

run(`creditNoteExportExcel(creditNoteVisibleRows(docs, '', { key: 'amount', direction: 'desc' }))`).then(() => {
    const book = XLSX.read(XLSX.write(written.wb, { type: 'buffer', bookType: 'xlsx' }), { type: 'buffer', cellNF: true });
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
    facts.excel = { file: written.name, sheets: book.SheetNames, grid };
    process.stdout.write(JSON.stringify(facts));
});
