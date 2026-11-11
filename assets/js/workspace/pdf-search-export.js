// ================= 3. SORTING & SEARCH ================= //

document.querySelectorAll('#detailed-table thead th').forEach(th => {
    th.addEventListener('click', (e) => {
        if (e.target.classList.contains('resizer')) return;

        const col = th.getAttribute('data-col');
        const type = th.getAttribute('data-type');
        if (!col || displayData.length === 0) return;

        const colLabel = th.textContent.trim();
        runWithLoading('กำลังเรียงลำดับข้อมูล...', `เรียงตามคอลัมน์ "${colLabel}" (${displayData.length.toLocaleString()} รายการ)`, () => {
            if (currentSort.column === col) {
                currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
            } else {
                currentSort.column = col;
                currentSort.direction = 'asc';
            }

            resetSortIcons('#detailed-table');
            th.classList.add('sorted');
            const icon = th.querySelector('.sort-icon');
            if (icon) {
                icon.className = currentSort.direction === 'asc' ? 'fa-solid fa-sort-up sort-icon' : 'fa-solid fa-sort-down sort-icon';
            }

            if (col === '#') {
                displayData.reverse();
            } else {
                const isAsc = currentSort.direction === 'asc';
                displayData.sort((a, b) => {
                    let valA = a[col] ?? '';
                    let valB = b[col] ?? '';
                    if (valA === 'Null') valA = '';
                    if (valB === 'Null') valB = '';

                    if (type === 'number' || type === 'currency') {
                        const numA = parseFloat(String(valA).replace(/,/g, '')) || 0;
                        const numB = parseFloat(String(valB).replace(/,/g, '')) || 0;
                        return isAsc ? numA - numB : numB - numA;
                    } else {
                        return isAsc
                            ? thCollator.compare(String(valA), String(valB))
                            : thCollator.compare(String(valB), String(valA));
                    }
                });
            }

            renderTable();
        });
    });
});

function applySearch() {
    const q = document.getElementById('global-search').value.toLowerCase().trim();
    if (!q) {
        displayData = [...detailedData];
    } else {
        displayData = detailedData.filter(r => preparePdfRow(r)._searchStr.includes(q));
    }
    renderTable();
}

// ================= 4. COPY & EXCEL EXPORT ================= //

document.getElementById('btn-copy-excel').addEventListener('click', async () => {
    if (!displayData || displayData.length === 0) return;

    try {
        const headers = [
            "#", "File Name", "Page", "Tax Invoice No.", "Date", "Address", "Tax ID", "Branch Code",
            "Ship To Party", "Term A", "Term B", "Product/Size", "Barcode", "Description", "Quantity", "Unit Price", "Amount",
            "Total Shipped", "Cartons", "Promo Discount", "Total Amount", "Discount %",
            "Total Discount", "Amt After Disc", "Amt Excl VAT", "VAT Amount", "Amt Incl VAT"
        ];

        const rows = displayData.map((r, idx) => [
            idx + 1,
            String(r.filename || ''),
            r.page_no ?? '',
            String(r.tax_invoice || ''),
            String(r.date || ''),
            String(r.address || '').replace(/\r?\n|\r/g, ' '),
            String(r.tax_id || ''),
            String(r.branch_code || ''),
            String(r.ship_to_party || '').replace(/\r?\n|\r/g, ' '),
            String(r.term_a || ''),
            String(r.term_b || ''),
            String(r.product_sz || ''),
            String(r.barcode || ''),
            String(r.description || ''),
            r.units ?? '',
            String(r.unit_price || ''),
            String(r.amount || ''),
            String(r.total_shipped || 'Null'),
            String(r.no_cartons || 'Null'),
            String(r.promotion_discount || 'Null'),
            String(r.total_amount || 'Null'),
            String(r.discount_percent || 'Null'),
            String(r.total_discount || 'Null'),
            String(r.amt_after_discount || 'Null'),
            String(r.amt_excl_vat || 'Null'),
            String(r.vat_amount || 'Null'),
            String(r.amt_incl_vat || 'Null')
        ].join('\t'));

        const tsv = [headers.join('\t'), ...rows].join('\n');
        await copyTextToClipboard(tsv);
        const btnCopy = document.getElementById('btn-copy-excel');
        const origHtml = btnCopy.innerHTML;
        btnCopy.innerHTML = '<i class="fa-solid fa-check me-1"></i> คัดลอกแล้ว!';
        btnCopy.classList.remove('btn-excel-copy');
        btnCopy.classList.add('btn-success');
        setTimeout(() => {
            btnCopy.innerHTML = origHtml;
            btnCopy.classList.remove('btn-success');
            btnCopy.classList.add('btn-excel-copy');
        }, 2000);
    } catch (err) {
        console.error('Copy Error:', err);
        alert('คัดลอกไม่สำเร็จ: ' + err.message);
    }
});

async function exportPdfToExcel() {
    if (!displayData || displayData.length === 0) return;
    await ensureXlsxLoaded();

    const excelRows = displayData.map((r, idx) => ({
        '#': idx + 1,
        'File Name': r.filename || '',
        'Page': r.page_no || 1,
        'Tax Invoice No.': r.tax_invoice || '',
        'Date': r.date || '',
        'Address': r.address || '',
        'Tax ID': r.tax_id || '',
        'Branch Code': r.branch_code || '',
        'Ship To Party': r.ship_to_party || '',
        'Term A': r.term_a || '',
        'Term B': r.term_b || '',
        'Product/Size': r.product_sz || '',
        'Barcode': String(r.barcode || ''),
        'Description': r.description || '',
        'Quantity': Number(r.units) || 0,
        'Unit Price': Number(r.unit_price) || 0,
        'Amount': Number(r.amount) || 0,
        'Total Shipped': r.total_shipped || '',
        'Cartons': r.no_cartons || '',
        'Promo Discount': r.promotion_discount || '',
        'Total Amount': r.total_amount || '',
        'Discount %': r.discount_percent || '',
        'Total Discount': r.total_discount || '',
        'Amt After Disc': r.amt_after_discount || '',
        'Amt Excl VAT': r.amt_excl_vat || '',
        'VAT Amount': r.vat_amount || '',
        'Amt Incl VAT': r.amt_incl_vat || ''
    }));

    const ws = XLSX.utils.json_to_sheet(excelRows);

    const colWidths = [];
    const headerKeys = Object.keys(excelRows[0]);
    headerKeys.forEach(k => {
        let maxLen = k.length;
        excelRows.forEach(r => {
            const val = String(r[k] || '');
            if (val.length > maxLen) maxLen = Math.min(val.length, 50);
        });
        colWidths.push({ wch: maxLen + 3 });
    });
    ws['!cols'] = colWidths;

    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'PDF_Items');

    const jobNameClean = typeof CURRENT_JOB_NAME_CLEAN !== 'undefined' ? CURRENT_JOB_NAME_CLEAN : 'Job';
    XLSX.writeFile(wb, `Job_${CURRENT_JOB_ID}_${jobNameClean}_Extracted.xlsx`);
}

