const thCollator = new Intl.Collator('th', { numeric: true, sensitivity: 'base' });
let toastTimeout = null;

function debounce(fn, delay = 120) {
    let timer = null;
    return function (...args) {
        if (timer) clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), delay);
    };
}

function escapeHtml(str) {
    if (str === null || str === undefined || str === '') return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function formatMoney(val) {
    if (val === undefined || val === null || val === '') return '0.00';
    const clean = String(val).replace(/,/g, '');
    const num = parseFloat(clean);
    if (isNaN(num)) return '0.00';
    return num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatDateDdMmYyyy(dateStr) {
    if (!dateStr) return '-';
    const parts = dateStr.split(/[- :]/);
    if (parts.length >= 3) {
        const y = parts[0];
        const m = String(parts[1]).padStart(2, '0');
        const d = String(parts[2]).padStart(2, '0');
        const timeHint = parts.length >= 5 ? ` เวลา ${parts[3]}:${parts[4]} น.` : '';
        return `<span title="สร้างเมื่อ ${d}/${m}/${y}${timeHint}">${d}/${m}/${y}</span>`;
    }
    return dateStr;
}

function showToast(message) {
    const toast = document.getElementById('app-toast');
    const msgEl = document.getElementById('toast-msg');
    if (!toast || !msgEl) return;
    msgEl.textContent = message;
    toast.classList.add('show');
    if (toastTimeout) clearTimeout(toastTimeout);
    toastTimeout = setTimeout(() => {
        toast.classList.remove('show');
    }, 2200);
}

function showLoadingOverlay(title, subtitle, showProgress = false, showStop = false) {
    const overlay = document.getElementById('app-loading-overlay');
    const titleEl = document.getElementById('loading-overlay-title');
    const subEl = document.getElementById('loading-overlay-sub');
    const progBox = document.getElementById('loading-overlay-progress-box');
    const stopBtn = document.getElementById('loading-overlay-stop-btn');

    if (titleEl && title) titleEl.textContent = title;
    if (subEl && subtitle) subEl.textContent = subtitle;
    if (progBox) progBox.classList.toggle('d-none', !showProgress);
    if (stopBtn) stopBtn.classList.toggle('d-none', !showStop);
    if (overlay) overlay.classList.remove('d-none');
}

function updateLoadingOverlay(subtitle, stepText, pct) {
    const subEl = document.getElementById('loading-overlay-sub');
    const stepEl = document.getElementById('loading-overlay-step');
    const pctEl = document.getElementById('loading-overlay-pct');
    const barEl = document.getElementById('loading-overlay-bar');

    if (subEl && subtitle) subEl.textContent = subtitle;
    if (stepEl && stepText !== undefined) stepEl.textContent = stepText;
    if (pctEl && pct !== undefined) pctEl.textContent = `${pct}%`;
    if (barEl && pct !== undefined) barEl.style.width = `${pct}%`;
}

function hideLoadingOverlay() {
    const overlay = document.getElementById('app-loading-overlay');
    if (overlay) overlay.classList.add('d-none');
}

function runWithLoading(title, subtitle, workFn) {
    let timer = setTimeout(() => {
        showLoadingOverlay(title, subtitle, false, false);
    }, 120);
    try {
        const res = workFn();
        if (res && typeof res.then === 'function') {
            return res.finally(() => {
                clearTimeout(timer);
                hideLoadingOverlay();
            });
        }
        clearTimeout(timer);
        hideLoadingOverlay();
        return res;
    } catch (err) {
        clearTimeout(timer);
        hideLoadingOverlay();
        throw err;
    }
}

function initColumnResizers(tableSelector = 'table') {
    document.querySelectorAll(`${tableSelector} thead th`).forEach(th => {
        if (th.querySelector('.resizer')) return;

        const resizer = document.createElement('div');
        resizer.classList.add('resizer');
        th.appendChild(resizer);

        let startX, startWidth, animFrameId;

        resizer.addEventListener('mousedown', (e) => {
            e.stopPropagation();
            e.preventDefault();

            startX = e.pageX;
            startWidth = th.offsetWidth;
            resizer.classList.add('resizing');

            const onMouseMove = (moveEvent) => {
                if (animFrameId) cancelAnimationFrame(animFrameId);
                animFrameId = requestAnimationFrame(() => {
                    const diffX = moveEvent.pageX - startX;
                    const newWidth = Math.max(50, startWidth + diffX);
                    th.style.width = newWidth + 'px';
                    // The Description column is locked by a CSS variable (app.css), so a drag has to move it
                    if (th.dataset.col === 'description') {
                        document.documentElement.style.setProperty('--col-desc-width', newWidth + 'px');
                    }
                });
            };

            const onMouseUp = () => {
                if (animFrameId) cancelAnimationFrame(animFrameId);
                resizer.classList.remove('resizing');
                document.removeEventListener('mousemove', onMouseMove);
                document.removeEventListener('mouseup', onMouseUp);
            };

            document.addEventListener('mousemove', onMouseMove, { passive: true });
            document.addEventListener('mouseup', onMouseUp);
        });
    });
}

function resetSortIcons(tableSelector = 'table') {
    document.querySelectorAll(`${tableSelector} thead th`).forEach(th => {
        th.classList.remove('sorted');
        const icon = th.querySelector('.sort-icon');
        if (icon) icon.className = 'fa-solid fa-sort sort-icon';
    });
}

function copyTextToClipboard(text) {
    return new Promise((resolve, reject) => {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(resolve).catch(() => {
                fallbackCopy(text) ? resolve() : reject(new Error('Copy failed'));
            });
        } else {
            fallbackCopy(text) ? resolve() : reject(new Error('Copy failed'));
        }
    });
}

function fallbackCopy(text) {
    const textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.left = '-9999px';
    textArea.style.top = '-9999px';
    textArea.style.opacity = '0';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    let success = false;
    try {
        success = document.execCommand('copy');
    } catch (e) {
        success = false;
    }
    document.body.removeChild(textArea);
    return success;
}

function copyTableToClipboard(html, text) {
    return new Promise((resolve, reject) => {
        if (navigator.clipboard && window.isSecureContext && window.ClipboardItem) {
            const item = new ClipboardItem({
                'text/html': new Blob([html], { type: 'text/html' }),
                'text/plain': new Blob([text], { type: 'text/plain' })
            });
            navigator.clipboard.write([item]).then(resolve).catch(() => {
                fallbackCopyHtml(html, text) ? resolve() : reject(new Error('Copy failed'));
            });
        } else {
            fallbackCopyHtml(html, text) ? resolve() : reject(new Error('Copy failed'));
        }
    });
}

function fallbackCopyHtml(html, text) {
    let success = false;
    const onCopy = (e) => {
        e.preventDefault();
        if (e.clipboardData) {
            e.clipboardData.setData('text/html', html);
            e.clipboardData.setData('text/plain', text);
        }
    };
    document.addEventListener('copy', onCopy, { once: true });

    const el = document.createElement('div');
    el.contentEditable = 'true';
    el.style.position = 'fixed';
    el.style.left = '-9999px';
    el.style.top = '-9999px';
    el.style.opacity = '0';
    el.innerHTML = 'copy';
    document.body.appendChild(el);
    el.focus();
    const range = document.createRange();
    range.selectNodeContents(el);
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);

    try {
        success = document.execCommand('copy');
    } catch (e) {
        success = false;
    }

    sel.removeAllRanges();
    document.body.removeChild(el);
    return success;
}

async function ensureXlsxLoaded() {
    if (typeof XLSX !== 'undefined') return;
    const script = document.querySelector('script[src*="xlsx.full.min.js"]');
    if (!script) throw new Error('ไม่พบไลบรารี XLSX');
    await new Promise((resolve, reject) => {
        script.addEventListener('load', resolve, { once: true });
        script.addEventListener('error', () => reject(new Error('โหลดไลบรารี XLSX ไม่สำเร็จ')), { once: true });
    });
}

async function openFolder(fields) {
    let json;
    document.body.style.cursor = 'progress';
    try {
        const fd = new FormData();
        Object.keys(fields).forEach(k => fd.append(k, fields[k]));
        const res = await fetch('api.php?action=open_folder', { method: 'POST', body: fd });
        json = await res.json();
    } catch (err) {
        json = { status: 'error', message: err.message };
    } finally {
        document.body.style.cursor = '';
    }
    if (json.status === 'success' && json.opened === true) return;
    showFolderNotOpenedModal(json);
}

const FOLDER_NOT_OPENED_CAUSES = {
    functions_disabled: 'เพราะ PHP ปิดคำสั่ง proc_open ไว้ (disable_functions ใน php.ini)',
    powershell_unavailable: 'เพราะเซิร์ฟเวอร์เรียกใช้ PowerShell ไม่ได้',
};

function showFolderNotOpenedModal(json) {
    const notOpened = json.status === 'success';
    const cause = FOLDER_NOT_OPENED_CAUSES[json.reason] || 'ซึ่งเกิดได้เมื่อเซิร์ฟเวอร์ (Apache) รันเป็น Windows service หรือรันในชื่อผู้ใช้อื่น';
    const message = notOpened
        ? `หน้าต่าง Explorer ไม่ได้เปิดขึ้นบนหน้าจอนี้ ${cause} ให้คัดลอกพาธด้านล่างไปเปิดเองใน File Explorer`
        : (json.message || 'เปิดโฟลเดอร์ไม่สำเร็จ');
    const path = json.path || '';
    const reason = notOpened ? `รหัส: ${json.reason || '-'}` : '';
    if (typeof bootstrap === 'undefined') {
        alert([message, path, reason].filter(Boolean).join('\n'));
        return;
    }

    let el = document.getElementById('folderNotOpenedModal');
    if (!el) {
        el = document.createElement('div');
        el.id = 'folderNotOpenedModal';
        el.className = 'modal fade';
        el.tabIndex = -1;
        // Above the notice modal, which holds the "open round folder" button
        el.style.zIndex = '1065';
        el.innerHTML = `
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header border-bottom pb-3 bg-light">
                        <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2">
                            <i class="fa-regular fa-folder-open text-warning"></i> เปิดโฟลเดอร์ไม่ได้
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body py-4">
                        <div class="text-dark mb-3" data-role="message"></div>
                        <div class="input-group input-group-sm" data-role="path-row">
                            <input type="text" class="form-control" readonly data-role="path">
                            <button type="button" class="btn btn-outline-secondary" data-role="copy">คัดลอกพาธ</button>
                        </div>
                        <div class="text-secondary small mt-2" data-role="reason"></div>
                    </div>
                    <div class="modal-footer border-top py-2 px-3 bg-light">
                        <button type="button" class="btn btn-primary btn-sm px-4 fw-semibold" data-bs-dismiss="modal" style="border-radius: 8px;">ตกลง</button>
                    </div>
                </div>
            </div>`;
        document.body.appendChild(el);
        const input = el.querySelector('[data-role="path"]');
        const copyBtn = el.querySelector('[data-role="copy"]');
        copyBtn.addEventListener('click', () => {
            input.select();
            copyTextToClipboard(input.value).then(() => {
                copyBtn.textContent = 'คัดลอกแล้ว';
                setTimeout(() => { copyBtn.textContent = 'คัดลอกพาธ'; }, 1500);
            }).catch(() => {});
        });
        el.addEventListener('shown.bs.modal', () => input.select());
    }

    el.querySelector('[data-role="message"]').textContent = message;
    el.querySelector('[data-role="path"]').value = path;
    el.querySelector('[data-role="path-row"]').classList.toggle('d-none', !path);
    el.querySelector('[data-role="reason"]').textContent = reason;

    bootstrap.Modal.getOrCreateInstance(el).show();
    const backdrops = document.querySelectorAll('.modal-backdrop');
    if (backdrops.length > 0) backdrops[backdrops.length - 1].style.zIndex = '1060';
}

/**
 * The Excel files of the asn folder of the Job: upload/{Job}/invoice/asn (side 'invoice') or upload/{Job}/cn/asn
 * (side 'cn'). Answers { folder, files: [{ filename, rel_path, size, imported }] }.
 */
async function asnFolderList(side) {
    const res = await fetch(`api.php?action=job_asn_folder&job_id=${CURRENT_JOB_ID}&side=${side}`);
    const json = await res.json();
    if (json.status !== 'success') throw new Error(json.message || 'อ่านโฟลเดอร์ asn ไม่สำเร็จ');
    return json.data;
}

/**
 * Fetches files listed by asnFolderList as File objects, so the page reads them as if they were picked by hand
 */
async function asnFolderDownload(side, entries) {
    const files = [];
    for (const entry of entries) {
        const res = await fetch(`api.php?action=job_asn_folder_file&job_id=${CURRENT_JOB_ID}&side=${side}&rel_path=${encodeURIComponent(entry.rel_path)}`);
        if (!res.ok) {
            let message = `โหลดไฟล์ ${entry.filename} ไม่สำเร็จ`;
            try { message = (await res.json()).message || message; } catch (e) { /* not JSON */ }
            throw new Error(message);
        }
        files.push(new File([await res.blob()], entry.filename));
    }
    return files;
}

/**
 * Which files of the asn folder to read: the ones not imported yet, or, when every file was imported before and the
 * user agrees, all of them. null when there is nothing to read (onEmpty has told the user why).
 */
async function asnFolderChoose(side, onEmpty) {
    const { folder, files } = await asnFolderList(side);
    if (files.length === 0) {
        onEmpty(folder);
        return null;
    }
    const fresh = files.filter(f => !f.imported);
    if (fresh.length > 0) return fresh;
    hideLoadingOverlay();
    const again = window.confirm(`ไฟล์ ASN ทั้ง ${files.length.toLocaleString()} ไฟล์ใน ${folder} เคยนำเข้าแล้ว
ต้องการอ่านใหม่ทั้งหมดหรือไม่?`);
    return again ? files : null;
}

function openJobFolder(target, round) {
    return openFolder({ job_id: CURRENT_JOB_ID, target, round: round || 0 });
}
