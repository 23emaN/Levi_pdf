// VBRP, the third source of the compare (tab 2). The chosen file is posted to job_vbrp_import, which reads it on the
// server and keeps its rows by invoice, never the file. The Job's VBRP counts live on the status bar item
// (#footer-vbrp-rows-item data-rows, data-invoices) the page renders, like the ASN rows next to it.

function vbrpCounts() {
    const item = document.getElementById('footer-vbrp-rows-item');
    return item ? { rows: Number(item.dataset.rows || 0), invoices: Number(item.dataset.invoices || 0) } : { rows: 0, invoices: 0 };
}

function vbrpPillText(counts) {
    return counts.rows > 0
        ? `VBRP: ${counts.invoices.toLocaleString()} บิล / ${counts.rows.toLocaleString()} แถว`
        : 'ยังไม่ได้นำเข้า VBRP';
}

// result is the answer of job_vbrp_import; replaced_invoices lists the bills whose earlier rows the file replaced
function vbrpImportToast(result) {
    const replaced = result.replaced_invoices.length;
    const ignored = result.ignored_sheets || [];
    return `นำเข้า VBRP ${Number(result.rows).toLocaleString()} แถว ${Number(result.invoices).toLocaleString()} บิล`
        + (replaced > 0 ? ` แทนที่ ${replaced.toLocaleString()} บิลเดิม` : '')
        + (ignored.length ? ` (ข้ามชีต ${ignored.join(', ')})` : '');
}

// The lines the compare notice adds from summary.vbrp, which the server sends only when the Job has VBRP rows
function vbrpCoverageLines(coverage) {
    if (!coverage) return [];
    return [`• บิลที่มีใน VBRP: ${Number(coverage.covered_bills).toLocaleString()} บิล`]
        .concat(coverage.uncovered_bills > 0 ? [`• บิลที่ไม่มีใน VBRP (เทียบแค่ PDF กับ ASN): ${Number(coverage.uncovered_bills).toLocaleString()} บิล`] : []);
}

function showVbrpCounts(counts) {
    const item = document.getElementById('footer-vbrp-rows-item');
    item.dataset.rows = Number(counts.rows || 0);
    item.dataset.invoices = Number(counts.invoices || 0);
    const shown = vbrpCounts();
    setStatusBarCount('footer-vbrp-rows-count', shown.rows);
    if (shown.rows > 0) item.title = `${shown.invoices.toLocaleString()} บิล`;
    else item.removeAttribute('title');
    document.getElementById('vbrp-pill-text').textContent = vbrpPillText(shown);
    document.getElementById('btn-clear-vbrp').disabled = shown.rows === 0;
    updateAsnToolbarStep();
}

async function postVbrpAction(action, fd) {
    const res = await fetch(`api.php?action=${action}`, { method: 'POST', body: fd });
    const json = await res.json();
    if (json.status !== 'success') {
        throw new Error(json.message || 'ทำรายการ VBRP ไม่สำเร็จ');
    }
    return json;
}

// A compared Job shows its results again with the new VBRP rows (the compare reads them itself); a Job not compared yet
// only needs its pill, step and compare button, which showVbrpCounts already set
async function reloadComparedResults(subtitle) {
    if (!reconData || !reconData.summary) return;
    showLoadingOverlay('กำลังเปรียบเทียบข้อมูลกับ PDF...', subtitle);
    await checkExistingReconcile();
}

async function handleVbrpFileSelect(event) {
    const file = (event.target.files || [])[0];
    event.target.value = '';
    if (!file) return;

    showLoadingOverlay('กำลังอ่านไฟล์ VBRP (.xlsx)...', file.name);
    try {
        const fd = new FormData();
        fd.append('job_id', CURRENT_JOB_ID);
        fd.append('file', file);
        const json = await postVbrpAction('job_vbrp_import', fd);
        showVbrpCounts(json.vbrp_counts);
        await reloadComparedResults('ข้อมูล VBRP เปลี่ยน ระบบเทียบสามแหล่งใหม่ให้อัตโนมัติ');
        hideLoadingOverlay();
        showToast(vbrpImportToast(json));
    } catch (err) {
        hideLoadingOverlay();
        console.error(err);
        showNoticeModal('นำเข้าไฟล์ VBRP ไม่สำเร็จ', err.message, file.name, 'error');
    }
}

function openVbrpClearModal() {
    const counts = vbrpCounts();
    document.getElementById('vbrpClearScope').textContent = `${counts.rows.toLocaleString()} แถว ${counts.invoices.toLocaleString()} บิล`;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('vbrpClearModal')).show();
}

async function executeVbrpClear() {
    bootstrap.Modal.getOrCreateInstance(document.getElementById('vbrpClearModal')).hide();
    showLoadingOverlay('กำลังล้างข้อมูล VBRP...', 'กำลังลบแถว VBRP ของ Job นี้');
    try {
        const fd = new FormData();
        fd.append('job_id', CURRENT_JOB_ID);
        const json = await postVbrpAction('job_vbrp_clear', fd);
        showVbrpCounts({ rows: 0, invoices: 0 });
        await reloadComparedResults('ล้างข้อมูล VBRP แล้ว ระบบเทียบ PDF กับ ASN ใหม่ให้อัตโนมัติ');
        hideLoadingOverlay();
        showToast(`ล้างข้อมูล VBRP ${Number(json.deleted_rows || 0).toLocaleString()} แถว`);
    } catch (err) {
        hideLoadingOverlay();
        console.error(err);
        showNoticeModal('ล้างข้อมูล VBRP ไม่สำเร็จ', err.message, '', 'error');
    }
}
