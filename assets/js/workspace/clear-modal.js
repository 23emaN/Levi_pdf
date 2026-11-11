async function openClearDataModal(target) {
    const mode = target || activeTab || 'pdf';
    const searchEl = document.getElementById('clear-modal-search');
    const clearBtn = document.getElementById('btn-clear-modal-search');
    if (searchEl) searchEl.value = '';
    if (clearBtn) clearBtn.style.display = 'none';

    const pageSizeEl = document.getElementById('clear-modal-page-size');
    if (pageSizeEl) {
        clearModalPageSize = parseInt(pageSizeEl.value, 10) || 25;
    }

    await loadClearModalTarget(mode);
    if (!clearDataModal) {
        clearDataModal = new bootstrap.Modal(document.getElementById('clearDataModal'));
    }
    clearDataModal.show();
}

async function switchClearModalTarget(target) {
    if (clearModalTarget === target) return;
    const searchEl = document.getElementById('clear-modal-search');
    const clearBtn = document.getElementById('btn-clear-modal-search');
    if (searchEl) searchEl.value = '';
    if (clearBtn) clearBtn.style.display = 'none';
    await loadClearModalTarget(target);
}

async function loadClearModalTarget(target) {
    clearModalTarget = target === 'asn' ? 'asn' : 'pdf';
    clearModalSelectedIds = new Set();
    clearModalCurrentPage = 1;
    clearModalFileFilter.reset();

    const tabPdf = document.getElementById('clear-tab-pdf');
    const tabAsn = document.getElementById('clear-tab-asn');
    if (tabPdf) tabPdf.classList.toggle('active', clearModalTarget === 'pdf');
    if (tabAsn) tabAsn.classList.toggle('active', clearModalTarget === 'asn');

    const titleEl = document.getElementById('clear-modal-title');
    if (titleEl) {
        titleEl.innerHTML = clearModalTarget === 'pdf'
            ? '<i class="fa-solid fa-trash-can text-danger me-2"></i>เลือกรายการที่ต้องการล้างข้อมูล (ฝั่ง PDF)'
            : '<i class="fa-solid fa-trash-can text-danger me-2"></i>เลือกรายการที่ต้องการล้างข้อมูล (ฝั่ง ASN)';
    }
    const asnNote = document.getElementById('clear-modal-asn-note');
    if (asnNote) asnNote.classList.toggle('d-none', clearModalTarget !== 'asn');

    const thead = document.getElementById('clear-modal-thead');
    const tbody = document.getElementById('clear-modal-tbody');
    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">กำลังโหลดรายการ...</td></tr>';
    }

    if (clearModalTarget === 'pdf') {
        if (detailedData.length > 0 && detailedData.some(r => !r.id)) {
            await loadJobItemsFromDb();
        }
        clearModalSourceRows = detailedData.map((r, idx) => {
            preparePdfRow(r);
            return {
                _id: Number(r.id),
                _rowIdx: idx + 1,
                _fileKey: String(r.filename || ''),
                _searchStr: r._searchStr || '',
                raw: r
            };
        });
        if (thead) {
            thead.innerHTML = `<tr>
                <th class="text-center" style="width: 44px;">
                    <input type="checkbox" class="form-check-input" id="clear-modal-select-page" onchange="toggleClearModalCurrentPage(this.checked)" title="เลือก/ยกเลิกทั้งหมดในหน้านี้">
                </th>
                <th class="text-center" style="width: 56px;">#</th>
                <th style="width: 240px;">File Name</th>
                <th style="width: 125px;">Tax Invoice No.</th>
                <th style="width: 95px;">Date</th>
                <th style="width: 130px;">Barcode</th>
                <th style="width: 220px;">Description</th>
                <th class="text-center" style="width: 70px;">Qty</th>
                <th class="text-end" style="width: 110px;">Amount</th>
            </tr>`;
        }
    } else {
        try {
            const res = await fetch(`api.php?action=get_asn_records&job_id=${CURRENT_JOB_ID}`);
            const json = await res.json();
            const records = (json.status === 'success' && Array.isArray(json.data)) ? json.data : [];
            clearModalSourceRows = records.map((r, idx) => {
                const sf = String(r.source_file || 'ASN_Upload.xlsx');
                return {
                    _id: Number(r.id),
                    _rowIdx: idx + 1,
                    _fileKey: sf,
                    _searchStr: [sf, r.invoice_no, r.ean_upc, r.dest_store_no, r.delivery_no].filter(Boolean).join(' ').toLowerCase(),
                    raw: r
                };
            });
        } catch (err) {
            console.error(err);
            clearModalSourceRows = [];
        }
        if (thead) {
            thead.innerHTML = `<tr>
                <th class="text-center" style="width: 44px;">
                    <input type="checkbox" class="form-check-input" id="clear-modal-select-page" onchange="toggleClearModalCurrentPage(this.checked)" title="เลือก/ยกเลิกทั้งหมดในหน้านี้">
                </th>
                <th class="text-center" style="width: 56px;">#</th>
                <th style="width: 230px;">ไฟล์ ASN</th>
                <th style="width: 125px;">Invoice No.</th>
                <th style="width: 130px;">Barcode / EAN</th>
                <th class="text-center" style="width: 85px;">Store</th>
                <th style="width: 115px;">Delivery No.</th>
                <th class="text-center" style="width: 70px;">Qty</th>
                <th class="text-end" style="width: 105px;">Sell Price</th>
                <th class="text-end" style="width: 115px;">Cost Ex VAT</th>
            </tr>`;
        }
    }

    populateClearModalFileFilter();
    applyClearModalFilter();
}

function populateClearModalFileFilter() {
    const fileCounts = new Map();
    for (let i = 0; i < clearModalSourceRows.length; i++) {
        const fk = clearModalSourceRows[i]._fileKey;
        if (!fk) continue;
        fileCounts.set(fk, (fileCounts.get(fk) || 0) + 1);
    }
    clearModalFileFilter.setOptions(Array.from(fileCounts, ([fileKey, count]) => ({ value: fileKey, label: fileKey, count })));
}

function handleClearModalFilterChange() {
    clearModalCurrentPage = 1;
    applyClearModalFilter();
}

function handleClearModalPageSizeChange(val) {
    clearModalPageSize = Math.max(1, parseInt(val, 10) || 25);
    clearModalCurrentPage = 1;
    renderClearModalPage();
}

function applyClearModalFilter() {
    const files = clearModalFileFilter.values;
    const q = (document.getElementById('clear-modal-search')?.value || '').toLowerCase().trim();

    clearModalFilteredRows = clearModalSourceRows.filter(item => {
        if (files.size && !files.has(item._fileKey)) return false;
        if (q && !item._searchStr.includes(q)) return false;
        return true;
    });

    const totalPages = Math.max(1, Math.ceil(clearModalFilteredRows.length / clearModalPageSize));
    if (clearModalCurrentPage > totalPages) {
        clearModalCurrentPage = totalPages;
    }
    renderClearModalPage();
}

function getClearModalCurrentPageRows() {
    const start = (clearModalCurrentPage - 1) * clearModalPageSize;
    return clearModalFilteredRows.slice(start, start + clearModalPageSize);
}

function renderClearModalPage() {
    const tbody = document.getElementById('clear-modal-tbody');
    const pageInfo = document.getElementById('clear-modal-page-info');
    if (!tbody) return;

    const total = clearModalFilteredRows.length;
    const totalPages = Math.max(1, Math.ceil(total / clearModalPageSize));
    if (clearModalCurrentPage > totalPages) clearModalCurrentPage = totalPages;
    if (clearModalCurrentPage < 1) clearModalCurrentPage = 1;

    const pageRows = getClearModalCurrentPageRows();
    const startIdx = total === 0 ? 0 : (clearModalCurrentPage - 1) * clearModalPageSize + 1;
    const endIdx = total === 0 ? 0 : startIdx + pageRows.length - 1;

    if (pageInfo) {
        pageInfo.textContent = `แสดงแถวที่ ${startIdx.toLocaleString()} - ${endIdx.toLocaleString()} จากทั้งหมด ${total.toLocaleString()} รายการ (หน้า ${clearModalCurrentPage.toLocaleString()} / ${totalPages.toLocaleString()})`;
    }

    const colSpan = clearModalTarget === 'pdf' ? 9 : 10;
    if (pageRows.length === 0) {
        tbody.innerHTML = `<tr><td colspan="${colSpan}" class="text-center text-muted py-5">ไม่พบข้อมูลในรายการ</td></tr>`;
        renderClearModalPagination(totalPages);
        updateClearModalSelectionUi();
        return;
    }

    const htmlParts = new Array(pageRows.length);
    for (let i = 0; i < pageRows.length; i++) {
        const item = pageRows[i];
        const r = item.raw;
        const isChecked = clearModalSelectedIds.has(item._id);
        const rowClass = isChecked ? ' class="row-selected-clear"' : '';
        const cbAttr = isChecked ? ' checked' : '';

        if (clearModalTarget === 'pdf') {
            const fn = escapeHtml(r.filename || '');
            const desc = escapeHtml(r.description || '');
            htmlParts[i] = `<tr${rowClass} data-clear-id="${item._id}" onclick="handleClearModalRowClick(event, ${item._id})">
                <td class="text-center">
                    <input type="checkbox" class="form-check-input clear-row-cb" data-id="${item._id}"${cbAttr} onclick="event.stopPropagation()" onchange="toggleClearModalRow(${item._id}, this.checked)">
                </td>
                <td class="text-center tabular-num text-secondary">${item._rowIdx}</td>
                <td class="fw-medium" title="${fn}">${fn}</td>
                <td class="tabular-num fw-semibold text-dark">${escapeHtml(r.tax_invoice || '')}</td>
                <td class="tabular-num">${escapeHtml(r.date || '')}</td>
                <td class="tabular-num text-primary fw-bold">${escapeHtml(r.barcode || '')}</td>
                <td title="${desc}">${desc}</td>
                <td class="text-center tabular-num fw-bold">${r.units ?? 0}</td>
                <td class="text-end tabular-num text-success fw-semibold">${formatMoney(r.amount)}</td>
            </tr>`;
        } else {
            const sf = escapeHtml(r.source_file || 'ASN_Upload.xlsx');
            htmlParts[i] = `<tr${rowClass} data-clear-id="${item._id}" onclick="handleClearModalRowClick(event, ${item._id})">
                <td class="text-center">
                    <input type="checkbox" class="form-check-input clear-row-cb" data-id="${item._id}"${cbAttr} onclick="event.stopPropagation()" onchange="toggleClearModalRow(${item._id}, this.checked)">
                </td>
                <td class="text-center tabular-num text-secondary">${item._rowIdx}</td>
                <td class="fw-medium" title="${sf}">${sf}</td>
                <td class="tabular-num fw-semibold text-dark">${escapeHtml(r.invoice_no || '')}</td>
                <td class="tabular-num text-primary fw-bold">${escapeHtml(r.ean_upc || '')}</td>
                <td class="text-center tabular-num">${escapeHtml(r.dest_store_no || '-')}</td>
                <td class="tabular-num">${escapeHtml(r.delivery_no || '-')}</td>
                <td class="text-center tabular-num fw-bold">${r.quantity ?? 0}</td>
                <td class="text-end tabular-num">${formatMoney(r.selling_price)}</td>
                <td class="text-end tabular-num fw-semibold text-dark">${formatMoney(r.total_cost_ex_vat)}</td>
            </tr>`;
        }
    }

    tbody.innerHTML = htmlParts.join('');
    renderClearModalPagination(totalPages);
    updateClearModalSelectionUi();
}

function renderClearModalPagination(totalPages) {
    const ul = document.getElementById('clear-modal-pagination');
    if (!ul) return;

    if (totalPages <= 1) {
        ul.innerHTML = '';
        return;
    }

    const cur = clearModalCurrentPage;
    const pages = [];
    pages.push(1);
    let start = Math.max(2, cur - 2);
    let end = Math.min(totalPages - 1, cur + 2);
    if (start > 2) pages.push('...');
    for (let p = start; p <= end; p++) {
        pages.push(p);
    }
    if (end < totalPages - 1) pages.push('...');
    if (totalPages > 1) pages.push(totalPages);

    let html = `
        <li class="page-item ${cur === 1 ? 'disabled' : ''}">
            <button type="button" class="page-link" onclick="goToClearModalPage(1)" title="หน้าแรก">&laquo;</button>
        </li>
        <li class="page-item ${cur === 1 ? 'disabled' : ''}">
            <button type="button" class="page-link" onclick="goToClearModalPage(${cur - 1})" title="หน้าก่อนหน้า">&lsaquo;</button>
        </li>
    `;

    pages.forEach(p => {
        if (p === '...') {
            html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
        } else {
            html += `<li class="page-item ${p === cur ? 'active' : ''}">
                <button type="button" class="page-link tabular-num" onclick="goToClearModalPage(${p})">${p}</button>
            </li>`;
        }
    });

    html += `
        <li class="page-item ${cur === totalPages ? 'disabled' : ''}">
            <button type="button" class="page-link" onclick="goToClearModalPage(${cur + 1})" title="หน้าถัดไป">&rsaquo;</button>
        </li>
        <li class="page-item ${cur === totalPages ? 'disabled' : ''}">
            <button type="button" class="page-link" onclick="goToClearModalPage(${totalPages})" title="หน้าสุดท้าย">&raquo;</button>
        </li>
    `;

    ul.innerHTML = html;
}

function goToClearModalPage(page) {
    const totalPages = Math.max(1, Math.ceil(clearModalFilteredRows.length / clearModalPageSize));
    const next = Math.min(Math.max(1, page), totalPages);
    if (next === clearModalCurrentPage) return;
    clearModalCurrentPage = next;
    renderClearModalPage();
}

function handleClearModalRowClick(event, id) {
    if (event.target && event.target.tagName === 'INPUT') return;
    const nextState = !clearModalSelectedIds.has(id);
    toggleClearModalRow(id, nextState);
}

function toggleClearModalRow(id, checked) {
    const numId = Number(id);
    if (checked) {
        clearModalSelectedIds.add(numId);
    } else {
        clearModalSelectedIds.delete(numId);
    }

    const tr = document.querySelector(`#clear-modal-tbody tr[data-clear-id="${numId}"]`);
    if (tr) {
        tr.classList.toggle('row-selected-clear', checked);
        const cb = tr.querySelector('.clear-row-cb');
        if (cb && cb.checked !== checked) cb.checked = checked;
    }
    updateClearModalSelectionUi();
}

function toggleClearModalCurrentPage(checked) {
    const pageRows = getClearModalCurrentPageRows();
    for (let i = 0; i < pageRows.length; i++) {
        const id = pageRows[i]._id;
        if (checked) {
            clearModalSelectedIds.add(id);
        } else {
            clearModalSelectedIds.delete(id);
        }
    }
    renderClearModalPage();
}

function selectAllFilteredInClearModal() {
    for (let i = 0; i < clearModalFilteredRows.length; i++) {
        clearModalSelectedIds.add(clearModalFilteredRows[i]._id);
    }
    renderClearModalPage();
}

function clearAllSelectionInModal() {
    clearModalSelectedIds.clear();
    renderClearModalPage();
}

function updateClearModalSelectionUi() {
    const pageRows = getClearModalCurrentPageRows();
    let selectedOnPage = 0;
    for (let i = 0; i < pageRows.length; i++) {
        if (clearModalSelectedIds.has(pageRows[i]._id)) {
            selectedOnPage++;
        }
    }

    const headerCb = document.getElementById('clear-modal-select-page');
    if (headerCb) {
        headerCb.disabled = pageRows.length === 0;
        if (pageRows.length === 0 || selectedOnPage === 0) {
            headerCb.checked = false;
            headerCb.indeterminate = false;
        } else if (selectedOnPage === pageRows.length) {
            headerCb.checked = true;
            headerCb.indeterminate = false;
        } else {
            headerCb.checked = false;
            headerCb.indeterminate = true;
        }
    }

    const totalSelected = clearModalSelectedIds.size;
    const countEl = document.getElementById('clear-modal-selected-count');
    if (countEl) countEl.textContent = totalSelected.toLocaleString();

    const confirmCountEl = document.getElementById('clear-confirm-count');
    if (confirmCountEl) confirmCountEl.textContent = totalSelected.toLocaleString();

    const btnConfirm = document.getElementById('btnConfirmClear');
    if (btnConfirm) {
        btnConfirm.disabled = totalSelected === 0;
    }
}

async function executeClearJobData() {
    const idsToDelete = Array.from(clearModalSelectedIds);
    if (idsToDelete.length === 0) return;

    const btn = document.getElementById('btnConfirmClear');
    btn.disabled = true;
    clearDataModal.hide();

    const isPdf = clearModalTarget === 'pdf';
    showLoadingOverlay(
        isPdf ? 'กำลังลบข้อมูล PDF ที่เลือก...' : 'กำลังลบข้อมูล ASN ที่เลือก...',
        `กำลังลบรายการที่เลือกจำนวน ${idsToDelete.length.toLocaleString()} รายการ`
    );

    try {
        const fd = new FormData();
        fd.append('job_id', CURRENT_JOB_ID);
        fd.append('ids', JSON.stringify(idsToDelete));

        const action = isPdf ? 'delete_job_items' : 'delete_asn_records';
        const res = await fetch(`api.php?action=${action}`, {
            method: 'POST',
            body: fd
        });
        const json = await res.json();
        if (json.status !== 'success') {
            throw new Error(json.message);
        }

        if (isPdf) {
            const deletedSet = new Set(idsToDelete.map(Number));
            detailedData = detailedData.filter(r => !deletedSet.has(Number(r.id)));
            applySearch();
            loadStats();
        } else {
            setStatusBarAsn(json.asn_counts);
            const asnText = json.asn_filename || 'ยังไม่ได้เลือกไฟล์ ASN';
            document.getElementById('asn-filename-text').textContent = asnText;
            document.getElementById('asn-file-pill').title = asnText;
            if (!json.asn_filename) {
                const sheetWrapper = document.getElementById('asn-sheet-wrapper');
                if (sheetWrapper) {
                    sheetWrapper.classList.add('d-none');
                    sheetWrapper.classList.remove('d-flex');
                }
            }
        }

        if (json.reconcile) {
            reconData = json.reconcile;
            if (reconData && reconData.results) {
                reconData.results.forEach(prepareReconRow);
            }
            renderReconSummary();
            filterReconTable();
        } else if (json.raw_records) {
            reconData = {
                summary: null,
                results: json.raw_records.map(prepareRawAsnRow)
            };
            renderReconSummary();
            filterReconTable();
        } else {
            await checkExistingReconcile();
        }
    } catch (err) {
        alert('เกิดข้อผิดพลาดในการลบข้อมูล: ' + err.message);
    } finally {
        hideLoadingOverlay();
        btn.disabled = false;
        btn.innerHTML = `<i class="fa-solid fa-trash-can me-1"></i>ลบรายการที่เลือก (<span id="clear-confirm-count" class="tabular-num">0</span>)`;
    }
}

// State: options (in source order), ticked (Set of option values, always a subset of options),
// query (typed text), visible (options matching query), active (keyboard highlight index into visible, -1 none).
class FileCombobox {
    constructor(host) {
        this.onChange = null;
        this.options = [];
        this.byValue = new Map();
        this.total = 0;
        this.ticked = new Set();
        this.query = '';
        this.visible = [];
        this.active = -1;
        this.isOpen = false;

        const listId = `${host.id}-list`;
        host.innerHTML = `
            <div class="modern-search">
                <i class="fa-solid fa-file-lines search-icon"></i>
                <input type="text" class="form-control form-control-sm" role="combobox" aria-label="กรองตามไฟล์" aria-haspopup="listbox"
                    aria-autocomplete="list" aria-expanded="false" aria-controls="${listId}" autocomplete="off" spellcheck="false">
                <button type="button" class="btn-clear" title="ล้างที่เลือก" aria-label="ล้างที่เลือก" hidden><i class="fa-solid fa-xmark"></i></button>
                <i class="fa-solid fa-chevron-down file-combo-caret" aria-hidden="true"></i>
            </div>
            <div class="file-combo-panel" hidden>
                <div class="file-combo-actions">
                    <button type="button" data-action="tick-visible">เลือกทั้งหมดที่แสดงอยู่</button>
                    <button type="button" data-action="clear">ล้างที่เลือก</button>
                </div>
                <div class="file-combo-list" role="listbox" id="${listId}" aria-multiselectable="true"></div>
            </div>`;
        this.host = host;
        this.input = host.querySelector('input');
        this.clearBtn = host.querySelector('.btn-clear');
        this.panel = host.querySelector('.file-combo-panel');
        this.list = host.querySelector('[role="listbox"]');
        this.tickVisibleBtn = host.querySelector('[data-action="tick-visible"]');
        this.clearTicksBtn = host.querySelector('[data-action="clear"]');

        this.input.addEventListener('mousedown', e => {
            // A click that focuses the field would otherwise drop a caret and undo the select-all done on focus.
            if (document.activeElement !== this.input) {
                e.preventDefault();
                this.input.focus();
            }
        });
        this.input.addEventListener('focus', () => this.open());
        this.input.addEventListener('click', () => this.open());
        // An edit to the closed field must land on the query, not on the summary it shows: open() swaps the
        // value to the selected query before the browser applies the typed, pasted or deleted text.
        this.input.addEventListener('beforeinput', () => this.open());
        this.input.addEventListener('input', () => {
            this.query = this.input.value;
            this.refilter();
            this.renderList();
        });
        this.input.addEventListener('keydown', e => this.handleKey(e));
        host.addEventListener('focusout', e => {
            if (!host.contains(e.relatedTarget)) this.close();
        });
        // Keeps focus in the field so the list stays open while ticking.
        this.panel.addEventListener('mousedown', e => e.preventDefault());
        this.clearBtn.addEventListener('mousedown', e => e.preventDefault());
        this.clearBtn.addEventListener('click', () => this.clearTicks());
        this.panel.addEventListener('click', e => {
            const action = e.target.closest('[data-action]');
            if (action) {
                if (action.dataset.action === 'tick-visible') this.tickVisible();
                else this.clearTicks();
                return;
            }
            const row = e.target.closest('[data-index]');
            if (row) this.toggle(this.visible[Number(row.dataset.index)]);
        });

        this.renderField();
    }

    get values() {
        return new Set(this.ticked);
    }

    setOptions(options) {
        this.options = options.map(o => ({ value: o.value, label: o.label, count: o.count, lower: o.label.toLowerCase() }));
        this.byValue = new Map(this.options.map(o => [o.value, o]));
        this.total = this.options.reduce((sum, o) => sum + o.count, 0);
        this.ticked.forEach(v => {
            if (!this.byValue.has(v)) this.ticked.delete(v);
        });
        this.refilter();
        if (this.isOpen) this.renderList();
        this.renderField();
    }

    reset() {
        this.ticked.clear();
        this.query = '';
        this.refilter();
        this.close();
        this.renderField();
    }

    open() {
        if (this.isOpen) return;
        this.isOpen = true;
        this.input.value = this.query;
        this.input.select();
        const bounds = (this.host.closest('.modal-content') || document.documentElement).getBoundingClientRect();
        const room = bounds.right - this.host.getBoundingClientRect().left - 12;
        this.panel.style.maxWidth = `${Math.max(this.host.offsetWidth, Math.min(560, room))}px`;
        this.panel.hidden = false;
        this.input.setAttribute('aria-expanded', 'true');
        this.renderList();
        this.renderField();
    }

    close() {
        if (!this.isOpen) return;
        this.isOpen = false;
        this.active = -1;
        this.panel.hidden = true;
        this.input.setAttribute('aria-expanded', 'false');
        this.input.removeAttribute('aria-activedescendant');
        this.renderField();
    }

    handleKey(e) {
        switch (e.key) {
            case 'ArrowDown':
            case 'ArrowUp':
                e.preventDefault();
                if (!this.isOpen) this.open();
                else this.moveActive(e.key === 'ArrowDown' ? 1 : -1);
                return;
            case 'Enter':
                e.preventDefault();
                if (!this.isOpen) this.open();
                else if (this.active >= 0) this.toggle(this.visible[this.active]);
                return;
            case ' ':
                if (!this.isOpen) {
                    e.preventDefault();
                    this.open();
                } else if (this.active >= 0) {
                    e.preventDefault();
                    this.toggle(this.visible[this.active]);
                } else if (this.query === '') {
                    e.preventDefault();
                }
                return;
            case 'Escape':
                if (this.isOpen) {
                    // Without this the Bootstrap modal around the field closes too.
                    e.preventDefault();
                    e.stopPropagation();
                    this.close();
                }
                return;
            case 'Tab':
                this.close();
        }
    }

    refilter() {
        const tokens = this.query.toLowerCase().split(/\s+/).filter(Boolean);
        this.visible = tokens.length
            ? this.options.filter(o => tokens.every(t => o.lower.includes(t)))
            : this.options;
    }

    moveActive(delta) {
        const n = this.visible.length;
        if (n === 0) return;
        const next = this.active < 0
            ? (delta > 0 ? 0 : n - 1)
            : Math.min(n - 1, Math.max(0, this.active + delta));
        const prevRow = this.list.children[this.active];
        if (prevRow) prevRow.classList.remove('is-active');
        this.active = next;
        const row = this.list.children[next];
        row.classList.add('is-active');
        row.scrollIntoView({ block: 'nearest' });
        this.input.setAttribute('aria-activedescendant', row.id);
    }

    toggle(option) {
        if (this.ticked.has(option.value)) this.ticked.delete(option.value);
        else this.ticked.add(option.value);
        this.commitTicks();
    }

    tickVisible() {
        const before = this.ticked.size;
        this.visible.forEach(o => this.ticked.add(o.value));
        if (this.ticked.size !== before) this.commitTicks();
    }

    clearTicks() {
        if (this.ticked.size === 0) return;
        this.ticked.clear();
        this.commitTicks();
    }

    commitTicks() {
        if (this.isOpen) {
            this.visible.forEach((o, i) => {
                const on = this.ticked.has(o.value);
                const row = this.list.children[i];
                row.setAttribute('aria-selected', String(on));
                row.firstElementChild.checked = on;
            });
        }
        this.syncActions();
        this.renderField();
        if (this.onChange) this.onChange(this.values);
    }

    renderList() {
        this.active = -1;
        this.input.removeAttribute('aria-activedescendant');
        if (this.visible.length === 0) {
            const text = this.query.trim() ? 'ไม่พบไฟล์ที่ตรงกัน' : 'ไม่มีไฟล์';
            this.list.innerHTML = `<div class="file-combo-empty" role="option" aria-disabled="true" aria-selected="false">${text}</div>`;
        } else {
            this.list.innerHTML = this.visible.map((o, i) => {
                const on = this.ticked.has(o.value);
                return `<div class="file-combo-option" role="option" id="${this.list.id}-${i}" data-index="${i}" aria-selected="${on}">`
                    + `<input type="checkbox" class="form-check-input" tabindex="-1" aria-hidden="true"${on ? ' checked' : ''}>`
                    + `<span class="file-combo-name">${escapeHtml(o.label)}</span>`
                    + `<span class="file-combo-count">${o.count.toLocaleString()} รายการ</span></div>`;
            }).join('');
        }
        this.list.scrollTop = 0;
        this.syncActions();
    }

    syncActions() {
        this.tickVisibleBtn.disabled = this.visible.every(o => this.ticked.has(o.value));
        this.clearTicksBtn.disabled = this.ticked.size === 0;
    }

    summary() {
        if (this.ticked.size === 0) return `ทุกไฟล์ (${this.total.toLocaleString()} รายการ)`;
        if (this.ticked.size === 1) return this.byValue.get(this.ticked.values().next().value).label;
        let rows = 0;
        this.ticked.forEach(v => { rows += this.byValue.get(v).count; });
        return `เลือก ${this.ticked.size.toLocaleString()} ไฟล์ (${rows.toLocaleString()} รายการ)`;
    }

    renderField() {
        const text = this.summary();
        this.input.title = text;
        this.input.placeholder = this.isOpen ? text : '';
        if (!this.isOpen) this.input.value = text;
        this.clearBtn.hidden = this.ticked.size === 0;
    }
}

let clearModalFileFilter = null;

document.addEventListener('DOMContentLoaded', () => {
    clearModalFileFilter = new FileCombobox(document.getElementById('clear-modal-file-filter'));
    clearModalFileFilter.onChange = handleClearModalFilterChange;
});

