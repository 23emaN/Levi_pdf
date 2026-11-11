function countReadyUnprintedInvoices() {
    if (!reconData || !reconData.summary || !reconData.results) {
        return 0;
    }
    const invoices = {};
    reconData.results.forEach(r => {
        const inv = (r.asn && r.asn.invoice_no) || '';
        const entry = invoices[inv] || (invoices[inv] = { complete: true, printed: false });
        if (r.status !== 'MATCH') entry.complete = false;
        if (r.printed_round) entry.printed = true;
    });
    return Object.values(invoices).filter(e => e.complete && !e.printed).length;
}

// The step of the ASN toolbar (data-step of #view-asn-section; app.css fills the button of that step), in the order of
// JobRepository::initialAsnStep, which picks it when the page is rendered: ASN, VBRP, compare, fix, print or done
function asnToolbarStep(data, vbrpRows, printable) {
    if (!data || (!data.summary && !(data.results || []).length)) return 'select';
    const summary = data.summary;
    if (!summary) return vbrpRows > 0 ? 'compare' : 'vbrp';
    if ((summary.pending_fix || 0) > 0) return 'fix';
    return printable > 0 ? 'print' : 'done';
}

// Why the compare button cannot run yet: it needs the ASN rows and the VBRP rows of the Job ('' when it can)
function compareBlocker(asnRows, vbrpRows) {
    const missing = [asnRows > 0 ? '' : 'ASN', vbrpRows > 0 ? '' : 'VBRP'].filter(Boolean);
    return missing.length ? `นำเข้าไฟล์ ${missing.join(' และ ')} ก่อน จึงจะเทียบกับ PDF ได้` : '';
}

function updateAsnToolbarStep() {
    const printable = countReadyUnprintedInvoices();
    const vbrpRows = vbrpCounts().rows;
    document.getElementById('view-asn-section').dataset.step = asnToolbarStep(reconData, vbrpRows, printable);
    const btnPrint = document.getElementById('btn-print');
    if (btnPrint) btnPrint.disabled = printable === 0;
    const btnCheck = document.getElementById('btn-check-pdf');
    const blocker = compareBlocker(asnRowCount(), vbrpRows);
    btnCheck.disabled = blocker !== '';
    btnCheck.title = blocker;
    document.querySelectorAll('#reconFilterGroup .recon-count').forEach(el => {
        el.classList.toggle('is-zero', el.textContent.trim() === '0');
    });
}

const RECON_COUNT_ELEMENTS = { ALL: 'count-all', MATCH: 'count-match', FIXED: 'count-fixed', PENDING_FIX: 'count-pending-fix', PENDING_ASN: 'count-pending-asn', MISMATCH: 'count-mismatch' };

function reconStatusCounts() {
    const rows = (reconData && reconData.results) || [];
    const counts = { ALL: rows.length };
    rows.forEach(r => {
        const key = reconRowStatus(r);
        counts[key] = (counts[key] || 0) + 1;
    });
    return counts;
}

function renderReconSummary() {
    const counts = reconStatusCounts();
    Object.keys(RECON_COUNT_ELEMENTS).forEach(key => {
        const el = document.getElementById(RECON_COUNT_ELEMENTS[key]);
        if (el) el.textContent = (counts[key] || 0).toLocaleString();
    });

    const btnFixAll = document.getElementById('btn-fix-all-pending');
    const btnFixAllCount = document.getElementById('btn-fix-all-count');
    const pendingFixCount = counts.PENDING_FIX || 0;
    if (btnFixAll) {
        if (pendingFixCount > 0) {
            btnFixAll.style.display = 'inline-flex';
            btnFixAll.style.alignItems = 'center';
            if (btnFixAllCount) btnFixAllCount.textContent = pendingFixCount.toLocaleString();
        } else {
            btnFixAll.style.display = 'none';
        }
    }
    updateAsnToolbarStep();
    setAsnStatusBarTitle();
}

// One line per row: the column fits a full billing PDF name, and a longer name loses its middle, never the
// end ("...-0093247359 3.pdf") that tells files apart
const SOURCE_FILE_TAIL = 16;

function sourceFileCellHtml(files) {
    if (!files || files.length === 0) return '<span class="text-null-subtle">-</span>';
    const name = files[0];
    const more = files.length > 1 ? `<span class="source-file-more">+${files.length - 1} ไฟล์</span>` : '';
    return `<span class="source-file" title="${escapeHtml(files.join('\n'))}"><span class="source-file-name">`
        + `<span class="source-file-head">${escapeHtml(name.slice(0, -SOURCE_FILE_TAIL))}</span>`
        + `<span class="source-file-tail">${escapeHtml(name.slice(-SOURCE_FILE_TAIL))}</span></span>${more}</span>`;
}

const NULL_CELL = '<span class="text-null-subtle">-</span>';

function reconRowView(row, rowNum) {
    const p = row.pdf || {};
    const a = row.asn || {};
    const hasPdf = (p.tax_invoice !== undefined && p.tax_invoice !== '') || (p.barcode !== undefined && p.barcode !== '') || (p.units !== undefined);
    const hasAsn = (a.invoice_no !== undefined && a.invoice_no !== '') || (a.ean_upc !== undefined && a.ean_upc !== '') || (a.quantity !== undefined);

    const pdfQty = hasPdf && p.units !== undefined ? Number(p.units) : null;
    const asnQty = hasAsn && a.quantity !== undefined ? Number(a.quantity) : null;
    const asnPrice = hasAsn && a.selling_price !== undefined ? Number(a.selling_price) : null;
    const pdfAmt = hasPdf ? (p.inv_amt_excl_vat || (p.amt_excl_vat && p.amt_excl_vat !== 'Null' ? p.amt_excl_vat : p.amount)) : null;
    const asnAmt = hasAsn && a.total_cost_ex_vat !== undefined ? Number(a.total_cost_ex_vat) : null;
    const pdfVat = hasPdf ? (p.inv_vat_amount || (p.vat_amount && p.vat_amount !== 'Null' ? p.vat_amount : null)) : null;
    const asnVat = hasAsn && a.total_vat !== undefined && Number(a.total_vat) > 0 ? Number(a.total_vat) : null;
    return {
        row, rowNum, hasPdf,
        statusKey: reconRowStatus(row),
        invNo: p.tax_invoice || a.invoice_no || '',
        barcode: p.barcode || a.ean_upc || '',
        desc: escapeHtml(p.description || ''),
        pdfStore: hasPdf ? (p.store_code || p.branch_code || null) : null,
        asnStore: hasAsn && a.dest_store_no ? a.dest_store_no : null,
        pdfQty, asnQty,
        asnRowCount: hasAsn && a.row_count > 1 ? a.row_count : 1,
        isQtyMismatch: hasPdf && (pdfQty !== null && asnQty !== null && pdfQty !== asnQty),
        pdfPrice: hasPdf && p.unit_price !== undefined ? p.unit_price : null,
        asnPrice,
        isNegPrice: asnPrice !== null && asnPrice < 0,
        pdfAmt, asnAmt,
        amountClass: row.amount_ok === false ? 'cell-mismatch' : (row.amount_ok === true ? 'cell-match' : ''),
        vatText: (pdfVat !== null || asnVat !== null)
            ? `${pdfVat !== null ? formatMoney(pdfVat) : '-'} / ${asnVat !== null ? formatMoney(asnVat) : '-'}`
            : null
    };
}

function reconMoney(value) {
    return value !== undefined ? parseFloat(String(value).replace(/,/g, '')) || 0 : -1;
}

// row.sources is null for a bill VBRP does not cover (and absent before the compare): its VBRP and verdict cells are '-'
function vbrpFigure(row, figure) {
    const vbrp = row.sources ? row.sources.vbrp : null;
    const value = vbrp ? vbrp[figure] : null;
    return value === null || value === undefined ? null : Number(value);
}

// A VBRP figure of the line; the check of the same name (sources.verdict) marks it red when it judges VBRP wrong. The
// price has no check, so it is never red
function vbrpFigureCell(figure, cellClass, format) {
    return {
        html: v => {
            const value = vbrpFigure(v.row, figure);
            if (value === null) return `<td class="${cellClass}">${NULL_CELL}</td>`;
            const wrong = verdictOf((v.row.sources.verdict || {})[figure], 'vbrp') === 'wrong';
            return `<td class="${cellClass}">${wrong ? `<span class="cell-mismatch">${format(value)}</span>` : format(value)}</td>`;
        },
        sort: r => {
            const value = vbrpFigure(r, figure);
            return value === null ? -1 : value;
        },
        excel: (r, n, col) => {
            const value = vbrpFigure(r, figure);
            return { [col.label]: value === null ? '' : value };
        }
    };
}

// One source's verdict on the line (sources.overall); the tooltip lists what each check said about that source
function lineVerdictCell(source) {
    const overall = r => (r.sources ? r.sources.overall : null);
    return {
        html: v => {
            const sources = v.row.sources;
            const detail = sources ? VERDICT_CHECKS.map(check =>
                `${check.label}: ${VERDICT_DISPLAY[verdictOf((sources.verdict || {})[check.key], source.key)].word}`) : [];
            return verdictCellHtml(overall(v.row), source, detail);
        },
        sort: r => VERDICT_DISPLAY[verdictOf(overall(r), source.key)].rank,
        excel: (r, n, col) => ({ [col.label]: VERDICT_DISPLAY[verdictOf(overall(r), source.key)].excel })
    };
}

// The cells of the ASN grid by column key: html(view) is the cell, sort(row) its sort value, search(row) its part of
// the search text and excel(row, number, column) its Excel columns. ASN_COLUMNS (workspace.php) puts them in order.
const ASN_CELLS = {
    '#': {
        html: v => `<td class="text-center tabular-num text-muted">${v.rowNum}</td>`,
        excel: (r, n, col) => ({ [col.label]: n })
    },
    status: {
        html: v => `<td class="text-center">${statusBadgeHtml(v.statusKey)}</td>`,
        sort: r => (STATUS_DISPLAY[reconRowStatus(r)] || STATUS_DISPLAY.MISMATCH).rank,
        excel: (r, n, col) => ({ [col.label]: (STATUS_DISPLAY[reconRowStatus(r)] || { label: r.status }).label, 'Previous status': previousStatusText(r.was) })
    },
    invoice_no: {
        html: v => `<td class="tabular-num fw-semibold text-dark">${v.invNo ? escapeHtml(v.invNo) : NULL_CELL}</td>`,
        sort: r => (r.pdf || {}).tax_invoice || (r.asn || {}).invoice_no || '',
        search: r => (r.pdf || {}).tax_invoice || (r.asn || {}).invoice_no,
        excel: (r, n, col) => ({ [col.label]: (r.pdf || {}).tax_invoice || (r.asn || {}).invoice_no || '' })
    },
    printed: {
        html: v => `<td class="text-center">${v.row.printed_round ? `<span class="pill-badge" style="background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd;">ครั้งที่ ${Number(v.row.printed_round)}</span>` : NULL_CELL}</td>`,
        sort: r => r.printed_round ? Number(r.printed_round) : 0,
        excel: (r, n, col) => ({ [col.label]: r.printed_round ? `ครั้งที่ ${r.printed_round}` : '' })
    },
    source_file: {
        html: v => `<td>${sourceFileCellHtml(v.row.source_files)}</td>`,
        sort: r => (r.source_files || [])[0] || '',
        search: r => (r.source_files || []).join(' '),
        excel: (r, n, col) => ({ [col.label]: (r.source_files || []).join(', ') })
    },
    barcode: {
        html: v => `<td class="tabular-num text-primary fw-bold">${v.barcode ? escapeHtml(v.barcode) : NULL_CELL}</td>`,
        sort: r => (r.pdf || {}).barcode || (r.asn || {}).ean_upc || '',
        search: r => (r.pdf || {}).barcode || (r.asn || {}).ean_upc,
        excel: (r, n, col) => ({ [col.label]: (r.pdf || {}).barcode || (r.asn || {}).ean_upc || '' })
    },
    description: {
        html: v => `<td title="${v.desc}">${v.desc ? v.desc : NULL_CELL}</td>`,
        sort: r => (r.pdf || {}).description || '',
        search: r => (r.pdf || {}).description,
        excel: (r, n, col) => ({ [col.label]: (r.pdf || {}).description || '' })
    },
    pdf_branch: {
        html: v => `<td class="text-center tabular-num">${v.pdfStore ? escapeHtml(v.pdfStore) : NULL_CELL}</td>`,
        sort: r => (r.pdf || {}).store_code || (r.pdf || {}).branch_code || '',
        search: r => (r.pdf || {}).store_code || (r.pdf || {}).branch_code,
        excel: (r, n, col) => ({ [col.label]: (r.pdf || {}).store_code || (r.pdf || {}).branch_code || '' })
    },
    asn_store: {
        html: v => `<td class="text-center tabular-num">${v.asnStore ? escapeHtml(v.asnStore) : NULL_CELL}</td>`,
        sort: r => (r.asn || {}).dest_store_no || '',
        search: r => (r.asn || {}).dest_store_no,
        excel: (r, n, col) => ({ [col.label]: (r.asn || {}).dest_store_no || '' })
    },
    pdf_qty: {
        html: v => `<td class="text-center tabular-num fw-bold">${v.pdfQty !== null ? v.pdfQty : NULL_CELL}</td>`,
        sort: r => (r.pdf || {}).units !== undefined ? Number(r.pdf.units) : -1,
        excel: (r, n, col) => ({ [col.label]: (r.pdf || {}).units !== undefined ? r.pdf.units : '' })
    },
    asn_qty: {
        html: v => `<td class="text-center tabular-num fw-bold">
            ${v.asnQty !== null ? `<span class="${v.isQtyMismatch ? 'cell-mismatch' : (v.hasPdf ? 'cell-match' : '')}">${v.asnQty}${v.asnRowCount > 1 ? ` <small class="text-muted fw-normal">(${v.asnRowCount} แถว)</small>` : ''}</span>` : NULL_CELL}
        </td>`,
        sort: r => (r.asn || {}).quantity !== undefined ? Number(r.asn.quantity) : -1,
        excel: (r, n, col) => ({ [col.label]: (r.asn || {}).quantity !== undefined ? r.asn.quantity : '' })
    },
    vbrp_qty: vbrpFigureCell('qty', 'text-center tabular-num fw-bold', value => value),
    pdf_price: {
        html: v => `<td class="text-end tabular-num">${v.pdfPrice !== null ? formatMoney(v.pdfPrice) : NULL_CELL}</td>`,
        sort: r => reconMoney((r.pdf || {}).unit_price),
        excel: (r, n, col) => ({ [col.label]: (r.pdf || {}).unit_price !== undefined ? r.pdf.unit_price : '' })
    },
    asn_price: {
        html: v => `<td class="text-end tabular-num">
            ${v.asnPrice !== null ? `<span class="${v.isNegPrice ? 'cell-mismatch' : ''}">${formatMoney(v.asnPrice)}</span>` : NULL_CELL}
        </td>`,
        sort: r => reconMoney((r.asn || {}).selling_price),
        excel: (r, n, col) => ({ [col.label]: (r.asn || {}).selling_price !== undefined ? r.asn.selling_price : '' })
    },
    vbrp_price: vbrpFigureCell('price', 'text-end tabular-num', formatMoney),
    pdf_amt: {
        html: v => `<td class="text-end tabular-num fw-semibold text-dark">${v.pdfAmt !== null ? formatMoney(v.pdfAmt) : NULL_CELL}</td>`,
        sort: r => {
            const p = r.pdf || {};
            return reconMoney(p.inv_amt_excl_vat || ((p.amt_excl_vat && p.amt_excl_vat !== 'Null') ? p.amt_excl_vat : p.amount));
        },
        excel: (r, n, col) => ({ [col.label]: (r.pdf || {}).inv_amt_excl_vat || (r.pdf || {}).amt_excl_vat || (r.pdf || {}).amount || '' })
    },
    asn_amt: {
        html: v => `<td class="text-end tabular-num fw-semibold">
            ${v.asnAmt !== null ? `<span class="${v.amountClass}">${formatMoney(v.asnAmt)}</span>` : NULL_CELL}
        </td>`,
        sort: r => reconMoney((r.asn || {}).total_cost_ex_vat),
        excel: (r, n, col) => ({ [col.label]: (r.asn || {}).total_cost_ex_vat !== undefined ? r.asn.total_cost_ex_vat : '' })
    },
    vbrp_amt: vbrpFigureCell('amount', 'text-end tabular-num fw-semibold', formatMoney),
    vat_amt: {
        html: v => `<td class="text-end tabular-num small text-secondary">${v.vatText ? v.vatText : NULL_CELL}</td>`,
        sort: r => (r.asn || {}).total_vat !== undefined ? Number(r.asn.total_vat) : -1,
        excel: r => ({
            'VAT (PDF)': (r.pdf || {}).inv_vat_amount || (r.pdf || {}).vat_amount || '',
            'VAT (ASN)': (r.asn || {}).total_vat !== undefined ? r.asn.total_vat : ''
        })
    },
    vbrp_vat: vbrpFigureCell('vat', 'text-end tabular-num small text-secondary', formatMoney),
    verdict_pdf: lineVerdictCell(VERDICT_SOURCES[0]),
    verdict_asn: lineVerdictCell(VERDICT_SOURCES[1]),
    verdict_vbrp: lineVerdictCell(VERDICT_SOURCES[2]),
    remark: {
        html: v => {
            const lines = reconRemark(v.row);
            const tone = lines.some(line => line.label) ? '' : ((STATUS_DISPLAY[v.statusKey] || {}).remarkClass || '');
            return `<td class="asn-remark ${tone}">${lines.length ? remarkHtml(lines) : NULL_CELL}</td>`;
        },
        sort: r => remarkText(reconRemark(r)),
        search: r => remarkText(reconRemark(r)),
        excel: r => {
            const lines = reconRemark(r);
            return { 'Problem': remarkExcelProblem(lines), 'Solution': remarkPart(lines, 'solution') };
        }
    },
    manage: {
        html: v => {
            if (v.row.status !== 'PENDING_FIX' && !v.row.can_fix) return '<td class="text-center"><span class="text-muted small">-</span></td>';
            return `<td class="text-center"><button type="button" class="btn btn-warning btn-sm py-0 px-2 fw-semibold" style="font-size: 0.74rem; border-radius: 6px;" onclick="openFixSingleConfirmModal('${escapeHtml(v.invNo)}')"><i class="fa-solid fa-wrench me-1"></i>แก้ไข</button></td>`;
        }
    }
};

function buildReconRowHtml(row, rowNum) {
    const view = reconRowView(row, rowNum);
    return `<tr>${ASN_COLUMNS.map(col => ASN_CELLS[col.key].html(view)).join('')}</tr>`;
}

function prepareReconRow(r) {
    if (!r._searchStr) {
        r._searchStr = ASN_COLUMNS.map(col => ASN_CELLS[col.key].search).filter(Boolean)
            .map(search => search(r)).filter(Boolean).join(' ').toLowerCase();
    }
    return r;
}

function reconExcelRows(rows) {
    return rows.map((r, i) => Object.assign({}, ...ASN_COLUMNS.filter(col => ASN_CELLS[col.key].excel)
        .map(col => ASN_CELLS[col.key].excel(r, i + 1, col))));
}

function buildReconEmptyPlaceholderRows(rowCount = 18) {
    const emptyCells = '<td></td>'.repeat(ASN_COLUMNS.length - 1);
    const htmlRows = [];
    for (let i = 1; i <= rowCount; i++) {
        htmlRows.push(`<tr class="placeholder-grid-row"><td class="text-center text-muted tabular-num opacity-40">${i}</td>${emptyCells}</tr>`);
    }
    return htmlRows.join('');
}

function renderReconTable(results) {
    cancelReconRenderSchedule();
    const tbody = document.getElementById('asn-tbody');

    const btnExportRecon = document.getElementById('btn-export-recon');

    if (!results || results.length === 0) {
        if (!reconData || !reconData.results || reconData.results.length === 0) {
            tbody.innerHTML = buildReconEmptyPlaceholderRows(18);
        } else {
            tbody.innerHTML = `<tr><td colspan="${ASN_COLUMNS.length}" class="text-center py-5 text-muted"><i class="fa-solid fa-magnifying-glass me-2"></i>ไม่พบรายการเปรียบเทียบที่ตรงกับเงื่อนไข</td></tr>`;
        }
        reconDomDirty = false;
        if (btnExportRecon) btnExportRecon.disabled = true;
        return;
    }

    if (btnExportRecon) btnExportRecon.disabled = false;

    if (activeTab !== 'asn') {
        tbody.innerHTML = '';
        reconDomDirty = true;
        return;
    }

    reconDomDirty = false;
    const total = results.length;
    const firstBatch = Math.min(INITIAL_RENDER_ROWS, total);
    const firstParts = new Array(firstBatch);
    for (let idx = 0; idx < firstBatch; idx++) {
        firstParts[idx] = buildReconRowHtml(results[idx], idx + 1);
    }
    tbody.innerHTML = firstParts.join('');

    if (total > firstBatch) {
        const token = reconRenderToken;
        let cursor = firstBatch;
        const pumpChunk = () => {
            if (token !== reconRenderToken || activeTab !== 'asn') return;
            const end = Math.min(cursor + CHUNK_RENDER_ROWS, results.length);
            const parts = new Array(end - cursor);
            for (let i = cursor; i < end; i++) {
                parts[i - cursor] = buildReconRowHtml(results[i], i + 1);
            }
            tbody.insertAdjacentHTML('beforeend', parts.join(''));
            cursor = end;
            if (cursor < results.length) {
                reconRenderFrameId = requestAnimationFrame(pumpChunk);
            } else {
                reconRenderFrameId = null;
            }
        };
        reconRenderFrameId = requestAnimationFrame(pumpChunk);
    }
}

document.querySelectorAll('#asn-table thead th').forEach(th => {
    th.addEventListener('click', (e) => {
        if (e.target.classList.contains('resizer')) return;
        const col = th.getAttribute('data-col');
        const type = th.getAttribute('data-type');
        const sortValue = (ASN_CELLS[col] || {}).sort;
        if (!type || (!sortValue && col !== '#') || reconDisplayData.length === 0) return;

        const colLabel = th.textContent.trim();
        runWithLoading('กำลังเรียงลำดับข้อมูล...', `เรียงตามคอลัมน์ "${colLabel}" (${reconDisplayData.length.toLocaleString()} รายการ)`, () => {
            if (currentReconSort.column === col) {
                currentReconSort.direction = currentReconSort.direction === 'asc' ? 'desc' : 'asc';
            } else {
                currentReconSort.column = col;
                currentReconSort.direction = 'asc';
            }

            resetSortIcons('#asn-table');
            th.classList.add('sorted');
            const icon = th.querySelector('.sort-icon');
            if (icon) {
                icon.className = currentReconSort.direction === 'asc' ? 'fa-solid fa-sort-up sort-icon' : 'fa-solid fa-sort-down sort-icon';
            }

            if (col === '#') {
                reconDisplayData.reverse();
            } else {
                const isAsc = currentReconSort.direction === 'asc';
                reconDisplayData.sort((a, b) => {
                    const valA = sortValue(a);
                    const valB = sortValue(b);
                    if (type === 'number' || type === 'currency') {
                        return isAsc ? (valA - valB) : (valB - valA);
                    } else {
                        return isAsc
                            ? thCollator.compare(String(valA), String(valB))
                            : thCollator.compare(String(valB), String(valA));
                    }
                });
            }

            renderReconTable(reconDisplayData);
        });
    });
});

function setReconFilter(filter, btn) {
    if (currentReconFilter === filter && btn && btn.classList.contains('active')) return;
    currentReconFilter = filter;
    document.querySelectorAll('#reconFilterGroup .recon-filter-btn').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');

    const filterLabel = btn ? btn.textContent.replace(/\s+/g, ' ').trim() : filter;
    runWithLoading('กำลังกรองข้อมูล...', `กรองรายการสถานะ "${filterLabel}"`, () => {
        filterReconTable();
    });
}

function filterReconTable() {
    updateAsnToolbarStep();
    if (!reconData || !reconData.results) {
        reconDisplayData = [];
        renderReconTable([]);
        return;
    }
    const q = (document.getElementById('asn-search').value || '').toLowerCase().trim();

    let filtered = reconData.results;
    if (currentReconFilter !== 'ALL') {
        filtered = filtered.filter(r => reconRowStatus(r) === currentReconFilter);
    }

    if (q) {
        filtered = filtered.filter(r => prepareReconRow(r)._searchStr.includes(q));
    } else if (filtered === reconData.results) {
        filtered = [...reconData.results];
    }

    reconDisplayData = filtered;
    renderReconTable(reconDisplayData);
}

