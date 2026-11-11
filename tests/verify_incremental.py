"""Walks the real workflow with files arriving in stages and checks the data stays right.

Stages: PDFs, ASN, compare and fix, more PDFs (one already extracted), a second ASN file, compare and fix again,
then repeated actions and duplicate bills. The end state must equal one run over all files.

Run: python tests/verify_incremental.py   (same requirements as verify_docs.py)
Everything runs on a throwaway copy behind a temporary PHP server, never on data/ or myfile/.
"""
import collections
import json
import re
import os
import shutil
import socket
import sqlite3
import subprocess
import sys
import tempfile
import time
import urllib.parse
import urllib.request
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import verify_docs as vd

ROOT = vd.ROOT
FIXTURES = vd.FIXTURES
PHP = vd.PHP
fails = []


def check(name, ok, detail=''):
    print(('PASS  ' if ok else 'FAIL  ') + name + (f'  [{detail}]' if detail else ''))
    if not ok:
        fails.append(name)


class Sandbox:
    def __init__(self, work):
        self.root = work / 'app'
        self.connections = []
        for folder in ('app', 'assets', 'vendor'):
            shutil.copytree(ROOT / folder, self.root / folder)
        for page in ('api.php', 'workspace.php'):
            shutil.copy(ROOT / page, self.root / page)
        (self.root / 'data').mkdir()
        (self.root / 'myfile').mkdir()
        with socket.socket() as s:
            s.bind(('127.0.0.1', 0))
            self.port = s.getsockname()[1]
        env = {**os.environ, 'PDF_READER_ROOT': str(self.root)}
        self.server = subprocess.Popen([PHP, '-S', f'127.0.0.1:{self.port}', '-t', str(self.root)], cwd=str(self.root), env=env,
                                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(50):
            try:
                urllib.request.urlopen(f'http://127.0.0.1:{self.port}/api.php?action=list_jobs', timeout=2).read()
                return
            except Exception:
                time.sleep(0.2)
        raise RuntimeError('PHP server did not start')

    def stop(self):
        for conn in self.connections:
            conn.close()
        self.server.terminate()
        self.server.wait(timeout=10)

    def call(self, action, post=None, **query):
        url = f'http://127.0.0.1:{self.port}/api.php?' + urllib.parse.urlencode({'action': action, **query})
        data = urllib.parse.urlencode(post).encode() if post is not None else None
        with urllib.request.urlopen(urllib.request.Request(url, data=data), timeout=600) as r:
            return json.loads(r.read().decode('utf-8'))

    def status_bar(self, job_id):
        with urllib.request.urlopen(f'http://127.0.0.1:{self.port}/workspace.php?job_id={job_id}', timeout=600) as r:
            html = r.read().decode('utf-8')
        found = re.search(r'id="job-status-counts">(.*?)</div>\s*<a ', html, re.S)
        return re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', '', found.group(1))).strip() if found else None

    def db(self):
        conn = sqlite3.connect(str(self.root / 'data' / 'app.sqlite'))
        conn.row_factory = sqlite3.Row
        self.connections.append(conn)
        return conn


def root_of(path):
    import re
    m = re.search(r'-00(\d{8}) ', path.name) or re.match(r'(\d{8})-', path.name)
    return m.group(1)


FORBIDDEN_REMARK_WORDS = re.compile('ต้องกด|ปุ่ม|สกัด|footer', re.I)


def pages_named(text):
    """Page numbers a remark names: "หน้า 1-3 และ 5", "หน้าที่ 1-3 และ 5" and "หน้าที่ 1 ถึง 3 และ 5" give {1, 2, 3, 5}"""
    pages = set()
    for ranges in re.findall(r'หน้า(?:ที่)? (\d+(?:(?:-| ถึง )\d+)?(?: และ \d+(?:(?:-| ถึง )\d+)?)*)', text):
        for part in ranges.split(' และ '):
            ends = re.split(r'-| ถึง ', part)
            pages.update(range(int(ends[0]), int(ends[-1]) + 1))
    return pages


def before_fix_faults(before, after):
    """Compare lines whose status before the fix is not the one they have after it (waiting counts as complete), or whose
    wrong reason or action changed. A line is complete and fixed after the fix exactly when it waited, or already was,
    before it; line by line and bill by bill."""
    def fixed_complete(lines):
        return {i for i, s in vd.worst_status(lines).items() if s == 'MATCH'} & {x['asn']['invoice_no'] for x in lines if x['fixed']}

    later = {(x['asn']['invoice_no'], x['asn']['ean_upc']): x for x in after}
    faults = [] if len(before) == len(later) else [('line count', len(before), len(later))]
    for x in before:
        y = later.get((x['asn']['invoice_no'], x['asn']['ean_upc']), {'status': 'missing', 'reason': '', 'action': '', 'fixed': False})
        kept = x['status'] != 'MISMATCH' or (x['reason'], x['action']) == (y['reason'], y['action'])
        waited = x['status'] == 'PENDING_FIX' or (x['status'] == 'MATCH' and x['fixed'])
        if y['status'] != ('MATCH' if x['status'] == 'PENDING_FIX' else x['status']) or not kept or waited != (y['status'] == 'MATCH' and y['fixed']):
            faults.append((x['asn']['invoice_no'], x['asn']['ean_upc'], x['status'], x['fixed'], y['status'], y['fixed'], x['reason'][:120]))
    waiting = {i for i, s in vd.worst_status(before).items() if s == 'PENDING_FIX'} | fixed_complete(before)
    if waiting != fixed_complete(after):
        faults.append(('bills waiting or fixed before but not fixed after, and the reverse', sorted(waiting - fixed_complete(after))[:4], sorted(fixed_complete(after) - waiting)[:4]))
    return faults


def statuses_before_fix(reference, lines):
    before, after = reference['before']['results'], reference['after']['results']
    faults = before_fix_faults(before, after)
    check('each compare line shows before the fix the status it has after it, waiting counted as complete, and a wrong line its reason',
          before and not faults, f'{len(before)} lines, faults: {faults[:2]}')
    later = {(x['asn']['invoice_no'], x['asn']['ean_upc']): x['status'] for x in after}
    false_promise = [x['asn']['invoice_no'] for x in before if x['status'] == 'PENDING_FIX' and later.get((x['asn']['invoice_no'], x['asn']['ean_upc'])) == 'MISMATCH']
    check('no line waits for the fix while it is wrong after the fix', not false_promise, str(sorted(set(false_promise))[:4]))
    bill = [x for x in before if x['asn']['invoice_no'] == '93251539-1']
    named = [x for x in bill if x['status'] == 'MISMATCH' and 'PDF: 4' in x['reason'] and 'ASN: 2' in x['reason']
             and (x['asn']['ean_upc'] == '5401221426009' or '5401221426009' in x['reason'])]
    check('before the fix bill 93251539-1 is wrong on every line, each naming barcode 5401221426009 with 4 pieces in the PDF and 2 in the ASN',
          bill and named == bill, f'{len(named)} of {len(bill)} lines: {[(x["status"], x["reason"][:80]) for x in bill if x not in named][:2]}')
    second = ({x['status'] for x in before if x['asn']['invoice_no'] == '93251539-2'}, {x['status'] for x in after if x['asn']['invoice_no'] == '93251539-2'})
    expected = {v for (inv, _), v in lines.items() if inv == '93251539-2'}
    check('bill 93251539-2, which gives a page to the wrong bill 93251539-1, has before the fix the status the independent check gives it and the same after it, waiting read as complete',
          expected and second == (expected, {'MATCH' if v == 'PENDING_FIX' else v for v in expected}), f'{second} vs {expected}')


def plan_matches_fix(reference):
    before = reference['before']['results']
    pending = {i for i, s in vd.worst_status(before).items() if s == 'PENDING_FIX'}

    def key(s):
        return s['kind'], s['filename'], int(s['page']), s.get('from') or '', s['invoice']

    def touching(steps, invoices):
        return {key(s) for s in steps if s['invoice'] in invoices or (s.get('from') or '') in invoices}

    planned, logged = reference['plan_before']['steps'], reference['steps_after']
    check('for every invoice that waited, the plan made before the fix holds exactly the steps the fix logged (kind, file, page, old number, owner), and so does the whole plan',
          pending and touching(planned, pending) == touching(logged, pending) and {key(s) for s in planned} == {key(s) for s in logged},
          f'{len(pending)} waiting invoices, {len(touching(logged, pending))} of their steps, {len(logged)} steps in all, '
          f'only planned {sorted({key(s) for s in planned} - {key(s) for s in logged})[:2]}, only logged {sorted({key(s) for s in logged} - {key(s) for s in planned})[:2]}')

    # A page printed with another number makes its owner and the bill whose number it prints both name every page of
    # the owner, only the misnumbered ones when the owner stays wrong; a total on a page that is not the last is a
    # problem of its own on a bill without such a page
    files = reference['plan_before']['files'].values()
    stays_wrong = {i for i, s in vd.worst_status(reference['after']['results']).items() if s == 'MISMATCH'}
    owner_pages, takers = collections.defaultdict(set), collections.defaultdict(set)
    for file in files:
        for page_no, page in file['pages'].items():
            if page['renumber']:
                owner = str(page['invoice'])
                owner_pages[owner] |= {int(page_no)} if owner in stays_wrong else set(file['invoices'][page['invoice']])
                takers[str(page['printed'])].add(owner)
    problem_pages, planned_pages = collections.defaultdict(set), collections.defaultdict(set)
    for file in files:
        for page_no, page in file['pages'].items():
            if str(page['invoice']) in owner_pages:
                problem_pages[str(page['invoice'])] |= owner_pages[str(page['invoice'])]
            elif page['clear_footer']:
                problem_pages[str(page['invoice'])].add(int(page_no))
    for giver, owners in takers.items():
        for owner in owners:
            problem_pages[giver] |= owner_pages[owner]
    for s in planned:
        for inv in {s['invoice'], s.get('from') or ''} - {''}:
            planned_pages[inv].add(int(s['page']))
    split_pages, split_files, split_bills = collections.defaultdict(set), collections.defaultdict(set), collections.defaultdict(set)
    for s in logged:
        if s['kind'] == 'split':
            split_pages[s['invoice']].add(int(s['page']))
            split_files[s['invoice']].add(s['filename'])
            split_bills[s['filename']].add(s['invoice'])

    def reason_ok(inv, reason):
        if problem_pages[inv]:
            return pages_named(reason) == problem_pages[inv]
        shared = re.fullmatch(r'ไฟล์ต้นฉบับมีหลายบิล บิลนี้อยู่หน้าที่ [\d\- และ]+', reason)
        return bool(shared) and all(len(split_bills[f]) > 1 for f in split_files[inv]) and pages_named(reason) == split_pages[inv]

    texts = collections.defaultdict(set)
    for x in before:
        if x['status'] == 'PENDING_FIX':
            texts[x['asn']['invoice_no']].add((x['reason'], x['action']))
    faults = []
    for inv in sorted(texts):
        reason, action = min(texts[inv])
        if len(texts[inv]) != 1 or not reason_ok(inv, reason) or FORBIDDEN_REMARK_WORDS.search(reason + action) \
                or pages_named(action) != planned_pages[inv] or any(f'ไปเป็นบิล {o}' not in action for o in takers[inv]) or '·' in reason + action:
            faults.append((inv, sorted(texts[inv])))
    only_split = sum(1 for inv in texts if not problem_pages[inv])
    check('every line of a waiting bill has one problem, naming exactly the pages the plan gives it, or for a bill with right pages that its file holds several '
          'bills and the pages the fix splits out; and one solution that names every page the plan touches and the bill a page goes to; neither names a button',
          pending and not faults and only_split > 0, f'{len(texts)} invoices with waiting lines, {only_split} of them only split out, wrong: {faults[:2]}')

    after = reference['plan_after']
    check('a plan made again after the fix finds no page to renumber, no footer to clear, nothing wrong and nothing to wait for, only splits the fix already logged',
          not [s for s in after['steps'] if s['kind'] != 'split'] and not any(t['problem'] or t['reason'] for t in after['texts'].values())
          and {key(s) for s in after['steps']} <= {key(s) for s in logged},
          f"{len(after['steps'])} split steps redone")


def scenario(sb, asn_all, pages_all, reference):
    call, db = sb.call, sb.db
    alternate = {p['file'] for p in pages_all if p['alternate']}
    pdfs = sorted(vd.sample_pdfs(), key=lambda p: p.name)
    standard = [p for p in pdfs if p.name not in alternate]
    part1 = standard[:170]
    part2 = standard[170:] + [p for p in pdfs if p.name in alternate]
    roots1 = {root_of(p) for p in part1}
    asn_a = [a for a in asn_all if a['invoice_no'].split('-')[0] in roots1]
    asn_b = [a for a in asn_all if a['invoice_no'].split('-')[0] not in roots1]
    print(f'part 1: {len(part1)} PDFs and {len(asn_a)} ASN rows | part 2: {len(part2)} PDFs and {len(asn_b)} ASN rows')
    plan_matches_fix(reference)
    statuses_before_fix(reference, vd.independent_line_verdicts(pages_all, asn_all))

    job = call('create_job', {'name': 'Incremental test'})['data']
    jid = job['id']
    folder = sb.root / 'myfile' / 'Incremental test' / 'TAX INVOICE'
    upload, finished = folder / 'upload here', folder / 'finished'
    check('job folders are created', upload.is_dir() and finished.is_dir())

    def place(paths, nested=0):
        for i, p in enumerate(paths):
            dest = upload / 'batch' / 'sub' if i < nested else upload
            dest.mkdir(parents=True, exist_ok=True)
            shutil.copy(p, dest / p.name)

    def extract(files, overwrite=0, replace=0):
        done = 0
        for i in range(0, len(files), 40):
            r = call('extract_job_batch', {'job_id': jid, 'files': json.dumps(files[i:i + 40]), 'overwrite': overwrite, 'replace_duplicates': replace})
            assert r['status'] == 'success', r
            done += r['processed_files']
        return done

    def rows_in(paths):
        names = {p.name for p in paths}
        return sum(len(p['rows']) for p in pages_all if p['file'] in names)

    def print_round():
        started = call('print_start', {'job_id': jid})['data']
        for offset in range(0, started['total'], 40):
            call('print_chunk', {'job_id': jid, 'token': started['token'], 'offset': offset})
        return call('print_finish', {'job_id': jid, 'token': started['token']})['data'] if started['token'] else None

    def printed_in(round_name):
        folder = sb.root / 'myfile' / 'Incremental test' / 'TAX INVOICE' / 'print' / round_name
        found = set()
        for f in folder.rglob('*.pdf'):
            found.add(f.stem.split('_', 1)[1])
        return found

    def count(table):
        return db().execute(f'select count(*) from {table} where job_id=?', (jid,)).fetchone()[0]

    def improved(before, after):
        return {i for i, was in before.items()
                if (was == 'PENDING_FIX' and after.get(i, was) != 'PENDING_FIX') or (was == 'MISMATCH' and after.get(i) == 'MATCH')}

    originals = {p.name for p in vd.sample_pdfs()}

    def split_out(finished_dir):
        return {f.stem for f in Path(finished_dir).glob('*.pdf') if f.name not in originals}

    def expected_dashboard(after, fixed_set):
        fixed = {i for i in fixed_set if i in after and after[i] != 'PENDING_FIX'}
        waiting = {i for i, s in after.items() if s == 'PENDING_FIX'}
        wrong = {i for i, s in after.items() if s == 'MISMATCH'}
        complete = {i for i, s in after.items() if s == 'MATCH' and i not in fixed}
        return {'total': len(after), 'complete': len(complete), 'needs_fix': len(waiting) + len(fixed), 'not_fixed': len(waiting),
                'fixed': len(fixed), 'error': len(wrong), 'error_after_fix': len(wrong & fixed)}

    def dashboard():
        return call('get_job_dashboard', job_id=jid)['data']

    def printed_files():
        return {f.stem.split('_', 1)[1]: f for f in (folder / 'print').rglob('*.pdf')}

    def printed_facts(path):
        text = vd.pdftotext(path, '-raw')
        shipped = re.search(r'TOTAL SHIPPED \(UNITS\)\s+([\d,]+)(?:\.\d+)?', text)
        footer = text[text.rfind('NO CARTONS (CTN)'):].split('LEVI STRAUSS')[0]
        whole = [int(t.replace(',', '')) for t in re.findall(r'(?<![\d,.])\d+(?![\d,.])', footer)] if 'NO CARTONS (CTN)' in text else []
        units = int(shipped.group(1).replace(',', '')) if shipped else (whole[0] if whole else None)
        branch = re.search(r'Branch code:\s*(\d+)', text)
        return {'pages': text.count(''), 'units': units, 'branch': branch.group(1) if branch else None,
                'store': vd.ship_to_store(path), 'folder': path.parent.name}

    def listed_pages(note):
        split = note.split('แยกออกมาจาก', 1)[1].split(', ')[0] if 'แยกออกมาจาก' in note else ''
        count = 0
        for ranges in re.findall(r'หน้า (\d+(?:-\d+)?(?: และ \d+(?:-\d+)?)*)', split):
            for part in ranges.split(' และ '):
                first, _, last = part.partition('-')
                count += int(last or first) - int(first) + 1
        return count

    def same_counts(shown, expected):
        return {k: shown[k] for k in expected} == expected

    def state():
        out = {}
        for r in db().execute('select filename,page_no,barcode,units,unit_price,amount,tax_invoice,amt_excl_vat,vat_amount from job_items where job_id=?', (jid,)):
            out[(r['filename'], r['page_no'], r['barcode'], r['units'], r['unit_price'], r['amount'])] = (r['tax_invoice'], r['amt_excl_vat'], r['vat_amount'])
        return out

    # 1. first PDFs, some in nested folders
    place(part1, nested=10)
    scan = call('scan_job_files', job_id=jid)['data']
    check('scan sees every waiting PDF including nested ones', scan['total_in_myfile'] == len(part1) and scan['new_count'] == len(part1))
    check('extraction processes every file', extract(scan['new_files']) == len(part1))
    check('upload here is emptied, nested folders included', not any(upload.iterdir()))
    check('finished holds the first PDFs', len(list(finished.glob('*.pdf'))) == len(part1))
    check('stored rows equal the rows read independently', count('job_items') == rows_in(part1), f'{count("job_items")} vs {rows_in(part1)}')

    # 2. first ASN file, compare, fix
    call('import_asn', {'job_id': jid, 'asn_filename': 'ASN_A.xlsx [sheet]', 'append': 1, 'reconcile': 0, 'records': json.dumps(asn_a)})
    rec = call('get_job_reconcile', job_id=jid, force=1)['data']
    names1 = {p.name for p in part1}
    lines1 = vd.independent_line_verdicts([p for p in pages_all if p['file'] in names1], asn_a)
    sys1 = vd.worst_status(rec['results'])
    differ = [k for k, v in lines1.items() if vd.line_statuses(rec['results']).get(k) != v]
    check('first compare: every line has the status the independent check gives it on the first PDFs and the first ASN file', lines1 and not differ, str(differ[:4]))
    shown = dashboard()
    dash_before = shown
    check('dashboard before the fix counts invoice numbers by their worst status', shown['compared'] and same_counts(shown, expected_dashboard(sys1, set())) and shown['fixed'] == 0, str(shown))
    waiting_texts = collections.defaultdict(set)
    for x in rec['results']:
        if x['status'] == 'PENDING_FIX':
            waiting_texts[x['asn']['invoice_no']].add((x['reason'], x['action']))
    remarks = {r['invoice_no']: r for r in shown['invoices']}
    bad_waiting = [(i, sorted(t), remarks.get(i, {}).get('problem')) for i, t in waiting_texts.items()
                   if len(t) != 1 or FORBIDDEN_REMARK_WORDS.search(min(t)[0] + min(t)[1] + remarks[i]['note_detail'])
                   or (remarks[i]['note'], remarks[i]['problem'], remarks[i]['solution']) != ('',) + min(t)]
    check('every waiting line of a bill shows one problem and one solution, neither names a button, and the dashboard remark of the bill is exactly that problem and solution',
          waiting_texts and not bad_waiting, f'{len(waiting_texts)} waiting invoices, wrong: {bad_waiting[:2]}')
    lines_before = rec['results']
    call('fix_all_invoices', job_id=jid)
    rec = call('get_job_reconcile', job_id=jid, force=1)['data']
    check('first fix leaves no pending invoice', rec['summary']['pending_fix'] == 0, str(rec['summary']))
    faults = before_fix_faults(lines_before, rec['results'])
    check('the first fix changes no status but waiting to complete, and no reason of a wrong line', not faults, str(faults[:2]))
    after1 = vd.worst_status(rec['results'])
    fixed1 = improved(sys1, after1) | split_out(finished)
    waiting_before = {i for i, s in sys1.items() if s == 'PENDING_FIX'}
    shown = dashboard()
    counts = ('complete', 'needs_fix', 'not_fixed', 'fixed', 'error', 'error_after_fix')
    check('dashboard after the fix: exactly the invoices waiting before it, the ones only split out of a shared file included, count as fixed and complete; '
          'the complete count and the count needing a fix are the ones shown before the fix',
          same_counts(shown, expected_dashboard(after1, fixed1)) and {i for i in split_out(finished) if after1.get(i) == 'MATCH'} == waiting_before
          and shown['not_fixed'] == 0 and shown['complete'] == dash_before['complete'] and shown['needs_fix'] == dash_before['needs_fix'] + shown['error_after_fix'],
          f'before {[dash_before[k] for k in counts]}, after {[shown[k] for k in counts]} ({", ".join(counts)})')
    check('every invoice that was waiting is counted once', shown['complete'] + shown['needs_fix'] + shown['error'] - shown['error_after_fix'] == shown['total'] == len(after1), str(shown))
    snap = state()
    first_invoices = {a['invoice_no'] for a in asn_a}
    ready1 = {i for i in first_invoices if after1.get(i) == 'MATCH'}
    round1 = print_round()
    printed1 = printed_in('print ครั้งที่ 1')
    check('first print round holds exactly the invoices of the first ASN file that are complete after the first fix',
          round1['round'] == 1 and ready1 and printed1 == ready1, f"{round1['created']} files, {len(ready1)} complete of {len(first_invoices)}")
    check('print left the job folders alone', len(list(finished.glob('*.pdf'))) >= len(part1) and (folder / 'print').is_dir())

    # 3. more PDFs arrive, plus one already extracted
    place(part2 + [part1[0]])
    scan = call('scan_job_files', job_id=jid)['data']
    check('scan separates new files from the one already extracted',
          scan['new_count'] == len(part2) and scan['existing_count'] == 1 and scan['duplicate_count'] == 0,
          f"new {scan['new_count']} existing {scan['existing_count']} duplicates {scan['duplicate_count']}")
    extract(scan['new_files'])
    now = state()
    check('rows of the first PDFs are untouched by the second extraction', all(now.get(k) == v for k, v in snap.items()))
    check('total rows equal all PDFs', count('job_items') == rows_in(part1 + part2))

    # 4. second ASN file
    before_rows = count('job_asn_records')
    imported_b = call('import_asn', {'job_id': jid, 'asn_filename': 'ASN_B.xlsx [sheet]', 'append': 1, 'reconcile': 0, 'records': json.dumps(asn_b)})
    sources = [r[0] for r in db().execute('select distinct source_file from job_asn_records where job_id=?', (jid,))]
    check('second ASN file adds rows and keeps the old ones', before_rows == len(asn_a) and count('job_asn_records') == len(asn_all) and len(sources) == 2)
    every_pdf = part1 + part2
    bar = sb.status_bar(jid)
    check('the status bar of the Job page shows every PDF file and row and both ASN files with every ASN row, all counted independently, and no VBRP row, and the import answer carries the same ASN counts',
          bar == f'PDF: {len(every_pdf):,} ไฟล์ | รายการ: {rows_in(every_pdf):,} รายการ | ASN: 2 ไฟล์ | รายการ: {len(asn_all):,} รายการ | VBRP: 0 แถว'
          and imported_b.get('asn_counts') == {'files': 2, 'rows': len(asn_all)}, f"{bar} / {imported_b.get('asn_counts')}")
    check('the comparison is marked outdated after adding ASN', db().execute('select asn_reconciled from jobs where id=?', (jid,)).fetchone()[0] == 0)
    rec = call('get_job_reconcile', job_id=jid, force=1)['data']
    lines_all = vd.independent_line_verdicts(pages_all, asn_all)
    sys_all = vd.worst_status(rec['results'])
    expected_all = {k: 'MATCH' if v == 'PENDING_FIX' and k[0] in first_invoices else v for k, v in lines_all.items()}
    differ = [k for k, v in expected_all.items() if vd.line_statuses(rec['results']).get(k) != v]
    check('full compare: every line has the status the independent check gives it, the waiting lines of the first ASN file complete since the first fix',
          lines_all and not differ, str(differ[:4]))
    dash_before = dashboard()
    wrong_row = {r['invoice_no']: r for r in dash_before['invoices']}.get('93251539-1', {})
    check('before the fix the dashboard shows bill 93251539-1 as wrong, its problem names the barcode with the pieces on both sides and it has no solution',
          wrong_row.get('status') == 'MISMATCH' and all(t in wrong_row.get('problem', '') for t in ('5401221426009', 'PDF: 4', 'ASN: 2'))
          and wrong_row.get('solution') == '', str(wrong_row)[:240])
    lines_before = rec['results']
    call('fix_all_invoices', job_id=jid)
    rec = call('get_job_reconcile', job_id=jid, force=1)['data']
    faults = before_fix_faults(lines_before, rec['results'])
    check('the second fix changes no status but waiting to complete, and no reason of a wrong line', not faults, str(faults[:2]))
    final = rec['summary']
    after_all = vd.worst_status(rec['results'])
    fixed_all = fixed1 | improved(sys_all, after_all) | split_out(finished)
    shown = dashboard()
    check('dashboard after the second round adds the new fixes to the first ones', same_counts(shown, expected_dashboard(after_all, fixed_all)), str(shown))
    check('the second fix leaves the complete count as it was shown before it, and the count needing a fix grows only by the fixed bills still wrong',
          shown['complete'] == dash_before['complete'] and shown['needs_fix'] == dash_before['needs_fix'] + shown['error_after_fix'] - dash_before['error_after_fix'],
          f'before {[dash_before[k] for k in counts]}, after {[shown[k] for k in counts]}')
    fixed_view = {(x['asn']['invoice_no'], x['asn']['ean_upc'], x['status'], x['fixed']) for x in rec['results']}
    one_before = vd.worst_status(reference['before']['results'])
    one_after = vd.worst_status(reference['after']['results'])
    one_fixed = improved(one_before, one_after) | split_out(reference['finished_dir'])
    check('dashboard equals what a single run over all files would show', same_counts(shown, expected_dashboard(one_after, one_fixed)), f'{shown} vs {expected_dashboard(one_after, one_fixed)}')
    ref_summary = reference['after']['summary']
    all_ready = {a['invoice_no'] for a in asn_all} - {i for i, s in vd.worst_status(rec['results']).items() if s != 'MATCH'}
    round2 = print_round()
    second = printed_in('print ครั้งที่ 2')
    check('second print round holds only the invoices not printed before', round2['round'] == 2 and second == all_ready - printed1 and not (second & printed1), f"{round2['created']} files")
    check('printing again with nothing new creates no round', print_round() is None and not (folder / 'print' / 'print ครั้งที่ 3').exists())
    table = {r['invoice_no']: r for r in dashboard()['invoices']}
    check('the table has one row per ASN invoice number', set(table) == {a['invoice_no'] for a in asn_all} and len(table) == shown['total'], f'{len(table)} rows')
    check('the result of every row is the worst status of its lines', all(table[i]['status'] == st for i, st in after_all.items()))
    asn_pieces = collections.Counter()
    for a in asn_all:
        asn_pieces[a['invoice_no']] += int(a['quantity'])
    check('a complete invoice shows as many pieces as the ASN lists', all(table[i]['units'] == asn_pieces[i] for i, st in after_all.items() if st == 'MATCH'))
    printed = printed_files()
    asn_store = {}
    for a in asn_all:
        asn_store.setdefault(a['invoice_no'], a['dest_store_no'])
    wrong, misplaced, unnumbered = [], [], 0
    for inv, path in printed.items():
        facts = printed_facts(path)
        row = table[inv]
        unnumbered += facts['store'] is None
        if (row['pages'], row['units'], row['branch_code']) != (facts['pages'], facts['units'], facts['branch']) or row['store_no'] != asn_store.get(inv):
            wrong.append((inv, row['pages'], facts['pages'], row['units'], facts['units'], row['branch_code'], facts['branch'], row['store_no']))
        if not re.fullmatch(r'[1-9]\d*_.+\.pdf', path.name) or facts['folder'] != asn_store.get(inv) or facts['store'] not in (None, facts['folder']):
            misplaced.append((inv, path.parent.name, path.name, asn_store.get(inv), facts['store']))
    check('pages, pieces and branch code of every row equal what its printed PDF shows, its store is the ASN Dest Store No.', printed and not wrong,
          f'{len(printed)} printed files, differences: {wrong[:3]}')
    asn_cost = {}
    for a in asn_all:
        if a['total_cost_ex_vat'] > 0:
            asn_cost.setdefault(a['invoice_no'], a['total_cost_ex_vat'])
    wrong_cost = [(inv, row.get('total_cost_ex_vat'), asn_cost.get(inv)) for inv, row in table.items()
                  if 'net_amount' in row or (row.get('total_cost_ex_vat') is None) != (inv not in asn_cost)
                  or (inv in asn_cost and abs(row['total_cost_ex_vat'] - asn_cost[inv]) > 0.005)]
    check('the Total Cost Ex VAT of every row is the first positive Total Cost Ex VAT the ASN workbook lists for its invoice, and no row carries a net amount',
          table and not wrong_cost, f'{len(table)} rows, {len(table) - len(asn_cost)} without a cost, differences: {wrong_cost[:3]}')
    check('every printed file is named 1_, 2_, ... without padding inside the folder of its ASN Dest Store No., the store its PDF ships to when it prints one',
          printed and not misplaced, f'{len(printed) - unnumbered} cross-checked with Ship to Party, {unnumbered} print no store number, misplaced: {misplaced[:3]}')

    split = split_out(finished)
    wrong_source = [(i, table[i]['source_files']) for i in sorted(split) if i in table and (
        not table[i]['source_files'] or any(n not in originals or root_of(Path(n)) != i.split('-')[0] for n in table[i]['source_files']))]
    check('a split invoice names the original PDF its pages come from, never its split file', split and not wrong_source, f'{len(split)} split invoices, wrong: {wrong_source[:3]}')
    fixed_now = {i for i in fixed_all if after_all.get(i) != 'PENDING_FIX'}
    bad_notes = [(i, r['note']) for i, r in table.items() if r['fixed'] != (i in fixed_now) or r['note'].startswith('แก้ไขแล้ว') or (
        r['status'] == 'MATCH' and ((i in fixed_now) != (r['note'] != '') or r['problem'] or r['solution'])) or (
        r['status'] == 'MATCH' and i in fixed_now and listed_pages(r['note']) != r['pages'])]
    check('a fixed complete invoice says which pages it was split from, as many pages as its PDF has, no other complete invoice has a remark, and no remark starts with "แก้ไขแล้ว"',
          fixed_now and not bad_notes, f'{len(fixed_now)} fixed, wrong: {bad_notes[:3]}')
    kinds = collections.defaultdict(set)
    for step in db().execute('select invoice_no, kind from job_invoice_fix_steps where job_id=?', (jid,)):
        kinds[step['invoice_no']].add(step['kind'])
    all_three = sorted(i for i, k in kinds.items() if k >= {'renumber', 'clear_footer', 'split'} and i in table)
    phrases = ('แก้เลขบิลจากหน้า', 'ลบยอดรวมท้ายบิลจากหน้า', 'แยกออกมาจากหน้า')
    incomplete = [(i, table[i]['note']) for i in all_three if not all(p in table[i]['note'] for p in phrases)]
    example = table.get('93247359-1', {}).get('note')
    check('every invoice the fix renumbered, took a footer off and split names all three in its remark',
          all_three and not incomplete and example == 'แก้เลขบิลจากหน้า 2 (93247359-2 เป็น 93247359-1), ลบยอดรวมท้ายบิลจากหน้า 1, แยกออกมาจากหน้า 1-2',
          f'{len(all_three)} invoices, missing a part: {incomplete[:3]}, 93247359-1: {example}')
    still_wrong = [r for r in table.values() if r['fixed'] and r['status'] == 'MISMATCH']
    check('a fixed invoice that is still wrong says what the fix did, then its problem, with no solution',
          still_wrong and all(r['note'] and r['problem'] and not r['solution'] and not r['note'].startswith('แก้ไขแล้ว') for r in still_wrong),
          f"{len(still_wrong)} rows: {[(r['note'][:60], r['problem'][:60]) for r in still_wrong[:2]]}")
    lines = rec['results']
    shows = collections.Counter('FIXED' if x['status'] == 'MATCH' and x['fixed'] else x['status'] for x in lines)
    flags_ok = all(x['fixed'] == (x['asn']['invoice_no'] in fixed_now) for x in lines)
    notes_ok = all(x['fix_note'] == table[x['asn']['invoice_no']]['note'] for x in lines if x['fixed'] and table[x['asn']['invoice_no']]['status'] == 'MATCH')
    s = rec['summary']
    check('compare lines split into complete, fixed, waiting and wrong, adding up to the totals, each fixed line with the remark of its invoice',
          sum(shows.values()) == s['total_compared'] and shows['MATCH'] + shows['FIXED'] == s['matched'] and shows['PENDING_FIX'] == s['pending_fix']
          and shows['MISMATCH'] == s['mismatch'] and shows['FIXED'] > 0 and flags_ok and notes_ok, f'{dict(shows)} vs {s}')
    problem_rows = [r for r in table.values() if r['status'] != 'MATCH']
    check('every invoice that is not complete says its problem; a waiting one its solution too, a wrong one none, and no wrong compare line has a solution',
          all(r['problem'] and bool(r['solution']) == (r['status'] == 'PENDING_FIX') for r in problem_rows)
          and not [x for x in rec['results'] if x['status'] == 'MISMATCH' and x['action']], f'{len(problem_rows)} rows')
    dotted = [t for t in [r['note'] + r['problem'] + r['solution'] for r in table.values()] + [x['reason'] + x['action'] + x['fix_note'] for x in lines]
              if '·' in t or re.search('footer', t, re.I)]
    check('no remark, reason or action separates its topics with a middle dot or says "footer"', not dotted, str(dotted[:2]))
    culprit_row = table.get('93251539-1', {})
    check('the wrong invoice names the barcode that breaks it, with the pieces on both sides', all(t in culprit_row.get('problem', '') for t in ('5401221426009', 'PDF: 4', 'ASN: 2'))
          and not culprit_row.get('problem', '').startswith('รายการนี้ถูกต้อง'), culprit_row.get('problem', '')[:160])
    check('final result equals one run over all files', all(final[k] == ref_summary[k] for k in ('matched', 'pending_fix', 'mismatch', 'total_compared')), str(final))

    # 5. end state equals a single run
    one_state = {}
    for r in reference['items_after']:
        one_state[(r['filename'], int(r['page_no']), r['barcode'], int(r['units']), float(r['unit_price']), float(r['amount']))] = (r['tax_invoice'], r['amt_excl_vat'], r['vat_amount'])
    inc_state = state()
    diff = [k for k in set(one_state) | set(inc_state) if one_state.get(k) != inc_state.get(k)]
    check('stored rows equal a single run', not diff, f'{len(diff)} differences')
    one_files = {f.name for f in Path(reference['finished_dir']).glob('*.pdf')}
    check('the same corrected PDFs exist', one_files == {f.name for f in finished.glob('*.pdf')})
    vd.fails = []
    vd.check = check
    vd.check_split_pages({'finished_dir': str(finished)})

    # 6. repeated actions
    call('fix_all_invoices', job_id=jid)
    check('pressing fix again changes nothing', state() == inc_state)

    def compare_view():
        lines = call('get_job_reconcile', job_id=jid, force=1)['data']['results']
        return {(x['asn']['invoice_no'], x['asn']['ean_upc'], x['status'], x['fixed']) for x in lines}

    again = compare_view()
    check('after pressing fix again the compare shows the same bills fixed and none waiting for a split already done',
          again == fixed_view and not [v for v in again if v[2] == 'PENDING_FIX'], f'{len(again ^ fixed_view)} lines differ')
    call('import_asn', {'job_id': jid, 'asn_filename': 'ASN_A.xlsx [sheet]', 'append': 1, 'reconcile': 0, 'records': json.dumps(asn_a)})
    check('adding the same ASN file again replaces its rows', count('job_asn_records') == len(asn_all))
    label = 'ASN_A_copy.xlsx [sheet]'
    r = call('import_asn', {'job_id': jid, 'asn_filename': label, 'append': 1, 'reconcile': 0, 'records': json.dumps(asn_a)})
    check('the same invoices under another ASN file name are skipped, not doubled', count('job_asn_records') == len(asn_all) and r['skipped_invoices'] > 0)
    rec = call('get_job_reconcile', job_id=jid, force=1)['data']
    check('result is unchanged after the skipped ASN file', rec['summary']['matched'] == final['matched'] and rec['summary']['mismatch'] == final['mismatch'])
    r = call('import_asn', {'job_id': jid, 'asn_filename': label, 'append': 1, 'reconcile': 0, 'duplicate_mode': 'replace', 'records': json.dumps(asn_a)})
    sources = [x[0] for x in db().execute('select distinct source_file from job_asn_records where job_id=?', (jid,))]
    check('replacing swaps the earlier rows without doubling', count('job_asn_records') == len(asn_all) and label in sources and r['replaced_invoices'] > 0)

    # 6b. the same ASN file again after the whole workflow
    call('get_job_reconcile', job_id=jid, force=1)
    probe = call('asn_overlap', {'job_id': jid, 'asn_filename': label, 'records': json.dumps(asn_a)})['same_file']
    check('the same ASN file again is recognised as imported, identical and already compared', probe['stored'] == len(asn_a) and probe['identical'] and probe['reconciled'], str(probe))
    changed = [dict(r) for r in asn_a]
    changed[0]['quantity'] = int(changed[0]['quantity']) + 1
    check('a file with one changed quantity is told apart', not call('asn_overlap', {'job_id': jid, 'asn_filename': label, 'records': json.dumps(changed)})['same_file']['identical'])
    rows_kept, state_kept = count('job_asn_records'), state()
    r = call('import_asn', {'job_id': jid, 'asn_filename': label, 'append': 1, 'reconcile': 0, 'same_file_mode': 'skip', 'records': json.dumps(asn_a)})
    check('skipping it leaves the rows and the comparison as they were', r['skipped_same_file'] and r['imported_records'] == 0 and r['asn_reconciled'] == 1 and count('job_asn_records') == rows_kept and state() == state_kept)
    r = call('import_asn', {'job_id': jid, 'asn_filename': label, 'append': 1, 'reconcile': 1, 'same_file_mode': 'replace', 'records': json.dumps(asn_a)})
    check('overwriting it reloads the rows and compares again to the same result', r['replaced_same_file'] and r['asn_reconciled'] == 1 and count('job_asn_records') == len(asn_all) and r['reconcile']['summary']['matched'] == final['matched'] and r['reconcile']['summary']['mismatch'] == final['mismatch'], str(r.get('reconcile', {}).get('summary')))

    ids = [a['id'] for a in call('get_asn_records', job_id=jid)['data']]
    cleared = call('delete_asn_records', {'job_id': jid, 'ids': json.dumps(ids)})
    emptied = count('job_asn_records')
    bar = sb.status_bar(jid)
    check('after the ASN is cleared the status bar and the answer of the clear count no ASN file and no ASN row, the PDF part stays',
          bar is not None and bar.endswith('| ASN: 0 ไฟล์ | รายการ: 0 รายการ | VBRP: 0 แถว') and bar.startswith(f'PDF: {len(every_pdf):,} ไฟล์')
          and cleared.get('asn_counts') == {'files': 0, 'rows': 0}, f"{bar} / {cleared.get('asn_counts')}")
    call('import_asn', {'job_id': jid, 'asn_filename': 'ASN_ALL.xlsx', 'append': 1, 'reconcile': 0, 'records': json.dumps(asn_all)})
    reimported = compare_view()
    check('clearing the ASN after the fix, importing it again and comparing shows the same bills fixed and none waiting for a split already done',
          emptied == 0 and reimported == fixed_view and not [v for v in reimported if v[2] == 'PENDING_FIX'], f'{emptied} rows left, {len(reimported ^ fixed_view)} lines differ')

    # 7. overwrite a fixed file
    call('import_asn', {'job_id': jid, 'asn_filename': 'ASN_ALL.xlsx', 'append': 0, 'reconcile': 0, 'records': json.dumps(asn_all)})
    call('fix_all_invoices', job_id=jid)
    base = call('get_job_reconcile', job_id=jid, force=1)['data']['summary']
    name = 'RM Document-Customer Billing(PDF)-0093247359 3.pdf'
    fixed_state = state()
    scan = call('scan_job_files', job_id=jid)['data']
    check('the leftover copy is listed as an existing file', scan['existing_count'] == 1 and scan['new_count'] == 0)
    call('extract_job_batch', {'job_id': jid, 'files': json.dumps(scan['existing_files']), 'overwrite': 1})
    reset = call('get_job_reconcile', job_id=jid)['data']['summary']
    check('overwriting a fixed file sends it back to pending until fix is pressed again', reset['pending_fix'] > 0 and state() != fixed_state, str(reset))
    call('fix_all_invoices', job_id=jid)
    check('fixing again restores the exact fixed rows', state() == fixed_state and call('get_job_reconcile', job_id=jid, force=1)['data']['summary']['matched'] == base['matched'])

    # 8. the same bill under another PDF name
    shutil.copy(FIXTURES / 'CDS ZFP (ZKE)' / 'RM Document-Customer Billing(PDF)-0093248051 3.pdf', upload / 'Copy of 0093248051.pdf')
    scan = call('scan_job_files', job_id=jid)['data']
    check('scan flags the renamed copy as a duplicate bill', scan['duplicate_count'] == 1 and scan['new_count'] == 0)
    rows_before = count('job_items')
    r = call('extract_job_batch', {'job_id': jid, 'files': json.dumps(scan['duplicate_files']), 'overwrite': 0})
    check('skipping leaves rows and file alone', r['processed_files'] == 0 and count('job_items') == rows_before and (upload / 'Copy of 0093248051.pdf').exists())
    r = call('extract_job_batch', {'job_id': jid, 'files': json.dumps(scan['duplicate_files']), 'overwrite': 1, 'replace_duplicates': 1})
    holders = [x[0] for x in db().execute("select distinct filename from job_items where job_id=? and tax_invoice like '93248051%'", (jid,))]
    check('replacing swaps the earlier file for the copy without doubling rows',
          holders == ['Copy of 0093248051.pdf'] and count('job_items') == rows_before and not list(upload.rglob('*.pdf')), str(holders))
    lines = call('get_job_reconcile', job_id=jid, force=1)['data']['results']
    waiting = {x['asn']['invoice_no'] for x in lines if x['status'] == 'PENDING_FIX'}
    check('the bills of the copy lost their fix history with the replaced file, so they wait for their split until fix is pressed again',
          waiting == {a['invoice_no'] for a in asn_all if a['invoice_no'].startswith('93248051-')}, str(sorted(waiting)))
    call('fix_all_invoices', job_id=jid)
    summary = call('get_job_reconcile', job_id=jid, force=1)['data']['summary']
    check('result is unchanged after replacing a bill with its copy and pressing fix', summary['matched'] == base['matched'] and summary['mismatch'] == base['mismatch'] and summary['pending_fix'] == 0, str(summary))


def pdf_only_job(sb, asn_all, pages_all):
    call = sb.call
    rows, picks = vd.pdf_only_fixture(pages_all, asn_all)
    _, _, assigned, _, _ = vd.assign_lines(pages_all, rows)
    files = sorted({x[0] for inv in picks for x in assigned[inv]})
    roots = {root_of(Path(f)) for f in files}
    pages = [p for p in pages_all if p['file'] in files]
    asn = [a for a in rows if vd.root(a['invoice_no']) in roots]
    jid = call('create_job', {'name': 'PDF only'})['data']['id']
    upload = sb.root / 'myfile' / 'PDF only' / 'TAX INVOICE' / 'upload here'
    by_name = {p.name: p for p in vd.sample_pdfs()}
    for f in files:
        shutil.copy(by_name[f], upload / f)
    scan = call('scan_job_files', job_id=jid)['data']
    call('extract_job_batch', {'job_id': jid, 'files': json.dumps(scan['new_files'])})
    call('import_asn', {'job_id': jid, 'asn_filename': 'ASN_PDF_ONLY.xlsx', 'append': 1, 'reconcile': 0, 'records': json.dumps(asn)})
    before = call('get_job_reconcile', job_id=jid, force=1)['data']['results']
    lines = vd.independent_line_verdicts(pages, asn)
    differ = [k for k, v in lines.items() if vd.line_statuses(before).get(k) != v]
    check(f'a Job of {len(files)} PDFs whose bills {sorted(picks)} lose 1, 2 and 4 barcodes from the ASN: every line has the status the independent check gives it',
          lines and not differ, str(differ[:4]))
    want = {inv: vd.expected_problem(pages, asn, inv) for inv in picks}

    def remarks():
        table = {r['invoice_no']: r for r in call('get_job_dashboard', job_id=jid)['data']['invoices']}
        return {inv: (table[inv]['status'], table[inv]['problem'], table[inv]['solution']) for inv in picks}

    shown = remarks()
    texts = {inv: {(x['status'], x['reason'], x['action']) for x in before if x['asn']['invoice_no'] == inv} for inv in picks}
    check('their compare lines and dashboard remarks read every barcode only in the PDF with its pieces, then the amount problem, and no solution',
          all(texts[inv] == {('MISMATCH', want[inv], '')} and shown[inv] == ('MISMATCH', want[inv], '') for inv in picks),
          json.dumps({inv: [sorted(texts[inv]), shown[inv]] for inv in picks if shown[inv] != ('MISMATCH', want[inv], '')}, ensure_ascii=False)[:600])
    call('fix_all_invoices', job_id=jid)
    after = call('get_job_reconcile', job_id=jid, force=1)['data']['results']
    faults = before_fix_faults(before, after)
    check('after the fix every line has the status it showed before, waiting counted as complete, those bills keep their problem and the dashboard says it again with no solution',
          not faults and remarks() == shown, f'{faults[:2]} {remarks()}'[:600])


def main():
    vd.require_samples()
    sheet, asn_all = vd.read_asn()
    pages_all = vd.read_pdfs()
    print(f'ASN sheet "{sheet}": {len(asn_all)} rows | PDFs: {len({p["file"] for p in pages_all})} files')
    work = Path(tempfile.mkdtemp(prefix='verify_incremental_'))
    sandbox = None
    try:
        one = work / 'one'
        (one / 'data').mkdir(parents=True)
        (one / 'myfile').mkdir()
        (work / 'asn.json').write_text(json.dumps(asn_all))
        done = subprocess.run([PHP, str(ROOT / 'tests' / 'verify_docs_run.php'), str(one), str(vd.write_pdf_list(work / 'pdfs.json')), str(work / 'asn.json'), str(work / 'one.json')],
                              capture_output=True, text=True, encoding='utf-8')
        if done.returncode != 0:
            print(done.stdout, done.stderr)
            sys.exit(2)
        reference = json.loads((work / 'one.json').read_text(encoding='utf-8'))
        sandbox = Sandbox(work)
        scenario(sandbox, asn_all, pages_all, reference)
        pdf_only_job(sandbox, asn_all, pages_all)
    finally:
        if sandbox:
            sandbox.stop()
        shutil.rmtree(work, ignore_errors=True)
    print('\n' + (f'{len(fails)} FAILED' if fails else 'ALL PASS'))
    sys.exit(1 if fails else 0)


if __name__ == '__main__':
    main()
