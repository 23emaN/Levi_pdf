// The three tabs of the page. leave() parks a table the user no longer sees, enter() draws what the tab shows; a tab is
// remembered per Job and ?tab=asn|dashboard opens one directly, as on workspace.php.
const CN_TABS = {
    pdf: {
        button: 'credit-note-tab-pdf', section: 'credit-note-section', label: '1. ดึงข้อมูลจากใบลดหนี้ (PDF)',
        leave: () => creditNoteParkTable('credit-note-tbody', creditNoteState.render),
        enter: async () => {
            if (creditNoteState.render.parked) creditNoteRenderTable();
        }
    },
    asn: {
        button: 'credit-note-tab-asn', section: 'credit-note-asn-section', label: '2. เปรียบเทียบกับไฟล์ ASN',
        leave: () => creditNoteParkTable('credit-note-asn-tbody', creditNoteCompare.render),
        enter: async () => {
            if (creditNoteCompare.loading) await creditNoteCompare.loading.catch(() => {});
            if (creditNoteCompare.render.parked) creditNoteCompareRender();
        }
    },
    dashboard: {
        button: 'credit-note-tab-dashboard', section: 'credit-note-dash-section', label: '3. ภาพรวม',
        leave: () => {},
        enter: () => creditNoteDashLoad()
    }
};

function creditNoteTabKey() {
    return 'credit_note_tab_job_' + CURRENT_JOB_ID;
}

function creditNoteSavedTab() {
    try {
        const urlTab = new URLSearchParams(window.location.search).get('tab');
        if (CN_TABS[urlTab]) return urlTab;
        const saved = localStorage.getItem(creditNoteTabKey());
        if (CN_TABS[saved]) return saved;
    } catch (e) {}
    return 'pdf';
}

function creditNotePersistTab(tab) {
    try {
        localStorage.setItem(creditNoteTabKey(), tab);
    } catch (e) {}
    try {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        window.history.replaceState({ tab }, '', url.toString());
    } catch (e) {}
}

function creditNoteShowTab(tab) {
    Object.keys(CN_TABS).forEach(key => {
        document.getElementById(CN_TABS[key].button).classList.toggle('active', key === tab);
        document.getElementById(CN_TABS[key].section).style.display = key === tab ? 'flex' : 'none';
    });
}

function creditNoteSwitchTab(tab) {
    if (!CN_TABS[tab] || tab === creditNoteTabs.active) return;
    const previous = creditNoteTabs.active;
    creditNoteTabs.active = tab;
    creditNotePersistTab(tab);
    runWithLoading('กำลังสลับหน้ามุมมอง...', `กำลังเปิดหน้า "${CN_TABS[tab].label}"`, async () => {
        CN_TABS[previous].leave();
        creditNoteShowTab(tab);
        await CN_TABS[tab].enter();
    });
}

async function creditNoteLoadDocs() {
    const json = await creditNoteApi('job_cn_docs');
    creditNoteSetDocs(json.data, json.counts);
    creditNoteRenderRereadNotice();
}

function creditNoteRenderRereadNotice() {
    const stale = creditNoteRereadDocs().length;
    document.getElementById('credit-note-reread-count').textContent = stale.toLocaleString();
    document.getElementById('credit-note-reread-notice').classList.toggle('d-none', stale === 0);
}

function creditNoteSortBy(th) {
    const sort = creditNoteState.sort;
    sort.direction = sort.key === th.dataset.col && sort.direction === 'asc' ? 'desc' : 'asc';
    sort.key = th.dataset.col;
    creditNoteMarkSorted('#credit-note-table', th, sort.direction);
    creditNoteApplyView();
}

function creditNoteOpenClear() {
    const counts = creditNoteState.counts;
    if (counts.documents === 0 && counts.files === 0) {
        creditNoteShowNotice({ tone: 'info', title: 'ล้างข้อมูลใบลดหนี้', message: 'Job นี้ยังไม่มีข้อมูลใบลดหนี้' });
        return;
    }
    document.getElementById('creditNoteClearDocs').textContent = counts.documents.toLocaleString();
    document.getElementById('creditNoteClearFiles').textContent = counts.files.toLocaleString();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('creditNoteClearModal')).show();
}

async function creditNoteConfirmClear() {
    const btn = document.getElementById('creditNoteClearConfirm');
    btn.disabled = true;
    try {
        const json = await creditNoteApi('job_cn_clear', { job_id: CURRENT_JOB_ID });
        bootstrap.Modal.getOrCreateInstance(document.getElementById('creditNoteClearModal')).hide();
        creditNoteSetDocs([], { files: 0, documents: 0 });
        creditNoteRenderRereadNotice();
        await creditNoteCompareLoad().catch(() => {});
        creditNoteShowNotice({
            tone: 'success',
            title: 'ล้างข้อมูลใบลดหนี้แล้ว',
            message: `ลบใบลดหนี้ ${Number(json.deleted_documents || 0).toLocaleString()} ใบ จาก ${Number(json.deleted_files || 0).toLocaleString()} ไฟล์`,
            detailHtml: '<div class="cn-notice-note">ไฟล์ PDF ยังอยู่ใน CREDIT NOTE/finished ถ้าต้องการดึงข้อมูลใหม่ ให้ย้ายกลับไปที่ CREDIT NOTE/upload here</div>'
        });
    } catch (err) {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('creditNoteClearModal')).hide();
        creditNoteShowNotice({ tone: 'error', title: 'เกิดข้อผิดพลาด', message: 'ล้างข้อมูลใบลดหนี้ไม่สำเร็จ: ' + err.message });
    } finally {
        btn.disabled = false;
    }
}

function creditNoteLoadError(tbodyId, columnCount, err) {
    document.getElementById(tbodyId).innerHTML = `<tr><td colspan="${columnCount}" class="text-center text-danger py-4">เกิดข้อผิดพลาดในการโหลดข้อมูล: ${escapeHtml(err.message)}</td></tr>`;
}

document.addEventListener('DOMContentLoaded', () => {
    creditNoteTabs.active = creditNoteSavedTab();
    creditNoteShowTab(creditNoteTabs.active);
    creditNotePersistTab(creditNoteTabs.active);
    document.querySelectorAll('.header-tabs [data-tab]').forEach(btn => {
        btn.addEventListener('click', () => creditNoteSwitchTab(btn.dataset.tab));
    });

    document.getElementById('credit-note-start').addEventListener('click', creditNoteStartExtraction);
    document.getElementById('credit-note-stop').addEventListener('click', creditNoteRequestStop);
    document.getElementById('loading-overlay-stop-btn').addEventListener('click', creditNoteRequestStop);
    document.getElementById('credit-note-reread').addEventListener('click', creditNoteReread);
    document.getElementById('credit-note-export').addEventListener('click', () => creditNoteExportExcel(creditNoteState.shown));
    document.getElementById('credit-note-clear').addEventListener('click', creditNoteOpenClear);
    document.getElementById('creditNoteClearConfirm').addEventListener('click', creditNoteConfirmClear);
    creditNoteWireSearch('credit-note-search', 'credit-note-clear-search', query => {
        creditNoteState.query = query;
        creditNoteApplyView();
    });
    document.querySelectorAll('#credit-note-table thead th').forEach(th => {
        th.addEventListener('click', e => {
            if (e.target.classList.contains('resizer')) return;
            creditNoteSortBy(th);
        });
    });
    initColumnResizers('#credit-note-table');
    creditNoteCompareInit();
    creditNoteDashInit();

    runWithLoading('กำลังโหลดข้อมูลใบลดหนี้...', 'กรุณารอสักครู่ ระบบกำลังดึงข้อมูลจากฐานข้อมูล', () => Promise.all([
        creditNoteLoadDocs().catch(err => creditNoteLoadError('credit-note-tbody', CN_COLUMNS.length, err)),
        creditNoteCompareLoad().catch(err => creditNoteLoadError('credit-note-asn-tbody', CN_COMPARE_COLUMNS.length, err)),
        creditNoteTabs.active === 'dashboard' ? creditNoteDashLoad() : null
    ]));
});
