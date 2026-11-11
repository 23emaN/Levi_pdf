"""Checks the Credit Note compare and dashboard against tests/fixtures/cn and the ASN workbook with an independent reader.

The rules come from the compare contract alone (exact money, Tax Invoice wording); the PDF side is read with pdftotext,
the ASN side the way the browser reads a sheet. Everything runs on a throwaway copy of the app behind a temporary PHP
server, never on data/ or myfile/.

Run: python tests/verify_cn.py   (needs openpyxl and xpdf/poppler pdftotext on PATH; PHP_BIN picks the PHP binary)
"""
import collections
import concurrent.futures
import copy
import datetime
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

ROOT = Path(__file__).resolve().parent.parent
FIXTURES = ROOT / 'tests' / 'fixtures'
CN_FOLDER = FIXTURES / 'cn'
WORKBOOK = FIXTURES / 'ASN S4 Promotion SIS Apr 2026_K.BIG ใหม่.xlsx'
SHEETS = ('CDS ZG2T (ZCR) done', 'RBS ZG2T (ZCR) done', 'CDS ZFP3 (ZKR) done 11.10.2026', 'RBS ZFP3 (ZKR) done 13.10.2026')
PHP = os.environ.get('PHP_BIN', 'php')
fails = []


def check(name, ok, detail=''):
    print(('PASS  ' if ok else 'FAIL  ') + name + (f'  [{detail}]' if detail else ''))
    if not ok:
        fails.append(name)


def info(text):
    print('INFO  ' + text)


def cents(x):
    return int(decimal.Decimal(repr(float(x))).quantize(decimal.Decimal('0.01'), decimal.ROUND_HALF_UP) * 100)


def number_format(x):
    return f'{cents(x) / 100:,.2f}'


def money(text):
    return float(text.replace(',', ''))


def natural_key(s):
    return [(0, int(t), '') if t.isdigit() else (1, 0, t) for t in re.split(r'(\d+)', str(s)) if t]


def doc_key(number):
    return re.sub(r'[^A-Z0-9]', '', str(number).upper())


def digits(s):
    return re.sub(r'\D', '', str(s))


def store_number(s):
    return digits(s).lstrip('0') or None


# PDF side

NUMBER = re.compile(r'Credit Note No\s*:\s*(\S+)', re.I)
ITEM = re.compile(r'^([0-9A-Z]{5}-\S*)\s+(\d{13})\b')
TRIPLE = re.compile(r'(?:^|\s)(\d+)\s+(-?[\d,]+\.\d{2})\s+(-?[\d,]+\.\d{2})\s*$')
PAIR = re.compile(r'(\d+)\s+(-?[\d,]+\.\d{2})\s+(-?[\d,]+\.\d{2})\s*$')
FIGURE_LINE = re.compile(r'^(?:(\d+)\s+)?(-?[\d,]+\.\d{2})$')
STORE_TEXT = re.compile(r'\s*(\d{4}-[A-Z].*?)(?:\s{2,}|$)')
RIGHT_BLOCK = (('promo', r'Promotion Discount'), ('total_amount', r'Total Amount'), ('discount', r'Total Discount'),
               ('after', r'Total Amt After Discount'), ('excl_vat', r'Total Amt Excluding VAT'), ('vat', r'VAT ?\(7%\)'),
               ('incl_vat', r'Total Amt Include VAT'))


def pdftotext(path, *flags):
    return subprocess.run(['pdftotext', *flags, str(path), '-'], capture_output=True, text=True, encoding='utf-8', errors='replace').stdout


def pdf_files():
    return sorted(CN_FOLDER.glob('*.pdf'))


def read_file(path):
    raw = pdftotext(path, '-raw').split('\f')[:-1]
    layout = pdftotext(path, '-layout').split('\f')[:-1]
    numbers = []
    for page in raw:
        found = NUMBER.search(page)
        numbers.append(found.group(1) if found else (numbers[-1] if numbers else None))
    docs = []
    for number in dict.fromkeys(numbers):
        pages = [i for i, n in enumerate(numbers) if n == number]
        docs.append({**read_document(path.name, number, [raw[i] for i in pages], layout[pages[0]]), 'page_list': [i + 1 for i in pages]})
    return docs


def pages_agree(doc, shown):
    """A page count, or page numbers of the file as '1-6' or '2, 4' that are exactly the document's pages"""
    if isinstance(shown, (int, float)):
        return shown == doc['pages']
    listed = set()
    for part in re.split(r'\s*,\s*', str(shown or '').strip()):
        ends = part.split('-')
        if not all(e.strip().isdigit() for e in ends):
            return False
        listed.update(range(int(ends[0]), int(ends[-1]) + 1))
    return listed == set(doc['page_list'])


def read_document(filename, number, raw_pages, first_layout):
    first = raw_pages[0]
    kind = 'attachment' if 'Attachment to Original' in first else 'standard' if 'CREDIT NOTE No' in first else 'variance'
    store_lines = [m.group(1) for m in map(STORE_TEXT.match, first_layout.splitlines()) if m]
    store = re.search(r'\s(\d+)$', store_lines[0]) if store_lines else None
    doc = {'doc_no': number, 'key': doc_key(number), 'file': filename, 'format': kind, 'pages': len(raw_pages),
           'store': store_number(store.group(1)) if store else None, 'items': [], 'notes': []}
    if kind == 'attachment':
        return doc
    lines = [l.strip() for page in raw_pages for l in page.splitlines()]
    current = None
    last_item_line = 0
    for i, line in enumerate(lines):
        started = ITEM.match(line)
        if started:
            current = {'barcode': started.group(2), 'units': None, 'unit_price': None, 'amount': None}
            doc['items'].append(current)
        if current is None or current['units'] is not None:
            continue
        spaced, glued = TRIPLE.search(line), PAIR.search(line)
        if spaced:
            current.update(units=int(spaced.group(1)), unit_price=money(spaced.group(2)), amount=money(spaced.group(3)))
        elif glued and money(glued.group(2)):
            units = round(money(glued.group(3)) / money(glued.group(2)))
            if glued.group(1).endswith(str(units)):
                current.update(units=units, unit_price=money(glued.group(2)), amount=money(glued.group(3)))
        last_item_line = i if current['units'] is not None else last_item_line
    run, leading = [], None
    for line in lines[last_item_line + 1:]:
        figure = FIGURE_LINE.match(line)
        if figure:
            leading = figure.group(1) if not run else leading
            run.append(money(figure.group(2)))
        elif run:
            break
    labels = [key for key, label in RIGHT_BLOCK if re.search(label, raw_pages[-1])]
    if len(run) == len(labels):
        doc.update(zip(labels, run))
    else:
        doc['notes'].append(f'right-hand block: {len(labels)} labels, {len(run)} figures')
    doc.setdefault('promo', 0.0)
    shipped = re.search(r'TOTAL SHIPPED \(UNITS\):? *([\d,]+(?:\.\d+)?)', '\n'.join(raw_pages))
    doc['shipped'] = money(shipped.group(1)) if shipped else money(leading) if leading else None
    return doc


def read_pdfs():
    with concurrent.futures.ThreadPoolExecutor(max_workers=min(16, (os.cpu_count() or 4) * 2)) as pool:
        return [doc for docs in pool.map(read_file, pdf_files()) for doc in docs]


def self_check(doc):
    """Problems of a document read against itself: every line priced, units x price = amount, lines less the Promotion
    Discount = Total Amount, Total Amount less Total Discount = After Discount, Excl + VAT = Incl within 0.03, shipped
    units = line units"""
    if doc['format'] == 'attachment':
        return []
    problems = list(doc['notes'])
    items = doc['items']
    if not items or any(i['units'] is None for i in items):
        problems.append('a line without units, price and amount')
        return problems
    if any(cents(i['units'] * i['unit_price']) != cents(i['amount']) for i in items):
        problems.append('units x price != amount')
    if any(doc.get(k) is None for k, _ in RIGHT_BLOCK if k != 'promo'):
        return problems + ['right-hand block incomplete']
    if cents(sum(i['amount'] for i in items) - doc['promo']) != cents(doc['total_amount']):
        problems.append('lines - promotion != total amount')
    if cents(doc['total_amount'] - doc['discount']) != cents(doc['after']):
        problems.append('total - discount != after discount')
    if abs(cents(doc['excl_vat']) + cents(doc['vat']) - cents(doc['incl_vat'])) > 3:
        problems.append('excl + vat != incl')
    if doc['shipped'] is None or round(doc['shipped']) != sum(i['units'] for i in items):
        problems.append('shipped units != line units')
    return problems


# ASN side

FIELD_PATTERNS = (
    ('invoice_no', re.compile(r'invoice\s*no|bill\s*no|tax\s*inv|cn\s*no|credit\s*note|billing\s*doc', re.I)),
    ('ean_upc', re.compile(r'^ean/upc$|^ean$|^upc$|^barcode$', re.I)),
    ('dest_store_no', re.compile(r'dest.*store|store\s*no|branch', re.I)),
    ('quantity', re.compile(r'^quantity$|^qty$', re.I)),
    ('selling_price', re.compile(r'selling\s*price|unit\s*price|^price$|unit\s*ret', re.I)),
    ('total_cost_ex_vat', re.compile(r'total\s*cost.*ex.*vat|cost.*ex.*vat|amt.*ex.*vat', re.I)),
    ('total_vat', re.compile(r'^total\s*vat$|^vat$', re.I)),
    ('delivery_no', re.compile(r'^delivery\s*no', re.I)),
)
DATE_HEADER = re.compile(r'invoice\s*date|cn\s*date', re.I)


def js_string(value):
    """String(value || '') of the browser"""
    if value is None or value == '' or value is False or (isinstance(value, (int, float)) and value == 0):
        return ''
    if isinstance(value, float) and value.is_integer():
        return str(int(value))
    if isinstance(value, datetime.datetime):
        return value.date().isoformat()
    return str(value)


def js_number(value):
    """Number(value) || 0 of the browser"""
    if value is None or isinstance(value, datetime.datetime):
        return 0
    if isinstance(value, (int, float)):
        return value
    try:
        text = str(value).strip()
        return float(text) if text else 0
    except ValueError:
        return 0


def detect_columns(rows):
    for r, row in enumerate(rows[:50]):
        found = {}
        for c, cell in enumerate(row):
            text = js_string(cell).strip()
            for field, pattern in FIELD_PATTERNS:
                if pattern.search(text) and field not in found:
                    found[field] = c
                    break
        if 'invoice_no' in found and 'ean_upc' in found:
            dates = [c for c, cell in enumerate(row) if DATE_HEADER.search(js_string(cell))]
            return r, found, dates[0] if dates else None
    return None


def read_sheet(workbook, name, label):
    rows = [list(r) for r in workbook[name].iter_rows(values_only=True)]
    header, cols, date_col = detect_columns(rows)
    kind = 'cost' if 'total_cost_ex_vat' in cols else 'retail'

    def cell(row, field):
        c = cols.get(field)
        return row[c] if c is not None and c < len(row) else None

    records = []
    for row in rows[header + 1:]:
        invoice, ean = js_string(cell(row, 'invoice_no')).strip(), js_string(cell(row, 'ean_upc')).strip()
        if not invoice or not ean or re.search(r'total|grand|summary|ex\.', invoice, re.I) or not re.fullmatch(r'\d{7,15}', digits(ean)):
            continue
        date = row[date_col] if date_col is not None and date_col < len(row) else None
        records.append({'invoice_no': invoice, 'invoice_date': js_string(date).strip(),
                        'dest_store_no': js_string(cell(row, 'dest_store_no')).strip(), 'ean_upc': ean,
                        'quantity': js_number(cell(row, 'quantity')), 'selling_price': js_number(cell(row, 'selling_price')),
                        'total_cost_ex_vat': js_number(cell(row, 'total_cost_ex_vat')), 'total_vat': js_number(cell(row, 'total_vat')),
                        'delivery_no': js_string(cell(row, 'delivery_no')).strip(), 'amount_kind': kind})
    return {'label': label, 'sheet': name, 'kind': kind, 'records': records}


def read_asn(work):
    copied = work / WORKBOOK.name
    shutil.copy(WORKBOOK, copied)
    workbook = openpyxl.load_workbook(copied, read_only=True, data_only=True)
    try:
        return [read_sheet(workbook, name, f'{WORKBOOK.name} / {name}') for name in SHEETS]
    finally:
        workbook.close()


# The contract's verdicts

NO_PDF = 'ไม่พบใบลดหนี้นี้ใน PDF'
ATTACHMENT = 'ใบแนบ (Attachment Sheet) ไม่มีบาร์โค้ดและยอดท้ายใบให้เทียบ ตรวจด้วยตา'
UNREAD = 'อ่านใบลดหนี้ไม่ครบ: '


def one_side(barcodes, where, missing):
    texts = [f'บาร์โค้ด {b} มีใน {where} แต่ไม่มีใน {missing} ({n} ชิ้น)' for b, n in barcodes]
    return ', '.join(texts[:3]) + (f' และอีก {len(texts) - 3} รายการ' if len(texts) > 3 else '')


def topics_text(topics):
    return ', '.join(topics[:3]) + (f' และอีก {len(topics) - 3} ประเด็น' if len(topics) > 3 else '')


def judge(rows, pdf):
    """One ASN document (its rows in stored order) against its PDF document or None: the compare rows and the dashboard
    row the contract gives it"""
    kind = rows[0]['amount_kind']
    merged = {}
    for r in rows:
        line = merged.setdefault(digits(r['ean_upc']), {'ean_upc': r['ean_upc'], 'quantity': 0, 'row_count': 0, 'selling_price': r['selling_price']})
        line['quantity'] += r['quantity']
        line['row_count'] += 1
        line['selling_price'] = min(line['selling_price'], r['selling_price'])
    if kind == 'cost':
        doc_amount = next((r['total_cost_ex_vat'] for r in rows if r['total_cost_ex_vat'] > 0), 0.0)
        asn_vat = next((r['total_vat'] for r in rows if r['total_vat'] > 0), 0.0)
    else:
        doc_amount, asn_vat = sum(r['quantity'] * r['selling_price'] for r in rows), None
    asn_store = rows[0]['dest_store_no']
    whole = NO_PDF if pdf is None else ATTACHMENT if pdf['format'] == 'attachment' else UNREAD if self_check(pdf) else None
    base = {'doc_no': rows[0]['invoice_no'], 'key': doc_key(rows[0]['invoice_no']), 'amount_kind': kind, 'doc_amount': doc_amount,
            'total_vat': asn_vat, 'dest_store_no': asn_store, 'source_files': [pdf['file']] if pdf else []}
    out, topics, pdf_units, gaps = [], [], collections.Counter(), {}
    if whole:
        for b, line in merged.items():
            out.append({**base, 'barcode': b, 'pdf_units': None, 'asn': line, 'status': 'MISMATCH', 'reason': whole, 'amount_ok': None, 'vat_ok': None})
        topics = [whole]
    else:
        for item in pdf['items']:
            pdf_units[item['barcode']] += item['units']
        problems = []
        pdf_store, store = pdf['store'], store_number(asn_store)
        if pdf_store and store and pdf_store != store:
            problems.append(f'รหัสสาขาไม่ตรง (PDF: {pdf_store} / ASN: {store})')
        asn_only = [(line['ean_upc'], line['quantity']) for b, line in merged.items() if b not in pdf_units]
        pdf_only = [(b, n) for b, n in pdf_units.items() if b not in merged]
        if asn_only:
            problems.append(one_side(asn_only, 'ASN', 'PDF'))
        if pdf_only:
            problems.append(one_side(pdf_only, 'PDF', 'ASN'))
        other_problems = len(problems)
        pdf_amount = pdf['excl_vat'] if kind == 'cost' else pdf['total_amount']
        amount_ok = cents(pdf_amount) == cents(doc_amount)
        gaps['amount'] = abs(cents(pdf_amount) - cents(doc_amount)) / 100
        if not amount_ok:
            problems.append(f'ยอดรวมไม่ตรง (PDF: {number_format(pdf_amount)} / ASN: {number_format(doc_amount)})')
        vat_ok = None
        if kind == 'cost':
            vat_ok = cents(pdf['vat']) == cents(asn_vat)
            gaps['vat'] = abs(cents(pdf['vat']) - cents(asn_vat)) / 100
            if not vat_ok:
                problems.append(f'VAT ไม่ตรง (PDF: ฿{number_format(pdf["vat"])} / ASN: ฿{number_format(asn_vat)})')
        for b, line in merged.items():
            own = [f'จำนวนไม่ตรง (PDF: {pdf_units[b]} ชิ้น / ASN: {line["quantity"]} ชิ้น)'] if b in pdf_units and pdf_units[b] != line['quantity'] else []
            topics += [f'บาร์โค้ด {line["ean_upc"]}: {own[0]}'] if own else []
            out.append({**base, 'barcode': b, 'pdf_units': pdf_units.get(b), 'asn': line, 'status': 'MISMATCH' if own + problems else 'MATCH',
                        'reason': ', '.join(own + problems), 'amount_ok': amount_ok, 'vat_ok': vat_ok})
        for b, n in pdf_only:
            out.append({**base, 'barcode': b, 'pdf_units': n, 'asn': None, 'status': 'MISMATCH', 'reason': ', '.join(problems),
                        'amount_ok': amount_ok, 'vat_ok': vat_ok})
        topics += problems
        gaps['money_only'] = not other_problems and not any(t.startswith('บาร์โค้ด') for t in topics)
    status = 'MISMATCH' if any(r['status'] == 'MISMATCH' for r in out) else 'MATCH'
    dashboard = {'store_no': asn_store or (pdf or {}).get('store'), 'doc_no': base['doc_no'], 'source_files': base['source_files'],
                 'units': sum(pdf_units.values()) if not whole else None, 'amount': doc_amount, 'amount_kind': kind,
                 'pages': pdf['pages'] if pdf else None, 'status': status, 'problem': topics_text(topics) if status == 'MISMATCH' else ''}
    return {'key': base['key'], 'doc_no': base['doc_no'], 'status': status, 'rows': out, 'dashboard': dashboard, 'pdf': pdf, 'gaps': gaps}


def expected(sources, pdf_by_key):
    """Every ASN document of the stored sources, in the order its first row was stored, judged"""
    grouped = {}
    for source in sources:
        for r in source['records']:
            grouped.setdefault(doc_key(r['invoice_no']), []).append(r)
    return [judge(rows, pdf_by_key.get(key)) for key, rows in grouped.items()]


def dashboard_order(docs):
    return sorted(docs, key=lambda d: (d['dashboard']['store_no'] in (None, ''), natural_key(d['dashboard']['store_no'] or ''), natural_key(d['doc_no'])))


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
        self.server.terminate()
        self.server.wait(timeout=10)

    def call(self, action, post=None, **query):
        url = f'http://127.0.0.1:{self.port}/api.php?' + urllib.parse.urlencode({'action': action, **query})
        data = urllib.parse.urlencode(post).encode() if post is not None else None
        try:
            with urllib.request.urlopen(urllib.request.Request(url, data=data), timeout=900) as r:
                body = r.read().decode('utf-8', errors='replace')
        except urllib.error.HTTPError as e:
            body = e.read().decode('utf-8', errors='replace')
        try:
            return json.loads(body)
        except ValueError:
            return {'status': 'error', 'message': f'{action} answered no JSON: {body[:300]}'}

    def folder(self, relative):
        path = Path(relative)
        return path if path.is_absolute() else self.root / path


# Comparing the app with the contract

def num(x):
    return None if x is None or x == '' else float(x)


def same_money(a, b):
    return (a is None) == (b is None) and (a is None or cents(a) == cents(b))


def by_doc(results):
    docs = {}
    for x in results:
        docs.setdefault(doc_key(x['asn']['doc_no']), []).append(x)
    return docs


def row_barcode(x):
    return digits(x['asn']['ean_upc']) if (x.get('asn') or {}).get('ean_upc') else digits((x.get('pdf') or {}).get('barcode') or '')


def reason_agrees(want, got):
    return str(got).startswith(UNREAD) if want == UNREAD else want == got


def row_faults(doc, want, x):
    """(topic, field, expected, shown) for each field of one compare row the app shows otherwise than the contract"""
    pdf, asn, line = x.get('pdf'), x.get('asn') or {}, want['asn']
    expected_pdf = doc['pdf']
    pairs = [
        ('verdict', 'status', want['status'], x.get('status')),
        ('verdict', 'action', '', x.get('action')),
        ('money flags', 'amount_ok', want['amount_ok'], x.get('amount_ok')),
        ('money flags', 'vat_ok', want['vat_ok'], x.get('vat_ok')),
        ('money flags', 'amount_kind', want['amount_kind'], x.get('amount_kind')),
        ('figures', 'source_files', want['source_files'], x.get('source_files')),
        ('figures', 'pdf is null', expected_pdf is None, pdf is None),
        ('figures', 'pdf.units', want['pdf_units'], num((pdf or {}).get('units'))),
        ('figures', 'asn.ean_upc null', line is None, asn.get('ean_upc') in (None, '')),
        ('figures', 'asn.quantity', line and line['quantity'], num(asn.get('quantity'))),
        ('figures', 'asn.row_count', line and line['row_count'], num(asn.get('row_count'))),
        ('figures', 'asn.doc_no', want['doc_no'], asn.get('doc_no')),
    ]
    faults = [(topic, field, a, b) for topic, field, a, b in pairs if a != b and not (a is None and b is None)]
    if not reason_agrees(want['reason'], x.get('reason')):
        faults.append(('verdict', 'reason', want['reason'], x.get('reason')))
    if pdf and expected_pdf and not pages_agree(expected_pdf, pdf.get('pages')):
        faults.append(('figures', 'pdf.pages', expected_pdf['page_list'], pdf.get('pages')))
    for field, a, b in (('asn.selling_price', line and line['selling_price'], asn.get('selling_price')),
                        ('asn.doc_amount', want['doc_amount'], asn.get('doc_amount')), ('asn.total_vat', want['total_vat'], asn.get('total_vat'))):
        if not same_money(a, num(b)):
            faults.append(('figures', field, a, b))
    return faults


def compare_results(want_docs, results):
    """topic => [(document, barcode, field, expected, shown)]: 'rows' when a document's rows are not the contract's
    rows in the contract's order, else the fields of its rows"""
    faults = collections.defaultdict(list)
    shown = by_doc(results)
    for doc in want_docs:
        got = shown.pop(doc['key'], [])
        ids, got_ids = [r['barcode'] for r in doc['rows']], [row_barcode(x) for x in got]
        if ids != got_ids:
            faults['rows'].append((doc['doc_no'], '', 'barcodes', ids[:4], got_ids[:4]))
            continue
        for want, x in zip(doc['rows'], got):
            for topic, field, a, b in row_faults(doc, want, x):
                faults[topic].append((doc['doc_no'], want['barcode'], field, a, b))
    for key, got in shown.items():
        faults['rows'].append((key, '', 'document not in the ASN', None, len(got)))
    return faults


def doc_signatures(results):
    return {key: tuple((x['status'], x['reason']) for x in rows) for key, rows in by_doc(results).items()}


def compare_dashboard(want_docs, shown, compared):
    faults = []
    rows = shown.get('documents') or []
    ordered = dashboard_order(want_docs)
    complete = sum(d['status'] == 'MATCH' for d in want_docs)
    counts = {'total': len(want_docs), 'compared': compared, 'complete': complete if compared else None,
              'error': len(want_docs) - complete if compared else None, 'pending': 0 if compared else len(want_docs)}
    faults += [('count', k, v, shown.get(k)) for k, v in counts.items() if shown.get(k) != v]
    if [doc_key(r['doc_no']) for r in rows] != [d['key'] for d in ordered]:
        return faults + [('order', 'documents', [d['doc_no'] for d in ordered][:5], [r['doc_no'] for r in rows][:5])]
    for d, r in zip(ordered, rows):
        want = d['dashboard']
        pairs = [('status', want['status'] if compared else 'PENDING', r.get('status')), ('problem', want['problem'] if compared else '', r.get('problem'))]
        if compared:
            pairs += [('store_no', want['store_no'], None if r.get('store_no') in (None, '') else str(r['store_no'])),
                      ('source_files', want['source_files'], r.get('source_files')), ('units', want['units'], num(r.get('units'))),
                      ('amount_kind', want['amount_kind'], r.get('amount_kind')), ('pages', want['pages'], num(r.get('pages')))]
            if not same_money(want['amount'], num(r.get('amount'))):
                faults.append((d['doc_no'], 'amount', want['amount'], r.get('amount')))
        faults += [(d['doc_no'], field, a, b) for field, a, b in pairs if a != b and not (a is None and b is None)]
    return faults


def short(faults, n=3):
    return json.dumps(faults[:n], ensure_ascii=False, default=str)[:700]


# Seeded changes to the ASN

def seeded_changes(sheets, baseline):
    """(name, sheet index, changed records, documents that must change): each change made to documents the baseline
    calls complete, so that each one breaks exactly what it names"""
    complete = {d['key'] for d in baseline if d['status'] == 'MATCH'}
    stores = {d['key'] for d in baseline if d['pdf'] and d['pdf']['store']}
    used = set()

    def rows_of(index):
        grouped = {}
        for i, r in enumerate(sheets[index]['records']):
            grouped.setdefault(doc_key(r['invoice_no']), []).append(i)
        return grouped

    def pick(index, test, store=False):
        for key, rows in rows_of(index).items():
            if key in complete and key not in used and (key in stores or not store) and test([sheets[index]['records'][i] for i in rows]):
                used.add(key)
                return key, rows
        raise RuntimeError(f'no complete document in {SHEETS[index]} fits a seeded change')

    def single_rows(records):
        once = collections.Counter(digits(r['ean_upc']) for r in records)
        return [i for i, r in enumerate(records) if once[digits(r['ean_upc'])] == 1]

    changes = []
    records = copy.deepcopy(sheets[0]['records'])
    key, rows = pick(0, lambda rs: len(single_rows(rs)) >= 1 and len({digits(r['ean_upc']) for r in rs}) >= 2)
    drop = rows[single_rows([records[i] for i in rows])[0]]
    changes.append(('one line of a ZG2T credit note dropped', 0, [r for i, r in enumerate(records) if i != drop], {key}))

    records = copy.deepcopy(sheets[2]['records'])
    key, rows = pick(2, lambda rs: True)
    for i in rows:
        records[i]['total_cost_ex_vat'] = round(records[i]['total_cost_ex_vat'] + 0.01, 2)
    changes.append(('Total Cost Ex VAT 0.01 higher on a ZFP3 credit note', 2, records, {key}))

    records = copy.deepcopy(sheets[1]['records'])
    key_a, rows_a = pick(1, lambda rs: len(single_rows(rs)) >= 1)
    key_b, rows_b = pick(1, lambda rs: len(single_rows(rs)) >= 1 and not {digits(r['ean_upc']) for r in rs} & {digits(records[i]['ean_upc']) for i in rows_a})
    a = rows_a[single_rows([records[i] for i in rows_a])[0]]
    b = rows_b[single_rows([records[i] for i in rows_b])[0]]
    records[a]['ean_upc'], records[b]['ean_upc'] = records[b]['ean_upc'], records[a]['ean_upc']
    changes.append(('barcodes of two lines of two ZG2T credit notes swapped, totals kept', 1, records, {key_a, key_b}))

    records = copy.deepcopy(sheets[1]['records'])
    key, rows = pick(1, lambda rs: True, store=True)
    for i in rows:
        records[i]['dest_store_no'] = '99999'
    changes.append(('Dest Store No. of a ZG2T credit note changed', 1, records, {key}))

    records = copy.deepcopy(sheets[3]['records'])
    key, rows = pick(3, lambda rs: True)
    changes.append(('a ZFP3 credit note removed from the ASN', 3, [r for i, r in enumerate(records) if i not in rows], {key}))

    records = copy.deepcopy(sheets[2]['records'])
    key, rows = pick(2, lambda rs: len({digits(r['ean_upc']) for r in rs}) >= 2)
    records[rows[0]]['quantity'] += 1
    changes.append(('quantity one higher on one line of a ZFP3 credit note', 2, records, {key}))

    records = copy.deepcopy(sheets[0]['records'])
    key, rows = pick(0, lambda rs: len(single_rows(rs)) >= 2, store=True)
    for i in [rows[j] for j in single_rows([records[i] for i in rows])[:2]]:
        records[i]['quantity'] += 1
    for i in rows:
        records[i]['dest_store_no'] = '99999'
    changes.append(('two quantities and the store of one ZG2T credit note changed, four problems', 0, records, {key}))

    records = copy.deepcopy(sheets[0]['records'])
    key, rows = pick(0, lambda rs: len(single_rows(rs)) >= 5)
    drop = {rows[j] for j in single_rows([records[i] for i in rows])[:4]}
    changes.append(('four lines of a ZG2T credit note dropped', 0, [r for i, r in enumerate(records) if i not in drop], {key}))
    return changes


# The run

def scenario(sb, pdf_docs, sheets):
    pdf_by_key = {d['key']: d for d in pdf_docs}

    def call(action, post=None, **query):
        answer = sb.call(action, post, **query)
        if answer.get('status') != 'success' and action != 'job_cn_import_asn':
            raise RuntimeError(f"{action} answered {answer.get('status')}: {answer.get('message')}")
        return answer

    jid = call('create_job', {'name': 'Credit note oracle'})['data']['id']

    def compare(force=1):
        return call('job_cn_reconcile', job_id=jid, force=force)['data']

    def dashboard():
        return call('job_cn_dashboard', job_id=jid)['data']

    def import_sheet(sheet, records=None, same_file_mode='replace', reconcile=0):
        return call('job_cn_import_asn', {'job_id': jid, 'asn_filename': sheet['label'], 'records': json.dumps(records if records is not None else sheet['records']),
                                          'append': 1, 'reconcile': reconcile, 'duplicate_mode': 'skip', 'same_file_mode': same_file_mode})

    def overlap(sheet, records=None):
        return call('job_cn_asn_overlap', {'job_id': jid, 'asn_filename': sheet['label'], 'records': json.dumps(records if records is not None else sheet['records'])})

    upload = sb.folder(call('job_cn_scan', job_id=jid)['data']['upload_dir'])
    files = pdf_files()
    for p in files:
        shutil.copy(p, upload / p.name)
    scan = call('job_cn_scan', job_id=jid)['data']
    check('the scan of upload here lists every credit note PDF as new', scan['new_count'] == len(files) and scan['total_in_upload'] == len(files),
          f"{scan['new_count']} new of {len(files)}")
    paths = [f['rel_path'] for f in scan['new_files']]
    processed, documents, trouble = 0, 0, []
    for i in range(0, len(paths), 50):
        r = call('job_cn_extract_batch', {'job_id': jid, 'files': json.dumps(paths[i:i + 50])})
        processed += r.get('processed_files', 0)
        documents += r.get('documents_count', 0)
        trouble += r.get('failed', []) + r.get('not_credit_note', [])
    check('extraction reads every file, as many documents as the independent reader finds', processed == len(files) and documents == len(pdf_docs) and not trouble,
          f'{processed} files, {documents} documents of {len(pdf_docs)}, trouble {short(trouble)}')

    stored = call('job_cn_docs', job_id=jid)
    rows = {doc_key(r['doc_no']): r for r in stored['data']}
    check('job_cn_docs holds every document once, counted by file and document', len(stored['data']) == len(pdf_docs) == len(rows)
          and stored['counts'].get('files') == len(files) and stored['counts'].get('documents') == len(pdf_docs), f"{len(stored['data'])} rows, counts {stored['counts']}")
    misread = []
    for d in pdf_docs:
        r = rows.get(d['key'], {})
        if store_number(r.get('store_no') or '') != d['store'] or not pages_agree(d, r.get('pages')) or r.get('has_items') not in (True, 1):
            misread.append((d['doc_no'], 'store, pages, has_items', d['store'], d['pages'], r.get('store_no'), r.get('pages'), r.get('has_items')))
        if d['format'] != 'attachment' and (r.get('read_ok') not in (True, 1) or not all(same_money(d[k], num(r.get(k))) for k in ('total_amount', 'excl_vat', 'vat', 'incl_vat'))):
            misread.append((d['doc_no'], 'read_ok, total, excl, vat, incl', [d[k] for k in ('total_amount', 'excl_vat', 'vat', 'incl_vat')],
                            [r.get(k) for k in ('read_ok', 'total_amount', 'excl_vat', 'vat', 'incl_vat')]))
    check('the app reads every credit note as the independent reader does: store, pages, and on every standard and variance note read_ok, '
          'Total Amount, Excl VAT, VAT and Incl VAT', not misread, f'{len(misread)} differ: {short(misread)}')

    empty = compare()
    check('a compare before any ASN is imported answers no summary and no rows', empty.get('summary') is None and empty.get('results') == [], short([empty]))

    total_rows = 0
    import_faults = []
    for n, sheet in enumerate(sheets, 1):
        probe = overlap(sheet)
        total_rows += len(sheet['records'])
        r = import_sheet(sheet)
        label = sheet['label'] if n == 1 else f'{n} ชีต: ' + ', '.join(s['label'] for s in sheets[:n])
        if (probe.get('same_file') or {}).get('stored') or probe.get('overlap_count') or r.get('imported_records') != len(sheet['records']) \
                or r.get('asn_counts') != {'files': n, 'rows': total_rows} or r.get('asn_filename') != label:
            import_faults.append((sheet['sheet'], probe.get('same_file'), probe.get('overlap_count'), r.get('imported_records'), r.get('asn_counts'), r.get('asn_filename')))
    check('each of the four credit note sheets imports as a new source with every row, sharing no document with another, the counts and the label growing per sheet',
          not import_faults, short(import_faults))
    refused = call('job_cn_import_asn', {'job_id': jid, 'asn_filename': 'no kind / sheet', 'append': 1, 'reconcile': 0,
                                         'records': json.dumps([{k: v for k, v in sheets[0]['records'][0].items() if k != 'amount_kind'}])})
    kept = call('job_cn_asn_records', job_id=jid)['data']
    check('a record without amount_kind makes the whole import refused with a Thai message and nothing stored',
          refused.get('status') == 'error' and bool(re.search('[฀-๿]', refused.get('message') or '')) and len(kept) == total_rows,
          f'{short([refused])} {len(kept)} rows')

    want = expected(sheets, pdf_by_key)
    faults = compare_dashboard(want, dashboard(), compared=False)
    check(f'before the compare the dashboard lists the {len(want)} ASN credit notes by store then number, every one waiting with no problem', not faults, short(faults))

    started = time.time()
    rec = compare()
    compare_seconds = time.time() - started
    results = rec['results']
    faults = compare_results(want, results)
    check(f'the compare holds, for each of the {len(want)} ASN credit notes, one row per ASN barcode then one per PDF-only barcode', not faults['rows'],
          f"{len(faults['rows'])} documents differ: {short(faults['rows'])}")
    check('every compare row has the status and the exact reason the contract gives it, and no action', not faults['verdict'],
          f"{len(faults['verdict'])} differ: {short(faults['verdict'])}")
    check("every compare row carries its document's amount and VAT verdicts and amount kind", not faults['money flags'],
          f"{len(faults['money flags'])} differ: {short(faults['money flags'])}")
    check('every compare row shows the PDF and ASN pieces, merged row count, lowest price, document amount, ASN VAT, pages and source file the contract gives it',
          not faults['figures'], f"{len(faults['figures'])} differ: {short(faults['figures'])}")
    order = [doc_key(x['asn']['doc_no']) for x in results]
    check('the compare lists the documents in the order their first ASN row was stored', list(dict.fromkeys(order)) == [d['key'] for d in want])
    every = [r for d in want for r in d['rows']]
    summary = rec['summary'] or {}
    wanted = {'total_asn_records': total_rows, 'total_asn_groups': sum(1 for r in every if r['asn']), 'matched': sum(r['status'] == 'MATCH' for r in every),
              'mismatch': sum(r['status'] == 'MISMATCH' for r in every), 'total_compared': len(every)}
    check('the compare summary counts ASN rows, merged ASN lines, complete and wrong rows', {k: summary.get(k) for k in wanted} == wanted, f'{summary} vs {wanted}')
    shown = dashboard()
    faults = compare_dashboard(want, shown, compared=True)
    check('after the compare the dashboard gives every credit note the status, problem, store, pieces, amount, pages and source file of the contract, by store then number',
          not faults, f'{len(faults)} differ: {short(faults)}')
    baseline = doc_signatures(results)

    statuses = collections.Counter(d['status'] for d in want)
    shown_statuses = collections.Counter(r['status'] for r in shown.get('documents', []))
    info(f'documents per status: independent {dict(statuses)}, app {dict(shown_statuses)}')
    money_only = [d['doc_no'] for d in want if d['status'] == 'MISMATCH' and d['gaps'].get('money_only')
                  and all(0.01 <= d['gaps'][k] <= 1.00 for k in ('amount', 'vat') if d['gaps'].get(k))]
    info(f'{len(money_only)} wrong documents are wrong only by a money gap of 0.01 to 1.00 (amount and/or VAT): {", ".join(money_only[:8])} ...')
    cost = [d for d in want if d['rows'][0]['amount_kind'] == 'cost']
    info(f'{sum(1 for d in cost if 0.01 <= d["gaps"].get("vat", 0) <= 0.07)} of the {len(cost)} ZFP3 documents differ in VAT by 0.01 to 0.07')
    attachment = [x for x in results if doc_key(x['asn']['doc_no']) == '93260445']
    info('Attachment Sheet 93260445 comes out as ' + short([(x['status'], x['reason'], x['amount_ok'], x['vat_ok']) for x in attachment], 1)
         + f' in {len(attachment)} rows; dashboard ' + short([r for r in shown.get('documents', []) if doc_key(r['doc_no']) == '93260445'], 1))
    info(f'the compare of {len(results)} rows took {compare_seconds:.1f} s')

    restored = []
    for name, index, records, seeded in seeded_changes(sheets, want):
        changed_sheets = [dict(s, records=records) if i == index else s for i, s in enumerate(sheets)]
        import_sheet(sheets[index], records)
        results = compare()['results']
        now = doc_signatures(results)
        moved = {k for k in set(now) | set(baseline) if now.get(k) != baseline.get(k)}
        want_now = expected(changed_sheets, pdf_by_key)
        faults = compare_results(want_now, results)
        faults = [f for topic in faults.values() for f in topic] + compare_dashboard(want_now, dashboard(), compared=True)
        seeded_docs = [d for d in want_now if d['key'] in seeded]
        check(f'seeded change, {name}: exactly {sorted(seeded)} change and the app reads them as the contract does',
              moved == seeded and not faults, f'changed {sorted(moved)[:5]}, differences {short(faults)}, contract: '
              + short([(d['doc_no'], d['dashboard']['problem']) for d in seeded_docs], 2))
        import_sheet(sheets[index])
        if doc_signatures(compare()['results']) != baseline:
            restored.append(name)
    check('re-importing the real sheet after each seeded change brings back the compare of the real workbook', not restored, str(restored))

    sheet = sheets[2]
    asn_rows = call('job_cn_asn_records', job_id=jid)['data']
    ids = [r['id'] for r in asn_rows if r.get('source_file') == sheet['label']]
    kinds_ok = all(r.get('amount_kind') == ('cost' if 'ZFP3' in r.get('source_file', '') else 'retail') for r in asn_rows)
    check('job_cn_asn_records lists every stored row with its source sheet and amount kind', len(asn_rows) == total_rows and len(ids) == len(sheet['records']) and kinds_ok,
          f'{len(asn_rows)} rows, {len(ids)} of {sheet["sheet"]}')
    r = call('job_cn_delete_asn', {'job_id': jid, 'ids': json.dumps(ids)})
    others = [s for s in sheets if s is not sheet]
    want_without = expected(others, pdf_by_key)
    faults = compare_results(want_without, (r.get('reconcile') or {}).get('results') or [])
    faults = [f for topic in faults.values() for f in topic] + compare_dashboard(want_without, dashboard(), compared=True)
    check(f'deleting the rows of {sheet["sheet"]} keeps the Job compared and answers the fresh compare of the other three sheets',
          r.get('asn_counts') == {'files': 3, 'rows': total_rows - len(ids)} and not faults, f"{r.get('asn_counts')} {short(faults)}")
    r = import_sheet(sheet)
    pending = dashboard()
    again = doc_signatures(compare()['results'])
    check('importing the deleted sheet again stores its rows, sends the Job back to waiting, and the compare is the one of the real workbook',
          r.get('imported_records') == len(sheet['records']) and pending.get('compared') is False and again == baseline,
          f"{r.get('imported_records')} rows, compared {pending.get('compared')}, "
          f"{len({k for k in set(again) | set(baseline) if again.get(k) != baseline.get(k)})} documents differ")
    probe = overlap(sheet).get('same_file') or {}
    changed = copy.deepcopy(sheet['records'])
    changed[0]['quantity'] += 1
    check('the same sheet again is recognised as stored, identical and compared, a copy with one quantity changed is not identical',
          probe.get('stored') == len(sheet['records']) and bool(probe.get('identical')) and bool(probe.get('reconciled'))
          and not (overlap(sheet, changed).get('same_file') or {}).get('identical'), str(probe))
    r = import_sheet(sheet, same_file_mode='skip')
    still = dashboard()
    check('importing the same sheet with skip stores nothing and leaves the Job compared with the same result',
          bool(r.get('skipped_same_file')) and not r.get('imported_records') and still.get('compared') is True
          and doc_signatures(compare(force=0)['results']) == baseline,
          f"skipped {r.get('skipped_same_file')}, imported {r.get('imported_records')}, compared {still.get('compared')}")
    r = import_sheet(sheet, same_file_mode='replace', reconcile=1)
    rows_now = len(call('job_cn_asn_records', job_id=jid)['data'])
    check('importing the same sheet with replace swaps its rows without doubling and compares again to the same result',
          bool(r.get('replaced_same_file')) and rows_now == total_rows and doc_signatures((r.get('reconcile') or {}).get('results') or []) == baseline,
          f"replaced {r.get('replaced_same_file')}, {rows_now} rows")


def main():
    sys.stdout.reconfigure(errors='backslashreplace')
    started = time.time()
    if not CN_FOLDER.is_dir() or not pdf_files():
        print(f'SKIP: credit note PDFs not found in {CN_FOLDER}')
        sys.exit(2)
    if not WORKBOOK.exists():
        print(f'SKIP: ASN workbook not found: {WORKBOOK}')
        sys.exit(2)
    work = Path(tempfile.mkdtemp(prefix='verify_cn_'))
    sandbox = None
    try:
        pdf_docs = read_pdfs()
        unread = {d['doc_no']: self_check(d) for d in pdf_docs if self_check(d)}
        lines = [d for d in pdf_docs if d['format'] != 'attachment']
        print(f'PDFs: {len(pdf_files())} files, {len(pdf_docs)} documents ({dict(collections.Counter(d["format"] for d in pdf_docs))}), '
              f"{len(lines) - len(unread)} of {len(lines)} with barcode lines pass the reader's own sums")
        check('the independent reader finds every document with its lines and right-hand block adding up', len(pdf_docs) == 557 and not unread,
              f'{len(pdf_docs)} documents, {len(unread)} fail their own sums {short(list(unread.items()))}')
        sheets = read_asn(work)
        print('ASN: ' + ', '.join(f'{s["sheet"]} {len(s["records"])} rows ({s["kind"]})' for s in sheets))
        sandbox = Sandbox(work)
        try:
            scenario(sandbox, pdf_docs, sheets)
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
