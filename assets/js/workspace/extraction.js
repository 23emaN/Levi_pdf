// ================= 2. BATCH EXTRACTION ================= //

function appendBatchRows(newRows) {
    if (!newRows || newRows.length === 0) return;

    const tbody = document.getElementById('detailed-tbody');
    if (detailedData.length === 0) {
        tbody.innerHTML = '';
    }

    const startIdx = detailedData.length;
    const domPreviewParts = [];

    for (let i = 0; i < newRows.length; i++) {
        const r = preparePdfRow(newRows[i]);
        detailedData.push(r);
        displayData.push(r);

        const rowIndex = startIdx + i;
        if (activeTab === 'pdf' && rowIndex < INITIAL_RENDER_ROWS) {
            domPreviewParts.push(buildPdfRowHtml(r, rowIndex + 1));
        }
    }

    if (domPreviewParts.length > 0) {
        tbody.insertAdjacentHTML('beforeend', domPreviewParts.join(''));
    }

    document.getElementById('btn-copy-excel').disabled = false;
    document.getElementById('btn-export-excel').disabled = false;
    setStatusBarPdf(detailedData);
}

function showNoticeModal(title, message, detail = '', iconType = 'warning', action = null) {
    hideLoadingOverlay();
    if (!noticeModal) {
        const el = document.getElementById('noticeModal');
        if (el) noticeModal = new bootstrap.Modal(el);
    }
    const titleEl = document.getElementById('noticeModalTitleText');
    const msgEl = document.getElementById('noticeModalMessage');
    const detailEl = document.getElementById('noticeModalDetail');
    const iconEl = document.getElementById('noticeModalIcon');
    const bodyIconEl = document.getElementById('noticeModalBodyIcon');
    const actionEl = document.getElementById('noticeModalAction');
    if (actionEl) {
        actionEl.classList.toggle('d-none', !action);
        if (action) {
            actionEl.innerHTML = action.label;
            actionEl.onclick = action.onClick;
        }
    }

    if (titleEl) titleEl.textContent = title;
    if (msgEl) msgEl.textContent = message;
    if (detailEl) {
        if (detail) {
            detailEl.textContent = detail;
            detailEl.style.display = 'block';
        } else {
            detailEl.style.display = 'none';
        }
    }

    if (iconType === 'warning') {
        if (iconEl) iconEl.className = 'fa-solid fa-triangle-exclamation text-warning';
        if (bodyIconEl) bodyIconEl.className = 'fa-solid fa-folder-open text-warning fs-3';
    } else if (iconType === 'error') {
        if (iconEl) iconEl.className = 'fa-solid fa-circle-xmark text-danger';
        if (bodyIconEl) bodyIconEl.className = 'fa-solid fa-circle-exclamation text-danger fs-3';
    } else {
        if (iconEl) iconEl.className = 'fa-solid fa-circle-info text-primary';
        if (bodyIconEl) bodyIconEl.className = 'fa-solid fa-folder-open text-primary fs-3';
    }

    if (noticeModal) {
        noticeModal.show();
    }
}

document.getElementById('btn-start').addEventListener('click', async () => {
    const btn = document.getElementById('btn-start');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> ตรวจสอบไฟล์...';

    try {
        const res = await fetch(`api.php?action=scan_job_files&job_id=${CURRENT_JOB_ID}`);
        const json = await res.json();
        if (json.status !== 'success') {
            throw new Error(json.message);
        }

        scanResult = json.data;

        if (scanResult.total_in_myfile === 0) {
            showNoticeModal(
                'ไม่พบไฟล์ PDF ในโฟลเดอร์ invoice/pdf',
                'ยังไม่มีไฟล์งานสำหรับดึงข้อมูลในรอบนี้',
                `กรุณานำไฟล์ PDF ที่ต้องการดึงข้อมูลไปวางไว้ใน:\n${scanResult.upload_dir || 'upload/.../invoice/pdf'}\nแล้วกดปุ่ม "ดึงข้อมูลจากโฟลเดอร์ invoice/pdf" อีกครั้ง`,
                'warning'
            );
            return;
        }

        if (scanResult.existing_count > 0 || (scanResult.duplicate_count || 0) > 0) {
            document.getElementById('scanTotalFiles').textContent = scanResult.total_in_myfile.toLocaleString();
            document.getElementById('scanNewCount').textContent = scanResult.new_count.toLocaleString();
            document.getElementById('scanExistingCount').textContent = scanResult.existing_count.toLocaleString();

            const dupRow = document.getElementById('scanDuplicateRow');
            const dupFiles = scanResult.duplicate_files || [];
            dupRow.style.display = dupFiles.length > 0 ? 'flex' : 'none';
            document.getElementById('scanDuplicateCount').textContent = dupFiles.length.toLocaleString();
            document.getElementById('scanDuplicateDetail').textContent = dupFiles.slice(0, 3)
                .map(f => `${f.filename} ซ้ำกับ ${f.duplicate_of}`).join(' | ') + (dupFiles.length > 3 ? ' ...' : '');

            const btnSkip = document.getElementById('btnSkipExisting');
            btnSkip.disabled = false;
            btnSkip.innerHTML = '<i class="fa-solid fa-forward me-1"></i> ข้ามไฟล์ที่ซ้ำ';

            const btnOverwrite = document.getElementById('btnOverwriteAll');
            if (btnOverwrite) {
                btnOverwrite.innerHTML = '<i class="fa-solid fa-arrows-rotate me-1"></i> เขียนทับทั้งหมด';
            }

            duplicateModal.show();
        } else {
            await startBatchExtraction('all');
        }
    } catch (err) {
        showNoticeModal('เกิดข้อผิดพลาด', 'เกิดข้อผิดพลาดในการตรวจสอบไฟล์', err.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-play me-1"></i> ดึงข้อมูลจากโฟลเดอร์ invoice/pdf';
    }
});

async function startBatchExtraction(mode) {
    if (duplicateModal) duplicateModal.hide();
    cancelPdfRenderSchedule();

    let targetFiles = [];
    let overwrite = 0;
    let replaceDuplicates = 0;

    if (mode === 'skip') {
        targetFiles = scanResult ? scanResult.new_files : [];
        overwrite = 0;
    } else if (mode === 'overwrite') {
        targetFiles = scanResult ? [...(scanResult.new_files || []), ...(scanResult.existing_files || []), ...(scanResult.duplicate_files || [])] : [];
        overwrite = 1;
        replaceDuplicates = 1;
    } else {
        targetFiles = scanResult ? scanResult.new_files : [];
        overwrite = 0;
    }

    if (!targetFiles || targetFiles.length === 0) {
        hideLoadingOverlay();
        showNoticeModal(
            'ไม่มีไฟล์ใหม่สำหรับดึงข้อมูล',
            'ข้ามไฟล์ที่มีอยู่เดิมหรือเลขบิลซ้ำทั้งหมดเรียบร้อยแล้ว',
            'หากต้องการดึงข้อมูลซ้ำจากไฟล์เดิม กรุณาเลือก "เขียนทับทั้งหมด"',
            'info'
        );
        return;
    }

    isRunning = true;
    document.getElementById('btn-start').style.display = 'none';
    document.getElementById('btn-stop').style.display = 'inline-block';
    document.getElementById('btn-copy-excel').disabled = true;
    document.getElementById('btn-export-excel').disabled = true;

    const totalFiles = targetFiles.length;
    const BATCH_SIZE = 40;

    showLoadingOverlay(
        'กำลังดึงข้อมูลจากไฟล์ PDF...',
        `เตรียมประมวลผลทั้งหมด ${totalFiles.toLocaleString()} ไฟล์`,
        true,
        true
    );
    updateLoadingOverlay(`เตรียมประมวลผลทั้งหมด ${totalFiles.toLocaleString()} ไฟล์`, `0 / ${totalFiles.toLocaleString()} ไฟล์`, 0);

    if (overwrite) {
        detailedData = [];
        displayData = [];
        document.getElementById('detailed-tbody').innerHTML = '';
    }

    for (let i = 0; i < totalFiles; i += BATCH_SIZE) {
        if (!isRunning) break;

        const chunk = targetFiles.slice(i, i + BATCH_SIZE);
        const currentCount = Math.min(i + chunk.length, totalFiles);
        const pct = Math.round((currentCount / totalFiles) * 100);

        const stepLabel = `กำลังประมวลผลไฟล์: ${i + 1} - ${currentCount} จากทั้งหมด ${totalFiles} ไฟล์...`;
        updateLoadingOverlay(
            stepLabel,
            `${currentCount.toLocaleString()} / ${totalFiles.toLocaleString()} ไฟล์ (${detailedData.length.toLocaleString()} รายการ)`,
            pct
        );

        try {
            const fd = new FormData();
            fd.append('job_id', CURRENT_JOB_ID);
            fd.append('files', JSON.stringify(chunk));
            fd.append('overwrite', overwrite);
            fd.append('replace_duplicates', replaceDuplicates);

            const res = await fetch('api.php?action=extract_job_batch', {
                method: 'POST',
                body: fd
            });
            const json = await res.json();
            if (json.status !== 'success') {
                throw new Error(json.message);
            }

            if (json.data && json.data.length > 0) {
                appendBatchRows(json.data);
            }
        } catch (err) {
            console.error('Batch extraction error:', err);
        }
    }

    isRunning = false;
    document.getElementById('btn-start').style.display = 'inline-block';
    document.getElementById('btn-stop').style.display = 'none';

    try {
        if (overwrite) {
            await loadJobItemsFromDb();
        }
        applySearch();
        loadStats();
        checkExistingReconcile();
    } finally {
        hideLoadingOverlay();
    }

    const leftDuplicates = scanResult && !replaceDuplicates ? (scanResult.duplicate_files || []) : [];
    if (leftDuplicates.length > 0) {
        showNoticeModal(
            'ข้ามไฟล์ที่เลขบิลซ้ำ',
            `ไม่ได้ดึง ${leftDuplicates.length.toLocaleString()} ไฟล์ เพราะเลขบิลซ้ำกับไฟล์ที่ดึงไว้แล้ว`,
            leftDuplicates.slice(0, 8).map(f => `• ${f.filename} ซ้ำกับ ${f.duplicate_of}`).join('\n') +
                (leftDuplicates.length > 8 ? '\n...' : '') + '\n\nไฟล์เหล่านี้ไม่ถูกอ่าน',
            'warning'
        );
    }
}

document.getElementById('btn-stop').addEventListener('click', () => {
    isRunning = false;
    hideLoadingOverlay();
    document.getElementById('btn-start').style.display = 'inline-block';
    document.getElementById('btn-stop').style.display = 'none';
    loadJobItemsFromDb();
});

