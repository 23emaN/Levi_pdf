// ================= 5. ASN IMPORT & RECONCILIATION ================= //

function getConfirmImportAsnModal() {
    if (!confirmImportAsnModal) {
        const el = document.getElementById('confirmImportAsnModal');
        if (el) confirmImportAsnModal = new bootstrap.Modal(el);
    }
    return confirmImportAsnModal;
}

function getConfirmReconcileModal() {
    if (!confirmReconcileModal) {
        const el = document.getElementById('confirmReconcileModal');
        if (el) confirmReconcileModal = new bootstrap.Modal(el);
    }
    return confirmReconcileModal;
}

function prepareRawAsnRow(raw) {
    const row = {
        _isRawAsn: true,
        status: 'PENDING',
        pdf: {},
        asn: {
            invoice_no: raw.invoice_no || '',
            dest_store_no: raw.dest_store_no || '',
            ean_upc: raw.ean_upc || '',
            quantity: Number(raw.quantity) || 0,
            selling_price: Number(raw.selling_price) || 0,
            total_cost_ex_vat: Number(raw.total_cost_ex_vat) || 0,
            total_vat: Number(raw.total_vat) || 0,
            delivery_no: raw.delivery_no || '',
            source_file: raw.source_file || ''
        },
        reason: 'รอการเปรียบเทียบกับ PDF',
        action: 'กดปุ่ม "เทียบกับ PDF" ด้านบนเพื่อเริ่มเปรียบเทียบ'
    };
    prepareReconRow(row);
    return row;
}

async function checkExistingReconcile() {
    const p = (async () => {
        try {
            const res = await fetch(`api.php?action=get_job_reconcile&job_id=${CURRENT_JOB_ID}`);
            const json = await res.json();
            if (json.status === 'success') {
                setStatusBarAsn(json.asn_counts);
                if (json.asn_filename !== undefined) {
                    const label = json.asn_filename || 'ยังไม่ได้เลือกไฟล์ ASN';
                    const fnEl = document.getElementById('asn-filename-text');
                    const pillEl = document.getElementById('asn-file-pill');
                    if (fnEl) fnEl.textContent = label;
                    if (pillEl) pillEl.title = label;
                }

                if (json.asn_reconciled === 1 && json.data) {
                    reconData = json.data;
                    if (reconData.results) {
                        reconData.results.forEach(prepareReconRow);
                    }
                    renderReconSummary();
                    filterReconTable();
                } else if (json.raw_records && json.raw_records.length > 0) {
                    reconData = {
                        summary: null,
                        results: json.raw_records.map(prepareRawAsnRow)
                    };
                    renderReconSummary();
                    filterReconTable();
                } else {
                    reconData = null;
                    renderReconTable([]);
                    renderReconSummary();
                }
            }
        } catch (e) {}
    })();
    pendingReconcilePromise = p;
    try {
        await p;
    } finally {
        if (pendingReconcilePromise === p) {
            pendingReconcilePromise = null;
        }
    }
}

function detectSheetColumns(rawRows) {
    for (let r = 0; r < Math.min(rawRows.length, 50); r++) {
        const row = rawRows[r];
        if (!Array.isArray(row)) continue;
        let hasInv = false;
        let hasEan = false;
        const currentMap = {};

        row.forEach((cell, cIdx) => {
            const str = String(cell || '').trim();
            if (/invoice\s*no|bill\s*no|tax\s*inv|cn\s*no|credit\s*note|billing\s*doc/i.test(str) && currentMap.invoice_no === undefined) {
                hasInv = true;
                currentMap.invoice_no = cIdx;
            } else if (/^ean\/upc$|^ean$|^upc$|^barcode$/i.test(str) && currentMap.ean_upc === undefined) {
                hasEan = true;
                currentMap.ean_upc = cIdx;
            } else if (/dest.*store|store\s*no|branch/i.test(str) && currentMap.dest_store_no === undefined) {
                currentMap.dest_store_no = cIdx;
            } else if (/^quantity$|^qty$/i.test(str) && currentMap.quantity === undefined) {
                currentMap.quantity = cIdx;
            } else if (/selling\s*price|unit\s*price|^price$|unit\s*ret/i.test(str) && currentMap.selling_price === undefined) {
                currentMap.selling_price = cIdx;
            } else if (/total\s*cost.*ex.*vat|cost.*ex.*vat|amt.*ex.*vat/i.test(str) && currentMap.total_cost_ex_vat === undefined) {
                currentMap.total_cost_ex_vat = cIdx;
            } else if (/^total\s*vat$|^vat$/i.test(str) && currentMap.total_vat === undefined) {
                currentMap.total_vat = cIdx;
            } else if (/^delivery\s*no/i.test(str) && currentMap.delivery_no === undefined) {
                currentMap.delivery_no = cIdx;
            }
        });

        if (hasInv && hasEan) {
            return { headerRowIdx: r, colMap: currentMap };
        }
    }
    return null;
}

function isSheetHidden(workbook, sheetName) {
    if (workbook && workbook.Workbook && Array.isArray(workbook.Workbook.Sheets)) {
        const meta = workbook.Workbook.Sheets.find(s => s && s.name === sheetName);
        if (meta && (meta.Hidden === 1 || meta.Hidden === 2 || meta.hidden)) {
            return true;
        }
    }
    return false;
}

async function handleAsnFileSelect(event) {
    const files = Array.from(event.target.files || []);
    event.target.value = '';
    if (files.length === 0) return;

    showLoadingOverlay('กำลังอ่านไฟล์ ASN (.xlsx)...', `กำลังสแกนข้อมูล ${files.length.toLocaleString()} ไฟล์`);

    try {
        await ensureXlsxLoaded();

        const pdfInvSet = new Set();
        (detailedData || []).forEach(item => {
            if (item.tax_invoice) {
                pdfInvSet.add(String(item.tax_invoice).replace(/[^A-Za-z0-9]/g, '').toUpperCase());
            }
        });

        pendingAsnFiles = [];

        for (let fIdx = 0; fIdx < files.length; fIdx++) {
            const file = files[fIdx];
            showLoadingOverlay('กำลังอ่านไฟล์ ASN (.xlsx)...', `กำลังสแกนชีตใน ${file.name} (${fIdx + 1}/${files.length})`);

            const buffer = await file.arrayBuffer();
            const workbook = XLSX.read(buffer, { type: 'array' });
            const localCachedSheets = {};
            const allValidSheets = [];

            workbook.SheetNames.forEach(sName => {
                const ws = workbook.Sheets[sName];
                const rawRows = XLSX.utils.sheet_to_json(ws, { header: 1 });
                const detected = detectSheetColumns(rawRows);
                if (!detected) return;

                localCachedSheets[sName] = { rawRows, detected };

                let dataRowCount = 0;
                const matchedPdfInvs = new Set();
                for (let r = detected.headerRowIdx + 1; r < rawRows.length; r++) {
                    const row = rawRows[r];
                    if (!row || row.length === 0) continue;
                    const inv = String(row[detected.colMap.invoice_no] || '').trim();
                    const ean = String(row[detected.colMap.ean_upc] || '').trim();
                    if (!inv || !ean || !/^\d{7,15}$/.test(ean.replace(/[^0-9]/g, ''))) continue;
                    if (/total|grand|summary|ex\./i.test(inv)) continue;
                    dataRowCount++;
                    const normInv = inv.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
                    if (pdfInvSet.has(normInv)) {
                        matchedPdfInvs.add(normInv);
                    }
                }

                if (dataRowCount > 0) {
                    allValidSheets.push({
                        name: sName,
                        rowCount: dataRowCount,
                        matchedInvs: matchedPdfInvs.size,
                        isHidden: isSheetHidden(workbook, sName)
                    });
                }
            });

            if (allValidSheets.length === 0) {
                throw new Error(`ไม่พบชีตที่มีคอลัมน์ Invoice No และ EAN/UPC ในไฟล์ ${file.name}`);
            }

            // Filter out hidden sheets in Excel (ignoring sheets hidden by the user in Excel)
            const visibleSheets = allValidSheets.filter(s => !s.isHidden);
            const validSheets = visibleSheets.length > 0 ? visibleSheets : allValidSheets;

            // Default to the first visible sheet in natural workbook order
            const defaultSheet = validSheets[0];

            pendingAsnFiles.push({
                file: file,
                fileName: file.name,
                workbook: workbook,
                cachedSheets: localCachedSheets,
                validSheets: validSheets,
                selectedSheet: defaultSheet.name,
                rowCount: defaultSheet.rowCount
            });
        }

        hideLoadingOverlay();
        openImportAsnConfirmModal();

    } catch (err) {
        hideLoadingOverlay();
        console.error(err);
        showNoticeModal('เกิดข้อผิดพลาดในการอ่านไฟล์ ASN', err.message, '', 'danger');
    }
}

function openImportAsnConfirmModal() {
    if (!pendingAsnFiles || pendingAsnFiles.length === 0) return;

    const countTitle = document.getElementById('confirmAsnFileCount');
    const subtext = document.getElementById('confirmAsnSubtext');
    const detailsContainer = document.getElementById('confirmAsnDetails');

    const totalFiles = pendingAsnFiles.length;
    let totalRows = 0;
    pendingAsnFiles.forEach(f => { totalRows += f.rowCount; });

    if (countTitle) {
        countTitle.textContent = `เลือกไฟล์ ASN จำนวน ${totalFiles} ไฟล์`;
    }
    if (subtext) {
        subtext.textContent = `พบข้อมูลรายการสินค้าประมาณ ${totalRows.toLocaleString()} แถว พร้อมดึงลงตาราง`;
    }

    let html = '';
    pendingAsnFiles.forEach((f, idx) => {
        // Guarantee default is always the first sheet of the file
        if ((!f.selectedSheet || !f.validSheets.some(s => s.name === f.selectedSheet)) && f.validSheets.length > 0) {
            f.selectedSheet = f.validSheets[0].name;
            f.rowCount = f.validSheets[0].rowCount;
        }

        html += `<div class="p-2 ${idx > 0 ? 'mt-2 pt-2 border-top' : ''}">`;
        html += `<div class="d-flex align-items-center justify-content-between mb-1">`;
        html += `<strong class="text-dark small"><i class="fa-regular fa-file-excel text-success me-1"></i>${escapeHtml(f.fileName)}</strong>`;
        html += `<span class="badge bg-success-subtle text-success border border-success-subtle tabular-num">${f.rowCount.toLocaleString()} แถว</span>`;
        html += `</div>`;

        if (f.validSheets.length > 1) {
            html += `<div class="d-flex align-items-center gap-2 mt-2">`;
            html += `<span class="text-secondary small text-nowrap flex-shrink-0" style="white-space: nowrap;">เลือกชีต:</span>`;
            html += `<select class="form-select form-select-sm" style="font-size: 0.78rem;" onchange="updatePendingFileSheet(${idx}, this.value)">`;
            f.validSheets.forEach((s, sIdx) => {
                const isSel = (s.name === f.selectedSheet || (!f.selectedSheet && sIdx === 0)) ? 'selected' : '';
                html += `<option value="${escapeHtml(s.name)}" ${isSel}>${escapeHtml(s.name)} (${s.rowCount.toLocaleString()} แถว)</option>`;
            });
            html += `</select>`;
            html += `</div>`;
        } else {
            html += `<div class="text-muted small">ชีต: <strong>${escapeHtml(f.selectedSheet)}</strong></div>`;
        }
        html += `</div>`;
    });

    if (detailsContainer) {
        detailsContainer.innerHTML = html;
    }

    const modal = getConfirmImportAsnModal();
    if (modal) modal.show();
}

function updatePendingFileSheet(fileIdx, sheetName) {
    if (!pendingAsnFiles[fileIdx]) return;
    const f = pendingAsnFiles[fileIdx];
    f.selectedSheet = sheetName;
    const sObj = f.validSheets.find(s => s.name === sheetName);
    if (sObj) {
        f.rowCount = sObj.rowCount;
    }
    let totalRows = 0;
    pendingAsnFiles.forEach(item => { totalRows += item.rowCount; });
    const subtext = document.getElementById('confirmAsnSubtext');
    if (subtext) {
        subtext.textContent = `พบข้อมูลรายการสินค้าประมาณ ${totalRows.toLocaleString()} แถว พร้อมดึงลงตาราง`;
    }
}

function cancelPendingAsnImport() {
    pendingAsnFiles = [];
    const input = document.getElementById('asnFileInput');
    if (input) input.value = '';
}

async function executeConfirmedAsnImport() {
    if (!pendingAsnFiles || pendingAsnFiles.length === 0) return;

    const modal = getConfirmImportAsnModal();
    if (modal) modal.hide();

    showLoadingOverlay('กำลังดึงข้อมูล ASN ลงตาราง...', `กำลังนำเข้าข้อมูล ${pendingAsnFiles.length} ไฟล์ลงในตาราง`);

    try {
        let lastAsnFilename = '';
        let lastRawRecords = [];
        let importedAny = false;
        let recompare = false;

        for (let i = 0; i < pendingAsnFiles.length; i++) {
            const f = pendingAsnFiles[i];
            const sheetName = f.selectedSheet;
            const displayLabel = `${f.fileName} [${sheetName}]`;
            showLoadingOverlay('กำลังดึงข้อมูล ASN ลงตาราง...', `กำลังประมวลผลไฟล์ ${f.fileName} (${i + 1}/${pendingAsnFiles.length})`);

            let cached = f.cachedSheets[sheetName];
            if (!cached) {
                const sheet = f.workbook.Sheets[sheetName];
                const rawRows = XLSX.utils.sheet_to_json(sheet, { header: 1 });
                const detected = detectSheetColumns(rawRows);
                cached = { rawRows, detected };
            }

            const { rawRows, detected } = cached;
            const { headerRowIdx, colMap } = detected;
            const records = [];
            for (let r = headerRowIdx + 1; r < rawRows.length; r++) {
                const row = rawRows[r];
                if (!row || row.length === 0) continue;
                const invoiceNo = String(row[colMap.invoice_no] || '').trim();
                const eanUpc = String(row[colMap.ean_upc] || '').trim();
                if (!invoiceNo || !eanUpc) continue;
                if (/total|grand|summary|ex\./i.test(invoiceNo)) continue;
                if (!/^\d{7,15}$/.test(eanUpc.replace(/[^0-9]/g, ''))) continue;

                records.push({
                    invoice_no: invoiceNo,
                    ean_upc: eanUpc,
                    delivery_no: colMap.delivery_no !== undefined ? String(row[colMap.delivery_no] || '').trim() : '',
                    dest_store_no: colMap.dest_store_no !== undefined ? String(row[colMap.dest_store_no] || '').trim() : '',
                    quantity: colMap.quantity !== undefined ? Number(row[colMap.quantity]) || 0 : 0,
                    selling_price: colMap.selling_price !== undefined ? Number(row[colMap.selling_price]) || 0 : 0,
                    total_cost_ex_vat: colMap.total_cost_ex_vat !== undefined ? Number(row[colMap.total_cost_ex_vat]) || 0 : 0,
                    total_vat: colMap.total_vat !== undefined ? Number(row[colMap.total_vat]) || 0 : 0
                });
            }

            const choice = await resolveAsnDuplicates(displayLabel, records, i + 1, pendingAsnFiles.length);
            if (!choice) {
                hideLoadingOverlay();
                pendingAsnFiles = [];
                await checkExistingReconcile();
                return;
            }
            if (choice.sameFileMode === 'skip') {
                continue;
            }

            const fd = new FormData();
            fd.append('job_id', CURRENT_JOB_ID);
            fd.append('asn_filename', displayLabel);
            fd.append('append', '1');
            fd.append('reconcile', '0');
            fd.append('duplicate_mode', choice.duplicateMode);
            fd.append('same_file_mode', choice.sameFileMode);
            fd.append('records', JSON.stringify(records));

            const res = await fetch('api.php?action=import_asn', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.status !== 'success') {
                throw new Error(json.message);
            }
            setStatusBarAsn(json.asn_counts);

            importedAny = true;
            recompare = recompare || choice.recompare;
            lastAsnFilename = json.asn_filename || displayLabel;
            if (json.raw_records) {
                lastRawRecords = json.raw_records;
            }

            currentAsnWorkbook = f.workbook;
            currentAsnFileName = f.fileName;
            cachedAsnSheets = f.cachedSheets;

            const sheetWrapper = document.getElementById('asn-sheet-wrapper');
            const sheetSelect = document.getElementById('asn-sheet-select');
            if (sheetSelect && sheetWrapper) {
                sheetSelect.innerHTML = '';
                f.validSheets.forEach(s => {
                    const opt = document.createElement('option');
                    opt.value = s.name;
                    opt.textContent = `${s.name} (${s.rowCount.toLocaleString()} แถว)`;
                    if (s.name === sheetName) opt.selected = true;
                    sheetSelect.appendChild(opt);
                });
                if (pendingAsnFiles.length === 1 && f.validSheets.length > 1) {
                    sheetWrapper.classList.remove('d-none');
                    sheetWrapper.classList.add('d-flex');
                } else {
                    sheetWrapper.classList.add('d-none');
                    sheetWrapper.classList.remove('d-flex');
                }
            }
        }

        if (!importedAny) {
            pendingAsnFiles = [];
            hideLoadingOverlay();
            await checkExistingReconcile();
            return;
        }

        const fnEl = document.getElementById('asn-filename-text');
        const pillEl = document.getElementById('asn-file-pill');
        if (fnEl) fnEl.textContent = lastAsnFilename;
        if (pillEl) pillEl.title = lastAsnFilename;

        if (recompare) {
            showLoadingOverlay('กำลังเปรียบเทียบข้อมูลกับ PDF...', 'ข้อมูล ASN ถูกเขียนทับ ระบบเทียบกับ PDF ใหม่ให้อัตโนมัติ');
            await recompareAfterAsnOverwrite();
        } else {
            reconData = {
                summary: null,
                results: lastRawRecords.map(prepareRawAsnRow)
            };

            renderReconSummary();

            filterReconTable();
        }

        pendingAsnFiles = [];
        hideLoadingOverlay();

    } catch (err) {
        hideLoadingOverlay();
        console.error(err);
        showNoticeModal('เกิดข้อผิดพลาดในการดึงข้อมูล ASN', err.message, '', 'danger');
    }
}

function openReconcileConfirmModal() {
    if (!reconData || !reconData.results || reconData.results.length === 0) {
        showNoticeModal(
            'ยังไม่มีข้อมูล ASN ในตาราง',
            'กรุณากดปุ่ม "เลือกไฟล์ ASN เพิ่ม (.xlsx)" เพื่อดึงข้อมูลลงตารางก่อนเริ่มเปรียบเทียบ',
            '',
            'warning'
        );
        return;
    }

    const pdfCountEl = document.getElementById('reconModalPdfCount');
    const asnCountEl = document.getElementById('reconModalAsnCount');

    const pdfCount = (detailedData || []).length;
    const asnCount = reconData.results.length;

    if (pdfCountEl) pdfCountEl.textContent = `${pdfCount.toLocaleString()} รายการ`;
    if (asnCountEl) asnCountEl.textContent = `${asnCount.toLocaleString()} รายการ`;
    const vbrp = vbrpCounts();
    document.getElementById('reconModalVbrpCount').textContent = `${vbrp.rows.toLocaleString()} แถว (${vbrp.invoices.toLocaleString()} บิล)`;

    const modal = getConfirmReconcileModal();
    if (modal) modal.show();
}

async function executeConfirmedReconcile() {
    const modal = getConfirmReconcileModal();
    if (modal) modal.hide();

    showLoadingOverlay(
        'กำลังเปรียบเทียบข้อมูลกับ PDF...',
        'กำลังเทียบสามแหล่ง PDF, ASN และ VBRP: Qty, ยอดก่อน VAT และ VAT'
    );

    try {
        // event=compare: the server records the statuses this compare gives in the bill history (a page load does not)
        const res = await fetch(`api.php?action=get_job_reconcile&job_id=${CURRENT_JOB_ID}&force=1&event=compare`);
        const json = await res.json();
        if (json.status !== 'success' || !json.data) {
            throw new Error(json.message || 'ไม่สามารถเปรียบเทียบข้อมูลได้');
        }
        setStatusBarAsn(json.asn_counts);

        reconData = json.data;
        if (reconData.results) {
            reconData.results.forEach(prepareReconRow);
        }

        if (json.asn_filename) {
            const fnEl = document.getElementById('asn-filename-text');
            const pillEl = document.getElementById('asn-file-pill');
            if (fnEl) fnEl.textContent = json.asn_filename;
            if (pillEl) pillEl.title = json.asn_filename;
        }

        renderReconSummary();
        filterReconTable();

        hideLoadingOverlay();

        const counts = reconStatusCounts();
        const lines = ['MATCH', 'FIXED', 'PENDING_FIX', 'PENDING_ASN', 'MISMATCH']
            .filter(key => counts[key])
            .map(key => `• ${STATUS_DISPLAY[key].label}: ${counts[key].toLocaleString()} รายการ`)
            .concat(vbrpCoverageLines(reconData.summary && reconData.summary.vbrp));

        showNoticeModal(
            'เปรียบเทียบข้อมูลสำเร็จ',
            'ระบบได้ทำการเปรียบเทียบข้อมูลระหว่าง PDF, ASN และ VBRP เรียบร้อยแล้ว',
            `ผลการเปรียบเทียบ:\n${lines.join('\n')}`,
            'success'
        );

    } catch (err) {
        hideLoadingOverlay();
        console.error(err);
        showNoticeModal('เกิดข้อผิดพลาดในการเปรียบเทียบ', err.message, '', 'danger');
    }
}

async function handleAsnSheetChange(sheetName) {
    if (!currentAsnWorkbook || !sheetName) return;
    if (!confirm(`ยืนยันการดึงข้อมูลจากชีต "${sheetName}" ลงในตารางใหม่หรือไม่?`)) {
        return;
    }
    await importAsnSheetFromWorkbook(sheetName, false);
}

async function importAsnSheetFromWorkbook(sheetName, keepOverlayOpen = false) {
    if (!currentAsnWorkbook) return;
    const displayLabel = `${currentAsnFileName} [${sheetName}]`;
    document.getElementById('asn-filename-text').textContent = `กำลังประมวลผล: ${sheetName}...`;
    showLoadingOverlay('กำลังดึงข้อมูล ASN ลงตาราง...', `กำลังเพิ่มข้อมูลชีต "${displayLabel}" ลงตาราง`);

    try {
        let cached = cachedAsnSheets[sheetName];
        if (!cached) {
            const sheet = currentAsnWorkbook.Sheets[sheetName];
            const rawRows = XLSX.utils.sheet_to_json(sheet, { header: 1 });
            const detected = detectSheetColumns(rawRows);
            if (!detected) {
                throw new Error(`ไม่พบคอลัมน์ Invoice No และ EAN/UPC ในชีต ${sheetName}`);
            }
            cached = { rawRows, detected };
            cachedAsnSheets[sheetName] = cached;
        }

        const { rawRows, detected } = cached;
        const { headerRowIdx, colMap } = detected;
        const records = [];
        for (let r = headerRowIdx + 1; r < rawRows.length; r++) {
            const row = rawRows[r];
            if (!row || row.length === 0) continue;
            const invoiceNo = String(row[colMap.invoice_no] || '').trim();
            const eanUpc = String(row[colMap.ean_upc] || '').trim();
            if (!invoiceNo || !eanUpc) continue;
            if (/total|grand|summary|ex\./i.test(invoiceNo)) continue;
            if (!/^\d{7,15}$/.test(eanUpc.replace(/[^0-9]/g, ''))) continue;

            records.push({
                invoice_no: invoiceNo,
                ean_upc: eanUpc,
                delivery_no: colMap.delivery_no !== undefined ? String(row[colMap.delivery_no] || '').trim() : '',
                dest_store_no: colMap.dest_store_no !== undefined ? String(row[colMap.dest_store_no] || '').trim() : '',
                quantity: colMap.quantity !== undefined ? Number(row[colMap.quantity]) || 0 : 0,
                selling_price: colMap.selling_price !== undefined ? Number(row[colMap.selling_price]) || 0 : 0,
                total_cost_ex_vat: colMap.total_cost_ex_vat !== undefined ? Number(row[colMap.total_cost_ex_vat]) || 0 : 0,
                total_vat: colMap.total_vat !== undefined ? Number(row[colMap.total_vat]) || 0 : 0
            });
        }

        const choice = await resolveAsnDuplicates(displayLabel, records, 1, 1);
        if (!choice || choice.sameFileMode === 'skip') {
            hideLoadingOverlay();
            await checkExistingReconcile();
            return;
        }

        const fd = new FormData();
        fd.append('job_id', CURRENT_JOB_ID);
        fd.append('asn_filename', displayLabel);
        fd.append('append', '1');
        fd.append('reconcile', '0');
        fd.append('duplicate_mode', choice.duplicateMode);
        fd.append('same_file_mode', choice.sameFileMode);
        fd.append('records', JSON.stringify(records));

        const res = await fetch('api.php?action=import_asn', { method: 'POST', body: fd });
        const json = await res.json();
        if (json.status !== 'success') {
            throw new Error(json.message);
        }
        setStatusBarAsn(json.asn_counts);

        const finalLabel = json.asn_filename || displayLabel;
        document.getElementById('asn-filename-text').textContent = finalLabel;
        document.getElementById('asn-file-pill').title = finalLabel;

        if (choice.recompare) {
            showLoadingOverlay('กำลังเปรียบเทียบข้อมูลกับ PDF...', 'ข้อมูล ASN ถูกเขียนทับ ระบบเทียบกับ PDF ใหม่ให้อัตโนมัติ');
            await recompareAfterAsnOverwrite();
        } else if (json.raw_records) {
            reconData = {
                summary: null,
                results: json.raw_records.map(prepareRawAsnRow)
            };
            renderReconSummary();
            filterReconTable();
        }

    } catch (err) {
        console.error(err);
        showNoticeModal('เกิดข้อผิดพลาดในการดึงข้อมูล ASN', err.message, '', 'danger');
    } finally {
        if (!keepOverlayOpen) {
            hideLoadingOverlay();
        }
    }
}

function askAsnDuplicateMode(overlap) {
    return new Promise(resolve => {
        const el = document.getElementById('asnDuplicateModal');
        const modal = bootstrap.Modal.getOrCreateInstance(el);
        document.getElementById('asnDupCount').textContent = overlap.overlap_count.toLocaleString();
        document.getElementById('asnDupFiles').textContent = 'ซ้ำกับไฟล์: ' + overlap.files.join(', ');
        document.getElementById('asnDupSample').textContent = overlap.sample.join(', ') + (overlap.overlap_count > overlap.sample.length ? ' ...' : '');

        let choice = null;
        const skipBtn = document.getElementById('btnAsnDupSkip');
        const replaceBtn = document.getElementById('btnAsnDupReplace');
        const onSkip = () => { choice = 'skip'; modal.hide(); };
        const onReplace = () => { choice = 'replace'; modal.hide(); };
        const onHidden = () => {
            skipBtn.removeEventListener('click', onSkip);
            replaceBtn.removeEventListener('click', onReplace);
            el.removeEventListener('hidden.bs.modal', onHidden);
            resolve(choice);
        };
        skipBtn.addEventListener('click', onSkip);
        replaceBtn.addEventListener('click', onReplace);
        el.addEventListener('hidden.bs.modal', onHidden);
        modal.show();
    });
}

function askAsnSameFileMode(label, sameFile) {
    return new Promise(resolve => {
        const el = document.getElementById('asnSameFileModal');
        const modal = bootstrap.Modal.getOrCreateInstance(el);
        document.getElementById('asnSameFileLabel').textContent = label;
        document.getElementById('asnSameStored').textContent = sameFile.stored.toLocaleString();
        document.getElementById('asnSameIncoming').textContent = sameFile.incoming.toLocaleString();
        document.getElementById('asnSameVerdict').textContent = sameFile.identical ? 'ข้อมูลเหมือนเดิมทุกแถว' : 'ข้อมูลในไฟล์ไม่เหมือนเดิม';
        document.getElementById('asnSameNote').textContent =
            'ข้าม = คงข้อมูลและผลเทียบเดิมไว้ ไม่เปลี่ยนแปลงอะไร\n' +
            (sameFile.reconciled
                ? 'เขียนทับ = แทนที่ด้วยข้อมูลจากไฟล์ที่เลือก แล้วเทียบกับ PDF ใหม่ให้อัตโนมัติ'
                : 'เขียนทับ = แทนที่ข้อมูลเดิมด้วยข้อมูลจากไฟล์ที่เลือก');

        let choice = null;
        const skipBtn = document.getElementById('btnAsnSameSkip');
        const replaceBtn = document.getElementById('btnAsnSameReplace');
        const onSkip = () => { choice = 'skip'; modal.hide(); };
        const onReplace = () => { choice = 'replace'; modal.hide(); };
        const onHidden = () => {
            skipBtn.removeEventListener('click', onSkip);
            replaceBtn.removeEventListener('click', onReplace);
            el.removeEventListener('hidden.bs.modal', onHidden);
            resolve(choice);
        };
        skipBtn.addEventListener('click', onSkip);
        replaceBtn.addEventListener('click', onReplace);
        el.addEventListener('hidden.bs.modal', onHidden);
        modal.show();
    });
}

/**
 * Asks what to do with data that is already in the Job.
 * Returns null when cancelled, otherwise { sameFileMode, duplicateMode, recompare }.
 */
async function resolveAsnDuplicates(label, records, position, total) {
    const fd = new FormData();
    fd.append('job_id', CURRENT_JOB_ID);
    fd.append('asn_filename', label);
    fd.append('records', JSON.stringify(records));
    const res = await fetch('api.php?action=asn_overlap', { method: 'POST', body: fd });
    const json = await res.json();
    if (json.status !== 'success') {
        throw new Error(json.message);
    }

    const choice = { sameFileMode: 'replace', duplicateMode: 'skip', recompare: false };
    const resume = () => showLoadingOverlay('กำลังดึงข้อมูล ASN ลงตาราง...', `กำลังนำเข้าไฟล์ ${position} / ${total}: ${label}`);

    if (json.same_file && json.same_file.stored > 0) {
        hideLoadingOverlay();
        const sameFileMode = await askAsnSameFileMode(label, json.same_file);
        if (!sameFileMode) {
            return null;
        }
        if (sameFileMode === 'skip') {
            resume();
            choice.sameFileMode = 'skip';
            return choice;
        }
        choice.recompare = !!json.same_file.reconciled;
        resume();
    }

    if (json.overlap_count) {
        hideLoadingOverlay();
        const duplicateMode = await askAsnDuplicateMode(json);
        if (!duplicateMode) {
            return null;
        }
        choice.duplicateMode = duplicateMode;
        resume();
    }
    return choice;
}

async function recompareAfterAsnOverwrite() {
    const res = await fetch(`api.php?action=get_job_reconcile&job_id=${CURRENT_JOB_ID}&force=1`);
    const json = await res.json();
    if (json.status !== 'success' || !json.data) {
        throw new Error(json.message || 'ไม่สามารถเปรียบเทียบข้อมูลได้');
    }
    setStatusBarAsn(json.asn_counts);
    reconData = json.data;
    if (reconData.results) {
        reconData.results.forEach(prepareReconRow);
    }
    renderReconSummary();
    filterReconTable();
}
