// ================= 1. LOAD & RENDER PDF DATA ================= //

function buildPdfRowHtml(r, rowNum) {
    const fn = escapeHtml(r.filename);
    const addr = escapeHtml(r.address);
    const ship = escapeHtml(r.ship_to_party);
    const desc = escapeHtml(r.description);
    return `<tr>
        <td class="text-center fw-medium text-secondary tabular-num">${rowNum}</td>
        <td class="fw-medium" title="${fn}">${fn}</td>
        <td class="text-center"><span class="pill-badge pill-page tabular-num">${r.page_no}</span></td>
        <td class="tabular-num fw-semibold text-dark">${escapeHtml(r.tax_invoice)}</td>
        <td class="tabular-num">${escapeHtml(r.date)}</td>
        <td title="${addr}">${addr}</td>
        <td class="tabular-num">${escapeHtml(r.tax_id)}</td>
        <td class="text-center tabular-num">${escapeHtml(r.branch_code)}</td>
        <td title="${ship}">${ship}</td>
        <td class="text-center"><span class="pill-badge pill-term tabular-num">${escapeHtml(r.term_a)}</span></td>
        <td class="small text-muted">${escapeHtml(r.term_b)}</td>
        <td class="fw-semibold">${escapeHtml(r.product_sz)}</td>
        <td class="tabular-num text-primary fw-bold">${escapeHtml(r.barcode)}</td>
        <td title="${desc}">${desc}</td>
        <td class="text-center fw-bold tabular-num">${r.units}</td>
        <td class="text-end tabular-num">${formatMoney(r.unit_price)}</td>
        <td class="text-end fw-semibold tabular-num text-success">${formatMoney(r.amount)}</td>
        <td class="text-center tabular-num ${r.total_shipped === 'Null' ? 'text-null-subtle' : 'fw-bold'}">${escapeHtml(r.total_shipped)}</td>
        <td class="text-center tabular-num ${r.no_cartons === 'Null' ? 'text-null-subtle' : ''}">${escapeHtml(r.no_cartons)}</td>
        <td class="text-end tabular-num ${r.promotion_discount === 'Null' ? 'text-null-subtle' : ''}">${escapeHtml(r.promotion_discount)}</td>
        <td class="text-end fw-semibold tabular-num ${r.total_amount === 'Null' ? 'text-null-subtle' : ''}">${escapeHtml(r.total_amount)}</td>
        <td class="text-center tabular-num ${r.discount_percent === 'Null' ? 'text-null-subtle' : ''}">${escapeHtml(r.discount_percent)}</td>
        <td class="text-end tabular-num ${r.total_discount === 'Null' ? 'text-null-subtle' : ''}">${escapeHtml(r.total_discount)}</td>
        <td class="text-end tabular-num ${r.amt_after_discount === 'Null' ? 'text-null-subtle' : ''}">${escapeHtml(r.amt_after_discount)}</td>
        <td class="text-end tabular-num ${r.amt_excl_vat === 'Null' ? 'text-null-subtle' : ''}">${escapeHtml(r.amt_excl_vat)}</td>
        <td class="text-end tabular-num ${r.vat_amount === 'Null' ? 'text-null-subtle' : ''}">${escapeHtml(r.vat_amount)}</td>
        <td class="text-end fw-bold text-primary tabular-num ${r.amt_incl_vat === 'Null' ? 'text-null-subtle' : ''}">${escapeHtml(r.amt_incl_vat)}</td>
    </tr>`;
}

async function loadJobItemsFromDb() {
    const tbody = document.getElementById('detailed-tbody');
    try {
        const res = await fetch(`api.php?action=get_job_items&job_id=${CURRENT_JOB_ID}`);
        const json = await res.json();

        if (json.status !== 'success') {
            throw new Error(json.message);
        }

        detailedData = (json.data || []).map(preparePdfRow);
        displayData = [...detailedData];
        renderTable();
    } catch (err) {
        console.error(err);
        tbody.innerHTML = `<tr><td colspan="27" class="text-center text-danger py-4">เกิดข้อผิดพลาดในการโหลดข้อมูล: ${err.message}</td></tr>`;
    }
}

function buildPdfEmptyPlaceholderRows(rowCount = 18) {
    const emptyCells = '<td></td>'.repeat(26);
    const htmlRows = [];
    for (let i = 1; i <= rowCount; i++) {
        htmlRows.push(`<tr class="placeholder-grid-row"><td class="text-center text-muted tabular-num opacity-40">${i}</td>${emptyCells}</tr>`);
    }
    return htmlRows.join('');
}

function renderTable() {
    cancelPdfRenderSchedule();
    const tbody = document.getElementById('detailed-tbody');

    if (displayData.length === 0) {
        if (detailedData.length === 0) {
            tbody.innerHTML = buildPdfEmptyPlaceholderRows(18);
        } else {
            tbody.innerHTML = '<tr><td colspan="27" class="text-center py-5 text-muted"><i class="fa-solid fa-magnifying-glass me-2"></i>ไม่พบข้อมูลที่ตรงกับคำค้นหา</td></tr>';
        }
        pdfDomDirty = false;
        document.getElementById('btn-copy-excel').disabled = true;
        document.getElementById('btn-export-excel').disabled = true;
        setStatusBarPdf(displayData);
        return;
    }

    document.getElementById('btn-copy-excel').disabled = false;
    document.getElementById('btn-export-excel').disabled = false;
    setStatusBarPdf(displayData);

    if (activeTab !== 'pdf') {
        tbody.innerHTML = '';
        pdfDomDirty = true;
        return;
    }

    pdfDomDirty = false;
    const total = displayData.length;
    const firstBatch = Math.min(INITIAL_RENDER_ROWS, total);
    const firstParts = new Array(firstBatch);
    for (let idx = 0; idx < firstBatch; idx++) {
        firstParts[idx] = buildPdfRowHtml(displayData[idx], idx + 1);
    }
    tbody.innerHTML = firstParts.join('');

    if (total > firstBatch) {
        const token = pdfRenderToken;
        let cursor = firstBatch;
        const pumpChunk = () => {
            if (token !== pdfRenderToken || activeTab !== 'pdf') return;
            const end = Math.min(cursor + CHUNK_RENDER_ROWS, displayData.length);
            const parts = new Array(end - cursor);
            for (let i = cursor; i < end; i++) {
                parts[i - cursor] = buildPdfRowHtml(displayData[i], i + 1);
            }
            tbody.insertAdjacentHTML('beforeend', parts.join(''));
            cursor = end;
            if (cursor < displayData.length) {
                pdfRenderFrameId = requestAnimationFrame(pumpChunk);
            } else {
                pdfRenderFrameId = null;
            }
        };
        pdfRenderFrameId = requestAnimationFrame(pumpChunk);
    }
}

