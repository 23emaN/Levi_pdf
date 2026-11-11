document.addEventListener('DOMContentLoaded', async () => {
    activeTab = getSavedTabForJob();
    showWorkspaceTab(activeTab);
    persistActiveTab(activeTab);

    duplicateModal = new bootstrap.Modal(document.getElementById('duplicateCheckModal'));
    clearDataModal = new bootstrap.Modal(document.getElementById('clearDataModal'));
    const noticeModalEl = document.getElementById('noticeModal');
    if (noticeModalEl) {
        noticeModal = new bootstrap.Modal(noticeModalEl);
    }
    const confirmImportEl = document.getElementById('confirmImportAsnModal');
    if (confirmImportEl) {
        confirmImportAsnModal = new bootstrap.Modal(confirmImportEl);
    }
    const confirmReconEl = document.getElementById('confirmReconcileModal');
    if (confirmReconEl) {
        confirmReconcileModal = new bootstrap.Modal(confirmReconEl);
    }
    const confirmFixEl = document.getElementById('confirmFixModal');
    if (confirmFixEl) {
        confirmFixModal = new bootstrap.Modal(confirmFixEl);
    }

    const overlayStopBtn = document.getElementById('loading-overlay-stop-btn');
    if (overlayStopBtn) {
        overlayStopBtn.addEventListener('click', () => {
            document.getElementById('btn-stop').click();
        });
    }

    const searchInput = document.getElementById('global-search');
    const clearBtn = document.getElementById('btn-clear-search');
    const debouncedApplySearch = debounce(() => applySearch(), 100);
    searchInput.addEventListener('input', () => {
        clearBtn.style.display = searchInput.value ? 'block' : 'none';
        debouncedApplySearch();
    });
    clearBtn.addEventListener('click', () => {
        searchInput.value = '';
        clearBtn.style.display = 'none';
        applySearch();
        searchInput.focus();
    });

    const asnSearch = document.getElementById('asn-search');
    const clearAsnBtn = document.getElementById('btn-clear-asn-search');
    const debouncedFilterRecon = debounce(() => filterReconTable(), 100);
    asnSearch.addEventListener('input', () => {
        clearAsnBtn.style.display = asnSearch.value ? 'block' : 'none';
        debouncedFilterRecon();
    });
    clearAsnBtn.addEventListener('click', () => {
        asnSearch.value = '';
        clearAsnBtn.style.display = 'none';
        filterReconTable();
        asnSearch.focus();
    });

    const clearModalSearch = document.getElementById('clear-modal-search');
    const btnClearModalSearch = document.getElementById('btn-clear-modal-search');
    if (clearModalSearch && btnClearModalSearch) {
        const debouncedClearModalFilter = debounce(() => {
            clearModalCurrentPage = 1;
            applyClearModalFilter();
        }, 100);
        clearModalSearch.addEventListener('input', () => {
            btnClearModalSearch.style.display = clearModalSearch.value ? 'block' : 'none';
            debouncedClearModalFilter();
        });
        btnClearModalSearch.addEventListener('click', () => {
            clearModalSearch.value = '';
            btnClearModalSearch.style.display = 'none';
            clearModalCurrentPage = 1;
            applyClearModalFilter();
            clearModalSearch.focus();
        });
    }

    initColumnResizers('#detailed-table');
    initColumnResizers('#asn-table');

    loadStats();
    if (activeTab === 'asn') {
        await runWithLoading(
            'กำลังโหลดข้อมูลใน Job...',
            'กรุณารอสักครู่ ระบบกำลังดึงข้อมูลเปรียบเทียบ ASN',
            () => Promise.all([loadJobItemsFromDb(), checkExistingReconcile()])
        );
    } else {
        await runWithLoading(
            'กำลังโหลดข้อมูลใน Job...',
            'กรุณารอสักครู่ ระบบกำลังดึงข้อมูลรายการสินค้า',
            () => Promise.all([loadJobItemsFromDb(), activeTab === 'dashboard' ? loadDashboard() : null])
        );
        checkExistingReconcile();
    }
});

function showWorkspaceTab(tab) {
    Object.keys(WORKSPACE_TABS).forEach(key => {
        const button = document.getElementById(WORKSPACE_TABS[key].button);
        const section = document.getElementById(WORKSPACE_TABS[key].section);
        if (button) button.classList.toggle('active', key === tab);
        if (section) section.style.display = key === tab ? 'flex' : 'none';
    });
}

function switchTab(tab) {
    if (!WORKSPACE_TABS[tab] || tab === activeTab) return;

    const previous = activeTab;
    activeTab = tab;
    persistActiveTab(activeTab);
    Object.keys(WORKSPACE_TABS).forEach(key => {
        document.getElementById(WORKSPACE_TABS[key].button).classList.toggle('active', key === tab);
    });

    runWithLoading('กำลังสลับหน้ามุมมอง...', `กำลังเปิดหน้า "${WORKSPACE_TABS[tab].label}"`, async () => {
        if (previous === 'pdf') {
            cancelPdfRenderSchedule();
            const pdfTbody = document.getElementById('detailed-tbody');
            if (pdfTbody && pdfTbody.children.length > 0) {
                pdfTbody.innerHTML = '';
                pdfDomDirty = true;
            }
        } else if (previous === 'asn') {
            cancelReconRenderSchedule();
            const asnTbody = document.getElementById('asn-tbody');
            if (asnTbody && asnTbody.children.length > 0) {
                asnTbody.innerHTML = '';
                reconDomDirty = true;
            }
        }

        showWorkspaceTab(tab);

        if (tab === 'pdf') {
            if (pdfDomDirty) {
                renderTable();
            }
        } else if (tab === 'asn') {
            if (pendingReconcilePromise) {
                await pendingReconcilePromise;
            }
            if (reconDomDirty) {
                renderReconTable(reconDisplayData);
            }
        } else {
            await loadDashboard();
        }
    });
}

async function loadStats() {
    try {
        const res = await fetch(`api.php?action=get_stats&only=myfile&job_id=${CURRENT_JOB_ID}`);
        const json = await res.json();
        if (json.status === 'success') {
            document.getElementById('stat-myfile').textContent = json.data.myfile_count.toLocaleString();
            document.getElementById('view-pdf-section').dataset.step = json.data.myfile_count > 0 ? 'extract' : 'idle';
        }
    } catch (e) {}
}

