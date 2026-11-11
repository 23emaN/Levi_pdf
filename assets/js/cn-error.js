let cnData = [];
let displayData = [];
let isRunning = false;
let currentSort = { column: null, direction: 'asc' };

async function loadStats() {
    try {
        const res = await fetch('api.php?action=get_stats');
        const json = await res.json();
        if (json.status === 'success') {
            const count = json.data.myfile_count.toLocaleString();
            document.getElementById('stat-myfile').textContent = count;
            document.querySelectorAll('.opt-count-myfile').forEach(el => el.textContent = count);
            document.querySelectorAll('.opt-count-invoice').forEach(el => el.textContent = json.data.invoice_count.toLocaleString());
            document.querySelectorAll('.opt-count-cn').forEach(el => el.textContent = json.data.cn_count.toLocaleString());
        }
    } catch (e) {
        console.error('Error loading stats:', e);
    }
}

document.getElementById('btn-start').addEventListener('click', async () => {
    const folder = document.getElementById('select-folder').value;
    const res = await fetch(`api.php?action=list_files&folder=${folder}`);
    const json = await res.json();

    if (json.status !== 'success' || json.files.length === 0) {
        showToast(`ไม่พบไฟล์ PDF ในโฟลเดอร์ ${folder}/`);
        return;
    }

    const files = json.files;
    cnData = [];
    displayData = [];
    isRunning = true;
    currentSort = { column: null, direction: 'asc' };
    resetSortIcons('#cn-table');

    document.getElementById('btn-start').style.display = 'none';
    document.getElementById('btn-stop').style.display = 'inline-block';
    document.getElementById('btn-copy-excel').disabled = true;
    document.getElementById('btn-export-excel').disabled = true;
    document.getElementById('completion-badge').style.display = 'none';
    document.getElementById('progress-wrapper').style.display = 'block';
    document.getElementById('counter-badge').style.display = 'none';
    document.getElementById('sum-badge').style.display = 'none';
    document.getElementById('global-search').value = '';
    document.getElementById('btn-clear-search').style.display = 'none';

    const tbody = document.getElementById('cn-tbody');
    tbody.innerHTML = '';

    const BATCH_SIZE = 40;
    const totalFiles = files.length;
    let processedFilesCount = 0;

    for (let i = 0; i < totalFiles; i += BATCH_SIZE) {
        if (!isRunning) break;

        const batch = files.slice(i, i + BATCH_SIZE);
        const batchNum = Math.floor(i / BATCH_SIZE) + 1;
        const totalBatches = Math.ceil(totalFiles / BATCH_SIZE);

        try {
            const response = await fetch('api.php?action=extract_cn_batch', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    folder: folder,
                    files: JSON.stringify(batch)
                })
            });

            const resJson = await response.json();
            if (resJson.status === 'success' && resJson.data.length > 0) {
                resJson.data.forEach(item => {
                    cnData.push(item);
                });
            }

            processedFilesCount += batch.length;
        } catch (err) {
            console.error(`Error processing batch ${batchNum}:`, err);
            processedFilesCount += batch.length;
        }

        const pct = Math.min(100, Math.round((processedFilesCount / totalFiles) * 100));
        document.getElementById('progress-bar').style.width = `${pct}%`;
        document.getElementById('progress-pct').textContent = `${pct}%`;
        document.getElementById('progress-label').textContent =
            `กำลังประมวลผลชุดที่ ${batchNum}/${totalBatches} (${processedFilesCount}/${totalFiles} ไฟล์)...`;
    }

    isRunning = false;
    document.getElementById('btn-start').style.display = 'inline-block';
    document.getElementById('btn-stop').style.display = 'none';
    document.getElementById('progress-wrapper').style.display = 'none';

    applyFilters();

    if (displayData.length > 0) {
        document.getElementById('btn-copy-excel').disabled = false;
        document.getElementById('btn-export-excel').disabled = false;
        document.getElementById('completion-badge').style.display = 'inline-block';
        document.getElementById('completion-text').textContent = `ดึงข้อมูลสำเร็จ (${displayData.length} รายการ)`;
    } else {
        showEmptySkeleton();
        showToast('ไม่พบข้อมูลตามเงื่อนไขที่เลือก');
    }
});

document.getElementById('btn-stop').addEventListener('click', () => {
    isRunning = false;
    document.getElementById('btn-stop').style.display = 'none';
    document.getElementById('btn-start').style.display = 'inline-block';
    document.getElementById('progress-wrapper').style.display = 'none';
    applyFilters();
    if (displayData.length > 0) {
        document.getElementById('btn-copy-excel').disabled = false;
        document.getElementById('btn-export-excel').disabled = false;
    }
});

function createTableRow(item, rowIdx) {
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td class="text-center text-muted" style="background-color: #f8fafc; font-weight: 500;">${rowIdx}</td>
        <td class="cell-mono fw-bold text-primary">${item.doc_no}</td>
        <td title="${item.filename}">${item.filename}</td>
        <td class="text-end cell-mono fw-bold text-danger">${item.amount}</td>
    `;
    return tr;
}

function renderTable(dataArray) {
    const tbody = document.getElementById('cn-tbody');
    tbody.innerHTML = '';

    if (dataArray.length === 0) {
        showEmptySkeleton();
        return;
    }

    const fragment = document.createDocumentFragment();
    dataArray.forEach((item, idx) => {
        fragment.appendChild(createTableRow(item, idx + 1));
    });
    tbody.appendChild(fragment);
}

function showEmptySkeleton() {
    const tbody = document.getElementById('cn-tbody');
    tbody.innerHTML = '';
    for (let i = 1; i <= 30; i++) {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td class="text-center text-muted" style="color: #94a3b8; font-weight: 500; background-color: #f8fafc;">${i}</td>` +
            '<td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>';
        tbody.appendChild(tr);
    }
}

function applyFilters() {
    const searchVal = document.getElementById('global-search').value.toLowerCase().trim();
    const typeFilter = document.getElementById('select-filter-type').value;

    displayData = cnData.filter(item => {
        if (typeFilter === 'legacy_error') {
            if (item.format_type !== 'Attachment Sheet') return false;
        } else if (typeFilter === 'has_dash') {
            if (!String(item.doc_no).includes('-')) return false;
        }

        if (searchVal !== '') {
            const searchTarget = `${item.doc_no} ${item.filename} ${item.amount}`.toLowerCase();
            return searchTarget.includes(searchVal);
        }

        return true;
    });

    if (currentSort.column) {
        sortDataArray(displayData, currentSort.column, currentSort.direction);
    }

    renderTable(displayData);

    const counterBadge = document.getElementById('counter-badge');
    const sumBadge = document.getElementById('sum-badge');

    if (displayData.length > 0) {
        counterBadge.style.display = 'inline-block';
        document.getElementById('visible-count').textContent = displayData.length.toLocaleString();

        let totalSum = 0;
        displayData.forEach(item => {
            if (item.amount && item.amount !== '-') {
                const cleanNum = parseFloat(item.amount.replace(/,/g, ''));
                if (!isNaN(cleanNum)) totalSum += cleanNum;
            }
        });

        sumBadge.style.display = 'inline-block';
        document.getElementById('sum-amount').textContent = totalSum.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        document.getElementById('btn-copy-excel').disabled = false;
        document.getElementById('btn-export-excel').disabled = false;
    } else {
        counterBadge.style.display = 'none';
        sumBadge.style.display = 'none';
        document.getElementById('btn-copy-excel').disabled = true;
        document.getElementById('btn-export-excel').disabled = true;
    }
}

document.getElementById('global-search').addEventListener('input', (e) => {
    const val = e.target.value;
    document.getElementById('btn-clear-search').style.display = val ? 'block' : 'none';
    applyFilters();
});

document.getElementById('btn-clear-search').addEventListener('click', () => {
    document.getElementById('global-search').value = '';
    document.getElementById('btn-clear-search').style.display = 'none';
    applyFilters();
});

document.getElementById('select-filter-type').addEventListener('change', () => {
    applyFilters();
});

function sortDataArray(array, col, dir) {
    array.sort((a, b) => {
        let valA = a[col] ?? '';
        let valB = b[col] ?? '';

        if (col === 'amount') {
            valA = parseFloat(String(valA).replace(/,/g, '')) || 0;
            valB = parseFloat(String(valB).replace(/,/g, '')) || 0;
        } else {
            valA = String(valA).toLowerCase();
            valB = String(valB).toLowerCase();
        }

        if (valA < valB) return dir === 'asc' ? -1 : 1;
        if (valA > valB) return dir === 'asc' ? 1 : -1;
        return 0;
    });
}

document.querySelectorAll('#cn-table thead th[data-col]').forEach(th => {
    th.addEventListener('click', (e) => {
        if (e.target.classList.contains('resizer')) return;

        const col = th.getAttribute('data-col');
        if (col === 'row_no') return;

        if (currentSort.column === col) {
            currentSort.direction = (currentSort.direction === 'asc') ? 'desc' : 'asc';
        } else {
            currentSort.column = col;
            currentSort.direction = 'asc';
        }

        resetSortIcons('#cn-table');
        th.classList.add('sorted');
        const icon = th.querySelector('.sort-icon');
        if (icon) {
            icon.className = (currentSort.direction === 'asc') ? 'fa-solid fa-sort-up sort-icon' : 'fa-solid fa-sort-down sort-icon';
        }

        sortDataArray(displayData, currentSort.column, currentSort.direction);
        renderTable(displayData);
    });
});

document.getElementById('btn-copy-excel').addEventListener('click', async () => {
    if (displayData.length === 0) return;

    try {
        const headers = ['DOCNO', 'FILENAME', 'AMOUNT'];
        const lines = [headers.join('\t')];

        let html = '<style>table{border-collapse:collapse;border:none;} th,td{border:none;white-space:nowrap;padding:2px 6px;font-family:Calibri,sans-serif;font-size:11pt;} th{font-weight:normal;text-align:left;} td.txt{mso-number-format:"\\@";} td.cur{mso-number-format:"\\#\\,\\#\\#0\\.00";}</style>' +
            '<table><thead><tr><th>DOCNO</th><th>FILENAME</th><th>AMOUNT</th></tr></thead><tbody>';

        displayData.forEach(item => {
            lines.push([
                item.doc_no,
                item.filename,
                item.amount
            ].join('\t'));

            html += '<tr>' +
                `<td class="txt">${escapeHtml(item.doc_no)}</td>` +
                `<td class="txt">${escapeHtml(item.filename)}</td>` +
                `<td class="cur">${escapeHtml(item.amount)}</td>` +
                '</tr>';
        });

        html += '</tbody></table>';
        const tsv = lines.join('\r\n');

        await copyTableToClipboard(html, tsv);
        showToast(`คัดลอกข้อมูล ${displayData.length.toLocaleString()} รายการสำหรับ Excel แล้ว (ตัวเลขไม่เพี้ยนและแถวไม่ยืด)`);
    } catch (err) {
        console.error('Copy failed:', err);
        showToast('เกิดข้อผิดพลาดในการคัดลอก: ' + err.message);
    }
});

document.getElementById('btn-export-excel').addEventListener('click', () => {
    if (displayData.length === 0) return;
    if (typeof XLSX === 'undefined') {
        showToast('กำลังโหลดไลบรารี Excel กรุณาลองใหม่อีกครั้ง');
        return;
    }

    const headers = ['DOCNO', 'FILENAME', 'AMOUNT'];
    const ws = {};
    const range = { s: { c: 0, r: 0 }, e: { c: headers.length - 1, r: displayData.length } };

    headers.forEach((h, col) => {
        ws[XLSX.utils.encode_cell({ c: col, r: 0 })] = { t: 's', v: h };
    });

    displayData.forEach((item, rowIdx) => {
        const rIdx = rowIdx + 1;
        const docCell = { t: 's', v: String(item.doc_no ?? '').replace(/\r?\n|\r/g, ' ').trim() };
        const fileCell = { t: 's', v: String(item.filename ?? '').replace(/\r?\n|\r/g, ' ').trim() };

        let amtCell;
        const cleanAmt = item.amount && item.amount !== '-' ? parseFloat(String(item.amount).replace(/,/g, '')) : null;
        if (cleanAmt !== null && !isNaN(cleanAmt)) {
            amtCell = { t: 'n', v: cleanAmt, z: '#,##0.00' };
        } else {
            amtCell = { t: 's', v: String(item.amount ?? '-') };
        }

        ws[XLSX.utils.encode_cell({ c: 0, r: rIdx })] = docCell;
        ws[XLSX.utils.encode_cell({ c: 1, r: rIdx })] = fileCell;
        ws[XLSX.utils.encode_cell({ c: 2, r: rIdx })] = amtCell;
    });

    ws['!ref'] = XLSX.utils.encode_range(range);

    const colWidths = headers.map((h, colIdx) => {
        let maxLen = h.length;
        displayData.forEach(item => {
            const valStr = colIdx === 0 ? String(item.doc_no ?? '') :
                           colIdx === 1 ? String(item.filename ?? '') :
                           String(item.amount ?? '');
            if (valStr.length > maxLen) maxLen = valStr.length;
        });
        return { wch: Math.min(Math.max(maxLen + 4, 16), 75) };
    });
    ws['!cols'] = colWidths;

    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'CN Error');
    const dateStr = new Date().toISOString().slice(0, 10);
    XLSX.writeFile(wb, `read_cn_error01_${dateStr}.xlsx`);
    showToast(`ส่งออก Excel (.xlsx) ความกว้างพอดีและแถวไม่ยืดสำเร็จ (${displayData.length.toLocaleString()} รายการ)`);
});

window.addEventListener('DOMContentLoaded', () => {
    loadStats();
    initColumnResizers('#cn-table');
});
