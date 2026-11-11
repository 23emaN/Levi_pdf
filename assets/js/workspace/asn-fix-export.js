function getPendingFixScope(invNo) {
    const invoices = new Set();
    let lines = 0;
    ((reconData && reconData.results) || []).forEach(r => {
        if (r.status !== 'PENDING_FIX') return;
        const inv = (r.asn && r.asn.invoice_no) || (r.pdf && r.pdf.tax_invoice) || '';
        const pdfInv = (r.pdf && r.pdf.tax_invoice) || '';
        if (invNo && inv !== invNo && pdfInv !== invNo) return;
        lines++;
        if (inv) invoices.add(inv);
    });
    return { invoices: invoices.size, lines };
}

function showFixConfirmModal(title, detail, note) {
    document.getElementById('fixModalTitle').textContent = title;
    document.getElementById('fixModalTargetText').textContent = detail;
    document.getElementById('fixModalDesc').textContent = note;
    if (confirmFixModal) confirmFixModal.show();
}

function openFixSingleConfirmModal(invNo) {
    pendingFixInvoiceTarget = invNo;
    const scope = getPendingFixScope(invNo);
    showFixConfirmModal(
        `Tax Invoice ${invNo}`,
        `${scope.lines.toLocaleString()} รายการสินค้า`,
        'ระบบจะแยกทุกบิลในไฟล์ PDF เดียวกับบิลนี้ด้วย ไฟล์ PDF ต้นฉบับยังอยู่ครบ ไม่ถูกลบหรือเขียนทับ'
    );
}

function openFixAllConfirmModal() {
    pendingFixInvoiceTarget = null;
    const scope = getPendingFixScope('');
    showFixConfirmModal(
        'บิลที่รอแก้ไข PDF ทั้งหมด',
        `${scope.invoices.toLocaleString()} บิล (${scope.lines.toLocaleString()} รายการสินค้า)`,
        'ไฟล์ PDF ต้นฉบับยังอยู่ครบ ไม่ถูกลบหรือเขียนทับ'
    );
}

async function executeConfirmedFix() {
    if (confirmFixModal) confirmFixModal.hide();
    const isSingle = (pendingFixInvoiceTarget !== null && pendingFixInvoiceTarget !== '');
    const actionTitle = isSingle ? `กำลังแก้ไขบิล ${pendingFixInvoiceTarget}...` : 'กำลังแก้ไขและแยกไฟล์ทั้งหมด...';
    const actionDesc = 'กรุณารอสักครู่ ระบบกำลังปรับปรุงฐานข้อมูลและตัดแยกหน้า PDF ใน finished/';

    await runWithLoading(actionTitle, actionDesc, async () => {
        const fd = new FormData();
        fd.append('job_id', CURRENT_JOB_ID);
        if (isSingle) {
            fd.append('invoice_no', pendingFixInvoiceTarget);
        }
        const endpoint = isSingle ? 'api.php?action=fix_invoice' : 'api.php?action=fix_all_invoices';
        const res = await fetch(endpoint, { method: 'POST', body: fd });
        const json = await res.json();
        if (json.status !== 'success') {
            showNoticeModal('เกิดข้อผิดพลาดในการแก้ไข', json.message || 'ไม่สามารถดำเนินการได้', '', 'error');
            return;
        }

        const resultData = json.data || {};
        await checkExistingReconcile();
        // The server refuses without touching anything, e.g. a bill that waits for the ASN (the grid may be older
        // than the ASN); the grid is reloaded above so it shows the status the server holds
        if (resultData.success === false) {
            showNoticeModal('แก้ไขไม่ได้', resultData.message || 'ไม่สามารถดำเนินการได้', '', 'warning');
            return;
        }
        await loadJobItemsFromDb();
        await loadStats();

        const splitCount = (resultData.split_files_created || []).length;
        const updatedCount = resultData.updated_items_count || 0;
        const clearedFooters = resultData.cleared_footers_count || 0;

        showNoticeModal(
            'ดำเนินการแก้ไขเรียบร้อยแล้ว',
            'ระบบได้ปรับปรุงข้อมูลและแยกไฟล์ PDF เรียบร้อยแล้ว',
            `• ไฟล์ PDF ที่สร้างใหม่ใน finished/: ${splitCount} ไฟล์
` +
            `• รายการสินค้าที่ปรับปรุง: ${updatedCount} แถว
` +
            `• หน้าที่ลบยอดรวมท้ายบิลซ้ำ: ${clearedFooters} แถว`,
            'success'
        );
    });
}

async function exportReconcileToExcel() {
    if (!reconDisplayData || reconDisplayData.length === 0) return;
    await ensureXlsxLoaded();

    const rows = reconExcelRows(reconDisplayData);
    const ws = XLSX.utils.json_to_sheet(rows);
    const colWidths = [];
    const headerKeys = Object.keys(rows[0]);
    headerKeys.forEach(k => {
        let maxLen = k.length;
        rows.forEach(r => {
            const val = String(r[k] || '');
            if (val.length > maxLen) maxLen = Math.min(val.length, 50);
        });
        colWidths.push({ wch: maxLen + 3 });
    });
    ws['!cols'] = colWidths;

    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Reconciliation');
    XLSX.writeFile(wb, `Job_${CURRENT_JOB_ID}_Reconciliation_Report.xlsx`);
}
