const CN_BATCH_SIZE = 10;
const CN_NOTICE_LIST_MAX = 8;

// What the toolbar shows in each phase of a run: idle -> scanning -> idle, or idle -> extracting (-> stopping) -> idle
const CN_PHASES = {
    idle: { start: 'ready', stop: 'hidden' },
    scanning: { start: 'busy', stop: 'hidden' },
    extracting: { start: 'hidden', stop: 'ready' },
    stopping: { start: 'hidden', stop: 'busy' }
};

// scan: the last job_cn_scan answer; progress: redraws the overlay of the run in progress
const creditNoteRun = { phase: 'idle', scan: null, progress: null };

const CN_NOTICE_TONES = {
    success: 'fa-solid fa-circle-check text-success',
    info: 'fa-solid fa-circle-info text-primary',
    warning: 'fa-solid fa-triangle-exclamation text-warning',
    error: 'fa-solid fa-circle-xmark text-danger'
};

function creditNoteSetPhase(phase) {
    creditNoteRun.phase = phase;
    const view = CN_PHASES[phase];
    const start = document.getElementById('credit-note-start');
    const stop = document.getElementById('credit-note-stop');
    const overlayStop = document.getElementById('loading-overlay-stop-btn');

    start.style.display = view.start === 'hidden' ? 'none' : '';
    start.disabled = view.start === 'busy';
    start.innerHTML = view.start === 'busy'
        ? '<span class="spinner-border spinner-border-sm me-1"></span> ตรวจสอบไฟล์...'
        : '<i class="fa-solid fa-play me-1"></i> ดึงข้อมูลจาก upload here';

    stop.style.display = view.stop === 'hidden' ? 'none' : '';
    stop.disabled = view.stop === 'busy';
    stop.innerHTML = view.stop === 'busy'
        ? '<span class="spinner-border spinner-border-sm me-1"></span> กำลังหยุด...'
        : '<i class="fa-solid fa-stop me-1"></i> หยุด';
    if (overlayStop) overlayStop.disabled = view.stop === 'busy';
}

function creditNoteSetUploadCount(count) {
    document.getElementById('credit-note-upload-count').textContent = Number(count || 0).toLocaleString();
    document.getElementById('credit-note-section').dataset.step = count > 0 ? 'extract' : 'idle';
}

// POST with fields, otherwise GET for this Job with the extra query fields
async function creditNoteApi(action, fields, query) {
    let res;
    if (fields) {
        const fd = new FormData();
        Object.keys(fields).forEach(key => fd.append(key, fields[key]));
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

function creditNoteNoticeSection(icon, title, items, note) {
    if (!items.length) return '';
    const shown = items.slice(0, CN_NOTICE_LIST_MAX).map(item => `<li>${item}</li>`).join('');
    const more = items.length > CN_NOTICE_LIST_MAX ? `<li class="text-muted">และอีก ${(items.length - CN_NOTICE_LIST_MAX).toLocaleString()} ไฟล์</li>` : '';
    return `<section class="cn-notice-section">
        <div class="cn-notice-head"><i class="${icon}"></i>${escapeHtml(title)} <span class="tabular-num">${items.length.toLocaleString()}</span> ไฟล์</div>
        <ul class="cn-notice-list">${shown}${more}</ul>
        ${note ? `<div class="cn-notice-note">${escapeHtml(note)}</div>` : ''}
    </section>`;
}

// detailHtml is built here from escaped parts only
function creditNoteShowNotice({ tone, title, message, detailHtml = '', action = null }) {
    hideLoadingOverlay();
    document.getElementById('creditNoteNoticeIcon').className = CN_NOTICE_TONES[tone];
    document.getElementById('creditNoteNoticeTitle').textContent = title;
    document.getElementById('creditNoteNoticeMessage').textContent = message;
    const detail = document.getElementById('creditNoteNoticeDetail');
    detail.innerHTML = detailHtml;
    detail.classList.toggle('d-none', !detailHtml);
    const actionBtn = document.getElementById('creditNoteNoticeAction');
    actionBtn.classList.toggle('d-none', !action);
    actionBtn.innerHTML = action ? action.label : '';
    actionBtn.onclick = action ? () => {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('creditNoteNoticeModal')).hide();
        action.onClick();
    } : null;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('creditNoteNoticeModal')).show();
}

function creditNoteDuplicateItem(file) {
    return `${escapeHtml(file.filename)} <span class="text-muted">ซ้ำกับ</span> ${escapeHtml(file.duplicate_of)}`;
}

async function creditNoteStartExtraction() {
    if (creditNoteRun.phase !== 'idle') return;
    creditNoteSetPhase('scanning');
    let scan;
    try {
        scan = (await creditNoteApi('job_cn_scan')).data;
    } catch (err) {
        creditNoteSetPhase('idle');
        creditNoteShowNotice({ tone: 'error', title: 'เกิดข้อผิดพลาด', message: 'ตรวจสอบไฟล์ใน upload here ไม่สำเร็จ: ' + err.message });
        return;
    }
    creditNoteSetPhase('idle');
    creditNoteRun.scan = scan;
    creditNoteSetUploadCount(scan.total_in_upload);

    const everyFile = [...scan.new_files, ...scan.existing_files, ...scan.duplicate_files];
    if (scan.total_in_upload === 0) {
        creditNoteShowNotice({
            tone: 'warning',
            title: 'ไม่พบไฟล์ PDF ใน upload here',
            message: 'ยังไม่มีไฟล์ใบลดหนี้สำหรับดึงข้อมูล',
            detailHtml: `<div class="cn-notice-note">วางไฟล์ PDF ใบลดหนี้ไว้ที่ <span class="tabular-num">${escapeHtml(scan.upload_dir)}</span> แล้วกด "ดึงข้อมูลจาก upload here" อีกครั้ง</div>`,
            action: { label: '<i class="fa-regular fa-folder-open me-1"></i> เปิดโฟลเดอร์ upload here', onClick: () => openJobFolder('cn_upload') }
        });
    } else if (scan.new_count === 0) {
        creditNoteShowNotice({
            tone: 'info',
            title: 'ไม่มีไฟล์ใหม่ใน upload here',
            message: scan.duplicate_count === 0
                ? `ทั้ง ${scan.total_in_upload.toLocaleString()} ไฟล์เคยดึงข้อมูลเข้า Job นี้แล้ว`
                : `ทั้ง ${scan.total_in_upload.toLocaleString()} ไฟล์เคยดึงข้อมูลแล้ว หรือมีเลขใบลดหนี้ซ้ำกับไฟล์อื่นใน Job นี้`,
            detailHtml: creditNoteNoticeSection('fa-solid fa-file-circle-check text-warning', 'ไฟล์ที่เคยดึงข้อมูลแล้ว', scan.existing_files.map(f => escapeHtml(f.filename)), '')
                + creditNoteNoticeSection('fa-solid fa-copy text-danger', 'ไฟล์ที่เลขใบลดหนี้ซ้ำ', scan.duplicate_files.map(creditNoteDuplicateItem), '')
                + '<div class="cn-notice-note">ถ้าต้องการดึงซ้ำ กด "ดึงซ้ำและแทนที่" ข้อมูลเดิมของไฟล์และเลขใบลดหนี้เดียวกันจะถูกแทนที่ด้วยข้อมูลจากไฟล์เหล่านี้</div>',
            action: { label: '<i class="fa-solid fa-arrows-rotate me-1"></i> ดึงซ้ำและแทนที่', onClick: () => creditNoteRunBatches(everyFile, true) }
        });
    } else if (scan.existing_count > 0 || scan.duplicate_count > 0) {
        document.getElementById('creditNoteScanTotal').textContent = scan.total_in_upload.toLocaleString();
        document.getElementById('creditNoteScanNew').textContent = scan.new_count.toLocaleString();
        document.getElementById('creditNoteScanExisting').textContent = scan.existing_count.toLocaleString();
        document.getElementById('creditNoteScanDuplicate').textContent = scan.duplicate_count.toLocaleString();
        document.getElementById('creditNoteScanExistingRow').classList.toggle('d-none', scan.existing_count === 0);
        document.getElementById('creditNoteScanDuplicateRow').classList.toggle('d-none', scan.duplicate_count === 0);
        document.getElementById('creditNoteScanDuplicateDetail').textContent = scan.duplicate_files.slice(0, 3)
            .map(f => `${f.filename} ซ้ำกับ ${f.duplicate_of}`).join(' | ') + (scan.duplicate_count > 3 ? ' ...' : '');
        document.getElementById('creditNoteScanSkip').onclick = () => creditNoteChooseFromScan(scan.new_files, false);
        document.getElementById('creditNoteScanReplace').onclick = () => creditNoteChooseFromScan(everyFile, true);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('creditNoteScanModal')).show();
    } else {
        await creditNoteRunBatches(scan.new_files, false);
    }
}

function creditNoteChooseFromScan(files, replace) {
    bootstrap.Modal.getOrCreateInstance(document.getElementById('creditNoteScanModal')).hide();
    creditNoteRunBatches(files, replace);
}

async function creditNoteRunBatches(files, replace) {
    if (creditNoteRun.phase !== 'idle') return;
    const scan = creditNoteRun.scan;
    const result = await creditNoteExtractFiles(files.map(f => f.rel_path), replace);
    creditNoteSetUploadCount(Math.max(0, scan.total_in_upload - result.processed));
    creditNoteShowResult(result, replace ? [] : scan.existing_files, replace ? [] : scan.duplicate_files);
}

// Reads the files of the Job that hold credit notes stored before the reader gave lines and totals, from finished,
// replacing their rows; nothing moves
async function creditNoteReread() {
    if (creditNoteRun.phase !== 'idle') return;
    const names = Array.from(new Set(creditNoteRereadDocs().map(doc => doc.filename).filter(Boolean)));
    if (names.length === 0) return;
    const result = await creditNoteExtractFiles(names, true);
    creditNoteShowResult(result, [], [], 'finished');
}

// The batch loop of a run, 10 files a request until done or stopped, then the table and the compare tab reload
// (reading files can reset the compare). Returns the counts creditNoteShowResult reports.
async function creditNoteExtractFiles(paths, replace) {
    const total = paths.length;
    const result = { total, done: 0, processed: 0, documents: 0, skipped: [], notCreditNote: [], failed: [], replaced: [], stopped: false };

    creditNoteSetPhase('extracting');
    showLoadingOverlay('กำลังดึงข้อมูลจากใบลดหนี้...', `เตรียมดึงข้อมูลทั้งหมด ${total.toLocaleString()} ไฟล์`, true, true);
    const progress = () => updateLoadingOverlay(
        creditNoteRun.phase === 'stopping'
            ? 'จะหยุดเมื่อชุดที่กำลังอ่านเสร็จ'
            : `กำลังอ่านไฟล์ ${Math.min(result.done + 1, total).toLocaleString()} - ${Math.min(result.done + CN_BATCH_SIZE, total).toLocaleString()} จาก ${total.toLocaleString()} ไฟล์`,
        `${result.done.toLocaleString()} / ${total.toLocaleString()} ไฟล์ (${result.documents.toLocaleString()} ใบลดหนี้)`,
        Math.round(result.done / total * 100)
    );
    creditNoteRun.progress = progress;
    progress();

    for (let i = 0; i < total; i += CN_BATCH_SIZE) {
        if (creditNoteRun.phase === 'stopping') {
            result.stopped = true;
            break;
        }
        const chunk = paths.slice(i, i + CN_BATCH_SIZE);
        try {
            const json = await creditNoteApi('job_cn_extract_batch', {
                job_id: CURRENT_JOB_ID,
                files: JSON.stringify(chunk),
                replace_duplicates: replace ? 1 : 0
            });
            result.processed += Number(json.processed_files || 0);
            result.documents += Number(json.documents_count || 0);
            result.skipped.push(...(json.skipped_duplicates || []));
            result.notCreditNote.push(...(json.not_credit_note || []));
            result.failed.push(...(json.failed || []));
            result.replaced.push(...(json.replaced_files || []));
            creditNoteAddDocs(json.data);
        } catch (err) {
            chunk.forEach(relPath => result.failed.push({ filename: relPath.split('/').pop(), message: err.message }));
        }
        result.done += chunk.length;
        progress();
    }
    creditNoteRun.progress = null;

    try {
        await Promise.all([creditNoteLoadDocs(), creditNoteCompareLoad()]);
    } catch (err) {
        result.failed.push({ filename: '-', message: 'โหลดตารางใหม่ไม่สำเร็จ: ' + err.message });
    }
    creditNoteSetPhase('idle');
    return result;
}

function creditNoteRequestStop() {
    if (creditNoteRun.phase !== 'extracting') return;
    creditNoteSetPhase('stopping');
    if (creditNoteRun.progress) creditNoteRun.progress();
}

// folder: where the files that were not read stay (upload here, or finished for a re-read)
function creditNoteShowResult(result, leftExisting, leftDuplicates, folder = 'upload here') {
    const skipped = [...leftDuplicates, ...result.skipped];
    const leftInUpload = skipped.length + leftExisting.length + result.notCreditNote.length;
    let message = `ดึงข้อมูล ${result.processed.toLocaleString()} ไฟล์ ได้ใบลดหนี้ ${result.documents.toLocaleString()} ใบ`;
    if (result.stopped) message += ` หยุดก่อนครบ ยังไม่ได้อ่าน ${(result.total - result.done).toLocaleString()} ไฟล์`;

    const stays = `ไฟล์เหล่านี้ยังอยู่ใน ${folder}`;
    const detailHtml = creditNoteNoticeSection('fa-solid fa-arrows-rotate text-primary', 'ไฟล์เดิมที่ข้อมูลถูกแทนที่ด้วยไฟล์ใหม่', result.replaced.map(name => escapeHtml(name)), '')
        + creditNoteNoticeSection('fa-solid fa-copy text-danger', 'ข้ามเพราะเลขใบลดหนี้ซ้ำกับไฟล์อื่น', skipped.map(creditNoteDuplicateItem), stays)
        + creditNoteNoticeSection('fa-solid fa-file-circle-check text-warning', 'ข้ามเพราะเคยดึงข้อมูลแล้ว', leftExisting.map(f => escapeHtml(f.filename)), stays)
        + creditNoteNoticeSection('fa-solid fa-file-circle-question text-secondary', 'ไม่ใช่ใบลดหนี้', result.notCreditNote.map(name => escapeHtml(name)), stays)
        + creditNoteNoticeSection('fa-solid fa-circle-exclamation text-danger', 'อ่านไม่สำเร็จ', result.failed.map(f => `${escapeHtml(f.filename)} <span class="text-muted">${escapeHtml(f.message)}</span>`), stays);

    creditNoteShowNotice({
        tone: result.failed.length > 0 ? 'error' : (result.stopped || leftInUpload > 0 ? 'warning' : 'success'),
        title: result.stopped ? 'หยุดดึงข้อมูลแล้ว' : 'ดึงข้อมูลเสร็จแล้ว',
        message,
        detailHtml
    });
}
