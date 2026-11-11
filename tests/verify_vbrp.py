"""Checks VBRP as the third source of the Tax Invoice compare on the real sample with an independent reader.

The rules come from the VBRP contract and the status split contract (รอแก้ไข PDF / รอแก้ไข ASN, 2026-10-28) alone. The
VBRP and ASN workbooks are read with openpyxl, never with the app's reader; the PDF side is the rows the app stores for the
290 sample PDFs once the fix button has worked on every file, so the page plan leaves them as they are judged. The verdict
of every source on every check, the status (a bill whose ASN is wrong waits for the ASN), the problem of a wrong PDF or
ASN, the notes, the dashboard and the print plan are worked out here and compared with what the app answers: before the
fix, after the fix button (which leaves the files of bills waiting for the ASN alone), on the real files and on seeded
changes that must change exactly the bill they touch. Everything runs on a throwaway copy of the app behind a temporary
PHP server, never on data/ or myfile/.

Run: python tests/verify_vbrp.py   (needs openpyxl; PHP_BIN picks the PHP binary)
"""
import collections
import copy
import decimal
import json
import os
import re
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import traceback
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

import openpyxl
from openpyxl.utils import column_index_from_string, get_column_letter

ROOT = Path(__file__).resolve().parent.parent
FIXTURES = ROOT / 'tests' / 'fixtures'
SAMPLE_FOLDERS = ('CDS ZFP (ZKE)', 'CDS ZFP (ZKE) เพิ่มเติม')
VBRP_BOOK = FIXTURES / 'Report VBRP_SIS PROMOTION ZFP Recheck K.Big.xlsx'
ASN_SHEET = 'CDS ZFP (ZKE) done 13.10.2026'
PHP = os.environ.get('PHP_BIN', 'php')

SOURCES = ('pdf', 'asn', 'vbrp')
NAMES = {'pdf': 'PDF', 'asn': 'ASN', 'vbrp': 'VBRP'}
AUTHORITY = ('vbrp', 'asn', 'pdf')
SKIP_FROM = -1.0
CHECKS = {  # check: tolerance, topic of a note, how a figure is written; the selling price is no check (the user's call)
    'qty': (0.0, 'จำนวน', 'pieces'),
    'amount': (1.00, 'ยอดก่อน VAT', 'money'),
    'vat': (0.15, 'VAT', 'baht'),
}
VBRP_FIGURES = ('qty', 'price', 'amount', 'vat')  # row.sources.vbrp: the figures of the checks and the price, only shown
PROBLEM = {'qty': 'จำนวนไม่ตรง', 'amount': 'ยอดรวมไม่ตรง', 'vat': 'VAT ไม่ตรง'}
LABELS = {'A': 'Combine', 'C': 'Bill', 'E': 'Bill. Doc.', 'H': 'Billed Quantity', 'CI': 'Subtotal 2', 'CJ': 'Subtotal 3', 'CK': 'Subtotal 4',
          'CQ': 'EAN/UPC', 'EM': 'Tax Amount'}
FIELDS = {'A': 'combine', 'C': 'invoice', 'E': 'delivery', 'H': 'qty', 'CI': 'price', 'CJ': 'sub3', 'CK': 'cost', 'CQ': 'ean', 'EM': 'vat'}
COMPLETE = 'ข้อมูลถูกต้อง'
ASN_LACKS = 'ASN ไม่มีบาร์โค้ด {} ({} ชิ้น) ที่ PDF และ VBRP มี'
ASN_ONLY = 'ASN มีบาร์โค้ดนี้แต่ PDF และ VBRP ไม่มี'
MISSING = 'มีรายการในไฟล์ ASN แต่ไม่พบไฟล์ PDF ของบิลนี้ [PDF ไม่ออก หรือบิลตกหล่น]'
FIX_ASN = 'แก้ข้อมูลใน ASN แล้วนำเข้า ASN ใหม่'
STILL_PDF = 'หลังแก้ ASN แล้วบิลนี้ยังต้องแก้ไข PDF: '
RANK = {'MATCH': 0, 'PENDING_FIX': 1, 'PENDING_ASN': 2, 'MISMATCH': 3}
ACTION = {'MATCH': COMPLETE, 'PENDING_ASN': FIX_ASN, 'MISMATCH': ''}
LABEL = {'MATCH': 'สมบูรณ์', 'PENDING_FIX': 'รอแก้ไข PDF', 'PENDING_ASN': 'รอแก้ไข ASN', 'MISMATCH': 'ผิดพลาด'}
fails = []


def check(name, ok, detail=''):
    print(('PASS  ' if ok else 'FAIL  ') + name + (f'  [{detail}]' if detail else ''))
    if not ok:
        fails.append(name)


def info(text):
    print('INFO  ' + text)


def short(items, n=3):
    items = list(items)
    return json.dumps(items[:n], ensure_ascii=False, default=str)[:900] + (f' and {len(items) - n} more' if len(items) > n else '')


def cents(x):
    return int(decimal.Decimal(repr(float(x))).quantize(decimal.Decimal('0.01'), decimal.ROUND_HALF_UP) * 100)


def key(invoice):
    return re.sub(r'[^A-Z0-9]', '', str(invoice or '').upper())


def digits(s):
    return re.sub(r'\D', '', str(s or ''))


def figure(text):
    try:
        return float(str(text).replace(',', '').replace(' ', ''))
    except (TypeError, ValueError):
        return None


def positive(x):
    return x if x is not None and x > 0 else None


def natural_key(s):
    return [(0, int(t), '') if t.isdigit() else (1, 0, t) for t in re.split(r'(\d+)', str(s)) if t]


# The workbooks, read with openpyxl

def asn_workbook():
    return next((p for p in sorted(FIXTURES.glob('ASN*.xlsx'))), None)


def read_asn(work):
    """The rows of the Tax Invoice ASN sheet as the page sends them to import_asn"""
    copied = work / 'asn.xlsx'
    shutil.copy(asn_workbook(), copied)
    rows = list(openpyxl.load_workbook(copied, read_only=True, data_only=True)[ASN_SHEET].iter_rows(values_only=True))
    col = {name: i for i, name in enumerate(rows[0]) if name}
    records = []
    for r in rows[1:]:
        if not r[col['Invoice No']]:
            continue
        records.append({'invoice_no': str(r[col['Invoice No']]).strip(), 'ean_upc': str(r[col['EAN/UPC']]).strip(),
                        'quantity': int(r[col['Quantity']] or 0), 'selling_price': float(r[col['Selling Price']] or 0),
                        'total_cost_ex_vat': float(r[col['Total Cost Ex VAT']] or 0), 'total_vat': float(r[col['Total VAT']] or 0),
                        'dest_store_no': str(r[col['Dest Store No.']] or '').strip(), 'delivery_no': str(r[col['Delivery No.']] or '').strip()})
    return records


def read_vbrp(work):
    """Every data row (row 4 down) of the sheets whose row 3 carries the VBRP labels, as {field: value}"""
    copied = work / 'vbrp.xlsx'
    shutil.copy(VBRP_BOOK, copied)
    workbook = openpyxl.load_workbook(copied, read_only=True, data_only=True)
    index = {letter: column_index_from_string(letter) - 1 for letter in FIELDS}
    rows = []
    for sheet in workbook.worksheets:
        values = list(sheet.iter_rows(values_only=True))
        label = lambda letter: str(values[2][index[letter]] or '').strip().lower() if len(values) > 2 and len(values[2]) > index[letter] else ''
        if any(label(letter) != text.lower() for letter, text in LABELS.items()):
            continue
        for number, r in enumerate(values[3:], 4):
            row = {FIELDS[letter]: (r[i] if i < len(r) else None) for letter, i in index.items()}
            if any(v is not None for v in row.values()):
                rows.append(dict(row, sheet=sheet.title, row=number))
    return rows


def write_vbrp(path, rows):
    """A VBRP workbook of the rows, the nine columns at their letters under the labels of row 3"""
    workbook = openpyxl.Workbook(write_only=True)
    sheet = workbook.create_sheet('ZFP')
    width = column_index_from_string('EM')
    letters = {letter: column_index_from_string(letter) - 1 for letter in FIELDS}

    def line(values):
        cells = [None] * width
        for letter, value in values.items():
            cells[letters[letter]] = value
        return cells
    sheet.append(['VBRP'])
    sheet.append([])
    sheet.append(line(LABELS))
    for r in rows:
        sheet.append(line({letter: r[field] for letter, field in FIELDS.items()}))
    workbook.save(path)


# The contract's rules

def counts(row):
    return row['sub3'] is None or float(row['sub3']) < SKIP_FROM


def vbrp_figures(rows):
    """bills: key => {cost, vat, lines}; lines: key => barcode => {qty, price}, over the rows that count"""
    bills, lines = {}, collections.defaultdict(dict)
    for r in rows:
        k = key(r['invoice'])
        if not counts(r) or not k:
            continue
        bill = bills.setdefault(k, {'cost': 0.0, 'vat': 0.0})
        bill['cost'] += float(r['cost'] or 0)
        bill['vat'] += float(r['vat'] or 0)
        barcode = digits(r['ean'])
        if barcode:
            line = lines[k].setdefault(barcode, {'qty': 0.0, 'price': None})
            line['qty'] += float(r['qty'] or 0)
            if r['price'] is not None and (line['price'] is None or float(r['price']) < line['price']):
                line['price'] = float(r['price'])
    for k, bill in bills.items():
        bill.update(cost=cents(bill['cost']) / 100, vat=cents(bill['vat']) / 100, lines=len(lines[k]))
    return bills, lines


def agrees(a, b, tolerance):
    return abs(cents(a) - cents(b)) <= cents(tolerance)


def judge(figures, tolerance):
    present = {s: figures[s] for s in SOURCES if figures[s] is not None}
    verdict = {s: 'na' for s in SOURCES}
    if len(present) < 2:
        return verdict
    for s, x in present.items():
        verdict[s] = 'ok' if any(agrees(x, y, tolerance) for t, y in present.items() if t != s) else 'wrong'
    if len(present) == 2 and 'ok' not in verdict.values():
        verdict[next(s for s in AUTHORITY if s in present)] = 'ok'
    return verdict


def pieces(x):
    return str(int(x)) if float(x).is_integer() else f'{x:g}'


def written(check_name, x):
    if x is None:
        return '-'
    form = CHECKS[check_name][2]
    if form == 'pieces':
        return pieces(x) + ' ชิ้น'
    return ('฿' if form == 'baht' else '') + f'{cents(x) / 100:,.2f}'


def asn_problem(check_name, figures):
    """The problem of an ASN a check judges wrong: the others that have a figure, joined with = when they agree"""
    f = figures[check_name]
    others = [f'{NAMES[s]} {written(check_name, f[s])}' for s in ('pdf', 'vbrp') if f[s] is not None]
    joined = ' = ' if len(others) == 2 and agrees(f['pdf'], f['vbrp'], CHECKS[check_name][0]) else ' / '
    return f"ASN {CHECKS[check_name][1]} ไม่ตรง (ASN {written(check_name, f['asn'])} / {joined.join(others)})"


def store_of(item):
    for text in (item.get('ship_to_party') or '', item.get('address') or ''):
        found = re.search(r'\b(10\d{3}|20\d{3})\b', text)
        if found:
            return found.group(1)
    return ''


def expected(items, asn, vbrp_rows):
    """What the app must answer once the fix has worked on every file, from its stored PDF rows and the two workbooks:
    lines (key, barcode) => {invoice_no, ean_upc, status, reason, action, sources, figures}, unjudged lines (a shape this
    oracle does not word), the bills' footer faults, and the VBRP figures of the bills"""
    bills, vlines = vbrp_figures(vbrp_rows)
    pdf, meta, pdf_roots = collections.OrderedDict(), {}, set()
    for item in items:
        k = key(item['tax_invoice'])
        if not k:
            continue
        bar = digits(item['barcode'])
        pdf[(k, bar)] = pdf.get((k, bar), 0) + int(item['units'] or 0)
        pdf_roots.add((key(str(item['tax_invoice']).split('-')[0]), bar))
        m = meta.setdefault(k, {'excl': None, 'vat': None, 'store': store_of(item), 'pages': set(), 'footers': set()})
        m['pages'].add((item['filename'], int(item['page_no'])))
        excl, vat = positive(figure(item['amt_excl_vat'])), positive(figure(item['vat_amount']))
        if excl is not None:
            m['excl'] = excl
            m['footers'].add((item['filename'], int(item['page_no'])))
        if vat is not None:
            m['vat'] = vat

    groups = collections.OrderedDict()
    for a in asn:
        g = (key(a['invoice_no']), digits(a['ean_upc']))
        if g not in groups:
            groups[g] = dict(a, quantity=0)
        groups[g]['quantity'] += int(a['quantity'])
    asn_bills = {k for k, _ in groups}

    bill_problem, bill_notes = collections.defaultdict(list), collections.defaultdict(list)
    for (k, bar), units in pdf.items():
        if k in asn_bills and (k, bar) not in groups:
            vq = vlines[k].get(bar, {'qty': 0.0})['qty']
            if k in bills and judge({'pdf': units, 'asn': 0.0, 'vbrp': vq}, 0.0)['pdf'] != 'wrong':
                bill_notes[k].append(ASN_LACKS.format(bar, units))
            else:
                bill_problem[k].append(f'บาร์โค้ด {bar} มีใน PDF แต่ไม่มีใน ASN ({units} ชิ้น)')
    problems = {k: ', '.join(p[:3]) + (f' และอีก {len(p) - 3} รายการ' if len(p) > 3 else '') for k, p in bill_problem.items()}

    verdicts, culprits, unjudged = {}, collections.defaultdict(list), []
    for (k, bar), a in groups.items():
        found = (k, bar) in pdf
        shifted = not found and (key(a['invoice_no'].split('-')[0]), bar) in pdf_roots
        if shifted:
            unjudged.append((a['invoice_no'], a['ean_upc'], 'line under another number of its root'))
        if k not in bills:
            continue
        m = meta.get(k, {})
        vl = vlines[k].get(bar, {'qty': 0.0})
        figures = {
            'qty': {'pdf': None if shifted else float(pdf.get((k, bar), 0)), 'asn': float(a['quantity']), 'vbrp': vl['qty']},
            'amount': {'pdf': None if shifted else m.get('excl'), 'asn': positive(float(a['total_cost_ex_vat'])), 'vbrp': positive(bills[k]['cost'])},
            'vat': {'pdf': None if shifted else m.get('vat'), 'asn': positive(float(a['total_vat'])), 'vbrp': positive(bills[k]['vat'])},
        }
        verdicts[(k, bar)] = (figures, {c: judge(figures[c], CHECKS[c][0]) for c in CHECKS})
        if found and verdicts[(k, bar)][1]['qty']['pdf'] == 'wrong':
            culprits[k].append(f"{bar} จำนวนไม่ตรง (PDF: {pdf[(k, bar)]} / ASN: {a['quantity']})")
        elif not found and not shifted and verdicts[(k, bar)][1]['qty']['pdf'] == 'wrong':
            culprits[k].append(f'{bar} ไม่พบใน PDF')

    lines = {}
    for (k, bar), a in groups.items():
        line = {'invoice_no': a['invoice_no'], 'ean_upc': a['ean_upc'], 'sources': None}
        lines[(k, bar)] = line
        if (k, bar) not in verdicts:
            continue
        figures, v = verdicts[(k, bar)]
        pdf_wrong = {c: v[c]['pdf'] == 'wrong' for c in CHECKS}
        found = (k, bar) in pdf
        m = meta.get(k, {'store': '', 'pages': set(), 'footers': set()})
        if len(m['pages']) > 1 and len(m['footers']) > 1:
            unjudged.append((a['invoice_no'], a['ean_upc'], 'bill with more than one footer'))
        absent_rightly = not found and figures['qty']['pdf'] is not None and not pdf_wrong['qty']
        if found or absent_rightly:
            store_ok = not m['store'] or not a['dest_store_no'] or m['store'] == a['dest_store_no']
            line_ok = not pdf_wrong['qty'] and store_ok
            problem = problems.get(k, '')
            if line_ok and not problem and not pdf_wrong['amount'] and not pdf_wrong['vat']:
                line.update(status='MATCH', reason=COMPLETE)
            else:
                reasons = []
                for c, text in (('qty', None), ('store', None), ('problem', None), ('amount', None), ('vat', None)):
                    if c == 'store' and not store_ok:
                        reasons.append(f"รหัสสาขาไม่ตรง (PDF: {m['store']} / ASN: {a['dest_store_no']})")
                    elif c == 'problem' and problem:
                        reasons.append(problem)
                    elif c in PROBLEM and pdf_wrong[c]:
                        reasons.append(f"{PROBLEM[c]} (PDF: {written(c, figures[c]['pdf'])} / ASN: {written(c, figures[c]['asn'])} / VBRP: {written(c, figures[c]['vbrp'])})")
                reason = ', '.join(reasons)
                if line_ok and culprits[k]:
                    named = culprits[k][:3]
                    more = f' และอีก {len(culprits[k]) - 3} รายการ' if len(culprits[k]) > 3 else ''
                    reason = 'รายการนี้ถูกต้อง แต่บิลผิดพลาดเพราะบาร์โค้ด ' + ', '.join(named) + more + ' จึงทำให้ยอดรวมบิลไม่ตรง (' + reason + ')'
                line.update(status='MISMATCH', reason=reason)
        else:
            line.update(status='MISMATCH', reason=MISSING + (', ' + problems[k] if problems.get(k) else ''))

        notes, note_checks = [], []
        for c in CHECKS:
            if pdf_wrong[c]:  # the PDF's problem of the check names the three figures already
                continue
            for s in ('asn', 'vbrp'):
                if v[c][s] != 'wrong':
                    continue
                note_checks.append(c)
                if c == 'qty' and absent_rightly and bar not in vlines[k]:
                    notes.append(ASN_ONLY)
                    break
                others = ' / '.join(f'{NAMES[o]}: {written(c, figures[c][o])}' for o in SOURCES if o != s and figures[c][o] is not None)
                notes.append(f'{NAMES[s]} ไม่ตรง: {CHECKS[c][1]} {written(c, figures[c][s])} ({others})')
        overall = {}
        for s in SOURCES:
            seen = [v[c][s] for c in CHECKS]
            overall[s] = 'wrong' if 'wrong' in seen else ('ok' if 'ok' in seen else 'na')
        overall['pdf'] = 'wrong' if line['status'] == 'MISMATCH' or overall['pdf'] == 'wrong' else ('ok' if line['status'] == 'PENDING_FIX' or found or overall['pdf'] != 'na' else 'na')
        vbrp_shown = dict({c: figures[c]['vbrp'] for c in CHECKS}, price=vlines[k].get(bar, {'price': None})['price'])
        line['sources'] = {'vbrp': vbrp_shown, 'verdict': v, 'overall': overall, 'notes': notes + bill_notes[k]}
        line['figures'] = figures
        line['check_notes'] = list(zip(note_checks, notes))

    # A covered bill whose ASN is wrong on a check of one of its lines, or lacks a barcode the PDF and VBRP have, waits
    # for the ASN on every line that is not wrong; its problem names those faults, quantities first, and its notes leave
    # out what the problem names
    for k in dict.fromkeys(k for k, _ in groups):
        own = [g for g in groups if g[0] == k]
        faults = []
        for c in CHECKS:
            for g in own:
                if g in verdicts and verdicts[g][1][c]['asn'] == 'wrong':
                    sentence = (f"บาร์โค้ด {groups[g]['ean_upc'].strip()}: " if c == 'qty' and len(own) > 1 else '') + asn_problem(c, verdicts[g][0])
                    if sentence not in faults:
                        faults.append(sentence)
        faults += [n for n in dict.fromkeys(bill_notes[k]) if n not in faults]
        if not faults:
            continue
        for g in own:
            line = lines[g]
            if line.get('status') != 'MATCH':
                continue
            named = {c for c in CHECKS if verdicts[g][1][c]['asn'] == 'wrong'}
            line.update(status='PENDING_ASN', reason=', '.join(faults))
            line['sources']['notes'] = [n for c, n in line['check_notes'] if c not in named]
    for line in lines.values():
        line.pop('check_notes', None)
        if 'status' in line:
            line['action'] = ACTION[line['status']]

    return lines, unjudged, footer_faults(items), bills


def dashboard_rows(lines, bills, order):
    """The dashboard row of every bill from the expected lines, taken in the order the grid lists them (the contract
    leaves the order of a bill's lines to the app; the notes of a bill are listed as its lines first give them)"""
    dashboard = {}
    rank = dict(RANK, na=0, ok=1, wrong=2)
    by_invoice = collections.OrderedDict()
    for g in sorted(lines, key=lambda g: order.get(g, len(order))):
        by_invoice.setdefault(lines[g]['invoice_no'], []).append(lines[g])
    for invoice, own in by_invoice.items():
        k = key(invoice)
        verdict = {s: 'na' for s in SOURCES}
        holders = collections.OrderedDict()
        for line in own:
            for s in SOURCES:
                if line['sources'] and rank[line['sources']['overall'][s]] > rank[verdict[s]]:
                    verdict[s] = line['sources']['overall'][s]
            for note in (line['sources'] or {}).get('notes', []):
                holders.setdefault(note, []).append(line['ean_upc'].strip())
        notes = [(f'บาร์โค้ด {b[0]}: ' if len(b) == 1 and len(own) > 1 and b[0] else '') + n for n, b in holders.items()]
        if len(notes) > 3:
            notes = notes[:3]
            notes[2] += f' และอีก {len(holders) - 3} ประเด็น'
        status = max((l.get('status', 'MISMATCH') for l in own), key=rank.get)
        dashboard[invoice] = {'status': status, 'verdict': verdict, 'vbrp': dict(bills[k]) if k in bills else None, 'notes': notes}
        if status == 'PENDING_ASN':  # every line that waits carries the bill's problem
            dashboard[invoice].update(problem=next(l['reason'] for l in own if l['status'] == status), solution=FIX_ASN)
    return dashboard


def footer_faults(items):
    """Bills whose footer Total Amt Excluding VAT their own lines do not give within 1.00 (this oracle does not word them)"""
    bills = {}
    for item in items:
        k = key(item['tax_invoice'])
        bill = bills.setdefault(k, {'lines': 0.0, 'footer': None})
        bill['lines'] += figure(item['amount']) or 0.0
        excl = positive(figure(item['amt_excl_vat']))
        promo = figure(item['promotion_discount'])
        pct = re.match(r'^(\d+(?:\.\d+)?)\s*%$', str(item['discount_percent'] or '').strip())
        if excl is not None:
            bill['footer'] = (promo, float(pct.group(1)), excl) if promo is not None and pct else None
    return sorted(k for k, b in bills.items() if b['footer'] and abs(cents((b['lines'] - b['footer'][0]) * (1 - b['footer'][1] / 100) / 1.07) - cents(b['footer'][2])) > 100)


# The app behind a temporary PHP server

class Sandbox:
    def __init__(self, work):
        self.root = work / 'app'
        for folder in ('app', 'assets', 'vendor'):
            shutil.copytree(ROOT / folder, self.root / folder)
        for page in ROOT.glob('*.php'):
            shutil.copy(page, self.root / page.name)
        (self.root / 'data').mkdir()
        (self.root / 'myfile').mkdir()
        with socket.socket() as s:
            s.bind(('127.0.0.1', 0))
            self.port = s.getsockname()[1]
        env = {**os.environ, 'PDF_READER_ROOT': str(self.root)}
        # The sample VBRP file is 2.4 MB, above PHP's default upload_max_filesize of 2M; this checks the compare, not php.ini
        self.server = subprocess.Popen([PHP, '-d', 'upload_max_filesize=64M', '-d', 'post_max_size=64M', '-S', f'127.0.0.1:{self.port}', '-t', str(self.root)], cwd=str(self.root), env=env,
                                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(50):
            try:
                urllib.request.urlopen(f'http://127.0.0.1:{self.port}/api.php?action=list_jobs', timeout=2).read()
                return
            except Exception:
                time.sleep(0.2)
        raise RuntimeError('PHP server did not start')

    def stop(self):
        self.server.terminate()
        self.server.wait(timeout=10)

    def request(self, action, data=None, headers=None, **query):
        url = f'http://127.0.0.1:{self.port}/api.php?' + urllib.parse.urlencode({'action': action, **query})
        try:
            with urllib.request.urlopen(urllib.request.Request(url, data=data, headers=headers or {}), timeout=900) as r:
                body = r.read().decode('utf-8', errors='replace')
        except urllib.error.HTTPError as e:
            body = e.read().decode('utf-8', errors='replace')
        try:
            return json.loads(body)
        except ValueError:
            return {'status': 'error', 'message': f'{action} answered no JSON: {body[:300]}'}

    def call(self, action, post=None, **query):
        return self.request(action, urllib.parse.urlencode(post).encode() if post is not None else None, **query)

    def upload(self, action, fields, name, path):
        """A multipart POST of one file under 'file', as the page sends it"""
        boundary = 'vbrp' + os.urandom(8).hex()
        body = b''
        for field, value in fields.items():
            body += f'--{boundary}\r\nContent-Disposition: form-data; name="{field}"\r\n\r\n{value}\r\n'.encode()
        body += (f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{name}"\r\n'
                 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet\r\n\r\n').encode('utf-8')
        body += Path(path).read_bytes() + f'\r\n--{boundary}--\r\n'.encode()
        return self.request(action, body, {'Content-Type': f'multipart/form-data; boundary={boundary}'})


# Comparing the app with the contract

def app_lines(results):
    return {(key(x['asn']['invoice_no']), digits(x['asn']['ean_upc'])): x for x in results}


def same_sources(want, got):
    if want is None or got is None:
        return want is None and got is None
    figures_agree = all((want['vbrp'][c] is None) == (got['vbrp'].get(c) is None)
                        and (want['vbrp'][c] is None or cents(want['vbrp'][c]) == cents(got['vbrp'][c])) for c in VBRP_FIGURES)
    return figures_agree and sorted(got['vbrp']) == sorted(VBRP_FIGURES) and got['verdict'] == want['verdict'] and got['overall'] == want['overall'] and got['notes'] == want['notes']


def without_still_pdf(row):
    """The row without its note that the PDF will still need the fix once the ASN is right (a page plan matter)"""
    if not row.get('sources'):
        return row
    return dict(row, sources=dict(row['sources'], notes=[n for n in row['sources']['notes'] if not n.startswith(STILL_PDF)]))


def line_faults(want, results, still_pdf_allowed=False):
    got = app_lines(results)
    faults = []
    for g, w in want.items():
        x = got.get(g)
        if x is None:
            faults.append((w['invoice_no'], w['ean_upc'], 'no compare line'))
            continue
        if w['sources'] is None:
            if x.get('sources', 'absent') is not None:
                faults.append((w['invoice_no'], w['ean_upc'], 'uncovered line has sources'))
            continue
        if still_pdf_allowed:
            x = without_still_pdf(x)
        if (x['status'], x['status_label'], x['reason'], x['action'], x['can_fix']) != (w['status'], LABEL[w['status']], w['reason'], w['action'], False):
            faults.append((w['invoice_no'], w['ean_upc'], 'want', w['status'], w['reason'], w['action'], 'got', x['status'], x['status_label'], x['reason'], x['action'], x['can_fix']))
        elif not same_sources(w['sources'], x.get('sources')):
            faults.append((w['invoice_no'], w['ean_upc'], 'sources want', w['sources'], 'got', x.get('sources')))
    if len(got) != len(want):
        faults.append(('line count', len(want), len(got)))
    return faults


def dashboard_faults(want, shown):
    faults = []
    rows = {r['invoice_no']: r for r in shown['invoices']}
    for invoice, w in want.items():
        r = rows.get(invoice)
        if r is None:
            faults.append((invoice, 'no dashboard row'))
            continue
        vbrp_ok = (w['vbrp'] is None and r.get('vbrp') is None) or (w['vbrp'] is not None and r.get('vbrp') is not None
                                                                    and cents(w['vbrp']['cost']) == cents(r['vbrp']['cost'])
                                                                    and cents(w['vbrp']['vat']) == cents(r['vbrp']['vat']) and w['vbrp']['lines'] == r['vbrp']['lines'])
        texts_ok = w['status'] != 'PENDING_ASN' or (r.get('problem'), r.get('solution'), r.get('fixed')) == (w['problem'], w['solution'], False)
        if (r['status'], r.get('verdict'), r.get('notes')) != (w['status'], w['verdict'], w['notes']) or not vbrp_ok or not texts_ok:
            faults.append((invoice, 'want', w, 'got', {k: r.get(k) for k in ('status', 'verdict', 'vbrp', 'notes', 'problem', 'solution', 'fixed')}))
    tally = {s: {'ok': 0, 'wrong': 0, 'na': 0} for s in SOURCES}
    for w in want.values():
        for s in SOURCES:
            tally[s][w['verdict'][s]] += 1
    if shown.get('verdict_counts') != (tally if any(w['vbrp'] for w in want.values()) else None):
        faults.append(('verdict_counts', tally, shown.get('verdict_counts')))
    bills = collections.Counter(w['status'] for w in want.values())
    counts = [shown.get('pending_asn'), shown.get('error'), shown.get('not_fixed'), (shown.get('complete') or 0) + (shown.get('fixed') or 0) - (shown.get('error_after_fix') or 0)]
    if counts != [bills['PENDING_ASN'], bills['MISMATCH'], 0, bills['MATCH']]:
        faults.append(('pending_asn, error, not_fixed, complete + fixed - error_after_fix', counts, 'want', [bills['PENDING_ASN'], bills['MISMATCH'], 0, bills['MATCH']]))
    return faults


def worst(results):
    bills = {}
    for x in results:
        inv = x['asn']['invoice_no']
        if inv not in bills or RANK[x['status']] > RANK[bills[inv]]:
            bills[inv] = x['status']
    return bills


def plan_faults(plan, results):
    bills = collections.Counter(worst(results).values())
    want = [bills['MATCH'], bills['PENDING_FIX'], bills['PENDING_ASN'], bills['MISMATCH']]
    skipped = plan.get('skipped', {})
    got = [plan.get('ready_count'), skipped.get('pending_fix'), skipped.get('pending_asn', 0), skipped.get('mismatch')]
    key_ok = ('pending_asn' in skipped) == (bills['PENDING_ASN'] > 0)
    return [] if want == got and key_ok else [('ready, waiting for the fix, waiting for the ASN, wrong bills of the grid', want, 'of the print plan', got, skipped)]


def changed_bills(a, b):
    return sorted({w['invoice_no'] for g, w in a.items() if (w.get('status'), w.get('reason'), json.dumps(w['sources'], sort_keys=True))
                   != (b[g].get('status'), b[g].get('reason'), json.dumps(b[g]['sources'], sort_keys=True))}, key=natural_key)


# The run

def by_file(items):
    """filename => {row id => row} of the stored PDF rows"""
    files = collections.defaultdict(dict)
    for item in items:
        files[item['filename']][item['id']] = item
    return files


def still_pdf_bills(results):
    """Bills with a line that says the PDF will still need the fix, and the faults of that note's form or place"""
    bills, faults = set(), []
    for x in results:
        notes = (x.get('sources') or {}).get('notes') or []
        still = [n for n in notes if n.startswith(STILL_PDF)]
        if not still:
            continue
        bills.add(x['asn']['invoice_no'])
        if x['status'] != 'PENDING_ASN' or len(still) != 1 or notes[-1] != still[0] or len(still[0]) == len(STILL_PDF):
            faults.append((x['asn']['invoice_no'], x['status'], notes))
    return bills, faults


def scenario(sb, work, asn, vbrp_rows):
    call = sb.call
    job = call('create_job', {'name': 'VBRP oracle'})['data']
    jid = job['id']
    upload = sb.root / 'myfile' / 'VBRP oracle' / 'TAX INVOICE' / 'upload here'
    finished = sb.root / 'myfile' / 'VBRP oracle' / 'TAX INVOICE' / 'finished'
    pdfs = sorted(p for folder in SAMPLE_FOLDERS for p in (FIXTURES / folder).rglob('*.pdf'))
    for p in pdfs:
        shutil.copy(p, upload / p.name)
    files = call('scan_job_files', job_id=jid)['data']['new_files']
    for i in range(0, len(files), 40):
        assert call('extract_job_batch', {'job_id': jid, 'files': json.dumps(files[i:i + 40])})['status'] == 'success'
    label = f'{asn_workbook().name} [{ASN_SHEET}]'
    imported = call('import_asn', {'job_id': jid, 'asn_filename': label, 'append': 1, 'reconcile': 0, 'records': json.dumps(asn)})
    assert imported['status'] == 'success', imported

    wrong_file = sb.upload('job_vbrp_import', {'job_id': jid}, asn_workbook().name, asn_workbook())
    check('the ASN workbook offered as a VBRP file is refused naming cell C3, and nothing is stored',
          wrong_file.get('status') == 'error' and wrong_file.get('message', '').startswith('ไม่ใช่ไฟล์ VBRP (ช่อง C3 ควรเป็น "Bill" แต่เป็น ')
          and call('get_job_reconcile', job_id=jid, force=1)['data'].get('summary', {}).get('vbrp') is None, wrong_file.get('message', ''))
    answer = sb.upload('job_vbrp_import', {'job_id': jid}, VBRP_BOOK.name, VBRP_BOOK)
    invoices = {str(r['invoice']).strip() for r in vbrp_rows if r['invoice']}
    check('the real VBRP file is imported whole: every data row, every bill, no sheet left out, no VBRP folder made',
          answer.get('rows') == len(vbrp_rows) and answer.get('invoices') == len(invoices) and answer.get('ignored_sheets') == []
          and answer.get('vbrp_counts') == {'files': 1, 'rows': len(vbrp_rows), 'invoices': len(invoices)}
          and not (sb.root / 'myfile' / 'VBRP oracle' / 'VBRP').exists(), json.dumps(answer, ensure_ascii=False))

    raw = by_file(call('get_job_items', job_id=jid)['data'])
    before = call('get_job_reconcile', job_id=jid, force=1)['data']
    plan_before = call('print_plan', job_id=jid)['data']
    dash_before = call('get_job_dashboard', job_id=jid)['data']
    split_before = {p.name for p in finished.glob('*.pdf')}
    call('fix_all_invoices', job_id=jid)
    after = call('get_job_reconcile', job_id=jid, force=1)['data']
    plan_after = call('print_plan', job_id=jid)['data']
    dash_after = call('get_job_dashboard', job_id=jid)['data']
    partial = by_file(call('get_job_items', job_id=jid)['data'])
    split_after = {p.name for p in finished.glob('*.pdf')} - split_before

    # The rows as the page plan leaves every file: without VBRP no bill waits for the ASN, so the fix works on every file
    call('job_vbrp_clear', {'job_id': jid})
    call('fix_all_invoices', job_id=jid)
    items = call('get_job_items', job_id=jid)['data']
    sb.upload('job_vbrp_import', {'job_id': jid}, VBRP_BOOK.name, VBRP_BOOK)
    full = call('get_job_reconcile', job_id=jid, force=1)['data']
    dash_full = call('get_job_dashboard', job_id=jid)['data']

    want, unjudged, footers, vbrp_bills = expected(items, asn, vbrp_rows)
    dashboard = dashboard_rows(want, vbrp_bills, {g: i for i, g in enumerate(app_lines(full['results']))})
    covered = sum(1 for w in dashboard.values() if w['vbrp'])
    check('every bill of the ASN is covered by VBRP and the summary says so', full['summary'].get('vbrp') == {'covered_bills': covered, 'uncovered_bills': len(dashboard) - covered}
          and covered == len(dashboard) == 373, json.dumps(full['summary'], ensure_ascii=False))
    check('no line of the sample has a shape this oracle leaves to verify_docs.py (a page under another number of its root, two footers, a footer not its own)',
          not unjudged and not footers, short(unjudged + footers))
    faults = line_faults(want, full['results'])
    check('once the fix has worked on every file every line has the status, the problem (three figures for a wrong PDF, the ASN figure and the two others for a wrong ASN), '
          'the action and the sources the contract gives it: VBRP figures, the verdict of each source on each check, each source over the line and the notes', not faults, f'{len(faults)} differ: ' + short(faults))
    faults = line_faults(want, after['results'], still_pdf_allowed=True)
    check('after the fix button every line reads the same, but for the note of a bill waiting for the ASN that the PDF will still need the fix', not faults, f'{len(faults)} differ: ' + short(faults))
    pending = [x for x in after['results'] if x['status'] == 'PENDING_FIX']
    check('after the fix nothing waits for the fix', not pending, short([(x['asn']['invoice_no'], x['reason']) for x in pending]))

    # Which files the fix button worked on: a file holding a bill that matches (it waited for the fix, or needed nothing)
    files_of = collections.defaultdict(set)
    bills_of = collections.defaultdict(set)
    for item in items:
        files_of[key(item['tax_invoice'])].add(item['filename'])
        bills_of[item['filename']].add(key(item['tax_invoice']))
    matching = {key(w['invoice_no']) for w in want.values() if w.get('status') == 'MATCH'} - {key(w['invoice_no']) for w in want.values() if w.get('status') != 'MATCH'}
    waiting_asn = {key(w['invoice_no']) for w in want.values() if w.get('status') == 'PENDING_ASN'}
    planned = by_file(items)
    left = sorted(f for f, bills in bills_of.items() if not bills & matching)
    faults = [(f, 'rows changed') for f in left if partial[f] != raw[f]]
    faults += [(f, 'rows are not those of the plan') for f in bills_of if f not in left and partial[f] != planned[f]]
    faults += [(name, 'split file of a bill whose file was left alone') for name in split_after if key(name[:-4]) in {b for f in left for b in bills_of[f]}]
    asn_only_files = [f for f in left if bills_of[f] & waiting_asn and planned[f] != raw[f]]
    check('the fix button leaves every file that holds no matching bill as it was (no row changed, no split file), and leaves every other file as the page plan has it',
          not faults and asn_only_files, f'{len(left)} files left alone, {len(asn_only_files)} of them with pages the plan would change: ' + short(faults))

    still_before, form_before = still_pdf_bills(before['results'])
    still_after, form_after = still_pdf_bills(after['results'])
    shared = {inv for inv, s in worst(before['results']).items() if s == 'PENDING_ASN' and any(len(bills_of[f]) > 1 for f in files_of[key(inv)])}
    unexplained = [inv for inv in still_before if not any(len(bills_of[f]) > 1 or planned[f] != raw[f] for f in files_of[key(inv)])]
    kept = {inv for inv in still_before if any(f in left for f in files_of[key(inv)])}
    check('a bill waiting for the ASN whose pages the plan would still change says so in one last note (each bill that shares its file does), and keeps it after the fix only when the fix left its file alone',
          not form_before and not form_after and shared <= still_before and not unexplained and still_after == kept and still_before,
          f'{len(still_before)} bills before, {len(still_after)} after: ' + short(form_before + form_after + sorted(shared - still_before) + unexplained + sorted(still_after ^ kept)))

    by_line = app_lines(after['results'])
    drift = []
    for g, x in app_lines(before['results']).items():
        y = by_line.get(g)
        if y is None:
            drift.append((g, 'missing after the fix'))
            continue
        x, y = without_still_pdf(x), without_still_pdf(y)
        same_status = x['status'] == y['status'] or (x['status'], y['status']) == ('PENDING_FIX', 'MATCH')
        same_sources = (x.get('sources') or {}).get('verdict') == (y.get('sources') or {}).get('verdict') and (x.get('sources') or {}).get('notes') == (y.get('sources') or {}).get('notes')
        pdf_checks = [c['pdf'] for c in (x.get('sources') or {}).get('verdict', {}).values()]
        pdf_follows = x.get('sources') is None or (x['sources']['overall']['pdf'] == 'wrong') == (x['status'] == 'MISMATCH' or 'wrong' in pdf_checks)
        if not same_status or not same_sources or not pdf_follows or (x['status'] in ('MISMATCH', 'PENDING_ASN') and x['reason'] != y['reason']):
            drift.append((x['asn']['invoice_no'], x['asn']['ean_upc'], x['status'], y['status']))
    check('before the fix every line already has its status after it (waiting for the fix counted as complete), the same verdicts and notes, a wrong or ASN-waiting line the same problem, '
          'and the PDF over the line is wrong exactly when the line is wrong or a check judges the PDF wrong, never merely because the line waits', not drift, short(drift))

    before_status = worst(before['results'])
    after_status = worst(after['results'])
    bills_before = collections.Counter(before_status.values())
    bills_after = collections.Counter(after_status.values())
    check('on the sample 23 bills are complete, 28 wait for the fix (รอแก้ไข PDF), 321 wait for the ASN (รอแก้ไข ASN) and 1 is wrong before the fix; after it 51 are complete, 321 wait for the ASN, 1 is wrong',
          bills_before == {'MATCH': 23, 'PENDING_FIX': 28, 'PENDING_ASN': 321, 'MISMATCH': 1} and bills_after == {'MATCH': 51, 'PENDING_ASN': 321, 'MISMATCH': 1},
          json.dumps([bills_before, bills_after]))
    faults = plan_faults(plan_before, before['results']) + plan_faults(plan_after, after['results'])
    check('the print plan counts the ready, waiting and wrong bills the grid shows, before (23 ready) and after the fix (51 ready)',
          not faults and plan_before.get('ready_count') == 23 and plan_after.get('ready_count') == 51, short(faults) + f" ready {plan_before.get('ready_count')} then {plan_after.get('ready_count')}")
    faults = dashboard_faults(dashboard, dash_full)
    check('once every file is fixed every dashboard row has the status, the worst verdict of each source, the VBRP figures and the notes (three, then the count of the rest) '
          'the contract gives, a bill waiting for the ASN its problem and solution, and the counts and verdict_counts count the bills', not faults, short(faults))
    faults = [(r['invoice_no'], r['status'], before_status.get(r['invoice_no']), r['verdict']) for r in dash_before['invoices']
              if r['status'] != before_status.get(r['invoice_no']) or (r.get('verdict') or {}).get('pdf') != ('wrong' if r['status'] == 'MISMATCH' else 'ok')]
    faults += [(r['invoice_no'], 'after', r['status'], after_status.get(r['invoice_no'])) for r in dash_after['invoices'] if r['status'] != after_status.get(r['invoice_no'])]
    counts = [[d.get(k) for k in ('complete', 'not_fixed', 'fixed', 'needs_fix', 'pending_asn', 'error')] for d in (dash_before, dash_after)]
    check('the dashboard status of every bill is the worst of its lines in the grid before and after the fix, the PDF is wrong exactly on the wrong bills, '
          'and the cards read 23 complete, 28 to fix, 321 waiting for the ASN, 1 wrong, then 23 complete and 28 fixed',
          not faults and counts == [[23, 28, 0, 28, 321, 1], [23, 0, 28, 28, 321, 1]] and dash_before.get('verdict_counts') == dash_after.get('verdict_counts') == dash_full.get('verdict_counts'),
          short(faults) + ' ' + json.dumps(counts))

    info('bills before the fix ' + json.dumps(bills_before) + ', after ' + json.dumps(bills_after))
    info('lines before the fix ' + json.dumps({k: v for k, v in before['summary'].items() if k in ('matched', 'pending_fix', 'pending_asn', 'mismatch')})
         + ', after ' + json.dumps({k: v for k, v in after['summary'].items() if k in ('matched', 'pending_fix', 'pending_asn', 'mismatch')}))
    info('verdict_counts before the fix ' + json.dumps(dash_before.get('verdict_counts')) + ', after ' + json.dumps(dash_after.get('verdict_counts')))
    info(f'files the fix left alone: {len(left)} of {len(bills_of)}; bills waiting for the ASN whose PDF still waits: {len(still_before)} before the fix, {len(still_after)} after')
    tally = collections.Counter()
    for w in want.values():
        if w['sources']:
            for c, v in w['sources']['verdict'].items():
                tally[f"{c} pdf/asn/vbrp {v['pdf']}/{v['asn']}/{v['vbrp']}"] += 1
    info('lines per check verdict: ' + json.dumps(dict(sorted(tally.items())), ensure_ascii=False))
    wrong = collections.OrderedDict((w['invoice_no'], w['reason']) for w in want.values() if w.get('status') == 'MISMATCH')
    info('bills still wrong after the fix: ' + json.dumps(wrong, ensure_ascii=False)[:700])
    sample = next(w for w in want.values() if w.get('status') == 'PENDING_ASN')
    info('a bill waiting for the ASN: ' + json.dumps([sample['invoice_no'], sample['reason'], sample['action']], ensure_ascii=False))

    seeds(sb, work, jid, items, asn, label, vbrp_rows, want)


def seeds(sb, work, jid, items, asn, label, vbrp_rows, baseline):
    """Each change is made to the real files, imported, judged here and in the app, then the real files go back"""
    status_of = {}
    for w in baseline.values():
        if w.get('status'):
            status_of[w['invoice_no']] = max(status_of.get(w['invoice_no'], 'MATCH'), w['status'], key=RANK.get)
    by_bill = collections.defaultdict(list)
    for i, r in enumerate(vbrp_rows):
        by_bill[str(r['invoice']).strip()].append(i)
    used = set()

    def pick(test, statuses):
        for invoice in sorted(status_of, key=natural_key):
            if invoice not in used and status_of[invoice] in statuses and test(invoice):
                used.add(invoice)
                return invoice
        raise RuntimeError(f'no {statuses} bill fits a seeded change')

    def lone_row(invoice):
        """A counted row whose barcode no other row of the bill has"""
        rows = [i for i in by_bill[invoice] if counts(vbrp_rows[i])]
        once = collections.Counter(digits(vbrp_rows[i]['ean']) for i in rows)
        return next((i for i in rows if once[digits(vbrp_rows[i]['ean'])] == 1), None)

    def own_lines(invoice):
        return [w for w in baseline.values() if w['invoice_no'] == invoice]

    def pdf_equals_vbrp(invoice):
        amount = own_lines(invoice)[0]['figures']['amount']
        return None not in amount.values() and cents(amount['pdf']) == cents(amount['vbrp']) and not agrees(amount['asn'], amount['pdf'], 1.00) and lone_row(invoice) is not None

    def amount_alone_wrong(invoice):
        return all(w['sources'] and [c for c, v in w['sources']['verdict'].items() if v['asn'] == 'wrong'] == ['amount'] and w['figures']['amount']['pdf'] is not None
                   for w in own_lines(invoice)) and not any(n.startswith('ASN ไม่มีบาร์โค้ด') for n in own_lines(invoice)[0]['reason'].split(', '))

    changes = []

    def vbrp_change(name, invoice, edit):
        rows = copy.deepcopy(vbrp_rows)
        edit(rows)
        changes.append((name, {invoice} if invoice else set(), rows, asn))

    vbrp_change('the real rows written again unchanged', None, lambda rows: None)
    target = pick(lambda inv: lone_row(inv) is not None, ('MATCH',))
    vbrp_change('one VBRP quantity one higher on a complete bill', target, lambda rows, i=lone_row(target): rows[i].update(qty=float(rows[i]['qty']) + 1))
    target = pick(pdf_equals_vbrp, ('PENDING_ASN',))
    vbrp_change('one VBRP Subtotal 4 1.01 higher on a bill waiting for the ASN, so no source agrees on the amount and the bill is wrong', target,
                lambda rows, i=lone_row(target): rows[i].update(cost=round(float(rows[i]['cost']) + 1.01, 2)))
    target = pick(pdf_equals_vbrp, ('PENDING_ASN',))
    vbrp_change('one VBRP Subtotal 4 0.99 higher, still within the tolerance, so the bill still waits for the ASN', target,
                lambda rows, i=lone_row(target): rows[i].update(cost=round(float(rows[i]['cost']) + 0.99, 2)))
    target = pick(lambda inv: lone_row(inv) is not None, ('MATCH',))
    vbrp_change('one counted VBRP row given Subtotal 3 -1, so it no longer counts', target, lambda rows, i=lone_row(target): rows[i].update(sub3=-1))
    skipped = next(i for i, r in enumerate(vbrp_rows) if not counts(r))
    vbrp_change('a VBRP row of Subtotal 3 0 given -1.01, so it counts', str(vbrp_rows[skipped]['invoice']).strip(), lambda rows, i=skipped: rows[i].update(sub3=-1.01))
    target = pick(lambda inv: True, ('MATCH', 'PENDING_ASN'))
    edited = copy.deepcopy(asn)
    bar = next(a['ean_upc'] for a in edited if a['invoice_no'] == target)
    for a in edited:
        if a['invoice_no'] == target and a['ean_upc'] == bar:
            a['selling_price'] = round(a['selling_price'] + 5, 2)
    changes.append(('one ASN Selling Price 5.00 higher, a figure no check compares, so nothing changes', set(), vbrp_rows, edited))
    target = pick(lambda inv: len({digits(a['ean_upc']) for a in asn if a['invoice_no'] == inv}) >= 2, ('MATCH', 'PENDING_ASN'))
    bar = next(digits(a['ean_upc']) for a in asn if a['invoice_no'] == target)
    changes.append(('one ASN line taken out while the PDF and VBRP still bill it, so the bill waits for the ASN', {target}, vbrp_rows,
                    [a for a in asn if not (a['invoice_no'] == target and digits(a['ean_upc']) == bar)]))
    target = pick(amount_alone_wrong, ('PENDING_ASN',))
    pdf_amount = own_lines(target)[0]['figures']['amount']['pdf']
    changes.append(('the ASN Total Cost Ex VAT of one bill waiting for the ASN made equal to its PDF, so it leaves รอแก้ไข ASN and is complete', {target}, vbrp_rows,
                    [dict(a, total_cost_ex_vat=pdf_amount) if a['invoice_no'] == target else a for a in asn]))

    for name, touched, rows, asn_rows in changes:
        seed_book = work / 'seed.xlsx'
        write_vbrp(seed_book, rows)
        imported = sb.upload('job_vbrp_import', {'job_id': jid}, 'seed.xlsx', seed_book)
        if asn_rows is not asn:
            sb.call('import_asn', {'job_id': jid, 'asn_filename': label, 'append': 1, 'reconcile': 0, 'records': json.dumps(asn_rows)})
        results = sb.call('get_job_reconcile', job_id=jid, force=1)['data']['results']
        want = expected(items, asn_rows, rows)[0]
        faults = line_faults(want, results)
        moved = changed_bills(want, baseline)
        statuses = sorted({w['status'] for w in want.values() if w['invoice_no'] in touched})
        check(f'seeded change, {name}: the app answers every line as the contract does, and only the bills it touches change',
              imported.get('status') == 'success' and not faults and moved == sorted(touched, key=natural_key),
              f'changed {moved}, touched {sorted(touched)} now {statuses}, {len(faults)} differ: ' + short(faults, 2))
        if asn_rows is not asn:
            sb.call('import_asn', {'job_id': jid, 'asn_filename': label, 'append': 1, 'reconcile': 0, 'records': json.dumps(asn)})
    sb.upload('job_vbrp_import', {'job_id': jid}, VBRP_BOOK.name, VBRP_BOOK)
    faults = line_faults(baseline, sb.call('get_job_reconcile', job_id=jid, force=1)['data']['results'])
    check('with the real files imported again every line reads as before the seeded changes', not faults, short(faults))


def main():
    sys.stdout.reconfigure(errors='backslashreplace')
    started = time.time()
    if not any((FIXTURES / folder).is_dir() for folder in SAMPLE_FOLDERS):
        print(f'SKIP: Tax Invoice sample PDFs not found in tests/fixtures/{SAMPLE_FOLDERS[0]}/')
        sys.exit(2)
    if not VBRP_BOOK.exists() or asn_workbook() is None:
        print(f'SKIP: VBRP workbook {VBRP_BOOK.name} or an ASN workbook (ASN*.xlsx) not found in tests/fixtures/')
        sys.exit(2)
    work = Path(tempfile.mkdtemp(prefix='verify_vbrp_'))
    sandbox = None
    try:
        asn = read_asn(work)
        vbrp_rows = read_vbrp(work)
        bills, _ = vbrp_figures(vbrp_rows)
        left_out = [(r['row'], r['invoice'], r['sub3']) for r in vbrp_rows if not counts(r)]
        print(f'ASN "{ASN_SHEET}": {len(asn)} rows, {len({a["invoice_no"] for a in asn})} bills; VBRP: {len(vbrp_rows)} rows, {len(bills)} bills, '
              f'left out by Subtotal 3: {left_out}')
        limits = subprocess.run([PHP, '-r', "echo ini_get('upload_max_filesize'), ' ', ini_get('post_max_size');"], capture_output=True, text=True).stdout.split()
        info(f'php.ini of PHP_BIN: upload_max_filesize {limits[0]}, post_max_size {limits[1]}; the test server runs with 64M, the VBRP file is '
             f'{VBRP_BOOK.stat().st_size / 1048576:.1f} MB')
        sandbox = Sandbox(work)
        try:
            scenario(sandbox, work, asn, vbrp_rows)
        except Exception as e:
            line = [f.lineno for f in traceback.extract_tb(e.__traceback__) if f.filename == __file__][-1]
            check('the run reaches its end', False, f'{type(e).__name__}: {e} at line {line}')
    finally:
        if sandbox:
            sandbox.stop()
        shutil.rmtree(work, ignore_errors=True)
    info(f'runtime {time.time() - started:.0f} s')
    print('\n' + (f'{len(fails)} FAILED' if fails else 'ALL PASS'))
    sys.exit(1 if fails else 0)


if __name__ == '__main__':
    main()
