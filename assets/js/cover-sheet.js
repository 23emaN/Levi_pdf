// cover_sheet.php: the stores and counts of the cover sheet (cover_preview) and the PDF (cover_generate)

function coverFileUrl(file, download) {
    return `cover_download.php?job_id=${CURRENT_JOB_ID}&file=${encodeURIComponent(file)}${download ? '&download=1' : ''}`;
}

function renderCoverWarnings(data) {
    const notes = [];
    if (!data.tax_compared) notes.push('Tax Invoice ของ Job นี้ยังไม่ได้เทียบกับ ASN จึงยังไม่มี Tax Invoice บนใบปะหน้า');
    if (!data.cn_compared) notes.push('ใบลดหนี้ของ Job นี้ยังไม่ได้เทียบกับ ASN จึงยังไม่มีใบลดหนี้บนใบปะหน้า');
    const skipped = [];
    if (data.skipped.invoices) skipped.push(`Tax Invoice ที่ยังไม่สมบูรณ์ ${data.skipped.invoices.toLocaleString()} บิล`);
    if (data.skipped.credit_notes) skipped.push(`ใบลดหนี้ที่ผิดพลาด ${data.skipped.credit_notes.toLocaleString()} ใบ`);
    if (skipped.length) notes.push(`ไม่รวม ${skipped.join(' และ ')}`);
    if (data.unnamed.length) notes.push(`ไม่พบชื่อสาขา ${data.unnamed.join(', ')} ช่องชื่อสาขาจะว่าง (เพิ่มชื่อได้ใน assets/cover/store_names.json)`);
    document.getElementById('cover-warnings').innerHTML = notes
        .map(n => `<div class="cover-warning"><i class="fa-solid fa-circle-info me-2"></i>${escapeHtml(n)}</div>`).join('');
}

function renderCoverStats(totals, storeCount) {
    const stat = (label, value) => `<div class="cover-stat"><span>${label}</span><strong class="tabular-num">${value.toLocaleString()}</strong></div>`;
    document.getElementById('cover-stats').innerHTML =
        stat('สาขา', storeCount) + stat('Tax Invoice', totals.invoices) + stat('Credit Note', totals.credit_notes) + stat('หน้า', totals.pages);
}

function renderCoverStores(stores) {
    const body = document.getElementById('cover-stores');
    if (!stores.length) {
        body.innerHTML = '<tr><td colspan="6" class="text-center text-secondary py-4">ยังไม่มีเอกสารที่สมบูรณ์</td></tr>';
        return;
    }
    body.innerHTML = stores.map((s, i) => `
        <tr>
            <td class="text-center tabular-num text-secondary">${i + 1}</td>
            <td class="tabular-num">${escapeHtml(s.store_no)}</td>
            <td>${s.store_name ? escapeHtml(s.store_name) : '<span class="text-warning">ไม่พบชื่อสาขา</span>'}</td>
            <td class="text-end tabular-num">${s.invoices ? s.invoices.toLocaleString() : '-'}</td>
            <td class="text-end tabular-num">${s.credit_notes ? s.credit_notes.toLocaleString() : '-'}</td>
            <td class="text-end tabular-num">${s.pages.toLocaleString()}</td>
        </tr>`).join('');
}

function renderCoverFiles(files) {
    const list = document.getElementById('cover-files');
    if (!files.length) {
        list.innerHTML = '<li class="text-secondary">ยังไม่มี</li>';
        return;
    }
    list.innerHTML = files.map(f => `
        <li>
            <a href="${coverFileUrl(f.file, false)}" target="_blank" rel="noopener"><i class="fa-regular fa-file-pdf me-1 text-danger"></i>${escapeHtml(f.file)}</a>
            <a href="${coverFileUrl(f.file, true)}" class="ms-auto text-secondary" title="ดาวน์โหลด"><i class="fa-solid fa-download"></i></a>
        </li>`).join('');
}

async function loadCoverPreview() {
    const button = document.getElementById('cover-generate');
    button.disabled = true;
    try {
        const res = await fetch(`api.php?action=cover_preview&job_id=${CURRENT_JOB_ID}`);
        const json = await res.json();
        if (json.status !== 'success') throw new Error(json.message);
        const data = json.data;
        renderCoverWarnings(data);
        renderCoverStats(data.totals, data.stores.length);
        renderCoverStores(data.stores);
        renderCoverFiles(data.files);
        button.disabled = data.totals.pages === 0;
    } catch (err) {
        document.getElementById('cover-warnings').innerHTML =
            `<div class="cover-warning cover-error"><i class="fa-solid fa-triangle-exclamation me-2"></i>${escapeHtml(err.message)}</div>`;
    }
}

async function generateCover(event) {
    event.preventDefault();
    const fd = new FormData(document.getElementById('cover-form'));
    fd.append('job_id', CURRENT_JOB_ID);
    showLoadingOverlay('กำลังสร้างใบปะหน้า...', 'กรุณารอสักครู่');
    try {
        const res = await fetch('api.php?action=cover_generate', { method: 'POST', body: fd });
        const json = await res.json();
        if (json.status !== 'success') throw new Error(json.message);
        hideLoadingOverlay();
        window.open(coverFileUrl(json.data.file, false), '_blank', 'noopener');
        showToast(`สร้างใบปะหน้า ${json.data.pages.toLocaleString()} หน้าแล้ว`);
        await loadCoverPreview();
    } catch (err) {
        hideLoadingOverlay();
        alert(`สร้างใบปะหน้าไม่สำเร็จ: ${err.message}`);
    }
}

document.getElementById('cover-form').addEventListener('submit', generateCover);
document.getElementById('cover-refresh').addEventListener('click', loadCoverPreview);
loadCoverPreview();
