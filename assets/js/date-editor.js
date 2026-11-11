// แก้ไขวันที่ PDF: the bills sit in the fixed folder date_edit/upload here/ (sub folders allowed). The page reads that
// folder, then asks api.php to change one file per request into date_edit/finished/ (same sub folders and names).
const DE_WORKERS = 3;
const DE_POLL_MS = 3000;

const de = {
    files: [],        // [{ path, sub, name, status, pages, oldDates, newDates, message }]
    counts: { upload: 0, finished: 0 },
    running: false,
    stopRequested: false,
    scanning: false,
    rescan: false,
    clearTarget: '',
};

let deClearModal = null;

document.addEventListener('DOMContentLoaded', () => {
    deClearModal = new bootstrap.Modal(document.getElementById('deClearModal'));
    document.getElementById('de-scan').addEventListener('click', () => scanFolder());
    document.getElementById('de-date').addEventListener('input', renderDatePreview);
    document.getElementById('de-start').addEventListener('click', startRun);
    document.getElementById('de-stop').addEventListener('click', () => {
        de.stopRequested = true;
        document.getElementById('de-stop').disabled = true;
    });
    document.querySelectorAll('[data-de-open]').forEach(btn => {
        btn.addEventListener('click', () => openDateEditFolder(btn.dataset.deOpen));
    });
    document.querySelectorAll('[data-de-clear]').forEach(btn => {
        btn.addEventListener('click', () => askClear(btn.dataset.deClear));
    });
    document.getElementById('deClearConfirm').addEventListener('click', executeClear);
    const zip = document.getElementById('de-zip');
    if (zip) {
        zip.addEventListener('click', event => {
            if (de.running || !de.counts.finished) event.preventDefault();
        });
    }
    // Files dropped into the folder in Explorer show up by themselves, also with Explorer beside the page
    setInterval(() => {
        if (!document.hidden) scanFolder(true);
    }, DE_POLL_MS);
    window.addEventListener('focus', () => {
        if (!de.running) scanFolder(true);
    });
    window.addEventListener('beforeunload', event => {
        if (!de.running) return;
        event.preventDefault();
        event.returnValue = '';
    });
    renderDatePreview();
    scanFolder();
});

async function apiGet(action) {
    const res = await fetch(`api.php?action=${action}`);
    return readJson(res);
}

async function apiPost(action, fields) {
    const fd = new FormData();
    Object.keys(fields).forEach(k => fd.append(k, fields[k]));
    const res = await fetch(`api.php?action=${action}`, { method: 'POST', body: fd });
    return readJson(res);
}

async function readJson(res) {
    let json;
    try {
        json = await res.json();
    } catch (e) {
        throw new Error(`เซิร์ฟเวอร์ตอบไม่ใช่ JSON (HTTP ${res.status})`);
    }
    if (json.status !== 'success') throw new Error(json.message || 'เกิดข้อผิดพลาด');
    return json;
}

/**
 * Read upload here again. A quiet scan (the timer, window focus) keeps the results of the files still in the folder.
 */
async function scanFolder(quiet = false) {
    if (de.running) return;
    if (de.scanning) {
        // A full scan asked for during a quiet one (a click, a clear) runs right after it
        if (!quiet) de.rescan = true;
        return;
    }
    de.scanning = true;
    const btn = document.getElementById('de-scan');
    if (!quiet) btn.disabled = true;
    try {
        const json = await apiGet('date_edit_scan');
        // A run started while this answer was on its way, and its workers go through de.files by index
        if (de.running) return;
        const data = json.data;
        setCounts(data.upload_count, data.finished_count);
        const same = de.files.length === data.files.length && de.files.every((f, i) => f.path === data.files[i]);
        if (!(quiet && same)) {
            const known = new Map(quiet ? de.files.map(f => [f.path, f]) : []);
            de.files = data.files.map(path => {
                if (known.has(path)) return known.get(path);
                const parts = path.split('/');
                return {
                    path,
                    sub: parts.slice(0, -1).join(' / '),
                    name: parts[parts.length - 1],
                    status: 'wait',
                    pages: null,
                    oldDates: [],
                    newDates: [],
                    message: '',
                };
            });
            document.getElementById('de-progress-wrap').classList.add('d-none');
            renderTable();
        }
        document.getElementById('de-scan-info').textContent = `อัปเดตล่าสุด ${new Date().toLocaleTimeString('th-TH')}`;
    } catch (err) {
        if (!quiet) {
            document.getElementById('de-tbody').innerHTML = `<tr><td colspan="7" class="text-center py-5 text-danger">โหลดรายการไฟล์ไม่สำเร็จ: ${escapeHtml(err.message)}</td></tr>`;
        }
    } finally {
        de.scanning = false;
        btn.disabled = de.running;
        renderButtons();
        if (de.rescan) {
            de.rescan = false;
            scanFolder();
        }
    }
}

function setCounts(upload, finished) {
    de.counts.upload = upload;
    de.counts.finished = finished;
    document.getElementById('de-upload-count').textContent = Number(upload).toLocaleString();
    document.getElementById('de-finished-count').textContent = Number(finished).toLocaleString();
}

function pickedDate() {
    const value = document.getElementById('de-date').value;
    return /^\d{4}-\d{2}-\d{2}$/.test(value) ? value : '';
}

function formatPicked(value, separator) {
    const [y, m, d] = value.split('-');
    return [d, m, y].join(separator);
}

function renderDatePreview() {
    const value = pickedDate();
    document.getElementById('de-date-preview').innerHTML = value
        ? `จะแสดงเป็น <strong class="tabular-num">${formatPicked(value, '-')}</strong> หรือ <strong class="tabular-num">${formatPicked(value, '.')}</strong> ตามรูปแบบเดิมของแต่ละไฟล์`
        : 'กรุณาเลือกวันที่ก่อนเริ่มแก้';
    renderButtons();
}

function renderButtons() {
    document.getElementById('de-start').disabled = de.running || !de.files.length || !pickedDate();
    document.getElementById('de-start-label').textContent = de.files.length
        ? `เริ่มแก้วันที่ (${de.files.length.toLocaleString()} ไฟล์)`
        : 'เริ่มแก้วันที่';
    document.getElementById('de-start').title = !de.files.length
        ? 'ยังไม่มีไฟล์ ใส่ไฟล์ในขั้นที่ 1 ก่อน'
        : (!pickedDate() ? 'เลือกวันที่ในขั้นที่ 2 ก่อน' : '');
    document.getElementById('de-scan').disabled = de.running || de.scanning;
    document.getElementById('de-date').disabled = de.running;
    document.getElementById('de-stop').classList.toggle('d-none', !de.running);
    document.querySelectorAll('[data-de-clear]').forEach(btn => {
        btn.disabled = de.running || !de.counts[btn.dataset.deClear];
    });
    const zip = document.getElementById('de-zip');
    if (zip) zip.classList.toggle('disabled', de.running || !de.counts.finished);
    // The date preview of step 2 already asks for a date, so only a missing file needs saying here
    document.getElementById('de-start-hint').textContent = !de.running && !de.files.length
        ? 'ยังไม่มีไฟล์ ใส่ไฟล์ในขั้นที่ 1 ก่อน'
        : '';
    renderSteps();
}

/**
 * Which step the user is on, worked out from the files, the date and the results. Step 3 needs a finished file, so
 * clearing that folder puts the user back on step 2 although the results stay in the table.
 */
function currentStep() {
    if (!de.files.length) return 1;
    const hasResult = de.files.some(f => f.status === 'ok' || f.status === 'warn');
    const waiting = de.files.some(f => f.status === 'wait');
    return !de.running && hasResult && !waiting && de.counts.finished ? 3 : 2;
}

function renderSteps() {
    const current = currentStep();
    document.querySelectorAll('[data-de-card]').forEach(card => {
        const step = Number(card.dataset.deCard);
        const done = step < current;
        card.classList.toggle('is-current', step === current);
        card.classList.toggle('is-done', done);
        card.querySelector('.de-step').innerHTML = done ? '<i class="fa-solid fa-check"></i>' : String(step);
    });
}

const DE_STATUS = {
    wait: '<span class="text-muted"><i class="fa-regular fa-clock me-1"></i>รอแก้</span>',
    work: '<span class="text-primary"><span class="spinner-border spinner-border-sm me-1"></span>กำลังแก้ไข</span>',
    ok: '<span class="text-success fw-semibold"><i class="fa-solid fa-circle-check me-1"></i>สำเร็จ</span>',
    stopped: '<span class="text-muted"><i class="fa-solid fa-ban me-1"></i>หยุดแล้ว</span>',
};

function statusHtml(item) {
    if (item.status === 'warn') {
        return `<span class="text-warning fw-semibold" title="${escapeHtml(item.message)}"><i class="fa-solid fa-triangle-exclamation me-1"></i>${escapeHtml(item.message)}</span>`;
    }
    if (item.status === 'error') {
        return `<span class="text-danger fw-semibold" title="${escapeHtml(item.message)}"><i class="fa-solid fa-circle-xmark me-1"></i>${escapeHtml(item.message)}</span>`;
    }
    return DE_STATUS[item.status] || '';
}

function rowHtml(item, index) {
    return `<tr id="de-row-${index}">
        <td class="text-center text-muted tabular-num">${index + 1}</td>
        <td class="small text-secondary">${escapeHtml(item.sub || '-')}</td>
        <td class="small">${escapeHtml(item.name)}</td>
        <td class="text-center tabular-num">${item.pages === null ? '' : item.pages}</td>
        <td class="tabular-num">${escapeHtml(item.oldDates.join(', '))}</td>
        <td class="tabular-num fw-semibold">${escapeHtml(item.newDates.join(', '))}</td>
        <td class="small">${statusHtml(item)}</td>
    </tr>`;
}

function renderTable() {
    const tbody = document.getElementById('de-tbody');
    tbody.innerHTML = de.files.length
        ? de.files.map(rowHtml).join('')
        : `<tr><td colspan="7" class="text-center py-5 text-muted">
               <i class="fa-solid fa-inbox fs-3 d-block mb-2"></i>
               <div class="fw-semibold text-dark mb-1">ยังไม่มีไฟล์ เริ่มจากขั้นที่ 1 ด้านบน</div>
               <div class="small">รองรับ Tax Invoice / Credit Note / Debit Note</div>
           </td></tr>`;
    renderCounts();
}

function renderRow(index) {
    const row = document.getElementById(`de-row-${index}`);
    if (row) row.outerHTML = rowHtml(de.files[index], index);
}

function renderCounts() {
    const count = status => de.files.filter(f => f.status === status).length;
    document.getElementById('de-count').textContent = de.files.length.toLocaleString();
    const ok = count('ok'), warn = count('warn'), err = count('error');
    document.getElementById('de-ok-count').textContent = ok ? `สำเร็จ ${ok.toLocaleString()}` : '';
    document.getElementById('de-warn-count').textContent = warn ? `ต้องตรวจสอบ ${warn.toLocaleString()}` : '';
    document.getElementById('de-err-count').textContent = err ? `ผิดพลาด ${err.toLocaleString()}` : '';
}

function renderProgress() {
    const done = de.files.filter(f => ['ok', 'warn', 'error'].includes(f.status)).length;
    const total = de.files.length;
    const pct = total ? Math.round(done * 100 / total) : 0;
    document.getElementById('de-progress-bar').style.width = pct + '%';
    document.getElementById('de-progress-text').textContent = de.running
        ? `กำลังแก้ไข ${done.toLocaleString()} / ${total.toLocaleString()} ไฟล์ (${pct}%)`
        : `เสร็จ ${done.toLocaleString()} / ${total.toLocaleString()} ไฟล์` + (de.stopRequested ? ' (หยุดกลางทาง)' : '')
            + ' · กด "เปิดโฟลเดอร์" ในขั้นที่ 3 เพื่อดูไฟล์';
    renderCounts();
    renderSteps();
}

async function startRun() {
    const date = pickedDate();
    if (de.running || !de.files.length || !date) return;

    de.running = true;
    de.stopRequested = false;
    de.files.forEach(f => Object.assign(f, { status: 'wait', pages: null, oldDates: [], newDates: [], message: '' }));
    renderTable();
    renderButtons();
    document.getElementById('de-stop').disabled = false;
    document.getElementById('de-progress-wrap').classList.remove('d-none');
    renderProgress();

    let next = 0;
    const worker = async () => {
        while (!de.stopRequested && next < de.files.length) {
            const index = next++;
            await processFile(index, date);
        }
    };
    await Promise.all(Array.from({ length: Math.min(DE_WORKERS, de.files.length) }, worker));

    de.files.forEach((f, i) => {
        if (f.status === 'wait') {
            f.status = 'stopped';
            renderRow(i);
        }
    });
    de.running = false;
    renderProgress();
    try {
        const json = await apiGet('date_edit_scan');
        setCounts(json.data.upload_count, json.data.finished_count);
    } catch (e) {}
    renderButtons();

    const done = de.files.filter(f => f.status === 'ok' || f.status === 'warn').length;
    showToast(`แก้วันที่เสร็จ ${done.toLocaleString()} ไฟล์`);
}

async function processFile(index, date) {
    const item = de.files[index];
    item.status = 'work';
    renderRow(index);
    try {
        const json = await apiPost('date_edit_file', { path: item.path, date });
        const r = json.data;
        item.pages = r.pages;
        item.oldDates = r.old_dates || [];
        item.newDates = r.new_dates || [];
        if (!r.replaced) {
            item.status = 'warn';
            item.message = 'ไม่พบวันที่ในไฟล์นี้ (คัดลอกไปโดยไม่แก้)';
        } else if ((r.pages_without_date || []).length) {
            item.status = 'warn';
            item.message = 'ไม่พบวันที่ในหน้า ' + r.pages_without_date.join(', ');
        } else {
            item.status = 'ok';
        }
    } catch (err) {
        item.status = 'error';
        item.message = err.message;
    }
    renderRow(index);
    renderProgress();
}

function askClear(target) {
    if (de.running) return;
    de.clearTarget = target;
    document.getElementById('deClearFolder').textContent = DE_FOLDER_LABELS[target];
    document.getElementById('deClearCount').textContent = Number(de.counts[target] || 0).toLocaleString();
    document.getElementById('deClearConfirm').disabled = false;
    deClearModal.show();
}

async function executeClear() {
    const target = de.clearTarget;
    if (!target || de.running) return;
    const btn = document.getElementById('deClearConfirm');
    btn.disabled = true;
    try {
        const json = await apiPost('date_edit_clear', { target });
        deClearModal.hide();
        showToast(`ลบ${DE_FOLDER_LABELS[target]}แล้ว ${Number(json.deleted).toLocaleString()} ไฟล์`);
    } catch (err) {
        deClearModal.hide();
        alert('ลบไฟล์ไม่สำเร็จ: ' + err.message);
    }
    // A clear of finished keeps the results of the run on screen; a clear of upload here empties the list
    if (target === 'upload') {
        de.files = [];
    }
    await scanFolder(target === 'finished');
}

async function openDateEditFolder(target) {
    let json;
    document.body.style.cursor = 'progress';
    try {
        json = await apiPost('date_edit_open', { target });
    } catch (err) {
        json = { status: 'error', message: err.message };
    } finally {
        document.body.style.cursor = '';
    }
    if (json.status === 'success' && json.opened === true) return;
    showFolderNotOpenedModal(json);
}
