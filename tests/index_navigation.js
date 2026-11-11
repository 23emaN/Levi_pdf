// Runs assets/js/index.js (the Job list) and prints where it sends the browser and the lines of the delete dialog.
// argv[2]: a job_delete_summary answer from api.php, so the dialog lines are checked on what the server really sends.
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const summary = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const element = () => ({ value: 'New job', disabled: false, innerHTML: '', addEventListener() {} });
const context = vm.createContext({
    console,
    window: { location: { href: 'index.php' }, addEventListener() {} },
    document: { addEventListener() {}, getElementById: element },
    FormData: class { append() {} },
    fetch: async () => ({ json: async () => ({ status: 'success', data: { id: 42 } }) }),
    alert: message => { throw new Error('alert: ' + message); },
    setTimeout, clearTimeout, setInterval, clearInterval, Intl
});
for (const file of ['ui-utils.js', 'index.js']) {
    vm.runInContext(fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', file), 'utf8'), context, { filename: file });
}
const run = code => vm.runInContext(code, context);

const facts = {};
run('navigateToJob(null, 7)');
facts.openJob = run('window.location.href');
run('createModalInstance = { hide() {} }');
run('handleCreateJob({ preventDefault() {} })').then(() => {
    facts.afterCreate = run('window.location.href');
    context.summary = summary;
    facts.serverLines = run('deleteJobSummaryLines(summary)');
    facts.withCreditNotes = run(`deleteJobSummaryLines({ pdf_files: 1, pdf_rows: 2, asn_files: 0, asn_rows: 0, print_files: 0, print_rounds: 0, fixed_bills: 0, upload_files: 4, cn_files: 1234, cn_documents: 5 })`);
    facts.withoutCreditNotes = run(`deleteJobSummaryLines({ pdf_files: 1, pdf_rows: 2, asn_files: 0, asn_rows: 0, print_files: 0, print_rounds: 0, fixed_bills: 0, upload_files: 0, cn_files: 0, cn_documents: 0 })`);
    process.stdout.write(JSON.stringify(facts));
});
