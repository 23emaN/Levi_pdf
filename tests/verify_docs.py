"""Checks the app against the real PDFs and ASN workbook in tests/fixtures/ with an independent reader.

Run: python tests/verify_docs.py   (needs pypdf, openpyxl and xpdf/poppler pdftotext on PATH)
Everything runs on a throwaway copy, never on data/ or myfile/.
"""
import collections
import itertools
import json
import logging
import os
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

import openpyxl
import pypdf
from pypdf.generic import ContentStream

logging.disable(logging.CRITICAL)
ROOT = Path(__file__).resolve().parent.parent
FIXTURES = ROOT / 'tests' / 'fixtures'
SAMPLE_FOLDERS = ('CDS ZFP (ZKE)', 'CDS ZFP (ZKE) เพิ่มเติม')
PHP = os.environ.get('PHP_BIN', 'php')
CODE = re.compile(r'^[A-Z0-9]{4,6}-\d{4}/[0-9A-Z]+$')
MONEY = re.compile(r'^-?[\d,]+\.\d{2}$')
ROWSTART = re.compile(r'^([A-Z0-9]{4,6}-\d{4}/[0-9A-Z]+)\s+(\d{8,14})(.*)$')
TRIPLE = re.compile(r'(\d+)\s+(-?[\d,]+\.\d{2})\s+(-?[\d,]+\.\d{2})\s*$')
TAX_IDS = ('0105564107046', '0105517005330')
fails = []


def check(name, ok, detail=''):
    print(('PASS  ' if ok else 'FAIL  ') + name + (f'  [{detail}]' if detail else ''))
    if not ok:
        fails.append(name)


def num(s):
    try:
        return float(str(s).replace(',', ''))
    except (TypeError, ValueError):
        return None


def pdftotext(path, *flags):
    return subprocess.run(['pdftotext', *flags, str(path), '-'], capture_output=True, text=True,
                          encoding='utf-8', errors='replace').stdout


def ship_to_store(path):
    lines = pdftotext(path, '-raw', '-l', '1').splitlines()
    start = next((i for i, l in enumerate(lines) if l.strip() == 'Ship to Party:'), None)
    for line in lines[start + 1:start + 5] if start is not None else []:
        found = re.search(r'\b(?:10|20)\d{3}\b', line)
        if found:
            return found.group(0)
    return None


def natural_key(s):
    return [int(t) if t.isdigit() else t for t in re.split(r'(\d+)', s)]


def parse_page_positions(page):
    toks = []

    def visit(text, cm, tm, fd, fs):
        if text.strip():
            toks.append((round(tm[5], 1), round(tm[4], 1), text.strip()))

    page.extract_text(visitor_text=visit)
    pos = [t for t in toks if t[0] > 0]
    flat = [t for t in toks if t[0] == 0] or pos
    rows = []
    for y, x, code in sorted((t for t in pos if CODE.match(t[2])), key=lambda t: -t[0]):
        same = [t for t in pos if abs(t[0] - y) < 0.6]
        units = [t[2] for t in same if re.fullmatch(r'\d+', t[2]) and 300 < t[1] < 380]
        price = [t[2] for t in same if MONEY.match(t[2]) and 380 <= t[1] < 470]
        amount = [t[2] for t in same if MONEY.match(t[2]) and t[1] >= 470]
        rows.append({'units': int(units[0]) if units else None, 'unit_price': num(price[0]) if price else None,
                     'amount': num(amount[0]) if amount else None})

    def right_of(pattern):
        for y, x, t in pos:
            if re.search(pattern, t):
                for y2, x2, t2 in pos:
                    if abs(y2 - y) < 0.6 and x2 > x and (MONEY.match(t2) or re.fullmatch(r'\d+', t2)):
                        return t2
        return None

    percent = next((m.group(1) for _, _, t in pos for m in [re.search(r'^Total Discount\((\d+)%\)', t)] if m), None)
    footer = {'has_footer': any('TOTAL SHIPPED' in t[2] for t in pos), 'excl_vat': num(right_of(r'Total Amt Excluding VAT')),
              'vat': num(right_of(r'^VAT\(7%\)')), 'incl_vat': num(right_of(r'Total Amt Include VAT')),
              'promo': num(right_of(r'^Promotion Discount')), 'total': num(right_of(r'^Total Amount$')), 'pct': num(percent),
              'discount': num(right_of(r'^Total Discount\(')), 'after': num(right_of(r'^Total Amt After Discount'))}
    return {'invoice': next((t[2] for t in flat if re.fullmatch(r'\d{8}-\d+', t[2])), None),
            'barcodes': [t[2] for t in flat if re.fullmatch(r'\d{8,14}', t[2])], 'rows': rows, 'footer': footer}


def parse_page_raw(path, page_no):
    lines = [l.rstrip() for l in pdftotext(path, '-raw', '-f', str(page_no), '-l', str(page_no)).splitlines()]
    invoice = next((m.group(1) for l in lines for m in [re.match(r'Tax Invoice : (\S+)', l)] if m), None)
    rows, barcodes, current, money, in_footer = [], [], None, [], False
    for l in lines:
        if re.match(r'(Promotion Discount|Total Amount|TOTAL SHIPPED)', l):
            in_footer = True
        if in_footer:
            if l.startswith('LEVI STRAUSS (THAILAND) LTD') and money:
                break
            if re.fullmatch(r'(\d+\s+)?-?[\d,]+\.\d{2}|\d+', l.strip()):
                money.append(l.strip())
            continue
        m = ROWSTART.match(l)
        if m:
            current = {'text': m.group(3)}
            rows.append(current)
            barcodes.append(m.group(2))
        elif current is not None:
            current['text'] += ' ' + l
    parsed = []
    for r in rows:
        t = TRIPLE.search(r['text'])
        parsed.append({'units': int(t.group(1)) if t else None, 'unit_price': num(t.group(2)) if t else None,
                       'amount': num(t.group(3)) if t else None})
    values = [v for line in money for v in line.split()]
    nums = [num(v) for v in values if '.' in v]
    footer = {'has_footer': bool(money)}
    if money:
        footer.update(incl_vat=nums[-1], vat=nums[-2], excl_vat=nums[-3])
    if len(nums) >= 7:
        percent = next((m.group(1) for l in lines for m in [re.search(r'Total Discount\((\d+)%\)', l)] if m), None)
        footer.update(after=nums[-4], discount=nums[-5], total=nums[-6], promo=nums[-7], pct=num(percent))
    return {'invoice': invoice, 'barcodes': barcodes, 'rows': parsed, 'footer': footer}


def parse_alternate_layout(path):
    text = pdftotext(path, '-layout')
    invoice = re.search(r'Tax Invoice No:\s*(\S+)', text).group(1)
    rows = [{'units': int(u), 'unit_price': num(p), 'amount': num(a)}
            for u, p, a in re.findall(r'^\S.*?\s+(\d+)\s+([\d,]+)\s+([\d,]+)\s*$', text, re.M)]
    # The labels of this footer drift off the lines of their figures (one even prints over another), the figures stay
    # in one column in label order: Promotion Discount, Total Amount, Total Discount, After Discount, Excl, VAT, Incl.
    # The shipped units, printed as 3.00, may stand among them.
    start = min(i for i in (text.find('Promotion Discount'), text.find('TOTAL SHIPPED'), len(text)) if i >= 0)
    block = re.sub(r'TOTAL SHIPPED \(UNITS\)\s*[\d,.]+', '', text[start:].split('LEVI STRAUSS')[0])
    figures = [num(v) for v in re.findall(r'(?<![\d,.])\d{1,3}(?:,\d{3})*\.\d{2}(?![\d,.])', block)]
    keys = ('promo', 'total', 'discount', 'after', 'excl_vat', 'vat', 'incl_vat')
    footer = dict(zip(keys, figures)) if len(figures) == 7 else {}
    percent = re.search(r'Total Discount\s*\((\d+)\s*%\)', pypdf.PdfReader(str(path)).pages[0].extract_text())
    return {'invoice': invoice, 'barcodes': [], 'rows': rows,
            'footer': {'has_footer': 'TOTAL SHIPPED' in text, 'pct': num(percent.group(1)) if percent else None, **footer}}


def sample_pdfs():
    """The Tax Invoice samples: the two invoice folders only, never the PDFs of fixtures/cn or fixtures/inv"""
    return sorted(p for folder in SAMPLE_FOLDERS for p in (FIXTURES / folder).rglob('*.pdf'))


def require_samples():
    if not sample_pdfs():
        print(f'SKIP: Tax Invoice sample PDFs not found in tests/fixtures/{SAMPLE_FOLDERS[0]}/')
        sys.exit(2)


def write_pdf_list(path):
    path.write_text(json.dumps([str(p) for p in sample_pdfs()]), encoding='utf-8')
    return path


def read_pdfs():
    pages = []
    for path in sample_pdfs():
        if 'Tax Invoice No:' in pdftotext(path, '-layout', '-l', '1'):
            pages.append({**parse_alternate_layout(path), 'file': path.name, 'page': 1, 'alternate': True})
            continue
        for number, page in enumerate(pypdf.PdfReader(str(path)).pages, 1):
            try:
                parsed = parse_page_positions(page)
            except Exception:
                parsed = parse_page_raw(path, number)
            for r in parsed['rows']:
                if r['units'] is None and r['unit_price']:
                    r['units'] = round(r['amount'] / r['unit_price'])
            pages.append({**parsed, 'file': path.name, 'page': number, 'alternate': False})
    return pages


def read_asn():
    workbook_path = next(p for p in sorted(FIXTURES.glob('ASN*.xlsx')))
    workbook = openpyxl.load_workbook(workbook_path, read_only=True, data_only=True)
    sheet = next(ws for ws in workbook.worksheets if ws.sheet_state == 'visible')
    rows = list(sheet.iter_rows(values_only=True))
    col = {name: i for i, name in enumerate(rows[0]) if name}
    asn = []
    for r in rows[1:]:
        if not r[col['Invoice No']]:
            continue
        asn.append({'invoice_no': str(r[col['Invoice No']]).strip(), 'ean_upc': str(r[col['EAN/UPC']]).strip(),
                    'quantity': int(r[col['Quantity']] or 0), 'selling_price': float(r[col['Selling Price']] or 0),
                    'total_cost_ex_vat': float(r[col['Total Cost Ex VAT']] or 0), 'total_vat': float(r[col['Total VAT']] or 0),
                    'dest_store_no': str(r[col['Dest Store No.']] or '').strip(),
                    'delivery_no': str(r[col['Delivery No.']] or '').strip()})
    return sheet.title, asn


FOOTER_COLUMNS = (('excl_vat', 'amt_excl_vat'), ('vat', 'vat_amount'), ('incl_vat', 'amt_incl_vat'), ('promo', 'promotion_discount'),
                  ('total', 'total_amount'), ('pct', 'discount_percent'), ('discount', 'total_discount'), ('after', 'amt_after_discount'))


def check_extraction(pages, rows):
    by_page = collections.defaultdict(list)
    for r in rows:
        by_page[(r['filename'], int(r['page_no']))].append(r)
    diffs, unread, footers = collections.Counter(), collections.Counter(), 0
    for p in pages:
        got = by_page.get((p['file'], p['page']), [])
        if p['alternate']:
            want = collections.Counter((r['units'], r['unit_price'], r['amount']) for r in p['rows'])
            have = collections.Counter((int(r['units']), num(r['unit_price']), num(r['amount'])) for r in got)
        else:
            want = collections.Counter((b, r['units'], r['unit_price'], r['amount']) for b, r in zip(p['barcodes'], p['rows']))
            have = collections.Counter((r['barcode'], int(r['units']), num(r['unit_price']), num(r['amount'])) for r in got)
        if want != have:
            diffs['items'] += 1
        if p['invoice'] and any(r['tax_invoice'] != p['invoice'] for r in got):
            diffs['printed invoice number'] += 1
        printed_footer = any(r['amt_excl_vat'] not in ('Null', '-', None, '') for r in got)
        if p['footer']['has_footer'] != printed_footer:
            diffs['footer presence'] += 1
        elif printed_footer:
            footers += 1
            for key, col in FOOTER_COLUMNS:
                want, have = p['footer'].get(key), num(str(got[0][col]).rstrip('%'))
                if want is None:
                    unread[key] += 1
                elif have is None or abs(want - have) > 0.005:
                    diffs['footer ' + key] += 1
    check('extraction equals the PDFs on every page, every figure of every footer included', not diffs and not unread,
          f'{len(pages)} pages, {footers} footers, differences {dict(diffs)}, figures the independent reader found no value for {dict(unread)}')


AMOUNT_TOLERANCE = 1.00
VAT_TOLERANCE = 0.15


def amount_agrees(pdf_excl, asn_cost):
    """The bill's Amt Excl VAT on the PDF and the ASN Total Cost Ex VAT differ by one baht at most (the user's rule);
    a figure missing or not positive on either side is not judged"""
    if pdf_excl is None or pdf_excl <= 0 or asn_cost <= 0:
        return True
    return round(abs(pdf_excl - asn_cost), 2) <= AMOUNT_TOLERANCE


def vat_agrees(pdf_vat, asn_vat):
    if pdf_vat is None or pdf_vat <= 0 or asn_vat <= 0:
        return True
    return abs(pdf_vat - asn_vat) <= VAT_TOLERANCE


def independent_verdicts(pages, asn):
    rank = {'MATCH': 1, 'PENDING_FIX': 2, 'MISMATCH': 3}
    verdict = {}
    for (inv, _), status in independent_line_verdicts(pages, asn).items():
        if inv not in verdict or rank[status] > rank[verdict[inv]]:
            verdict[inv] = status
    return verdict


def root(inv):
    return inv.split('-')[0]


def assign_lines(pages, asn):
    """The PDF lines bill by bill, from the PDF text and the workbook alone. A line goes to the bill the page before
    left open, else the printed one, else the first bill of the same root whose ASN still has its pieces; a page goes
    to the bill most of its lines went to, else the printed one. A line no ASN quantity takes whose barcode the ASN of
    its page's bill does not list is PDF only. Returns asn_qty (bill => barcode => pieces), meta (bill => an ASN row),
    assigned (bill => [(file, page, printed number, barcode, pieces)]), pdf_only (bill => barcode => pieces, in the order
    the PDF prints them) and page_of ((file, page) => page, with the bill it goes to as 'owner'). Bills in the alternate
    layout are left out."""
    asn_qty = collections.defaultdict(collections.Counter)
    meta = {}
    for a in asn:
        asn_qty[a['invoice_no']][a['ean_upc']] += a['quantity']
        meta[a['invoice_no']] = a
    by_root = collections.defaultdict(list)
    for inv in asn_qty:
        by_root[root(inv)].append(inv)
    for invoices in by_root.values():
        invoices.sort(key=lambda s: int(s.split('-')[1]))
    standard = sorted((dict(p) for p in pages if not p['alternate']), key=lambda p: (p['file'], p['page']))
    previous = {}
    for p in standard:
        if not p['invoice'] and previous.get(p['file']):
            p['invoice'] = previous[p['file']]
        previous[p['file']] = p['invoice']
    remaining = {inv: collections.Counter(q) for inv, q in asn_qty.items()}
    assigned = collections.defaultdict(list)
    pdf_only = collections.defaultdict(dict)
    open_invoice = {}
    for p in standard:
        candidates = by_root.get(root(p['invoice']), []) if p['invoice'] else []
        votes, left = collections.Counter(), []
        for barcode, r in zip(p['barcodes'], p['rows']):
            for c in [open_invoice.get(p['file']), p['invoice']] + candidates:
                if c in candidates and remaining[c][barcode] >= r['units']:
                    remaining[c][barcode] -= r['units']
                    assigned[c].append((p['file'], p['page'], p['invoice'], barcode, r['units']))
                    open_invoice[p['file']] = c
                    votes[c] += 1
                    break
            else:
                left.append((barcode, r))
        owner = max(votes, key=votes.get) if votes else p['invoice']
        p['owner'] = owner
        for barcode, r in left:
            if owner in asn_qty and barcode not in asn_qty[owner]:
                pdf_only[owner][barcode] = pdf_only[owner].get(barcode, 0) + r['units']
    return asn_qty, meta, assigned, pdf_only, {(p['file'], p['page']): p for p in standard}


def footer_from_lines(footer, lines):
    return round((lines - footer['promo']) * (1 - footer['pct'] / 100) / 1.07, 2)


def footer_read(footer):
    return all(footer.get(k) is not None for k in ('promo', 'pct', 'excl_vat')) and footer['excl_vat'] > 0


def footer_fits(footer, lines):
    return round(abs(footer_from_lines(footer, lines) - footer['excl_vat']), 2) <= AMOUNT_TOLERANCE


def bill_footers(page_of):
    """From the pages of assign_lines: bill => {'lines': the amounts of every line on the pages it owns, 'footer': the
    footer of its last page, 'last': that page, 'files'}, and file => its bills in the order it prints them"""
    owned, file_bills = collections.defaultdict(list), collections.defaultdict(list)
    for key in sorted(page_of):
        owner = page_of[key]['owner']
        owned[owner].append(key)
        if owner not in file_bills[key[0]]:
            file_bills[key[0]].append(owner)
    bills = {inv: {'lines': round(sum(r['amount'] or 0 for k in keys for r in page_of[k]['rows']), 2), 'footer': page_of[keys[-1]]['footer'],
                   'last': keys[-1], 'files': sorted({f for f, _ in keys})} for inv, keys in owned.items()}
    return bills, file_bills


def footer_problem(page_of, inv):
    """The sentence of a bill whose footer total its own lines do not give, naming the first bill of its files whose
    lines give it; '' when the lines give it or the footer lacks a figure"""
    bills, file_bills = bill_footers(page_of)
    bill = bills.get(inv)
    if not bill or not footer_read(bill['footer']) or footer_fits(bill['footer'], bill['lines']):
        return ''
    owner = next((o for f in bill['files'] for o in file_bills[f] if o != inv and footer_fits(bill['footer'], bills[o]['lines'])), None)
    figures = f"(ยอดรวมก่อน VAT ท้ายบิล: {bill['footer']['excl_vat']:,.2f} / จากรายการของบิลนี้: {footer_from_lines(bill['footer'], bill['lines']):,.2f})"
    return f'ยอดรวมท้ายบิลเป็นของบิล {owner} {figures}' if owner else f'ยอดรวมท้ายบิลไม่ตรงกับรายการของบิลนี้ {figures}'


def independent_line_verdicts(pages, asn):
    """(invoice, barcode) => the status every ASN line must show before the fix, from the PDF text and the workbook
    alone, on the bills of assign_lines. A line is wrong when its pieces differ, when its bill's PDF holds a barcode
    its ASN does not list or a footer total its lines do not give, or when the amounts or the VAT of its bill disagree
    with the footer of the bill's last page; a right line waits when its bill has a page printed with another number, a
    total on a page that is not its last, or a file shared with another bill. Bills printed in the alternate layout are
    not judged."""
    asn_qty, meta, assigned, pdf_only, page_of = assign_lines(pages, asn)
    alternate_invoices = {p['invoice'] for p in pages if p['alternate']}
    bills_in_file = collections.defaultdict(set)
    for inv, lines in assigned.items():
        for x in lines:
            bills_in_file[x[0]].add(inv)
    verdict = {}
    for inv, q in asn_qty.items():
        if not assigned[inv]:
            if inv not in alternate_invoices:
                verdict.update({(inv, barcode): 'MISMATCH' for barcode in q})
            continue
        got = collections.Counter()
        for x in assigned[inv]:
            got[x[3]] += x[4]
        pgs = sorted(set((x[0], x[1]) for x in assigned[inv]))
        footer = page_of[pgs[-1]]['footer']
        totals_ok = amount_agrees(footer.get('excl_vat'), meta[inv]['total_cost_ex_vat']) and vat_agrees(footer.get('vat'), meta[inv]['total_vat'])
        wrong_number = any(x[2] != inv for x in assigned[inv])
        duplicate_footer = len(pgs) > 1 and sum(1 for pg in pgs if page_of[pg]['footer']['has_footer']) > 1
        shares_file = any(len(bills_in_file[f]) > 1 for f, _ in pgs)
        right = 'PENDING_FIX' if wrong_number or duplicate_footer or shares_file else 'MATCH'
        own_pdf = not pdf_only[inv] and not footer_problem(page_of, inv)
        for barcode in q:
            verdict[(inv, barcode)] = right if got[barcode] == q[barcode] and totals_ok and own_pdf else 'MISMATCH'
    return verdict


def money(value):
    return f'฿{value:,.2f}'


def expected_problem(pages, asn, inv):
    """The problem every line of a bill whose own lines are right reads, built from the PDF text and the workbook:
    its PDF-only barcodes with their pieces in the order the PDF prints them, three at most, then a footer total its
    lines do not give, then the two totals that do not match, then the VAT"""
    _, meta, assigned, pdf_only, page_of = assign_lines(pages, asn)
    extra = list(pdf_only[inv].items())
    problems = [f'บาร์โค้ด {bar} มีใน PDF แต่ไม่มีใน ASN ({pieces} ชิ้น)' for bar, pieces in extra[:3]]
    if len(extra) > 3:
        problems[-1] += f' และอีก {len(extra) - 3} รายการ'
    problems += [p for p in [footer_problem(page_of, inv)] if p]
    footer = page_of[max((x[0], x[1]) for x in assigned[inv])]['footer']
    excl, cost = footer['excl_vat'], meta[inv]['total_cost_ex_vat']
    if not amount_agrees(excl, cost):
        problems.append(f'ยอดรวมไม่ตรง (PDF: {excl:,.2f} / ASN: {cost:,.2f})')
    if not vat_agrees(footer.get('vat'), meta[inv]['total_vat']):
        problems.append(f"VAT ไม่ตรง (PDF: {money(footer['vat'])} / ASN: {money(meta[inv]['total_vat'])})")
    return ', '.join(problems)


def pdf_only_fixture(pages, asn):
    """The workbook with barcodes taken out of three bills of the sample, so their PDFs hold barcodes their ASN does not
    list: one from a complete bill and four from a waiting bill (their amounts stay within the tolerance), two from a
    bill short of the ASN cost. A barcode is taken only from a
    bill whose lines are all right, when no other bill of its root lists it and another line of its bill stays on its
    page. Returns the new rows and {bill: barcodes taken out}."""
    verdicts = independent_line_verdicts(pages, asn)
    asn_qty, meta, assigned, _, page_of = assign_lines(pages, asn)
    status = {}
    for (inv, _), s in verdicts.items():
        status.setdefault(inv, set()).add(s)
    listed_by_root = collections.defaultdict(collections.Counter)
    for inv, q in asn_qty.items():
        listed_by_root[root(inv)].update(q.keys())

    def takeable(inv, n):
        lines = assigned[inv]
        on_page = collections.Counter((x[0], x[1]) for x in lines)
        once = collections.Counter(x[3] for x in lines)
        taken = []
        for f, pg, _, bar, _ in lines:
            if len(taken) < n and once[bar] == 1 and listed_by_root[root(inv)][bar] == 1 and on_page[(f, pg)] > 1:
                taken.append(bar)
                on_page[(f, pg)] -= 1
        return taken if len(taken) == n else None

    def short(inv):
        excl = page_of[max((x[0], x[1]) for x in assigned[inv])]['footer']['excl_vat']
        return excl is not None and not amount_agrees(excl, meta[inv]['total_cost_ex_vat']) and excl < meta[inv]['total_cost_ex_vat']

    def pieces_right(inv):
        got = collections.Counter()
        for x in assigned[inv]:
            got[x[3]] += x[4]
        return got == asn_qty[inv]

    def pick(want, n, also=lambda inv: True):
        for inv in sorted(status, key=natural_key):
            if status[inv] == {want} and pieces_right(inv) and also(inv) and len({x[0] for x in assigned[inv]}) == 1 and takeable(inv, n):
                return inv, takeable(inv, n)
        raise RuntimeError(f'no {want} bill to take {n} barcodes from')

    picks = dict([pick('MATCH', 1), pick('MISMATCH', 2, short), pick('PENDING_FIX', 4)])
    return [a for a in asn if a['ean_upc'] not in picks.get(a['invoice_no'], ())], picks


def worst_status(results):
    rank = {'MISMATCH': 3, 'PENDING_FIX': 2, 'MATCH': 1}
    status = {}
    for x in results:
        inv = x['asn']['invoice_no']
        if inv not in status or rank[x['status']] > rank[status[inv]]:
            status[inv] = x['status']
    return status


def line_statuses(results):
    return {(x['asn']['invoice_no'], x['asn']['ean_upc']): x['status'] for x in results}


def check_reconcile(lines, run, label=''):
    before, after = line_statuses(run['before']['results']), line_statuses(run['after']['results'])
    bills = collections.defaultdict(lambda: 'MATCH')
    rank = {'MATCH': 1, 'PENDING_FIX': 2, 'MISMATCH': 3}
    for (inv, _), status in lines.items():
        bills[inv] = max(bills[inv], status, key=rank.get)
    system = worst_status(run['before']['results'])
    print(f'  {label}before fix, (independent, app) line pairs:', dict(collections.Counter((v, before.get(k)) for k, v in lines.items())),
          f'| {len(before) - len(lines)} lines of bills in the alternate layout not judged')
    print(f'  {label}before fix, (independent, app) bill pairs:', dict(collections.Counter((v, system.get(i)) for i, v in bills.items())))
    wrong_before = [f'{k[0]} {k[1]} {v}/{before.get(k)}' for k, v in lines.items() if before.get(k) != v]
    check(label + 'before the fix every ASN line has the status the independent check gives it: wrong when its pieces differ, its bill\'s PDF holds a barcode its ASN does not list or its bill\'s '
          'Amt Excl VAT and Cost Ex VAT differ by more than ฿1.00 (or the VAT by more than ฿0.15), else waiting when its bill has pages to adjust or a shared file',
          not wrong_before and {'MATCH', 'PENDING_FIX', 'MISMATCH'} <= set(lines.values()), f'{len(wrong_before)} differ: ' + ', '.join(wrong_before[:6]))
    wrong_after = [f'{k[0]} {k[1]} {v}/{after.get(k)}' for k, v in lines.items() if after.get(k) != ('MATCH' if v == 'PENDING_FIX' else v)]
    check(label + 'after the fix nothing waits and every line the independent check judges is complete, or wrong exactly when it is independently wrong',
          not wrong_after and 'PENDING_FIX' not in after.values(), f"{len(wrong_after)} differ: {', '.join(wrong_after[:6])}, after summary {run['after']['summary']}")


PDF_ONLY = 'มีใน PDF แต่ไม่มีใน ASN'


def check_pdf_only(pages, asn, rows, picks, base, run):
    """base: the app on the real workbook, run: the app on the workbook of pdf_only_fixture (rows, picks)"""
    _, _, _, pdf_only, _ = assign_lines(pages, asn)
    shown = [x for x in base['before']['results'] if PDF_ONLY in x['reason']]
    print(f'  on the real sample {sum(len(v) for v in pdf_only.values())} PDF-only barcodes in {sum(1 for v in pdf_only.values() if v)} bills, '
          f'{len(shown)} compare lines name one; fixture bills {picks}')
    check('on the real sample no bill holds a barcode its ASN does not list, independently and in the app', not any(pdf_only.values()) and not shown)
    check_reconcile(independent_line_verdicts(pages, rows), run, 'with barcodes taken out of the ASN of three bills, ')
    texts = {}
    for when in ('before', 'after'):
        for x in run[when]['results']:
            texts.setdefault((when, x['asn']['invoice_no']), set()).add((x['status'], x['reason'], x['action']))
    wrong = {}
    for inv in picks:
        want = {('MISMATCH', expected_problem(pages, rows, inv), '')}
        if texts.get(('before', inv)) != want or texts.get(('after', inv)) != want:
            wrong[inv] = (sorted(texts.get(('before', inv), ())), sorted(texts.get(('after', inv), ())), min(want))
    check('every line of a bill whose PDF holds barcodes its ASN does not list is wrong before and after the fix and reads, '
          'each such barcode with its pieces in the order the PDF prints them, three at most, before the amount problem; '
          'an amount within ฿1.00 is no problem and no solution is given',
          len(picks) == 3 and not wrong, json.dumps(wrong, ensure_ascii=False)[:900])
    remarks = {r['invoice_no']: (r['problem'], r['solution']) for r in run['dashboard_before']}
    wrong_remark = {inv: remarks.get(inv) for inv in picks if remarks.get(inv) != (expected_problem(pages, rows, inv), '')}
    check('the dashboard remark of those bills before the fix is the same problem and no solution', not wrong_remark, json.dumps(wrong_remark, ensure_ascii=False)[:600])
    touched = {root(inv) for inv in picks}
    same = {(when, inv): v for (when, inv), v in texts.items() if when == 'before' and root(inv) not in touched}
    base_texts = {}
    for x in base['before']['results']:
        if root(x['asn']['invoice_no']) not in touched:
            base_texts.setdefault(('before', x['asn']['invoice_no']), set()).add((x['status'], x['reason'], x['action']))
    changed = sorted(inv for (_, inv) in set(same) | set(base_texts) if same.get(('before', inv)) != base_texts.get(('before', inv)))
    check('every other bill reads exactly as with the real workbook', same and not changed, f'{len(same)} bills, changed {changed[:4]}')


def check_footer_relations(pages, asn, run):
    """The footer of every bill of the sample against its own lines, from the PDF text alone: the bills of assign_lines
    and every page in the alternate layout. A change of layout or a misread figure shows here before it shows as a bill
    the app calls wrong."""
    _, _, _, _, page_of = assign_lines(pages, asn)
    bills, _ = bill_footers(page_of)
    footers = {inv: (b['lines'], b['footer']) for inv, b in bills.items()}
    footers.update({p['invoice']: (sum(r['amount'] or 0 for r in p['rows']), p['footer']) for p in pages if p['alternate']})
    unread = sorted(inv for inv, (_, f) in footers.items() if not footer_read(f) or None in (f.get('total'), f.get('discount'), f.get('after')))
    judged = {inv: v for inv, v in footers.items() if inv not in unread}
    off = collections.defaultdict(list)
    deviations = []
    for inv, (lines, f) in judged.items():
        if abs(lines - f['promo'] - f['total']) > 0.005:
            off['lines - Promotion Discount = Total Amount'].append(inv)
        if abs(f['total'] * f['pct'] / 100 - f['discount']) > 0.005 or abs(f['total'] - f['discount'] - f['after']) > 0.005:
            off['Total Amount x percentage = Total Discount = Total Amount - Total Amt After Discount'].append(inv)
        deviations.append((round(abs(footer_from_lines(f, lines) - f['excl_vat']), 2), inv))
    spread = collections.Counter(d for d, _ in deviations)
    print(f'  footer of every bill against its own lines: {len(judged)} bills judged, {len(unread)} not ({unread[:4]}), Total Amt Excluding VAT '
          f'from the lines exact on {spread[0]}, within 0.01 on {spread[0] + spread[0.01]}, largest deviations {sorted(deviations, reverse=True)[:3]}')
    check('on every bill of the sample, from the PDF text alone, the line amounts less the Promotion Discount are the Total Amount, the Total Discount is its '
          'percentage of it, and the Total Amt Excluding VAT is what the lines give within ฿1.00',
          judged and not unread and not off and max(deviations)[0] <= AMOUNT_TOLERANCE, f'{dict(off)} {max(deviations)}')
    flagged = [inv for inv in bills if footer_problem(page_of, inv)]
    shown = sorted({x['asn']['invoice_no'] for x in run['before']['results'] if FOOTER_WORDS.search(x['reason'])})
    check('on the real sample no bill prints a footer total its lines do not give, independently and in the app', not flagged and not shown, f'{flagged[:3]} {shown[:3]}')


FOOTER_WORDS = re.compile('ยอดรวมท้ายบิล(เป็นของบิล|ไม่ตรงกับรายการ)')


def footer_swap_fixture(pages, asn):
    """The first two bills of one file of the sample, each with the pieces of its ASN, whose footers the other's lines
    do not give: the pages with the footers of their last pages exchanged, and (file, page of the first, page of the
    second, first bill, second bill)"""
    asn_qty, _, assigned, pdf_only, page_of = assign_lines(pages, asn)
    bills, file_bills = bill_footers(page_of)

    def right(inv):
        got = collections.Counter()
        for x in assigned[inv]:
            got[x[3]] += x[4]
        return inv in asn_qty and got == asn_qty[inv] and not pdf_only[inv] and footer_read(bills[inv]['footer'])

    for f in sorted(file_bills):
        for a, b in itertools.combinations([inv for inv in file_bills[f] if right(inv)], 2):
            if not footer_fits(bills[b]['footer'], bills[a]['lines']) and not footer_fits(bills[a]['footer'], bills[b]['lines']):
                ends = {bills[a]['last']: bills[b]['footer'], bills[b]['last']: bills[a]['footer']}
                swapped = [dict(p, footer=ends.get((p['file'], p['page']), p['footer'])) for p in pages]
                return swapped, (f, bills[a]['last'][1], bills[b]['last'][1], a, b)
    raise RuntimeError('no two bills of one file to exchange the footers of')


def check_footer_swap(pages, asn, swap, base, run):
    """base: the app on the real sample, run: the app on it with the footers of swap (footer_swap_fixture) exchanged in
    the stored rows"""
    swapped, (f, page_a, page_b, a, b) = swap
    print(f'  footers exchanged: {a} (page {page_a}) and {b} (page {page_b}) of {f}')
    check_reconcile(independent_line_verdicts(swapped, asn), run, f'with the footers of {a} and {b} exchanged, ')
    want = {inv: ('MISMATCH', expected_problem(swapped, asn, inv), '') for inv in (a, b)}
    texts = {(when, inv): {(x['status'], x['reason'], x['action']) for x in run[when]['results'] if x['asn']['invoice_no'] == inv}
             for when in ('before', 'after') for inv in (a, b)}
    remarks = {r['invoice_no']: (r['status'], r['problem'], r['solution']) for r in run['dashboard_before'] if r['invoice_no'] in (a, b)}
    named = f'ยอดรวมท้ายบิลเป็นของบิล {b} ' in want[a][1] and f'ยอดรวมท้ายบิลเป็นของบิล {a} ' in want[b][1]
    check('each of the two bills names the other on every line before and after the fix, in its dashboard remark too, before its amount problem and with no solution',
          named and all(texts[(when, inv)] == {want[inv]} for when in ('before', 'after') for inv in (a, b)) and remarks == want,
          json.dumps([want, {f'{w} {i}': sorted(t) for (w, i), t in texts.items()}, remarks], ensure_ascii=False)[:900])
    touched = {root(a), root(b)}
    same = [x for x in run['before']['results'] if root(x['asn']['invoice_no']) not in touched]
    real = [x for x in base['before']['results'] if root(x['asn']['invoice_no']) not in touched]
    changed = sorted({x['asn']['invoice_no'] for x, y in zip(same, real) if (x['status'], x['reason'], x['action']) != (y['status'], y['reason'], y['action'])})
    check('every bill of another file reads exactly as on the real sample', same and len(same) == len(real) and not changed, f'{len(same)} lines, changed {changed[:4]}')


WRONG_1539 ='ยอดรวมไม่ตรง (PDF: 22,594.56 / ASN: 31,364.53), VAT ไม่ตรง (PDF: ฿1,581.60 / ASN: ฿1,302.17)'
NAMED_BILLS = {
    # bill: every (problem, solution) its lines show before the fix, in the user's wording
    '93247359-3': {('ไฟล์ต้นฉบับมีหลายบิล บิลนี้อยู่หน้าที่ 4-5', 'แยกหน้าที่ 4 ถึง 5 ออกมาเป็นอีกไฟล์')},
    '93248165-1': {('หน้า 2 คาดว่าเลขบิลผิด เนื่องจากรวมกับหน้า 1 แล้วรายการถูกต้องตาม ASN',
                    'แก้ไขเลขบิลของหน้า 2, ลบยอดรวมของหน้าที่ 1, แยกหน้าที่ 1 ถึง 2 ออกมาเป็นอีกไฟล์')},
    '93248165-2': {('หน้า 2 คาดว่าเลขบิลผิด เนื่องจากรวมกับหน้า 1 แล้วรายการถูกต้องตาม ASN',
                    'ย้ายหน้า 2 ไปเป็นบิล 93248165-1, แยกหน้าที่ 3 ออกมาเป็นอีกไฟล์')},
    '93248280-1': {('หน้า 1 มียอดรวมท้ายบิล ทั้งที่ไม่ใช่หน้าสุดท้ายของบิล', 'ลบยอดรวมของหน้าที่ 1, แยกหน้าที่ 1 ถึง 2 ออกมาเป็นอีกไฟล์')},
    '93248057-1': {('ข้อมูลถูกต้อง', 'ข้อมูลถูกต้อง')},
    '93250621-1': {('ยอดรวมไม่ตรง (PDF: 23,932.97 / ASN: 40,644.89)', '')},
    '93251539-1': {(f'รายการนี้ถูกต้อง แต่บิลผิดพลาดเพราะบาร์โค้ด 5401221426009 จำนวนไม่ตรง (PDF: 4 / ASN: 2) จึงทำให้ยอดรวมบิลไม่ตรง ({WRONG_1539})', ''),
                   (f'จำนวนไม่ตรง (PDF: 4 ชิ้น / ASN: 2 ชิ้น), {WRONG_1539}', '')},
    '93251539-2': {('ยอดรวมไม่ตรง (PDF: 1,608.97 / ASN: 2,514.01)', '')},
}
NAMED_REMARKS = {
    # bill: (note, problem, solution) of its dashboard row before the fix
    '93248165-2': ('',) + min(NAMED_BILLS['93248165-2']),
    '93248280-1': ('',) + min(NAMED_BILLS['93248280-1']),
    '93248057-1': ('', '', ''),
    '93250621-1': ('',) + min(NAMED_BILLS['93250621-1']),
    '93251539-1': ('', f'บิลผิดพลาดเพราะบาร์โค้ด 5401221426009 จำนวนไม่ตรง (PDF: 4 / ASN: 2) จึงทำให้ยอดรวมบิลไม่ตรง ({WRONG_1539})', ''),
}


def check_texts(run):
    shown = collections.defaultdict(set)
    for x in run['before']['results']:
        shown[x['asn']['invoice_no']].add((x['reason'], x['action']))
    wrong = {inv: sorted(shown.get(inv, ())) for inv, texts in NAMED_BILLS.items() if shown.get(inv) != texts}
    check('before the fix the sample bills read the user\'s problem and solution: split only, wrong number and the bill giving the page, '
          'total only, complete, short of the ASN cost, wrong on its pieces and the bill giving it a page, itself short; a wrong bill has no solution',
          not wrong, json.dumps(wrong, ensure_ascii=False)[:600])
    rows = {r['invoice_no']: r for r in run['dashboard_before']}
    remarks = {inv: tuple(rows.get(inv, {}).get(k) for k in ('note', 'problem', 'solution')) for inv in NAMED_REMARKS}
    check('their dashboard remark before the fix is the same problem and solution, nothing for the complete bill and no solution for the wrong one',
          remarks == NAMED_REMARKS, json.dumps({i: r for i, r in remarks.items() if r != NAMED_REMARKS[i]}, ensure_ascii=False)[:600])


def check_fix_output(asn, run):
    items = collections.defaultdict(lambda: collections.defaultdict(bool))
    for r in run['items_after']:
        items[(r['filename'], r['tax_invoice'])][int(r['page_no'])] |= r['amt_excl_vat'] not in ('Null', '-', None, '')
    footer_off_last = [k for k, pg in items.items() if any(has and p != max(pg) for p, has in pg.items())]
    check('after the fix every footer sits on the last page of its invoice', not footer_off_last, str(footer_off_last[:3]))
    asn_barcodes = collections.defaultdict(list)
    for a in asn:
        asn_barcodes[a['invoice_no']].append(a['ean_upc'])
    originals = {p.name for p in sample_pdfs()}
    splits = [f for f in Path(run['finished_dir']).glob('*.pdf') if f.name not in originals]
    wrong = []
    for f in splits:
        codes = [c for c in re.findall(r'(?<!\d)(\d{13})(?!\d)', pdftotext(f, '-raw')) if c not in TAX_IDS]
        if len(codes) != len(set(asn_barcodes[f.stem])) or set(codes) != set(asn_barcodes[f.stem]):
            wrong.append(f.stem)
    check('every split PDF holds exactly the lines of its ASN invoice', not wrong, f'{len(splits)} split files, wrong {wrong[:6]}')


def check_split_pages(run):
    originals = {p.name for p in sample_pdfs()}
    wrong_number, wrong_footer = [], []
    splits = [f for f in Path(run['finished_dir']).glob('*.pdf') if f.name not in originals]
    for f in splits:
        reader = pypdf.PdfReader(str(f))
        last = len(reader.pages)
        for number, page in enumerate(reader.pages, 1):
            try:
                ops = ContentStream(page.get_contents(), reader).operations
            except Exception:
                ops = []
            written = [t for t in (str(a[0]).strip() for a, op in ops if op == b'Tj') if re.fullmatch(r'\d{8}-\d+', t)]
            covered = any(op == b're' and abs(float(a[2]) - 595) < 1 for a, op in ops)
            text = pdftotext(f, '-raw', '-f', str(number), '-l', str(number))
            printed = re.search(r'Tax Invoice\s*:\s*(\d{8}-\d+)', text)
            shown = written[-1] if written else (printed.group(1) if printed else None)
            if shown != f.stem:
                wrong_number.append((f.stem, number))
            if 'TOTAL SHIPPED' in text and ((number < last) != covered):
                wrong_footer.append((f.stem, number))
    check('every page of a split PDF shows its own invoice number', not wrong_number, str(wrong_number[:4]))
    check('a split PDF shows a footer on its last page only', not wrong_footer, str(wrong_footer[:4]))


def check_print(asn, run):
    round_dir = Path(run['print_dir']) / 'print ครั้งที่ 1'
    check('the print run produced round 1', round_dir.is_dir() and run['print']['round'] == 1, str(run['print'].get('failed'))[:120])
    after = worst_status(run['after']['results'])
    complete = {inv for inv, status in after.items() if status == 'MATCH'}

    files = {}
    numbering_ok = True
    for folder in sorted(p for p in round_dir.iterdir() if p.is_dir()):
        numbered = {}
        for f in folder.glob('*.pdf'):
            m = re.fullmatch(r'([1-9]\d*)_(.+)\.pdf', f.name)
            if not m:
                numbering_ok = False
                continue
            numbered[int(m.group(1))] = m.group(2)
            files[m.group(2)] = (folder.name, f)
        stems = [numbered[i] for i in sorted(numbered)]
        if sorted(numbered) != list(range(1, len(numbered) + 1)) or stems != sorted(stems, key=natural_key):
            numbering_ok = False
    check('printed invoices are exactly the complete ones, each once', set(files) == complete and len(list(round_dir.rglob('*.pdf'))) == len(complete), f'{len(files)} printed, {len(complete)} complete')
    check('files are numbered 1, 2, 3 without padding per store folder and sorted by invoice', numbering_ok)

    asn_store = {}
    for a in asn:
        asn_store.setdefault(a['invoice_no'], a['dest_store_no'])
    shipped_to = {inv: ship_to_store(path) for inv, (store, path) in files.items()}
    wrong_store = [(inv, store, asn_store.get(inv), shipped_to[inv]) for inv, (store, path) in files.items()
                   if store != asn_store.get(inv) or shipped_to[inv] not in (None, store)]
    unnumbered = sum(1 for s in shipped_to.values() if s is None)
    check('every file sits in the folder of its ASN Dest Store No., the store number its printed PDF ships to when it prints one',
          bool(files) and not wrong_store, f'{len(files) - unnumbered} files cross-checked with Ship to Party, {unnumbered} print no store number, wrong {wrong_store[:4]}')

    asn_barcodes = collections.defaultdict(list)
    for a in asn:
        asn_barcodes[a['invoice_no']].append(a['ean_upc'])
    flat = Path(tempfile.mkdtemp(prefix='verify_print_'))
    try:
        wrong_content = []
        for inv, (branch, path) in files.items():
            shutil.copy(path, flat / f'{inv}.pdf')
            codes = [c for c in re.findall(r'(?<!\d)(\d{13})(?!\d)', pdftotext(path, '-raw')) if c not in TAX_IDS]
            if len(codes) != len(set(asn_barcodes[inv])) or set(codes) != set(asn_barcodes[inv]):
                wrong_content.append(inv)
        check('every printed file holds exactly the lines of its invoice', not wrong_content, str(wrong_content[:4]))
        before = len(fails)
        check_split_pages({'finished_dir': str(flat)})
        if len(fails) == before:
            print('  (pages of every printed file show their own number and the footer on the last page only)')
    finally:
        shutil.rmtree(flat, ignore_errors=True)


def main():
    require_samples()
    sheet, asn = read_asn()
    print(f'ASN sheet "{sheet}": {len(asn)} rows, {len({a["invoice_no"] for a in asn})} invoices')
    pages = read_pdfs()
    print(f'PDFs: {len({p["file"] for p in pages})} files, {len(pages)} pages')
    work = Path(tempfile.mkdtemp(prefix='verify_docs_'))

    def run_app(name, rows, swap=None):
        folder = work / name
        (folder / 'data').mkdir(parents=True)
        (folder / 'myfile').mkdir()
        (folder / 'asn.json').write_text(json.dumps(rows))
        (folder / 'swap.json').write_text(json.dumps(swap[1][:3] if swap else None), encoding='utf-8')
        env = {**os.environ, 'PYTHONUTF8': '1'}
        done = subprocess.run([PHP, str(ROOT / 'tests' / 'verify_docs_run.php'), str(folder), str(write_pdf_list(folder / 'pdfs.json')),
                               str(folder / 'asn.json'), str(folder / 'out.json'), str(folder / 'swap.json')],
                              capture_output=True, text=True, encoding='utf-8', env=env)
        if done.returncode != 0:
            print(done.stdout, done.stderr)
            sys.exit(2)
        return json.loads((folder / 'out.json').read_text(encoding='utf-8'))

    try:
        run = run_app('real', asn)
        check_extraction(pages, run['rows'])
        check_reconcile(independent_line_verdicts(pages, asn), run)
        check_texts(run)
        check_fix_output(asn, run)
        check_split_pages(run)
        check_print(asn, run)
        print('  summary before fix', run['before']['summary'])
        print('  summary after fix ', run['after']['summary'])
        rows, picks = pdf_only_fixture(pages, asn)
        check_pdf_only(pages, asn, rows, picks, run, run_app('pdf_only', rows))
        check_footer_relations(pages, asn, run)
        swap = footer_swap_fixture(pages, asn)
        check_footer_swap(pages, asn, swap, run, run_app('footer_swap', asn, swap))
    finally:
        shutil.rmtree(work, ignore_errors=True)
    print('\n' + (f'{len(fails)} FAILED' if fails else 'ALL PASS'))
    sys.exit(1 if fails else 0)


if __name__ == '__main__':
    main()
