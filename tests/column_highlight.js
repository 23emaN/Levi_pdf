const fs = require('fs');
const path = require('path');
const vm = require('vm');

// Rendered pages: workspace.php, and credit_note.php and vbrp.php when given; a table on neither page has no headers
const pages = process.argv.slice(2).map(file => fs.readFileSync(file, 'utf8')).join('\n');
const headers = {};
for (const table of ['detailed-table', 'asn-table', 'dash-table', 'credit-note-table', 'credit-note-asn-table', 'credit-note-dash-table', 'vbrp-table']) {
    const head = new RegExp(`id="${table}">\\s*<thead>(.*?)</thead>`, 's').exec(pages);
    headers[table] = head ? [...head[1].matchAll(/<th\b[^>]*\bdata-col="([^"]*)"/g)].map(m => m[1]) : [];
}

const store = {};
let storageBroken = false;
const styles = {};
const context = vm.createContext({
    console,
    JSON,
    localStorage: {
        getItem: key => { if (storageBroken) throw new Error('blocked'); return key in store ? store[key] : null; },
        setItem: (key, value) => { if (storageBroken) throw new Error('blocked'); store[key] = String(value); }
    },
    window: { addEventListener() {}, innerWidth: 1280, innerHeight: 720 },
    document: {
        head: { appendChild: el => { styles[el.id] = el; } },
        body: { appendChild() {} },
        addEventListener() {},
        createElement: () => ({}),
        getElementById: id => styles[id] || null,
        querySelectorAll: selector => {
            const table = /^#([\w-]+) thead th$/.exec(selector);
            return table ? headers[table[1]].map(col => ({ dataset: { col } })) : [];
        }
    }
});
vm.runInContext('const CURRENT_JOB_ID = 7;', context);
vm.runInContext(fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'workspace', 'column-highlight.js'), 'utf8'), context, { filename: 'column-highlight.js' });

const run = code => JSON.parse(vm.runInContext(`JSON.stringify(${code})`, context) || 'null');
const css = () => styles['column-highlight-style'].textContent;
const facts = { headers, cssAtStart: css() };

run(`setColumnHighlight('asn-table', 'barcode', '#FFF59D')`);
run(`setColumnHighlight('dash-table', 'status', '#a5d8ff')`);
run(`setColumnHighlight('detailed-table', 'tax_invoice', '#b2f2bb')`);
facts.css = css();
facts.stored = JSON.parse(store['column_highlight_job_7']);
facts.reloaded = run('loadColumnHighlights()');

run(`setColumnHighlight('asn-table', 'status', '#1E3A8A')`);
facts.dark = {
    css: css(),
    dark: Object.fromEntries(['#000000', '#1e3a8a', '#fff59d', '#ffffff', '#808080', '#ff0000'].map(color => [color, run(`isDarkHighlight('${color}')`)]))
};
run(`setColumnHighlight('asn-table', 'status', null)`);

run(`setColumnHighlight('asn-table', 'barcode', null)`);
facts.afterClearOne = { asn: run(`columnHighlights['asn-table']`), css: css() };
run(`clearTableHighlights('dash-table')`);
facts.afterClearTable = { state: run('columnHighlights'), css: css() };

store['column_highlight_job_7'] = JSON.stringify({
    'asn-table': { barcode: 'red', source_file: '#12', description: '#fff59d;}body{display:none}/*', remark: 'url(x)', status: '#B2F2BB', printed: 5 },
    'unknown-table': { a: '#ffffff' },
    'dash-table': ['#ffffff'],
    'detailed-table': 'x'
});
facts.hostile = run('loadColumnHighlights()');
store['column_highlight_job_7'] = JSON.stringify([{ 'asn-table': { status: '#ffffff' } }]);
facts.topLevelArray = run('loadColumnHighlights()');
store['column_highlight_job_7'] = '{not json';
facts.brokenJson = run('loadColumnHighlights()');
storageBroken = true;
facts.storageBlocked = run('loadColumnHighlights()');
run(`setColumnHighlight('asn-table', 'remark', '#ffd8a8')`);
facts.cssWithStorageBlocked = css();

storageBroken = false;
store['column_highlight_job_7'] = JSON.stringify({ 'asn-table': { barcode: '#fff59d' } });
run('columnHighlights = loadColumnHighlights()');
run(`setColumnHighlight('credit-note-table', 'doc_no', '#d0bfff')`);
run(`setColumnHighlight('credit-note-asn-table', 'remark', '#ffd8a8')`);
run(`setColumnHighlight('credit-note-dash-table', 'status', '#a5d8ff')`);
facts.creditNote = { css: css(), stored: JSON.parse(store['column_highlight_job_7']) };

process.stdout.write(JSON.stringify(facts));
