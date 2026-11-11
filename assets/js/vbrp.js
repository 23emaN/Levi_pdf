const VBRP_BATCH_SIZE = 1;
const VBRP_NOTICE_LIST_MAX = 8;
const VBRP_INITIAL_RENDER_ROWS = 80;
const VBRP_CHUNK_RENDER_ROWS = 220;
const VBRP_PLACEHOLDER_ROWS = 18;

// What the toolbar shows in each phase of a run: idle -> scanning -> idle, or idle -> extracting (-> stopping) -> idle
const VBRP_PHASES = {
    idle: { start: 'ready', stop: 'hidden' },
    scanning: { start: 'busy', stop: 'hidden' },
    extracting: { start: 'hidden', stop: 'ready' },
    stopping: { start: 'hidden', stop: 'busy' }
};

const VBRP_NOTICE_TONES = {
    success: 'fa-solid fa-circle-check text-success',
    info: 'fa-solid fa-circle-info text-primary',
    warning: 'fa-solid fa-triangle-exclamation text-warning',
    error: 'fa-solid fa-circle-xmark text-danger'
};

// scan: the last job_vbrp_scan answer; progress: redraws the overlay of the run in progress
const vbrpRun = { phase: 'idle', scan: null, progress: null };

// all: every row of the Job in the order the server keeps them; shown: what the table lists after file filter, search and sort
const vbrpState = {
    all: [],
    shown: [],
    files: typeof VBRP_INITIAL_FILES !== 'undefined' ? VBRP_INITIAL_FILES : [],
    selectedFile: '',
    counts: { files: 0, rows: 0 },
    query: '',
    sort: { key: '#', direction: 'asc' },
    render: { token: 0, frame: null }
};

// ---------------------------------------------------------------- server

// FormData or an object of fields is POSTed, otherwise GET for this Job with the extra query fields
async function vbrpApi(action, body, query) {
    let res;
    if (body) {
        let fd = body;
        if (!(body instanceof FormData)) {
            fd = new FormData();
            Object.keys(body).forEach(key => fd.append(key, body[key]));
        }
        res = await fetch(`api.php?action=${action}`, { method: 'POST', body: fd });
    } else {
        const params = new URLSearchParams(Object.assign({ action, job_id: CURRENT_JOB_ID }, query || {}));
        res = await fetch(`api.php?${params}`);
    }
    let json;
    try {
        json = await res.json();
    } catch (e) {
        throw new Error(`เซิร์ฟเวอร์ตอบกลับไม่ใช่ JSON (HTTP ${res.status})`);
    }
    if (json.status !== 'success') throw new Error(json.message || 'เกิดข้อผิดพลาด');
    return json;
}

async function vbrpLoadRows() {
    const json = await vbrpApi('job_vbrp_rows');
    vbrpSetRows(json.data, json.counts, json.files);
}

// ---------------------------------------------------------------- notice

function vbrpNoticeSection(icon, title, items, note, unit = 'ไฟล์') {
    if (!items.length) return '';
    const shown = items.slice(0, VBRP_NOTICE_LIST_MAX).map(item => `<li>${item}</li>`).join('');
    const more = items.length > VBRP_NOTICE_LIST_MAX ? `<li class="text-muted">และอีก ${(items.length - VBRP_NOTICE_LIST_MAX).toLocaleString()} ${escapeHtml(unit)}</li>` : '';
    return `<section class="cn-notice-section">
        <div class="cn-notice-head"><i class="${icon}"></i>${escapeHtml(title)} <span class="tabular-num">${items.length.toLocaleString()}</span> ${escapeHtml(unit)}</div>
        <ul class="cn-notice-list">${shown}${more}</ul>
        ${note ? `<div class="cn-notice-note">${escapeHtml(note)}</div>` : ''}
    </section>`;
}

// detailHtml is built here from escaped parts only
function vbrpShowNotice({ tone, title, message, detailHtml = '', action = null }) {
    hideLoadingOverlay();
    document.getElementById('vbrpNoticeIcon').className = VBRP_NOTICE_TONES[tone];
    document.getElementById('vbrpNoticeTitle').textContent = title;
    document.getElementById('vbrpNoticeMessage').textContent = message;
    const detail = document.getElementById('vbrpNoticeDetail');
    detail.innerHTML = detailHtml;
    detail.classList.toggle('d-none', !detailHtml);
    const actionBtn = document.getElementById('vbrpNoticeAction');
    actionBtn.classList.toggle('d-none', !action);
    actionBtn.innerHTML = action ? action.label : '';
    actionBtn.onclick = action ? () => {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('vbrpNoticeModal')).hide();
        action.onClick();
    } : null;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('vbrpNoticeModal')).show();
}

// ---------------------------------------------------------------- run

function vbrpSetPhase(phase) {
    vbrpRun.phase = phase;
    const view = VBRP_PHASES[phase];
    const start = document.getElementById('vbrp-start');
    const stop = document.getElementById('vbrp-stop');
    const overlayStop = document.getElementById('loading-overlay-stop-btn');

    start.style.display = view.start === 'hidden' ? 'none' : '';
    start.disabled = view.start === 'busy';
    start.innerHTML = view.start === 'busy'
        ? '<span class="spinner-border spinner-border-sm me-1"></span> ตรวจสอบไฟล์...'
        : '<i class="fa-solid fa-play me-1"></i> ดึงข้อมูลจากโฟลเดอร์ invoice/vbrp';

    stop.style.display = view.stop === 'hidden' ? 'none' : '';
    stop.disabled = view.stop === 'busy';
    stop.innerHTML = view.stop === 'busy'
        ? '<span class="spinner-border spinner-border-sm me-1"></span> กำลังหยุด...'
        : '<i class="fa-solid fa-stop me-1"></i> หยุด';
    if (overlayStop) overlayStop.disabled = view.stop === 'busy';
}

function vbrpSetUploadCount(count) {
    document.getElementById('vbrp-upload-count').textContent = Number(count || 0).toLocaleString();
    document.getElementById('vbrp-section').dataset.step = count > 0 ? 'extract' : 'idle';
}

async function vbrpStartExtraction() {
    if (vbrpRun.phase !== 'idle') return;
    vbrpSetPhase('scanning');
    let scan;
    try {
        scan = (await vbrpApi('job_vbrp_scan')).data;
    } catch (err) {
        vbrpSetPhase('idle');
        vbrpShowNotice({ tone: 'error', title: 'เกิดข้อผิดพลาด', message: 'ตรวจสอบไฟล์ในโฟลเดอร์ invoice/vbrp ไม่สำเร็จ: ' + err.message });
        return;
    }
    vbrpSetPhase('idle');
    vbrpRun.scan = scan;
    vbrpSetUploadCount(scan.new_count);

    if (scan.total_in_upload === 0) {
        vbrpShowNotice({
            tone: 'warning',
            title: 'ไม่พบไฟล์ Excel ในโฟลเดอร์ invoice/vbrp',
            message: 'ยังไม่มีไฟล์ VBRP สำหรับดึงข้อมูล',
            detailHtml: `<div class="cn-notice-note">วางไฟล์ Excel (.xlsx) ไว้ที่ <span class="tabular-num">${escapeHtml(scan.upload_dir)}</span> แล้วกด "ดึงข้อมูลจากโฟลเดอร์ invoice/vbrp" อีกครั้ง</div>`,
            action: { label: '<i class="fa-regular fa-folder-open me-1"></i> เปิดโฟลเดอร์ invoice/vbrp', onClick: () => openJobFolder('vbrp_upload') }
        });
    } else {
        const files = [...(scan.new_files || []), ...(scan.existing_files || [])];
        await vbrpRunFiles(files, true);
    }
}

async function vbrpRunFiles(files, replace) {
    if (vbrpRun.phase !== 'idle') return;
    const scan = vbrpRun.scan;
    const result = await vbrpExtractFiles(files.map(f => f.rel_path), replace);
    vbrpSetUploadCount(Math.max(0, scan.new_count - result.processed));
    vbrpShowResult(result, replace ? [] : scan.existing_files);
}

// The loop of a run, one file a request until done or stopped, then the table reloads. Returns the counts
// vbrpShowResult reports.
async function vbrpExtractFiles(paths, replace) {
    const total = paths.length;
    const result = { total, done: 0, processed: 0, rows: 0, noData: [], failed: [], replaced: [], replacedInvoices: [], replacedRows: 0, stopped: false };

    vbrpSetPhase('extracting');
    showLoadingOverlay('กำลังดึงข้อมูลจากไฟล์ VBRP...', `เตรียมดึงข้อมูลทั้งหมด ${total.toLocaleString()} ไฟล์`, true, true);
    const progress = () => updateLoadingOverlay(
        vbrpRun.phase === 'stopping'
            ? 'จะหยุดเมื่อไฟล์ที่กำลังอ่านเสร็จ'
            : `กำลังอ่านไฟล์ ${Math.min(result.done + 1, total).toLocaleString()} จาก ${total.toLocaleString()} ไฟล์ (ไฟล์ใหญ่ใช้เวลาสักครู่)`,
        `${result.done.toLocaleString()} / ${total.toLocaleString()} ไฟล์ (${result.rows.toLocaleString()} รายการ)`,
        Math.round(result.done / total * 100)
    );
    vbrpRun.progress = progress;
    progress();

    for (let i = 0; i < total; i += VBRP_BATCH_SIZE) {
        if (vbrpRun.phase === 'stopping') {
            result.stopped = true;
            break;
        }
        const chunk = paths.slice(i, i + VBRP_BATCH_SIZE);
        try {
            const json = await vbrpApi('job_vbrp_extract_batch', {
                job_id: CURRENT_JOB_ID,
                files: JSON.stringify(chunk),
                replace_existing: replace ? 1 : 0
            });
            result.processed += Number(json.processed_files || 0);
            result.rows += Number(json.rows_count || 0);
            result.noData.push(...(json.no_data || []));
            result.failed.push(...(json.failed || []));
            result.replaced.push(...(json.replaced_files || []));
            result.replacedInvoices.push(...(json.replaced_invoices || []));
            result.replacedRows += Number(json.replaced_rows || 0);
        } catch (err) {
            chunk.forEach(relPath => result.failed.push({ filename: relPath.split('/').pop(), message: err.message }));
        }
        result.done += chunk.length;
        progress();
    }
    vbrpRun.progress = null;

    try {
        await vbrpLoadRows();
    } catch (err) {
        result.failed.push({ filename: '-', message: 'โหลดตารางใหม่ไม่สำเร็จ: ' + err.message });
    }
    vbrpSetPhase('idle');
    return result;
}

function vbrpRequestStop() {
    if (vbrpRun.phase !== 'extracting') return;
    vbrpSetPhase('stopping');
    if (vbrpRun.progress) vbrpRun.progress();
}

// leftExisting: files of invoice/vbrp that were not read because their name was read before
function vbrpShowResult(result, leftExisting) {
    const uniqueInvoices = Array.from(new Set(result.replacedInvoices || []));
    const uniqueReplacedFiles = Array.from(new Set(result.replaced || []));
    let message = `ดึงข้อมูล ${result.processed.toLocaleString()} ไฟล์ ได้ ${result.rows.toLocaleString()} รายการ`;
    if (uniqueInvoices.length > 0) {
        message += ` (ลบข้อมูลเก่าและแทนที่ ${result.replacedRows.toLocaleString()} รายการ จาก ${uniqueInvoices.length.toLocaleString()} เลข Invoice ที่ซ้ำกันในคอลัมน์ C)`;
    }
    if (result.stopped) message += ` หยุดก่อนครบ ยังไม่ได้อ่าน ${(result.total - result.done).toLocaleString()} ไฟล์`;

    const stays = 'ไฟล์เหล่านี้ไม่ถูกอ่าน';
    const detailHtml = vbrpNoticeSection('fa-solid fa-arrows-rotate text-primary', 'เลข Invoice (คอลัมน์ C) ที่ลบข้อมูลเก่าและแทนที่ด้วยข้อมูลใหม่', uniqueInvoices.map(inv => `<span class="tabular-num">${escapeHtml(inv)}</span>`), '', 'เลข Invoice')
        + vbrpNoticeSection('fa-solid fa-file-Excel text-primary', 'ไฟล์เดิมที่ข้อมูลถูกแทนที่ทั้งหมด', uniqueReplacedFiles.map(name => escapeHtml(name)), '')
        + vbrpNoticeSection('fa-solid fa-file-circle-check text-warning', 'ข้ามเพราะเคยดึงข้อมูลแล้ว', leftExisting.map(f => escapeHtml(f.filename)), stays)
        + vbrpNoticeSection('fa-solid fa-file-circle-question text-secondary', `ไม่มีข้อมูลตั้งแต่แถวที่ 4 ในคอลัมน์ที่อ่าน`, result.noData.map(name => escapeHtml(name)), stays)
        + vbrpNoticeSection('fa-solid fa-circle-exclamation text-danger', 'อ่านไม่สำเร็จ', result.failed.map(f => `${escapeHtml(f.filename)} <span class="text-muted">${escapeHtml(f.message)}</span>`), stays);

    vbrpShowNotice({
        tone: result.failed.length > 0 ? 'error' : (result.stopped || leftExisting.length > 0 || result.noData.length > 0 ? 'warning' : 'success'),
        title: result.stopped ? 'หยุดดึงข้อมูลแล้ว' : 'ดึงข้อมูลเสร็จแล้ว',
        message,
        detailHtml,
        action: leftExisting.length > 0
            ? { label: '<i class="fa-solid fa-arrows-rotate me-1"></i> ดึงไฟล์เดิมซ้ำและแทนที่', onClick: () => vbrpRunFiles(leftExisting, true) }
            : null
    });
}

// ---------------------------------------------------------------- upload

// Sends the chosen Excel files to upload/{Job}/invoice/vbrp; reading them is the button's job, as on the other pages
async function vbrpUploadFiles(fileList) {
    const files = Array.from(fileList || []);
    if (files.length === 0) return;
    const zone = document.getElementById('vbrp-dropzone');
    zone.classList.add('is-busy');
    showLoadingOverlay('กำลังอัปโหลดไฟล์ VBRP...', `ส่ง ${files.length.toLocaleString()} ไฟล์ไปที่โฟลเดอร์ invoice/vbrp`);
    try {
        const fd = new FormData();
        fd.append('job_id', CURRENT_JOB_ID);
        files.forEach(file => fd.append('files[]', file));
        const json = await vbrpApi('job_vbrp_upload', fd);
        const scan = (await vbrpApi('job_vbrp_scan')).data;
        vbrpRun.scan = scan;
        vbrpSetUploadCount(scan.new_count);
        vbrpShowNotice({
            tone: json.rejected.length > 0 ? (json.saved.length > 0 ? 'warning' : 'error') : 'success',
            title: json.saved.length > 0 ? 'อัปโหลดไฟล์แล้ว' : 'อัปโหลดไม่สำเร็จ',
            message: `เก็บไฟล์ ${json.saved.length.toLocaleString()} ไฟล์ไว้ในโฟลเดอร์ invoice/vbrp${json.saved.length > 0 ? ' กด "ดึงข้อมูลจากโฟลเดอร์ invoice/vbrp" เพื่ออ่านข้อมูล' : ''}`,
            detailHtml: vbrpNoticeSection('fa-solid fa-circle-exclamation text-danger', 'อัปโหลดไม่ได้', json.rejected.map(f => `${escapeHtml(f.filename)} <span class="text-muted">${escapeHtml(f.message)}</span>`), ''),
            action: json.saved.length > 0 ? { label: '<i class="fa-solid fa-play me-1"></i> ดึงข้อมูลเลย', onClick: vbrpStartExtraction } : null
        });
    } catch (err) {
        vbrpShowNotice({ tone: 'error', title: 'เกิดข้อผิดพลาด', message: 'อัปโหลดไฟล์ไม่สำเร็จ: ' + err.message });
    } finally {
        zone.classList.remove('is-busy');
        document.getElementById('vbrp-file-input').value = '';
    }
}

function vbrpInitUpload() {
    const zone = document.getElementById('vbrp-dropzone');
    const input = document.getElementById('vbrp-file-input');
    if (!zone || !input) return;
    zone.addEventListener('click', () => input.click());
    zone.addEventListener('keydown', e => {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        e.preventDefault();
        input.click();
    });
    input.addEventListener('change', () => vbrpUploadFiles(input.files));
    ['dragenter', 'dragover'].forEach(name => zone.addEventListener(name, e => {
        e.preventDefault();
        zone.classList.add('is-dragover');
    }));
    ['dragleave', 'drop'].forEach(name => zone.addEventListener(name, e => {
        e.preventDefault();
        zone.classList.remove('is-dragover');
    }));
    zone.addEventListener('drop', e => vbrpUploadFiles(e.dataTransfer ? e.dataTransfer.files : []));
}

// ---------------------------------------------------------------- table

function vbrpIsBlank(value) {
    return value === null || value === undefined || value === '';
}

function vbrpTextCell(value, className, title) {
    if (vbrpIsBlank(value)) return `<td class="${className} text-null-subtle">–</td>`;
    const text = escapeHtml(value);
    return `<td class="${className}" title="${title ? escapeHtml(title) : text}">${text}</td>`;
}

function vbrpMoneyCell(value) {
    if (vbrpIsBlank(value)) return '<td class="text-end tabular-num text-null-subtle">–</td>';
    return `<td class="text-end tabular-num">${formatMoney(value)}</td>`;
}

function vbrpQuantityCell(value) {
    if (vbrpIsBlank(value)) return '<td class="text-center tabular-num text-null-subtle">–</td>';
    return `<td class="text-center tabular-num">${Number(value).toLocaleString(undefined, { maximumFractionDigits: 3 })}</td>`;
}

// One cell per column key, in the order of VBRP_COLUMNS (vbrp.php)
const VBRP_CELLS = {
    '#': (row, rowNum) => `<td class="text-center fw-medium text-secondary tabular-num">${rowNum}</td>`,
    combine: row => vbrpTextCell(row.combine, 'tabular-num', ''),
    invoice: row => vbrpTextCell(row.invoice, 'tabular-num fw-semibold text-dark', ''),
    delivery_no: row => vbrpTextCell(row.delivery_no, 'tabular-num', ''),
    quantity: row => vbrpQuantityCell(row.quantity),
    selling_price: row => vbrpMoneyCell(row.selling_price),
    total_cost_ex_vat: row => vbrpMoneyCell(row.total_cost_ex_vat),
    ean_upc: row => vbrpTextCell(row.ean_upc, 'tabular-num', ''),
    total_vat: row => vbrpMoneyCell(row.total_vat),
    sheet: row => vbrpTextCell(row.sheet, '', `${row.filename} / ${row.sheet} (แถวที่ ${row.row_no})`)
};

function vbrpRowHtml(row, rowNum) {
    return `<tr>${VBRP_COLUMNS.map(col => VBRP_CELLS[col.key](row, rowNum)).join('')}</tr>`;
}

// What the search box matches for an amount: as shown (1,234.50) and as typed without the comma (1234.50)
function vbrpSearchText(row) {
    return VBRP_COLUMNS.filter(col => col.key !== '#').map(col => {
        const value = row[col.key];
        if (vbrpIsBlank(value)) return '';
        if (col.type === 'currency') return `${formatMoney(value)} ${Number(value).toFixed(2)}`;
        return String(value);
    }).filter(Boolean).join(' ').toLowerCase();
}

function vbrpSortValue(col, row) {
    if (col.key === '#') return row._order;
    const value = row[col.key];
    if (vbrpIsBlank(value)) return null;
    return col.type === 'currency' || col.type === 'number' ? Number(value) : String(value);
}

// Missing values go last in both directions; ties keep the server's order (_order)
function vbrpComparator(sort) {
    const col = VBRP_COLUMNS.find(c => c.key === sort.key);
    const sign = sort.direction === 'desc' ? -1 : 1;
    return (a, b) => {
        const va = vbrpSortValue(col, a);
        const vb = vbrpSortValue(col, b);
        if (va === null || vb === null) {
            if (va !== vb) return va === null ? 1 : -1;
            return a._order - b._order;
        }
        const diff = typeof va === 'number' ? va - vb : thCollator.compare(va, vb);
        return diff !== 0 ? sign * diff : a._order - b._order;
    };
}

function vbrpVisibleRows(all, selectedFile, query, sort) {
    const byFile = selectedFile ? all.filter(row => row.filename === selectedFile) : all;
    const words = String(query || '').toLowerCase().split(/\s+/).filter(Boolean);
    const rows = words.length ? byFile.filter(row => words.every(word => row._search.includes(word))) : byFile.slice();
    return rows.sort(vbrpComparator(sort));
}

function vbrpRenderFinishedMenu() {
    const countEl = document.getElementById('vbrp-finished-count');
    const labelEl = document.getElementById('vbrp-finished-label');
    const btnEl = document.getElementById('vbrp-open-finished');
    const menuEl = document.getElementById('vbrp-finished-menu');
    const files = vbrpState.files || [];
    const selected = vbrpState.selectedFile || '';

    if (countEl) countEl.textContent = files.length.toLocaleString();
    if (labelEl) labelEl.textContent = selected ? `finished: ${selected}` : 'finished';
    if (btnEl) {
        btnEl.classList.toggle('btn-tb-accent', Boolean(selected));
        btnEl.title = selected ? `กำลังแสดงไฟล์: ${selected} (คลิกเพื่อเปลี่ยนไฟล์)` : 'ดูรายการไฟล์ที่อัปโหลดผ่านแล้วใน VBRP/finished';
    }
    if (!menuEl) return;

    if (files.length === 0) {
        menuEl.innerHTML = '<li><span class="dropdown-item-text text-muted small py-2"><i class="fa-regular fa-folder-open me-2"></i>ยังไม่มีไฟล์ที่อัปโหลดผ่านใน finished</span></li>'
            + '<li><hr class="dropdown-divider my-1"></li>'
            + '<li><button type="button" class="dropdown-item d-flex align-items-center text-primary" data-vbrp-open-folder="1"><i class="fa-regular fa-folder-open me-2"></i>เปิดโฟลเดอร์ finished ใน Explorer</button></li>';
        return;
    }

    const totalRows = vbrpState.all.length;
    const itemsHtml = files.map(file => {
        const name = String(file.filename || '');
        const escName = escapeHtml(name);
        const active = selected === name ? ' active' : '';
        const rows = Number(file.row_count || 0).toLocaleString();
        return `<li><button type="button" class="dropdown-item d-flex align-items-center justify-content-between${active}" data-vbrp-file="${escName}" title="${escName}">`
            + `<span class="text-truncate vbrp-finished-item-name"><i class="fa-regular fa-file-excel text-success me-2"></i>${escName}</span>`
            + `<span class="badge rounded-pill text-bg-light tabular-num ms-3">${rows} รายการ</span>`
            + `</button></li>`;
    }).join('');

    menuEl.innerHTML = `<li><button type="button" class="dropdown-item d-flex align-items-center justify-content-between${selected === '' ? ' active' : ''}" data-vbrp-file="">`
        + `<span><i class="fa-solid fa-layer-group me-2 text-primary"></i>แสดงทุกไฟล์รวมกัน</span>`
        + `<span class="badge rounded-pill text-bg-light tabular-num ms-3">${totalRows.toLocaleString()} รายการ</span>`
        + `</button></li>`
        + `<li><hr class="dropdown-divider my-1"></li>`
        + `<li><h6 class="dropdown-header py-1 text-muted">ไฟล์ใน finished (${files.length.toLocaleString()} ไฟล์)</h6></li>`
        + itemsHtml
        + `<li><hr class="dropdown-divider my-1"></li>`
        + `<li><button type="button" class="dropdown-item d-flex align-items-center text-primary" data-vbrp-open-folder="1"><i class="fa-regular fa-folder-open me-2"></i>เปิดโฟลเดอร์ finished ใน Explorer</button></li>`;
}

function vbrpSelectFile(filename) {
    vbrpState.selectedFile = String(filename || '');
    vbrpRenderFinishedMenu();
    vbrpApplyView();
}

function vbrpWireFinishedMenu() {
    const menuEl = document.getElementById('vbrp-finished-menu');
    if (!menuEl) return;
    menuEl.addEventListener('click', e => {
        const openBtn = e.target.closest('[data-vbrp-open-folder]');
        if (openBtn) {
            openJobFolder('vbrp_finished');
            return;
        }
        const fileBtn = e.target.closest('[data-vbrp-file]');
        if (fileBtn) {
            vbrpSelectFile(fileBtn.getAttribute('data-vbrp-file') || '');
        }
    });
}

function vbrpPlaceholderHtml(columnCount) {
    const emptyCells = '<td></td>'.repeat(columnCount - 1);
    let html = '';
    for (let i = 1; i <= VBRP_PLACEHOLDER_ROWS; i++) {
        html += `<tr class="placeholder-grid-row"><td class="text-center text-muted tabular-num opacity-40">${i}</td>${emptyCells}</tr>`;
    }
    return html;
}

function vbrpCancelRender() {
    const render = vbrpState.render;
    render.token++;
    if (render.frame !== null) {
        cancelAnimationFrame(render.frame);
        render.frame = null;
    }
}

// The first VBRP_INITIAL_RENDER_ROWS at once, the rest one animation frame per VBRP_CHUNK_RENDER_ROWS, so a long list
// never blocks the page
function vbrpAppendRows(tbody, rows) {
    const render = vbrpState.render;
    const token = render.token;
    const firstEnd = Math.min(rows.length, VBRP_INITIAL_RENDER_ROWS);
    tbody.insertAdjacentHTML('beforeend', rows.slice(0, firstEnd).map((row, i) => vbrpRowHtml(row, i + 1)).join(''));

    let cursor = firstEnd;
    const pump = () => {
        if (token !== render.token) return;
        const end = Math.min(cursor + VBRP_CHUNK_RENDER_ROWS, rows.length);
        const parts = [];
        for (let i = cursor; i < end; i++) parts.push(vbrpRowHtml(rows[i], i + 1));
        tbody.insertAdjacentHTML('beforeend', parts.join(''));
        cursor = end;
        render.frame = cursor < rows.length ? requestAnimationFrame(pump) : null;
    };
    if (cursor < rows.length) render.frame = requestAnimationFrame(pump);
}

function vbrpRenderTable() {
    vbrpCancelRender();
    vbrpRenderStatus();
    const tbody = document.getElementById('vbrp-tbody');
    const rows = vbrpState.shown;
    if (rows.length === 0) {
        tbody.innerHTML = vbrpState.all.length === 0
            ? vbrpPlaceholderHtml(VBRP_COLUMNS.length)
            : `<tr><td colspan="${VBRP_COLUMNS.length}" class="text-center py-5 text-muted"><i class="fa-solid fa-magnifying-glass me-2"></i>ไม่พบข้อมูลที่ตรงกับคำค้นหา</td></tr>`;
        return;
    }
    tbody.innerHTML = '';
    vbrpAppendRows(tbody, rows);
}

function vbrpRenderStatus() {
    document.getElementById('vbrp-files-count').textContent = vbrpState.counts.files.toLocaleString();
    document.getElementById('vbrp-rows-count').textContent = vbrpState.shown.length.toLocaleString();
    document.getElementById('vbrp-export').disabled = vbrpState.shown.length === 0;
}

function vbrpApplyView() {
    vbrpState.shown = vbrpVisibleRows(vbrpState.all, vbrpState.selectedFile, vbrpState.query, vbrpState.sort);
    vbrpRenderTable();
}

function vbrpSetRows(rows, counts, files) {
    vbrpState.all = (rows || []).map((row, i) => {
        row._order = i;
        row._search = vbrpSearchText(row);
        return row;
    });
    vbrpState.counts = counts || { files: 0, rows: 0 };
    if (Array.isArray(files)) {
        vbrpState.files = files;
    } else {
        const byName = new Map();
        vbrpState.all.forEach(r => {
            byName.set(r.filename, (byName.get(r.filename) || 0) + 1);
        });
        vbrpState.files = Array.from(byName.entries()).map(([filename, row_count], idx) => ({ id: idx + 1, filename, row_count }));
    }
    if (vbrpState.selectedFile && !vbrpState.files.some(f => f.filename === vbrpState.selectedFile)) {
        vbrpState.selectedFile = '';
    }
    vbrpRenderFinishedMenu();
    vbrpApplyView();
}

function vbrpSortBy(th) {
    const sort = vbrpState.sort;
    sort.direction = sort.key === th.dataset.col && sort.direction === 'asc' ? 'desc' : 'asc';
    sort.key = th.dataset.col;
    resetSortIcons('#vbrp-table');
    th.classList.add('sorted');
    const icon = th.querySelector('.sort-icon');
    if (icon) icon.className = `fa-solid fa-sort-${sort.direction === 'asc' ? 'up' : 'down'} sort-icon`;
    vbrpApplyView();
}

// ---------------------------------------------------------------- excel, clear

// Amounts are numbers and a missing value is an empty cell, never a dash or a zero
function vbrpExcelRows(rows) {
    return rows.map((row, i) => {
        const out = {};
        VBRP_COLUMNS.forEach(col => {
            if (col.key === '#') out[col.label] = i + 1;
            else if (col.type === 'currency' || col.type === 'number') out[col.label] = vbrpIsBlank(row[col.key]) ? null : Number(row[col.key]);
            else out[col.label] = vbrpIsBlank(row[col.key]) ? null : String(row[col.key]);
        });
        return out;
    });
}

async function vbrpExportExcel(rows) {
    if (!rows || rows.length === 0) return;
    await ensureXlsxLoaded();
    const labels = VBRP_COLUMNS.map(col => col.label);
    const moneyLabels = VBRP_COLUMNS.filter(col => col.type === 'currency').map(col => col.label);
    const sheetRows = vbrpExcelRows(rows);
    const ws = XLSX.utils.json_to_sheet(sheetRows, { header: labels });
    labels.forEach((label, c) => {
        if (!moneyLabels.includes(label)) return;
        for (let r = 1; r <= sheetRows.length; r++) {
            const cell = ws[XLSX.utils.encode_cell({ r, c })];
            if (cell) cell.z = '#,##0.00';
        }
    });
    ws['!cols'] = labels.map(label => {
        let maxLen = label.length;
        sheetRows.forEach(row => {
            const len = row[label] === null || row[label] === undefined ? 0 : String(row[label]).length;
            if (len > maxLen) maxLen = Math.min(len, 50);
        });
        return { wch: maxLen + 3 };
    });

    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'VBRP');
    XLSX.writeFile(wb, `Job_${CURRENT_JOB_ID}_${VBRP_JOB_NAME_CLEAN}_VBRP.xlsx`);
}

function vbrpOpenClear() {
    const counts = vbrpState.counts;
    if (counts.rows === 0 && counts.files === 0) {
        vbrpShowNotice({ tone: 'info', title: 'ล้างข้อมูล VBRP', message: 'Job นี้ยังไม่มีข้อมูล VBRP' });
        return;
    }
    document.getElementById('vbrpClearRows').textContent = counts.rows.toLocaleString();
    document.getElementById('vbrpClearFiles').textContent = counts.files.toLocaleString();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('vbrpClearModal')).show();
}

async function vbrpConfirmClear() {
    const btn = document.getElementById('vbrpClearConfirm');
    btn.disabled = true;
    try {
        const json = await vbrpApi('job_vbrp_clear', { job_id: CURRENT_JOB_ID });
        bootstrap.Modal.getOrCreateInstance(document.getElementById('vbrpClearModal')).hide();
        vbrpSetRows([], { files: 0, rows: 0 }, []);
        vbrpShowNotice({
            tone: 'success',
            title: 'ล้างข้อมูล VBRP แล้ว',
            message: `ลบ ${Number(json.deleted_rows || 0).toLocaleString()} รายการ จาก ${Number(json.deleted_files || 0).toLocaleString()} ไฟล์`,
            detailHtml: '<div class="cn-notice-note">ไฟล์ต้นฉบับยังอยู่ในโฟลเดอร์ invoice/vbrp ถ้าต้องการดึงข้อมูลใหม่ กดดึงข้อมูลอีกครั้ง</div>'
        });
    } catch (err) {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('vbrpClearModal')).hide();
        vbrpShowNotice({ tone: 'error', title: 'เกิดข้อผิดพลาด', message: 'ล้างข้อมูล VBRP ไม่สำเร็จ: ' + err.message });
    } finally {
        btn.disabled = false;
    }
}

// ---------------------------------------------------------------- start

function vbrpWireSearch() {
    const input = document.getElementById('vbrp-search');
    const clear = document.getElementById('vbrp-clear-search');
    const apply = query => {
        vbrpState.query = query;
        vbrpApplyView();
    };
    const debounced = debounce(() => apply(input.value), 100);
    input.addEventListener('input', () => {
        clear.style.display = input.value ? 'block' : 'none';
        debounced();
    });
    clear.addEventListener('click', () => {
        input.value = '';
        clear.style.display = 'none';
        apply('');
        input.focus();
    });
}

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('vbrp-start').addEventListener('click', vbrpStartExtraction);
    document.getElementById('vbrp-stop').addEventListener('click', vbrpRequestStop);
    document.getElementById('loading-overlay-stop-btn').addEventListener('click', vbrpRequestStop);
    document.getElementById('vbrp-export').addEventListener('click', () => vbrpExportExcel(vbrpState.shown));
    document.getElementById('vbrp-clear').addEventListener('click', vbrpOpenClear);
    document.getElementById('vbrpClearConfirm').addEventListener('click', vbrpConfirmClear);
    vbrpWireSearch();
    vbrpWireFinishedMenu();
    vbrpInitUpload();
    document.querySelectorAll('#vbrp-table thead th').forEach(th => {
        th.addEventListener('click', e => {
            if (e.target.classList.contains('resizer')) return;
            vbrpSortBy(th);
        });
    });
    initColumnResizers('#vbrp-table');

    runWithLoading('กำลังโหลดข้อมูล VBRP...', 'กรุณารอสักครู่ ระบบกำลังดึงข้อมูลจากฐานข้อมูล', () => vbrpLoadRows().catch(err => {
        document.getElementById('vbrp-tbody').innerHTML = `<tr><td colspan="${VBRP_COLUMNS.length}" class="text-center text-danger py-4">เกิดข้อผิดพลาดในการโหลดข้อมูล: ${escapeHtml(err.message)}</td></tr>`;
    }));
});
