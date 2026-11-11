// Reading the ASN workbook for the credit note compare. The user picks one sheet per import, as on the Tax Invoice
// page; nothing here touches the DOM, so tests/credit_note_compare.js runs it in Node on the real workbook.

const CN_SHEET_HINT = 'น่าจะเป็นใบลดหนี้';
const CN_SHEET_HINT_NAME = /ZCR|ZKR/i;

// detectSheetColumns of the Tax Invoice import (workspace/asn-import.js), copied so the two importers can change
// apart: the same headers map to the same keys, plus invoice_date (Invoice Date, CN Date)
function creditNoteDetectSheetColumns(rawRows) {
    for (let r = 0; r < Math.min(rawRows.length, 50); r++) {
        const row = rawRows[r];
        if (!Array.isArray(row)) continue;
        let hasInv = false;
        let hasEan = false;
        const currentMap = {};

        row.forEach((cell, cIdx) => {
            const str = String(cell || '').trim();
            if (/invoice\s*no|bill\s*no|tax\s*inv|cn\s*no|credit\s*note|billing\s*doc/i.test(str) && currentMap.invoice_no === undefined) {
                hasInv = true;
                currentMap.invoice_no = cIdx;
            } else if (/^ean\/upc$|^ean$|^upc$|^barcode$/i.test(str) && currentMap.ean_upc === undefined) {
                hasEan = true;
                currentMap.ean_upc = cIdx;
            } else if (/dest.*store|store\s*no|branch/i.test(str) && currentMap.dest_store_no === undefined) {
                currentMap.dest_store_no = cIdx;
            } else if (/^quantity$|^qty$/i.test(str) && currentMap.quantity === undefined) {
                currentMap.quantity = cIdx;
            } else if (/selling\s*price|unit\s*price|^price$|unit\s*ret/i.test(str) && currentMap.selling_price === undefined) {
                currentMap.selling_price = cIdx;
            } else if (/total\s*cost.*ex.*vat|cost.*ex.*vat|amt.*ex.*vat/i.test(str) && currentMap.total_cost_ex_vat === undefined) {
                currentMap.total_cost_ex_vat = cIdx;
            } else if (/^total\s*vat$|^vat$/i.test(str) && currentMap.total_vat === undefined) {
                currentMap.total_vat = cIdx;
            } else if (/^delivery\s*no/i.test(str) && currentMap.delivery_no === undefined) {
                currentMap.delivery_no = cIdx;
            } else if (/^invoice\s*date$|^cn\s*date$/i.test(str) && currentMap.invoice_date === undefined) {
                currentMap.invoice_date = cIdx;
            }
        });

        if (hasInv && hasEan) {
            return { headerRowIdx: r, colMap: currentMap };
        }
    }
    return null;
}

// Excel keeps a date as the number of days since 1899-12-30; a date typed as text stays as typed
function creditNoteSheetDate(value) {
    if (typeof value === 'number' && isFinite(value)) {
        return new Date(Date.UTC(1899, 11, 30) + Math.floor(value) * 86400000).toISOString().slice(0, 10);
    }
    return String(value === null || value === undefined ? '' : value).trim();
}

// cost: the sheet has Total Cost Ex VAT (the ZFP3 family), compared with the PDF Total Amt Excluding VAT;
// retail: it has not (ZG2T), the document amount is quantity x Unit Ret, compared with the PDF Total Amount
function creditNoteSheetKind(detected) {
    return detected.colMap.total_cost_ex_vat !== undefined ? 'cost' : 'retail';
}

// The rows the Tax Invoice import keeps (a document number, a 7 to 15 digit barcode, no total line), as the records
// job_cn_import_asn stores, in the order of the sheet
function creditNoteSheetRecords(rawRows, detected) {
    const { headerRowIdx, colMap } = detected;
    const kind = creditNoteSheetKind(detected);
    const text = (row, key) => colMap[key] !== undefined ? String(row[colMap[key]] || '').trim() : '';
    const number = (row, key) => colMap[key] !== undefined ? Number(row[colMap[key]]) || 0 : 0;
    const records = [];
    for (let r = headerRowIdx + 1; r < rawRows.length; r++) {
        const row = rawRows[r];
        if (!row || row.length === 0) continue;
        const invoiceNo = text(row, 'invoice_no');
        const eanUpc = text(row, 'ean_upc');
        if (!invoiceNo || !eanUpc) continue;
        if (/total|grand|summary|ex\./i.test(invoiceNo)) continue;
        if (!/^\d{7,15}$/.test(eanUpc.replace(/[^0-9]/g, ''))) continue;

        records.push({
            invoice_no: invoiceNo,
            invoice_date: colMap.invoice_date !== undefined ? creditNoteSheetDate(row[colMap.invoice_date]) : '',
            dest_store_no: text(row, 'dest_store_no'),
            ean_upc: eanUpc,
            quantity: number(row, 'quantity'),
            selling_price: number(row, 'selling_price'),
            total_cost_ex_vat: number(row, 'total_cost_ex_vat'),
            total_vat: number(row, 'total_vat'),
            delivery_no: text(row, 'delivery_no'),
            amount_kind: kind
        });
    }
    return records;
}

function creditNoteSheetHidden(workbook, sheetName) {
    const meta = workbook && workbook.Workbook && Array.isArray(workbook.Workbook.Sheets)
        ? workbook.Workbook.Sheets.find(s => s && s.name === sheetName)
        : null;
    return !!meta && (meta.Hidden === 1 || meta.Hidden === 2 || !!meta.hidden);
}

// The sheets the picker offers, in workbook order: those with a document number and a barcode column and at least
// one record, the visible ones only unless every such sheet is hidden. The first one is the default, as on the Tax
// Invoice page; the hint only marks a name, it never picks.
function creditNoteWorkbookSheets(workbook) {
    const all = [];
    workbook.SheetNames.forEach(name => {
        const rawRows = XLSX.utils.sheet_to_json(workbook.Sheets[name], { header: 1 });
        const detected = creditNoteDetectSheetColumns(rawRows);
        if (!detected) return;
        const records = creditNoteSheetRecords(rawRows, detected);
        if (records.length === 0) return;
        all.push({
            name,
            records,
            kind: creditNoteSheetKind(detected),
            hidden: creditNoteSheetHidden(workbook, name),
            hint: CN_SHEET_HINT_NAME.test(name)
        });
    });
    const visible = all.filter(sheet => !sheet.hidden);
    return visible.length > 0 ? visible : all;
}

function creditNoteSheetOptionText(sheet) {
    return `${sheet.name} (${sheet.records.length.toLocaleString()} แถว)${sheet.hint ? ` ${CN_SHEET_HINT}` : ''}`;
}

// The source of one import: two sheets of one workbook are two sources, so they never count as the same file
function creditNoteAsnSource(fileName, sheetName) {
    return `${fileName} / ${sheetName}`;
}
