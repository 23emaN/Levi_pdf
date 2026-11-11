async function postPrint(action, fields) {
    const fd = new FormData();
    fd.append('job_id', CURRENT_JOB_ID);
    Object.keys(fields || {}).forEach(k => fd.append(k, fields[k]));
    const res = await fetch(`api.php?action=${action}`, { method: 'POST', body: fd });
    const json = await res.json();
    if (json.status !== 'success') {
        throw new Error(json.message || 'ไม่สามารถดำเนินการได้');
    }
    return json.data;
}

function describeSkipped(skipped) {
    const parts = [];
    if (skipped.pending_fix) parts.push(`รอแก้ไข PDF ${skipped.pending_fix.toLocaleString()} บิล`);
    if (skipped.pending_asn) parts.push(`รอแก้ไข ASN ${skipped.pending_asn.toLocaleString()} บิล`);
    if (skipped.mismatch) parts.push(`ผิดพลาด ${skipped.mismatch.toLocaleString()} บิล`);
    if (skipped.printed) parts.push(`สั่งพิมพ์ไปแล้ว ${skipped.printed.toLocaleString()} บิล`);
    return parts.length ? `ไม่รวม ${parts.join(' | ')}` : '';
}

async function openPrintConfirmModal() {
    const btn = document.getElementById('btn-print');
    btn.disabled = true;
    try {
        const res = await fetch(`api.php?action=print_plan&job_id=${CURRENT_JOB_ID}`);
        const json = await res.json();
        if (json.status !== 'success') {
            throw new Error(json.message);
        }
        const plan = json.data;
        if (plan.ready_count === 0) {
            showNoticeModal('ไม่มีบิลใหม่ให้สั่งพิมพ์', 'บิลที่สมบูรณ์ถูกสั่งพิมพ์ไปแล้วทั้งหมด หรือยังไม่มีบิลที่สมบูรณ์', describeSkipped(plan.skipped), 'info');
            return;
        }
        document.getElementById('printModalTitle').textContent = `สร้าง print ครั้งที่ ${plan.round}`;
        document.getElementById('printModalDetail').textContent = `${plan.ready_count.toLocaleString()} บิล จาก ${plan.store_count.toLocaleString()} สาขา`;
        document.getElementById('printModalFolder').textContent = plan.folder;
        document.getElementById('printModalSkipped').textContent = describeSkipped(plan.skipped);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('confirmPrintModal')).show();
    } catch (err) {
        showNoticeModal('เกิดข้อผิดพลาด', err.message, '', 'error');
    } finally {
        updateAsnToolbarStep();
    }
}

async function executePrint() {
    bootstrap.Modal.getOrCreateInstance(document.getElementById('confirmPrintModal')).hide();
    showLoadingOverlay('กำลังสั่งพิมพ์...', 'กำลังเตรียมรายการบิล', true);
    try {
        const started = await postPrint('print_start');
        if (!started.token) {
            hideLoadingOverlay();
            showNoticeModal('ไม่มีบิลใหม่ให้สั่งพิมพ์', 'ไม่พบบิลที่พร้อมสั่งพิมพ์ในตอนนี้', '', 'info');
            return;
        }

        const total = started.total;
        for (let offset = 0; offset < total; offset += 40) {
            const upto = Math.min(offset + 40, total);
            updateLoadingOverlay(`กำลังคัดลอกไฟล์ ${upto.toLocaleString()} / ${total.toLocaleString()} บิล`, '', Math.round((offset / total) * 100));
            await postPrint('print_chunk', { token: started.token, offset });
        }
        updateLoadingOverlay('กำลังสร้างโฟลเดอร์รอบการสั่งพิมพ์', '', 100);
        const summary = await postPrint('print_finish', { token: started.token });

        await checkExistingReconcile();
        hideLoadingOverlay();

        if (summary.created === 0) {
            showNoticeModal('สั่งพิมพ์ไม่สำเร็จ', 'ไม่สามารถสร้างไฟล์ได้เลย จึงไม่ได้สร้างโฟลเดอร์รอบใหม่', summary.failed.slice(0, 8).map(f => `• ${f.invoice}: ${f.reason}`).join('\n'), 'error');
            return;
        }
        const lines = [
            `• สร้าง print ครั้งที่ ${summary.round}: ${summary.created.toLocaleString()} ไฟล์ จาก ${summary.stores.toLocaleString()} สาขา`,
            `• โฟลเดอร์: ${summary.folder}`
        ];
        if (summary.failed.length > 0) {
            lines.push(`• สั่งพิมพ์ไม่สำเร็จ ${summary.failed.length.toLocaleString()} บิล (ยังไม่ถูกนับว่าสั่งพิมพ์แล้ว)`);
            summary.failed.slice(0, 8).forEach(f => lines.push(`   ${f.invoice}: ${f.reason}`));
        }
        showNoticeModal('สั่งพิมพ์เรียบร้อยแล้ว', 'คัดลอกไฟล์ที่พร้อมสั่งพิมพ์เรียบร้อยแล้ว', lines.join('\n'), summary.failed.length > 0 ? 'warning' : 'success', {
            label: '<i class="fa-regular fa-folder-open me-1"></i> เปิดโฟลเดอร์',
            onClick: () => openJobFolder('round', summary.round)
        });
    } catch (err) {
        hideLoadingOverlay();
        console.error(err);
        showNoticeModal('เกิดข้อผิดพลาดในการสั่งพิมพ์', err.message, '', 'error');
    }
}
