// Tab 2: import ASN sheets, compare with the credit note PDFs, the grid, Excel and clearing ASN sheets. Adapted from
// the Tax Invoice page (workspace/asn-import.js, asn-table.js, clear-modal.js) without the fix and print steps.

// rows: every grid row in the server's order; shown: after the status filter, search and sort; counts: the Job's
// ASN sheets and rows; compared: the rows are a compare result, not ASN lines waiting for one
const creditNoteCompare = {
    rows: [],
    shown: [],
    compared: false,
    counts: { files: 0, rows: 0 },
    label: '',
    filter: 'ALL',
    query: '',
    sort: { key: '#', direction: 'asc' },
    render: { token: 0, frame: null, parked: false },
    loading: null
};

// files: the workbooks chosen and the sheet picked in each, waiting for the confirmation; last: the workbook of the
// last import, so another of its sheets can be imported without choosing the file again
const creditNoteAsnImport = { files: [], last: null };

// The sources listed by the clear dialog, with the ids of their rows
let creditNoteAsnClearSources = [];

function creditNoteModal(id) {
    return bootstrap.Modal.getOrCreateInstance(document.getElementById(id));
}

// job_cn_reconcile, job_cn_import_asn and job_cn_delete_asn answer with the same keys: a compare result (data or
// reconcile, with a summary) once the Job is compared, otherwise the ASN rows (raw_records); a Job without ASN rows
// gets a result with no summary
function creditNoteCompareAccept(json) {
    const result = [json.data, json.reconcile].find(r => r && r.summary && Array.isArray(r.results)) || null;
    const rows = result ? result.results : creditNotePendingRows(json.raw_records || []);
    creditNoteCompare.rows = rows.map((row, i) => creditNoteComparePrepare(row, i));
    creditNoteCompare.compared = !!result;
    if (json.asn_counts) creditNoteCompare.counts = json.asn_counts;
    if (json.asn_filename !== undefined) creditNoteCompare.label = json.asn_filename || '';
    creditNoteCompareApply();
}

async function creditNoteCompareLoad(force) {
    const load = creditNoteApi('job_cn_reconcile', null, force ? { force: 1 } : null).then(creditNoteCompareAccept);
    creditNoteCompare.loading = load;
    try {
        await load;
    } finally {
        if (creditNoteCompare.loading === load) creditNoteCompare.loading = null;
    }
}

function creditNoteCompareApply() {
    const c = creditNoteCompare;
    c.shown = creditNoteCompareVisibleRows(c.rows, c.filter, c.query, c.sort);
    creditNoteCompareToolbar();
    creditNoteCompareRender();
}

function creditNoteCompareToolbar() {
    const c = creditNoteCompare;
    const counts = creditNoteCompareCounts(c.rows);
    document.querySelectorAll('#credit-note-asn-filters .recon-filter-btn').forEach(btn => {
        const el = btn.querySelector('.recon-count');
        const count = counts[btn.dataset.filter] || 0;
        el.textContent = count.toLocaleString();
        el.classList.toggle('is-zero', count === 0);
        btn.classList.toggle('active', btn.dataset.filter === c.filter);
    });
    const rows = Number(c.counts.rows || 0);
    document.getElementById('credit-note-asn-section').dataset.step = creditNoteCompareStep(rows, c.compared);
    document.getElementById('credit-note-asn-export').disabled = c.shown.length === 0;

    const label = creditNoteAsnLabel(c.label, c.counts);
    document.getElementById('credit-note-asn-file-text').textContent = label.text;
    document.getElementById('credit-note-asn-file-pill').title = label.title;
    document.getElementById('credit-note-asn-sheets-item').title = rows > 0 ? label.title : '';
    document.getElementById('credit-note-asn-sheets-count').textContent = Number(c.counts.files || 0).toLocaleString();
    document.getElementById('credit-note-asn-rows-count').textContent = rows.toLocaleString();
}

function creditNoteCompareRender() {
    const c = creditNoteCompare;
    creditNoteCancelRender(c.render);
    if (creditNoteTabs.active !== 'asn') {
        creditNoteParkTable('credit-note-asn-tbody', c.render);
        return;
    }
    c.render.parked = false;
    const tbody = document.getElementById('credit-note-asn-tbody');
    if (c.shown.length === 0) {
        tbody.innerHTML = c.rows.length === 0
            ? creditNotePlaceholderHtml(CN_COMPARE_COLUMNS.length)
            : `<tr><td colspan="${CN_COMPARE_COLUMNS.length}" class="text-center py-5 text-muted"><i class="fa-solid fa-magnifying-glass me-2"></i>ไม่พบรายการเปรียบเทียบที่ตรงกับเงื่อนไข</td></tr>`;
        return;
    }
    tbody.innerHTML = '';
    creditNoteAppendRows(tbody, c.shown, 0, creditNoteCompareRowHtml, c.render);
}

function creditNoteCompareSetFilter(filter) {
    if (creditNoteCompare.filter === filter) return;
    creditNoteCompare.filter = filter;
    runWithLoading('กำลังกรองข้อมูล...', `กรองรายการสถานะ "${filter === 'ALL' ? 'ทั้งหมด' : CN_STATUS_DISPLAY[filter].label}"`, creditNoteCompareApply);
}

function creditNoteCompareSortBy(th) {
    const sort = creditNoteCompare.sort;
    sort.direction = sort.key === th.dataset.col && sort.direction === 'asc' ? 'desc' : 'asc';
    sort.key = th.dataset.col;
    creditNoteMarkSorted('#credit-note-asn-table', th, sort.direction);
    runWithLoading('กำลังเรียงลำดับข้อมูล...', `เรียงตามคอลัมน์ "${th.textContent.trim()}" (${creditNoteCompare.shown.length.toLocaleString()} รายการ)`, creditNoteCompareApply);
}

// Reads the ASN Excel files placed in upload/{Job}/cn/asn, the same way as files picked by hand
async function creditNoteAsnReadFolder() {
    try {
        showLoadingOverlay('กำลังตรวจโฟลเดอร์ asn...', 'upload/.../cn/asn');
        const chosen = await asnFolderChoose('cn', folder => {
            hideLoadingOverlay();
            creditNoteShowNotice({
                tone: 'warning',
                title: 'ไม่พบไฟล์ ASN ในโฟลเดอร์ asn',
                message: 'ยังไม่มีไฟล์ Excel สำหรับนำเข้า',
                detailHtml: `<div class="cn-notice-note">วางไฟล์ ASN (.xlsx) ไว้ที่ <span class="tabular-num">${escapeHtml(folder)}</span> แล้วกด "อ่านจากโฟลเดอร์ asn" อีกครั้ง</div>`,
                action: { label: '<i class="fa-regular fa-folder-open me-1"></i> เปิดโฟลเดอร์ asn', onClick: () => openJobFolder('cn_asn') }
            });
        });
        if (!chosen) {
            hideLoadingOverlay();
            return;
        }
        showLoadingOverlay('กำลังโหลดไฟล์ ASN...', `${chosen.length.toLocaleString()} ไฟล์จากโฟลเดอร์ asn`);
        const files = await asnFolderDownload('cn', chosen);
        await creditNoteAsnChoose({ target: { files, value: '' } });
    } catch (err) {
        hideLoadingOverlay();
        creditNoteShowNotice({ tone: 'error', title: 'เกิดข้อผิดพลาด', message: 'อ่านไฟล์จากโฟลเดอร์ asn ไม่สำเร็จ: ' + err.message });
    }
}

async function creditNoteAsnChoose(event) {
    const files = Array.from(event.target.files || []);
    event.target.value = '';
    if (files.length === 0) return;

    showLoadingOverlay('กำลังอ่านไฟล์ ASN (.xlsx)...', `กำลังสแกนข้อมูล ${files.length.toLocaleString()} ไฟล์`);
    try {
        await ensureXlsxLoaded();
        const chosen = [];
        for (let i = 0; i < files.length; i++) {
            showLoadingOverlay('กำลังอ่านไฟล์ ASN (.xlsx)...', `กำลังสแกนชีตใน ${files[i].name} (${i + 1}/${files.length})`);
            const workbook = XLSX.read(await files[i].arrayBuffer(), { type: 'array' });
            const sheets = creditNoteWorkbookSheets(workbook);
            if (sheets.length === 0) {
                throw new Error(`ไม่พบชีตที่มีคอลัมน์เลขใบลดหนี้ (CN NO หรือ Invoice No) และ EAN/UPC ในไฟล์ ${files[i].name}`);
            }
            chosen.push({ fileName: files[i].name, sheets, selected: sheets[0].name });
        }
        hideLoadingOverlay();
        creditNoteAsnImport.files = chosen;
        creditNoteAsnOpenImport();
    } catch (err) {
        console.error(err);
        creditNoteShowNotice({ tone: 'error', title: 'เกิดข้อผิดพลาดในการอ่านไฟล์ ASN', message: err.message });
    }
}

function creditNoteAsnSelected(file) {
    return file.sheets.find(sheet => sheet.name === file.selected) || file.sheets[0];
}

function creditNoteAsnSheetNote(sheet) {
    const basis = CN_AMOUNT_BASIS[sheet.kind];
    return (sheet.hint ? `<span class="cn-sheet-hint"><i class="fa-solid fa-lightbulb me-1"></i>${escapeHtml(CN_SHEET_HINT)}</span>` : '')
        + `<span class="cn-sheet-basis">ยอดเงินของใบ: ${escapeHtml(basis.excel)}${basis.hasVat ? '' : ' (ชีตนี้ไม่มี VAT)'}</span>`;
}

function creditNoteAsnImportSubtext() {
    const rows = creditNoteAsnImport.files.reduce((sum, file) => sum + creditNoteAsnSelected(file).records.length, 0);
    document.getElementById('creditNoteAsnImportSub').textContent = `พบข้อมูลรายการสินค้า ${rows.toLocaleString()} แถว พร้อมดึงลงตาราง`;
}

function creditNoteAsnOpenImport() {
    const files = creditNoteAsnImport.files;
    if (files.length === 0) return;
    document.getElementById('creditNoteAsnImportTitle').textContent = `เลือกไฟล์ ASN จำนวน ${files.length.toLocaleString()} ไฟล์`;
    document.getElementById('creditNoteAsnImportDetails').innerHTML = files.map((file, idx) => {
        const sheet = creditNoteAsnSelected(file);
        const picker = file.sheets.length > 1
            ? `<div class="d-flex align-items-center gap-2 mt-2">
                <label class="text-secondary small text-nowrap flex-shrink-0" for="creditNoteAsnImportSheet${idx}">เลือกชีต:</label>
                <select class="form-select form-select-sm cn-import-sheet" id="creditNoteAsnImportSheet${idx}" data-file="${idx}">
                    ${file.sheets.map(s => `<option value="${escapeHtml(s.name)}"${s.name === sheet.name ? ' selected' : ''}>${escapeHtml(creditNoteSheetOptionText(s))}</option>`).join('')}
                </select>
            </div>`
            : `<div class="text-muted small">ชีต: <strong>${escapeHtml(sheet.name)}</strong></div>`;
        return `<div class="p-2${idx > 0 ? ' mt-2 pt-2 border-top' : ''}">
            <div class="d-flex align-items-center justify-content-between mb-1 gap-2">
                <strong class="text-dark small"><i class="fa-regular fa-file-excel text-success me-1"></i>${escapeHtml(file.fileName)}</strong>
                <span class="badge bg-success-subtle text-success border border-success-subtle tabular-num" data-cn-rows="${idx}">${sheet.records.length.toLocaleString()} แถว</span>
            </div>
            ${picker}
            <div class="cn-sheet-note" data-cn-note="${idx}">${creditNoteAsnSheetNote(sheet)}</div>
        </div>`;
    }).join('');
    creditNoteAsnImportSubtext();
    creditNoteModal('creditNoteAsnImportModal').show();
}

function creditNoteAsnPickSheet(select) {
    const idx = Number(select.dataset.file);
    const file = creditNoteAsnImport.files[idx];
    if (!file) return;
    file.selected = select.value;
    const sheet = creditNoteAsnSelected(file);
    document.querySelector(`[data-cn-rows="${idx}"]`).textContent = `${sheet.records.length.toLocaleString()} แถว`;
    document.querySelector(`[data-cn-note="${idx}"]`).innerHTML = creditNoteAsnSheetNote(sheet);
    creditNoteAsnImportSubtext();
}

// Resolves 'skip', 'replace', or null when the dialog is closed another way
function creditNoteAsk(modalId, skipId, replaceId) {
    return new Promise(resolve => {
        const el = document.getElementById(modalId);
        const modal = bootstrap.Modal.getOrCreateInstance(el);
        const skipBtn = document.getElementById(skipId);
        const replaceBtn = document.getElementById(replaceId);
        let choice = null;
        const onSkip = () => { choice = 'skip'; modal.hide(); };
        const onReplace = () => { choice = 'replace'; modal.hide(); };
        const onHidden = () => {
            skipBtn.removeEventListener('click', onSkip);
            replaceBtn.removeEventListener('click', onReplace);
            el.removeEventListener('hidden.bs.modal', onHidden);
            resolve(choice);
        };
        skipBtn.addEventListener('click', onSkip);
        replaceBtn.addEventListener('click', onReplace);
        el.addEventListener('hidden.bs.modal', onHidden);
        modal.show();
    });
}

function creditNoteAsnAskSame(label, sameFile) {
    document.getElementById('creditNoteAsnSameLabel').textContent = label;
    document.getElementById('creditNoteAsnSameStored').textContent = Number(sameFile.stored).toLocaleString();
    document.getElementById('creditNoteAsnSameIncoming').textContent = Number(sameFile.incoming).toLocaleString();
    document.getElementById('creditNoteAsnSameVerdict').textContent = sameFile.identical ? 'ข้อมูลเหมือนเดิมทุกแถว' : 'ข้อมูลในชีตไม่เหมือนเดิม';
    document.getElementById('creditNoteAsnSameNote').textContent =
        'ข้าม = คงข้อมูลและผลเทียบเดิมไว้ ไม่เปลี่ยนแปลงอะไร\n' +
        (sameFile.reconciled
            ? 'เขียนทับ = แทนที่ด้วยข้อมูลจากชีตที่เลือก แล้วเทียบกับ PDF ใหม่ให้อัตโนมัติ'
            : 'เขียนทับ = แทนที่ข้อมูลเดิมด้วยข้อมูลจากชีตที่เลือก');
    return creditNoteAsk('creditNoteAsnSameModal', 'creditNoteAsnSameSkip', 'creditNoteAsnSameReplace');
}

function creditNoteAsnAskDuplicate(overlap) {
    document.getElementById('creditNoteAsnDupCount').textContent = Number(overlap.overlap_count).toLocaleString();
    document.getElementById('creditNoteAsnDupFiles').textContent = 'ซ้ำกับชีต: ' + (overlap.files || []).join(', ');
    document.getElementById('creditNoteAsnDupSample').textContent = (overlap.sample || []).join(', ')
        + (overlap.overlap_count > (overlap.sample || []).length ? ' ...' : '');
    return creditNoteAsk('creditNoteAsnDupModal', 'creditNoteAsnDupSkip', 'creditNoteAsnDupReplace');
}

// What to do with data the Job already holds; null when the user cancels, otherwise
// { sameFileMode, duplicateMode, recompare } as import_asn takes them on the Tax Invoice page
async function creditNoteAsnResolve(label, records, position, total, fileName = '') {
    const overlap = await creditNoteApi('job_cn_asn_overlap', { job_id: CURRENT_JOB_ID, asn_filename: label, records: JSON.stringify(records) });
    const choice = { sameFileMode: 'replace', duplicateMode: 'skip', recompare: false };
    const resume = () => showLoadingOverlay('กำลังดึงข้อมูล ASN ลงตาราง...', `กำลังนำเข้าชีต ${position} / ${total}: ${label}`);

    if (overlap.same_file && overlap.same_file.stored > 0) {
        hideLoadingOverlay();
        const sameFileMode = await creditNoteAsnAskSame(label, overlap.same_file);
        if (!sameFileMode) return null;
        resume();
        if (sameFileMode === 'skip') {
            choice.sameFileMode = 'skip';
            return choice;
        }
        choice.recompare = !!overlap.same_file.reconciled;
    }

    if (overlap.overlap_count && creditNoteAsnOverlapIsSameWorkbook(overlap.files, fileName)) {
        // The sheet just picked takes over the credit notes the other sheets of this file hold, without asking
        choice.duplicateMode = 'replace';
    } else if (overlap.overlap_count) {
        hideLoadingOverlay();
        const duplicateMode = await creditNoteAsnAskDuplicate(overlap);
        if (!duplicateMode) return null;
        choice.duplicateMode = duplicateMode;
        resume();
    }
    return choice;
}

async function creditNoteAsnRunImport() {
    const files = creditNoteAsnImport.files;
    if (files.length === 0) return;
    creditNoteModal('creditNoteAsnImportModal').hide();
    showLoadingOverlay('กำลังดึงข้อมูล ASN ลงตาราง...', `กำลังนำเข้าข้อมูล ${files.length.toLocaleString()} ไฟล์ลงในตาราง`);

    try {
        let last = null;
        let recompare = false;
        for (let i = 0; i < files.length; i++) {
            const sheet = creditNoteAsnSelected(files[i]);
            const label = creditNoteAsnSource(files[i].fileName, sheet.name);
            showLoadingOverlay('กำลังดึงข้อมูล ASN ลงตาราง...', `กำลังนำเข้าชีต ${i + 1} / ${files.length}: ${label}`);
            const choice = await creditNoteAsnResolve(label, sheet.records, i + 1, files.length, files[i].fileName);
            if (!choice) break;
            if (choice.sameFileMode === 'skip') continue;
            last = await creditNoteApi('job_cn_import_asn', {
                job_id: CURRENT_JOB_ID,
                asn_filename: label,
                append: 1,
                reconcile: 0,
                duplicate_mode: choice.duplicateMode,
                same_file_mode: choice.sameFileMode,
                records: JSON.stringify(sheet.records)
            });
            recompare = recompare || choice.recompare;
            creditNoteAsnImport.last = files[i];
        }
        creditNoteAsnImport.files = [];
        creditNoteAsnRenderSheetPicker();

        if (recompare) {
            showLoadingOverlay('กำลังเปรียบเทียบข้อมูลกับ PDF...', 'ข้อมูล ASN ถูกเขียนทับ ระบบเทียบกับ PDF ใหม่ให้อัตโนมัติ');
            await creditNoteCompareLoad(true);
        } else if (last) {
            creditNoteCompareAccept(last);
        } else {
            await creditNoteCompareLoad();
        }
        hideLoadingOverlay();
    } catch (err) {
        console.error(err);
        creditNoteAsnImport.files = [];
        creditNoteShowNotice({ tone: 'error', title: 'เกิดข้อผิดพลาดในการดึงข้อมูล ASN', message: err.message });
    }
}

// After an import, the other sheets of that workbook can be imported from the toolbar: picking one opens the same
// confirmation with that sheet
function creditNoteAsnRenderSheetPicker() {
    const last = creditNoteAsnImport.last;
    const wrapper = document.getElementById('credit-note-asn-sheet-wrapper');
    const select = document.getElementById('credit-note-asn-sheet');
    const show = !!last && last.sheets.length > 1;
    wrapper.classList.toggle('d-none', !show);
    wrapper.classList.toggle('d-flex', show);
    if (!show) return;
    select.innerHTML = '<option value="" selected hidden>เลือกชีตเพื่อนำเข้า...</option>'
        + last.sheets.map(s => `<option value="${escapeHtml(s.name)}">${escapeHtml(creditNoteSheetOptionText(s))}</option>`).join('');
}

function creditNoteAsnImportAnotherSheet(select) {
    const last = creditNoteAsnImport.last;
    const name = select.value;
    select.value = '';
    if (!last || !name) return;
    creditNoteAsnImport.files = [{ fileName: last.fileName, sheets: last.sheets, selected: name }];
    creditNoteAsnOpenImport();
}

function creditNoteCompareOpen() {
    const counts = creditNoteCompare.counts;
    if (!Number(counts.rows)) {
        creditNoteShowNotice({ tone: 'warning', title: 'ยังไม่มีข้อมูล ASN ในตาราง', message: 'กดปุ่ม "เลือกไฟล์ ASN เพิ่ม (.xlsx)" เพื่อดึงข้อมูลลงตารางก่อนเริ่มเปรียบเทียบ' });
        return;
    }
    const docs = creditNoteState.all;
    const files = new Set(docs.map(doc => doc.filename).filter(Boolean)).size;
    document.getElementById('creditNoteComparePdfCount').textContent = `${docs.length.toLocaleString()} ใบ (${files.toLocaleString()} ไฟล์)`;
    document.getElementById('creditNoteCompareAsnCount').textContent = `${Number(counts.rows).toLocaleString()} รายการ จาก ${Number(counts.files).toLocaleString()} ชีต`;
    const stale = creditNoteRereadDocs().length;
    document.getElementById('creditNoteCompareRereadNote').classList.toggle('d-none', stale === 0);
    document.getElementById('creditNoteCompareRereadText').textContent = stale === 0 ? ''
        : `ใบลดหนี้ ${stale.toLocaleString()} ใบยังไม่มีบรรทัดสินค้าและยอดรวมด้านขวา จะขึ้นเป็นผิดพลาด ควรกด "ดึงข้อมูลใหม่จาก finished" ก่อน`;
    creditNoteModal('creditNoteCompareModal').show();
}

async function creditNoteCompareRun() {
    creditNoteModal('creditNoteCompareModal').hide();
    showLoadingOverlay('กำลังเปรียบเทียบข้อมูลกับ PDF...', 'กำลังจับคู่ Credit Note No. และ Barcode แล้วเทียบ Qty ยอดเงิน และ VAT');
    try {
        await creditNoteCompareLoad(true);
        const counts = creditNoteCompareCounts(creditNoteCompare.rows);
        const lines = ['MATCH', 'MISMATCH', 'PENDING'].filter(key => counts[key])
            .map(key => `<li>${CN_STATUS_DISPLAY[key].label}: <span class="tabular-num">${counts[key].toLocaleString()}</span> รายการ</li>`);
        creditNoteShowNotice({
            tone: 'success',
            title: 'เปรียบเทียบข้อมูลสำเร็จ',
            message: 'ระบบเปรียบเทียบข้อมูลระหว่าง PDF และ ASN เรียบร้อยแล้ว',
            detailHtml: lines.length ? `<section class="cn-notice-section"><div class="cn-notice-head">ผลการเปรียบเทียบ</div><ul class="cn-notice-list">${lines.join('')}</ul></section>` : ''
        });
    } catch (err) {
        console.error(err);
        creditNoteShowNotice({ tone: 'error', title: 'เกิดข้อผิดพลาดในการเปรียบเทียบ', message: err.message });
    }
}

async function creditNoteAsnClearOpen() {
    let records;
    try {
        records = (await runWithLoading('กำลังโหลดรายการ ASN...', 'กรุณารอสักครู่', () => creditNoteApi('job_cn_asn_records'))).data || [];
    } catch (err) {
        creditNoteShowNotice({ tone: 'error', title: 'เกิดข้อผิดพลาด', message: 'โหลดรายการ ASN ไม่สำเร็จ: ' + err.message });
        return;
    }
    creditNoteAsnClearSources = creditNoteAsnSources(records);
    if (creditNoteAsnClearSources.length === 0) {
        creditNoteShowNotice({ tone: 'info', title: 'ล้างข้อมูล ASN', message: 'Job นี้ยังไม่มีข้อมูล ASN' });
        return;
    }
    document.getElementById('creditNoteAsnClearList').innerHTML = creditNoteAsnClearSources.map((source, i) => `
        <label class="list-group-item d-flex align-items-center gap-2 cn-clear-source">
            <input class="form-check-input flex-shrink-0 m-0" type="checkbox" data-source="${i}">
            <span class="flex-grow-1 cn-clear-name">${escapeHtml(source.source)}</span>
            <span class="text-muted small text-nowrap tabular-num">${source.documents.toLocaleString()} ใบลดหนี้</span>
            <span class="badge bg-light text-dark border tabular-num">${source.rows.toLocaleString()} แถว</span>
        </label>`).join('');
    document.getElementById('creditNoteAsnClearAll').checked = false;
    creditNoteAsnClearSync();
    creditNoteModal('creditNoteAsnClearModal').show();
}

function creditNoteAsnClearTicked() {
    return Array.from(document.querySelectorAll('#creditNoteAsnClearList input[data-source]'))
        .filter(box => box.checked).map(box => creditNoteAsnClearSources[Number(box.dataset.source)]);
}

function creditNoteAsnClearSync() {
    const ticked = creditNoteAsnClearTicked();
    const all = document.getElementById('creditNoteAsnClearAll');
    all.checked = ticked.length === creditNoteAsnClearSources.length;
    all.indeterminate = ticked.length > 0 && !all.checked;
    document.getElementById('creditNoteAsnClearCount').textContent = ticked.length.toLocaleString();
    document.getElementById('creditNoteAsnClearConfirm').disabled = ticked.length === 0;
    document.querySelectorAll('#creditNoteAsnClearList .cn-clear-source').forEach(row => {
        row.classList.toggle('is-ticked', row.querySelector('input').checked);
    });
}

async function creditNoteAsnClearRun() {
    const ticked = creditNoteAsnClearTicked();
    if (ticked.length === 0) return;
    const ids = [].concat(...ticked.map(source => source.ids));
    creditNoteModal('creditNoteAsnClearModal').hide();
    showLoadingOverlay('กำลังลบข้อมูล ASN ที่เลือก...', `กำลังลบ ${ids.length.toLocaleString()} แถว จาก ${ticked.length.toLocaleString()} ชีต`);
    try {
        const json = await creditNoteApi('job_cn_delete_asn', { job_id: CURRENT_JOB_ID, ids: JSON.stringify(ids) });
        creditNoteCompareAccept(json);
        hideLoadingOverlay();
        creditNoteShowNotice({
            tone: 'success',
            title: 'ล้างข้อมูล ASN แล้ว',
            message: `ลบ ${Number(json.deleted_count === undefined ? ids.length : json.deleted_count).toLocaleString()} แถว จาก ${ticked.length.toLocaleString()} ชีต`
        });
    } catch (err) {
        console.error(err);
        creditNoteShowNotice({ tone: 'error', title: 'เกิดข้อผิดพลาด', message: 'ลบข้อมูล ASN ไม่สำเร็จ: ' + err.message });
    }
}

// Elements marked data-cn-rule="amount|vat" (the money headers, the compare dialog) say the rule of the server
function creditNoteApplyMoneyRules() {
    document.querySelectorAll('[data-cn-rule]').forEach(el => {
        const rule = creditNoteMoneyRule(CN_TOLERANCES[el.dataset.cnRule]);
        if (el.tagName === 'TH') el.title = `${el.title} ${rule}`;
        else el.textContent = rule;
    });
}

function creditNoteCompareInit() {
    creditNoteApplyMoneyRules();
    const input = document.getElementById('credit-note-asn-file');
    input.addEventListener('change', creditNoteAsnChoose);
    document.getElementById('credit-note-asn-choose').addEventListener('click', () => input.click());
    document.getElementById('credit-note-asn-folder').addEventListener('click', creditNoteAsnReadFolder);
    document.getElementById('creditNoteAsnImportDetails').addEventListener('change', e => {
        if (e.target.matches('select[data-file]')) creditNoteAsnPickSheet(e.target);
    });
    document.getElementById('creditNoteAsnImportConfirm').addEventListener('click', creditNoteAsnRunImport);
    document.getElementById('credit-note-asn-sheet').addEventListener('change', e => creditNoteAsnImportAnotherSheet(e.target));
    document.getElementById('creditNoteCompareConfirm').addEventListener('click', creditNoteCompareRun);
    document.getElementById('credit-note-asn-export').addEventListener('click', () => creditNoteCompareExportExcel(creditNoteCompare.shown));
    document.getElementById('credit-note-asn-clear').addEventListener('click', creditNoteAsnClearOpen);
    document.getElementById('creditNoteAsnClearList').addEventListener('change', creditNoteAsnClearSync);
    document.getElementById('creditNoteAsnClearAll').addEventListener('change', e => {
        document.querySelectorAll('#creditNoteAsnClearList input[data-source]').forEach(box => { box.checked = e.target.checked; });
        creditNoteAsnClearSync();
    });
    document.getElementById('creditNoteAsnClearConfirm').addEventListener('click', creditNoteAsnClearRun);

    document.querySelectorAll('#credit-note-asn-filters .recon-filter-btn').forEach(btn => {
        btn.addEventListener('click', () => creditNoteCompareSetFilter(btn.dataset.filter));
    });
    creditNoteWireSearch('credit-note-asn-search', 'credit-note-asn-clear-search', query => {
        creditNoteCompare.query = query;
        creditNoteCompareApply();
    });
    document.querySelectorAll('#credit-note-asn-table thead th').forEach(th => {
        th.addEventListener('click', e => {
            if (e.target.classList.contains('resizer') || creditNoteCompare.rows.length === 0) return;
            creditNoteCompareSortBy(th);
        });
    });
    initColumnResizers('#credit-note-asn-table');
}
