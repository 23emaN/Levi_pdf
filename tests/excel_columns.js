const fs = require('fs');
const path = require('path');
const vm = require('vm');

const [pageFile, reconRowsFile, dashRowsFile] = process.argv.slice(2);
const columns = /const ASN_COLUMNS = (.*?);\n/.exec(fs.readFileSync(pageFile, 'utf8'))[1];

const element = () => ({
    addEventListener() {}, querySelectorAll: () => [], querySelector: () => element(),
    classList: { add() {}, remove() {}, toggle() {} }, style: {}, dataset: {}
});
const sheets = [];
const context = vm.createContext({
    console, Intl, setTimeout, clearTimeout,
    document: { getElementById: element, querySelectorAll: () => [], querySelector: element, addEventListener() {} },
    XLSX: {
        utils: {
            json_to_sheet: rows => { sheets.push(rows); return {}; },
            encode_cell: () => 'A1',
            book_new: () => ({}),
            book_append_sheet() {}
        },
        writeFile() {}
    },
    reconRows: JSON.parse(fs.readFileSync(reconRowsFile, 'utf8')),
    dashRows: JSON.parse(fs.readFileSync(dashRowsFile, 'utf8')),
    remarks: null
});
vm.runInContext(`const CURRENT_JOB_ID = 1; const CURRENT_JOB_NAME_CLEAN = 'T'; const ASN_COLUMNS = ${columns};`, context);
const scripts = path.join(__dirname, '..', 'assets', 'js');
for (const file of ['ui-utils.js', 'workspace/state.js', 'workspace/asn-table.js', 'workspace/asn-fix-export.js', 'workspace/dashboard.js']) {
    vm.runInContext(fs.readFileSync(path.join(scripts, file), 'utf8'), context, { filename: file });
}
vm.runInContext(`(async () => {
    reconDisplayData = reconRows;
    await exportReconcileToExcel();
    dashboardTable.rows = dashRows.map(prepareDashboardRow);
    await exportDashboardToExcel();
    remarks = {
        asn: reconRows.map((r, i) => ASN_CELLS.remark.html(reconRowView(r, i + 1))),
        dashboard: dashboardTable.rows.map(row => remarkHtml(row._remark))
    };
})()`, context).then(() => process.stdout.write(JSON.stringify({ asn: sheets[0], dashboard: sheets[1], remarks: context.remarks })));
