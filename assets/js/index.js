let allJobs = [];
let createModalInstance = null;
let deleteModalInstance = null;

const DELETE_JOB_WAIT_MS = 3000;
const deleteJob = { id: null, name: '', openedAt: 0, busy: false, summaryToken: 0, timer: null };

document.addEventListener('DOMContentLoaded', () => {
    createModalInstance = new bootstrap.Modal(document.getElementById('createJobModal'));
    const deleteModalEl = document.getElementById('deleteJobModal');
    deleteModalInstance = new bootstrap.Modal(deleteModalEl);
    deleteModalEl.addEventListener('shown.bs.modal', () => document.getElementById('deleteJobConfirmInput').focus());
    deleteModalEl.addEventListener('hidden.bs.modal', () => {
        clearInterval(deleteJob.timer);
        deleteJob.id = null;
    });
    const confirmInput = document.getElementById('deleteJobConfirmInput');
    confirmInput.addEventListener('input', renderDeleteJobButton);
    confirmInput.addEventListener('keydown', event => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        if (canDeleteJob()) executeDeleteJob();
    });

    if (Array.isArray(window.INITIAL_JOBS)) {
        allJobs = window.INITIAL_JOBS;
        const footerCount = document.getElementById('footer-count');
        if (footerCount) footerCount.textContent = allJobs.length.toLocaleString();
        renderJobsTable(allJobs);
    } else {
        loadJobs();
    }

    setTimeout(loadStats, 10);
    window.addEventListener('focus', loadJobs);

    document.getElementById('btnConfirmDeleteJob').addEventListener('click', executeDeleteJob);

    const searchInput = document.getElementById('search-jobs');
    const clearBtn = document.getElementById('btn-clear-search');
    searchInput.addEventListener('input', () => {
        clearBtn.style.display = searchInput.value ? 'block' : 'none';
        filterJobs();
    });
    clearBtn.addEventListener('click', () => {
        searchInput.value = '';
        clearBtn.style.display = 'none';
        filterJobs();
        searchInput.focus();
    });
});

async function loadStats() {
    try {
        const res = await fetch('api.php?action=get_stats&only=myfile');
        const json = await res.json();
        if (json.status === 'success') {
            document.getElementById('stat-myfile').textContent = json.data.myfile_count.toLocaleString();
        }
    } catch (e) {}
}

async function loadJobs() {
    const tbody = document.getElementById('jobs-tbody');
    try {
        const res = await fetch('api.php?action=list_jobs');
        const json = await res.json();

        if (json.status !== 'success') {
            throw new Error(json.message);
        }

        allJobs = json.data || [];
        const footerCount = document.getElementById('footer-count');
        if (footerCount) footerCount.textContent = allJobs.length.toLocaleString();
        filterJobs();
    } catch (err) {
        console.error(err);
        tbody.innerHTML = `<tr><td colspan="8" class="text-center text-danger py-4">เกิดข้อผิดพลาดในการโหลดรายการ Job: ${err.message}</td></tr>`;
    }
}

function renderJobsTable(jobs) {
    const tbody = document.getElementById('jobs-tbody');
    if (!jobs || jobs.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="8" class="text-center py-5 text-muted">
                    <i class="fa-regular fa-folder-open fs-1 d-block mb-3 text-secondary opacity-50"></i>
                    <span>ยังไม่มี Job ในระบบ กดปุ่ม <strong>"สร้าง Job ใหม่"</strong> ด้านบนเพื่อเริ่มสร้างรอบบิล</span>
                </td>
            </tr>
        `;
        return;
    }

    let html = '';
    jobs.forEach((j, idx) => {
        const filesCount = parseInt(j.live_files_count || j.total_files || 0, 10);
        const itemsCount = parseInt(j.live_items_count || j.total_items || 0, 10);
        const amount = parseFloat(j.live_amount || j.total_amount || 0);
        const asnFilename = j.asn_filename || '';
        const asnMatched = parseInt(j.asn_matched_count || 0, 10);
        const asnMismatch = parseInt(j.asn_mismatch_count || 0, 10);

        let asnBadge = '<span class="badge bg-light text-secondary border px-2 py-1"><i class="fa-regular fa-circle-question me-1"></i>ยังไม่ได้เทียบ ASN</span>';
        if (asnFilename) {
            if (asnMismatch > 0) {
                asnBadge = `<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1" title="${escapeHtml(asnFilename)}">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i>เทียบแล้ว (คลาดเคลื่อน ${asnMismatch} รายการ)
                </span>`;
            } else if (asnMatched > 0) {
                asnBadge = `<span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1" title="${escapeHtml(asnFilename)}">
                    <i class="fa-solid fa-check me-1"></i>ตรงกับ ASN 100% (${asnMatched} รายการ)
                </span>`;
            }
        }

        const createdDate = formatDateDdMmYyyy(j.created_at);

        html += `
            <tr onclick="navigateToJob(event, ${j.id})" title="คลิกเพื่อเปิดเข้าสู่ Job ${escapeHtml(j.name)}">
                <td class="text-center tabular-num text-muted">${idx + 1}</td>
                <td>
                    <span class="job-link">
                        <i class="fa-solid fa-folder text-warning me-1"></i>
                        <span>${escapeHtml(j.name)}</span>
                    </span>
                    <span class="badge bg-light text-secondary border tabular-num ms-1">#${j.id}</span>
                </td>
                <td class="text-center tabular-num fw-semibold">${filesCount.toLocaleString()}</td>
                <td class="text-center tabular-num fw-semibold text-primary">${itemsCount.toLocaleString()}</td>
                <td class="text-end tabular-num fw-bold text-success">฿${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                <td>${asnBadge}</td>
                <td class="tabular-num text-muted small">${createdDate}</td>
                <td class="text-center" onclick="event.stopPropagation()">
                    <button class="btn btn-outline-danger btn-sm px-2 py-1" style="border-radius: 6px; font-size: 0.8rem;" title="ลบ Job นี้" 
                            onclick="openDeleteJobModal(event, ${j.id})">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>
                </td>
            </tr>
        `;
    });

    tbody.innerHTML = html;
    initColumnResizers('#jobs-table');
}

function navigateToJob(event, jobId) {
    window.location.href = `job.php?job_id=${jobId}`;
}

function filterJobs() {
    const query = (document.getElementById('search-jobs').value || '').toLowerCase().trim();
    if (!query) {
        renderJobsTable(allJobs);
        return;
    }
    const filtered = allJobs.filter(j => {
        const name = (j.name || '').toLowerCase();
        const id = String(j.id);
        return name.includes(query) || id.includes(query);
    });
    renderJobsTable(filtered);
}

function openCreateJobModal() {
    const input = document.getElementById('jobNameInput');
    input.value = '';
    createModalInstance.show();
    setTimeout(() => input.focus(), 300);
}

async function handleCreateJob(event) {
    event.preventDefault();
    const btn = document.getElementById('btnSubmitCreateJob');
    const input = document.getElementById('jobNameInput');
    const jobName = input.value.trim();

    if (!jobName) return;

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> กำลังสร้าง...';

    try {
        const fd = new FormData();
        fd.append('name', jobName);

        const res = await fetch('api.php?action=create_job', {
            method: 'POST',
            body: fd
        });
        const json = await res.json();

        if (json.status !== 'success') {
            throw new Error(json.message);
        }

        createModalInstance.hide();
        window.location.href = `job.php?job_id=${json.data.id}`;
    } catch (err) {
        alert('เกิดข้อผิดพลาดในการสร้าง Job: ' + err.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-check me-1"></i>สร้าง Job ทันที';
    }
}

function openDeleteJobModal(event, jobId) {
    if (event) event.stopPropagation();
    const job = allJobs.find(j => Number(j.id) === Number(jobId));
    if (!job) return;
    clearInterval(deleteJob.timer);
    Object.assign(deleteJob, { id: Number(job.id), name: String(job.name || ''), openedAt: Date.now(), busy: false, summaryToken: deleteJob.summaryToken + 1 });

    document.getElementById('deleteJobModalName').textContent = deleteJob.name;
    document.getElementById('deleteJobConfirmName').textContent = deleteJob.name;
    document.getElementById('deleteJobConfirmInput').value = '';
    const printed = job.print_file_count || 0;
    const warning = document.getElementById('deleteJobPrintWarning');
    warning.textContent = `รวมไฟล์ที่สั่งพิมพ์ไปแล้ว ${Number(printed).toLocaleString()} ไฟล์ในโฟลเดอร์ print`;
    warning.classList.toggle('d-none', printed === 0);

    loadDeleteJobSummary(deleteJob.id, deleteJob.summaryToken);
    deleteJob.timer = setInterval(() => {
        renderDeleteJobButton();
        if (deleteWaitLeftMs() === 0) clearInterval(deleteJob.timer);
    }, 200);
    renderDeleteJobButton();
    deleteModalInstance.show();
}

function deleteWaitLeftMs() {
    return Math.max(0, DELETE_JOB_WAIT_MS - (Date.now() - deleteJob.openedAt));
}

function canDeleteJob() {
    return deleteJob.id !== null && !deleteJob.busy && deleteWaitLeftMs() === 0
        && document.getElementById('deleteJobConfirmInput').value.trim() === deleteJob.name.trim();
}

function renderDeleteJobButton() {
    const left = Math.ceil(deleteWaitLeftMs() / 1000);
    document.getElementById('btnConfirmDeleteJob').disabled = !canDeleteJob();
    document.getElementById('btnConfirmDeleteJobLabel').textContent = deleteJob.busy ? 'กำลังลบ...' : (left > 0 ? `ลบ Job (${left})` : 'ลบ Job');
}

function deleteJobSummaryLines(s) {
    const n = value => Number(value || 0).toLocaleString();
    const lines = [
        `PDF ${n(s.pdf_files)} ไฟล์ (${n(s.pdf_rows)} รายการ)`,
        `ASN ${n(s.asn_files)} ไฟล์ (${n(s.asn_rows)} รายการ)`,
        `ไฟล์ที่สั่งพิมพ์ ${n(s.print_files)} ไฟล์ ใน ${n(s.print_rounds)} รอบ`,
        `บิลที่แก้ไขแล้ว ${n(s.fixed_bills)} บิล`
    ];
    if (s.cn_files > 0 || s.cn_documents > 0) {
        lines.push(`ใบลดหนี้ ${n(s.cn_files)} ไฟล์ (${n(s.cn_documents)} ใบ)`);
    }
    if (s.cn_asn_rows > 0) {
        lines.push(`ASN ของใบลดหนี้ ${n(s.cn_asn_rows)} รายการ`);
    }
    if (s.vbrp_rows > 0) {
        lines.push(`VBRP ${n(s.vbrp_rows)} แถว`);
    }
    if (s.upload_files > 0) {
        lines.push(`ไฟล์ PDF ที่ยังรอดึงข้อมูลในโฟลเดอร์ TAX INVOICE/upload here ${n(s.upload_files)} ไฟล์`);
    }
    return lines;
}

async function loadDeleteJobSummary(jobId, summaryToken) {
    const loading = document.getElementById('deleteJobSummaryLoading');
    const list = document.getElementById('deleteJobSummaryList');
    loading.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> กำลังนับข้อมูลของ Job นี้...';
    loading.classList.remove('d-none');
    list.classList.add('d-none');
    list.innerHTML = '';
    try {
        const res = await fetch(`api.php?action=job_delete_summary&job_id=${jobId}`);
        const json = await res.json();
        if (summaryToken !== deleteJob.summaryToken) return;
        if (json.status !== 'success') {
            throw new Error(json.message);
        }
        list.innerHTML = deleteJobSummaryLines(json.data).map(line => `<li>${escapeHtml(line)}</li>`).join('');
        loading.classList.add('d-none');
        list.classList.remove('d-none');
    } catch (err) {
        if (summaryToken !== deleteJob.summaryToken) return;
        loading.textContent = 'นับข้อมูลไม่สำเร็จ: ' + err.message;
    }
}

async function executeDeleteJob() {
    if (!canDeleteJob()) return;
    const fd = new FormData();
    fd.append('job_id', deleteJob.id);
    fd.append('confirm_name', document.getElementById('deleteJobConfirmInput').value.trim());
    deleteJob.busy = true;
    renderDeleteJobButton();

    try {
        const res = await fetch('api.php?action=delete_job', {
            method: 'POST',
            body: fd
        });
        const json = await res.json();
        if (json.status !== 'success') {
            throw new Error(json.message);
        }

        deleteModalInstance.hide();
        loadJobs();
    } catch (err) {
        alert('เกิดข้อผิดพลาดในการลบ Job: ' + err.message);
    } finally {
        deleteJob.busy = false;
        renderDeleteJobButton();
    }
}
