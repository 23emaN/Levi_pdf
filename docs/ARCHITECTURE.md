# โครงสร้างของโปรแกรม

เอกสารนี้อธิบายโค้ด ณ 2026-10-28 กติกาทางธุรกิจอยู่ใน [BUSINESS_RULES.md](BUSINESS_RULES.md)

## ชั้นของโค้ด

```
หน้าเว็บ  index.php, job.php, workspace.php,              สร้าง HTML พร้อมสถานะเริ่มต้น
   │      credit_note.php, read_cn_error01.php
   │      assets/js/*.js                                    ทำงานในเบราว์เซอร์ เรียก api.php ด้วย fetch
   ▼
api.php   switch ตาม action 35 กรณี                          อ่านคำขอ เรียก class ตอบ JSON
   ▼
app/      class ใน namespace App\                            กติกา ข้อมูล และไฟล์
   ▼
SQLite data/app.sqlite และโฟลเดอร์ myfile/
```

### class ใน `app/` และทิศทางการพึ่งพา

| class | หน้าที่ | ใช้ class ไหน |
| --- | --- | --- |
| `Config` | ที่อยู่ root, `myfile/` และโฟลเดอร์ของหน้าใบลดหนี้ `PDF_READER_ROOT` เปลี่ยน root ได้ | ไม่มี |
| `Database` | การเชื่อมต่อ SQLite ตัวเดียว, สร้างและอัปเดต schema | `Config` |
| `FileManager` | หาไฟล์ PDF, ลบโฟลเดอร์ว่าง, ตรวจ path, เปิด Explorer | ไม่มี |
| `PdfExtractor` | อ่าน PDF เป็นแถวต่อสินค้า (`extractDetailedRows`) และอ่านใบลดหนี้เป็นแถวต่อใบ (`extractCnDocuments` ใช้ทั้ง `CreditNoteStore::scan` และหน้าเก่า) การแบ่งหน้าเป็นใบและค่าของแต่ละใบอยู่ใน `cnDocumentsOfPages` ซึ่งรับข้อความของหน้าที่ parse แล้ว | `smalot/pdfparser` |
| `CreditNoteReader` | อ่านใบลดหนี้เป็นใบ (`read`): ค่าเดิมของ `extractCnDocuments` ทุกช่อง บวกบรรทัดสินค้า ยอดรวมด้านขวา และ `read_ok` parse PDF ครั้งเดียวแล้วส่งข้อความของหน้าให้ `PdfExtractor::cnDocumentsOfPages` `CreditNoteStore::extractBatch` เรียก | `PdfExtractor`, `smalot/pdfparser` |
| `PdfSplitter` | ตัดหน้า PDF เป็นไฟล์ใหม่ ทับเลขบิลและยอดรวมท้ายบิลด้วยกล่องขาว | `setasign/fpdi`, `smalot/pdfparser` |
| `FixLog` | บันทึกและเล่าสิ่งที่ปุ่มแก้ไขทำ (`job_invoice_fix_steps`) | `JobManager::invoiceKey` |
| `FixPlan` | แผนว่าหน้าไหนเป็นของบิลไหนและปุ่มแก้ไขจะทำอะไร ไม่เขียนอะไรเลย | `FixLog`, `JobManager::invoiceKey` |
| `AsnReconciler` | ตัดสินสถานะทุกรายการ บิลที่ VBRP ครอบคลุมตัดสินสามแหล่ง (โหลด `VbrpFigures::forJob` เอง ทุกทางที่เรียก reconcile จึงเห็นสถานะเดียวกัน) และเติม `row.sources` กับ `summary.vbrp` | `FixPlan`, `FixLog`, `VbrpFigures`, `SourceVerdict` |
| `VbrpFigures` | ตัวเลขของ VBRP ต่อบิล (`cost`, `vat`, `lines`) และต่อบาร์โค้ด (`qty`, `price`) จากแถวที่นับเท่านั้น (Subtotal 3 ต่ำกว่า `SKIP_FROM` = -1 หรือไม่มีค่า) ไม่เขียนอะไร ไม่มีตาราง VBRP ก็ได้ค่าว่าง (`forJob`, `fromRows`, `covers`, `bill`, `line`, `lists`) | `DashboardBuilder::invoiceKey` |
| `SourceVerdict` | คำตัดสินของ PDF, ASN, VBRP ในหนึ่งรายการ ทีละข้อ (`judge` เป็น static ไม่มีสถานะ) ทะเบียนสามข้อ `CHECKS` (`qty`, `amount`, `vat` ค่าความคลาดเคลื่อนอ่านจากค่าคงที่ของ `AsnReconciler` ราคาขายไม่ใช่ข้อหนึ่ง ราคาของ VBRP แค่ส่งต่อไปที่ `row.sources.vbrp.price`) ประโยคปัญหาของ PDF แบบสามค่า (`problem`) หมายเหตุของ ASN และ VBRP (`notes`) และ `row.sources` (`payload`) ไม่มี SQL | `AsnReconciler` (ค่าคงที่เท่านั้น) |
| `BillStatus` | ทะเบียนสถานะของรายการและบิล Tax Invoice จากดีไปแย่: `MATCH` สมบูรณ์, `PENDING_FIX` รอแก้ไข PDF, `PENDING_ASN` รอแก้ไข ASN, `MISMATCH` ผิดพลาด พร้อมป้าย ลำดับ (`rank`) ว่าปุ่มแก้ไขแก้ได้ไหม (`canFix`) สั่งพิมพ์ได้ไหม (`canPrint`) และ `worst` ไม่มี SQL | ไม่มี |
| `BillHistory` | ตาราง `job_bill_history` (สร้างเองใน `createTable` แบบ `VbrpStore`): หนึ่งแถวต่อการเปลี่ยนหมวดของบิลหลังการกระทำของผู้ใช้ (`EVENTS`: `compare`, `asn_import`, `asn_delete`, `vbrp_import`, `vbrp_clear`, `fix`) หมวดคือรหัสของ `BillStatus` หรือ `FIXED` (`category`) `record` เขียนเฉพาะบิลที่หมวดต่างจากแถวล่าสุด `forJob` อ่าน `was` บอกหมวดที่รอหรือผิดก่อนหมวดล่าสุด | `BillStatus` |
| `PdfFixer` | ทำตามแผน: แก้แถว PDF แยกไฟล์ บันทึกการแก้ไข | `JobManager`, `FixPlan`, `AsnReconciler`, `FixLog`, `PdfSplitter` |
| `PrintManager` | รอบสั่งพิมพ์ | `JobManager`, `PdfSplitter`, `AsnReconciler`, `Database` |
| `CoverSheet` | ใบปะหน้า: Tax Invoice ที่ `BillStatus::canPrint` และใบลดหนี้ `MATCH` ของ Job จัดกลุ่มตามสาขา (`group`) แบ่งหน้า (`pages` static ไม่มี SQL) ค่าส่วนหัวในตาราง `cover_settings` (สร้างเองใน constructor) ไฟล์ใน `COVER SHEET/` | `JobManager`, `AsnReconciler`, `CreditNoteCompare`, `StoreNames`, `CoverSheetPdf` |
| `CoverSheetPdf` | วาดหน้าของ `CoverSheet::pages` บนแบบฟอร์ม `assets/cover/form.jpg` ด้วยฟอนต์ Sarabun (`assets/cover/font`, cp874) | `setasign/fpdf` |
| `StoreNames` | รหัสสาขาเป็นชื่อสาขา จาก `assets/cover/store_names.json` (75 สาขาจากใบปะหน้าตัวอย่าง March 2026) และบรรทัดร้านของใบลดหนี้ | ไม่มี |
| `JobFolders` | โฟลเดอร์ของ Job ใน `myfile/` (`TAX INVOICE/` กับ `upload here`, `finished`, `print` และ `CREDIT NOTE/` กับ `upload here`, `finished`) และชื่อรอบ `print ครั้งที่ N` class เดียวที่รู้ชื่อโฟลเดอร์ ย้าย Job ที่สร้างก่อนแยกโฟลเดอร์ (ดูหัวข้อ "โฟลเดอร์ของ Job") และแปลงที่อยู่เป็นข้อความที่หน้าเว็บแสดง (`displayPath`) ชื่อโฟลเดอร์ Job มาจากชื่อและเลขของ Job เท่านั้น ไม่มี SQL | `Config`, `FileManager` |
| `JobRepository` | ตาราง `jobs`: แถวของ Job ตัวเลขสรุป ชื่อไฟล์ ASN และค่าว่าเทียบแล้วหรือยัง | `JobFolders`, `FileManager` |
| `PdfItemStore` | ตาราง `job_files` และ `job_items` (แถว PDF), สแกน `upload here` หาไฟล์ใหม่และบิลซ้ำ, ลบบันทึกการแก้ไขพร้อมแถว PDF ของบิล | `JobFolders`, `JobRepository`, `FileManager`, `PdfExtractor` |
| `AsnStore` | ตาราง `job_asn_records` (แถว ASN): นำเข้า ไฟล์ ASN ซ้ำ ลบ นับ | `JobRepository` |
| `CreditNoteStore` | ตาราง `job_cn_files`, `job_cn_docs` (ใบลดหนี้ หนึ่งแถวต่อใบ) และ `job_cn_items` (บรรทัดสินค้าของใบ): สแกน `CREDIT NOTE/upload here` หาไฟล์ใหม่และใบลดหนี้ซ้ำ (`PdfExtractor::extractCnDocuments`) ดึงข้อมูลด้วย `CreditNoteReader::read` และย้ายไฟล์ไป `CREDIT NOTE/finished` ล้าง นับ ส่งใบพร้อมบรรทัดสินค้าให้การเทียบ (`docsWithItems`) | `JobFolders`, `JobRepository`, `FileManager`, `PdfExtractor`, `CreditNoteReader` |
| `CreditNoteAsnStore` | ตาราง `job_cn_asn_records` (แถว ASN ของการเทียบใบลดหนี้ แยกจาก `job_asn_records`) และ `job_cn_state` (ว่าเทียบใบลดหนี้ของ Job แล้วหรือยัง): นำเข้าทีละชีต ชีตเดิมซ้ำ เลขใบลดหนี้ซ้ำกับชีตอื่น ลบ นับ ชื่อที่หน้าเว็บแสดง ไม่แตะตาราง `jobs` | ไม่มี |
| `CreditNoteReconciler` | ตัดสินใบลดหนี้กับแถว ASN (method static ไม่มี SQL ไม่มีสถานะ): แถวของแท็บ 2 สรุป และหนึ่งคำตัดสินต่อใบให้แท็บ 3 | `DashboardBuilder::invoiceKey` |
| `CreditNoteCompare` | งานของแท็บ 2 และ 3 ของหน้าใบลดหนี้ แต่ละ method ตอบสิ่งที่ action `job_cn_*` ของมันส่ง (นำเข้า ASN ถามซ้ำ แถว ASN ลบ เทียบ ภาพรวม) | `CreditNoteAsnStore`, `CreditNoteStore`, `CreditNoteReconciler`, `JobRepository` |
| `DashboardBuilder` | ผลการเทียบพร้อมรอบที่พิมพ์ บันทึกการแก้ไข และไฟล์ต้นฉบับของแต่ละรายการ (แท็บ 2) ตัวเลขและตารางของหน้าภาพรวม (แท็บ 3) เมื่อ Job มีแถว VBRP ทุกแถวของภาพรวมได้ `verdict`, `vbrp`, `notes` และคำตอบได้ `verdict_counts` (`sourceColumns`, `verdictCounts`) ไม่มี SQL ของตัวเอง | `JobRepository`, `PdfItemStore`, `AsnStore`, `AsnReconciler`, `FixLog`, `PrintManager`, `VbrpFigures`, `SourceVerdict` |
| `VbrpStore` | ตาราง `job_vbrp_files` และ `job_vbrp_rows` (สร้างเองใน `createTables` พร้อมเพิ่มคอลัมน์ `subtotal_3` ให้ฐานข้อมูลเก่า ไม่ผ่าน `Database::initSchema`) นำเข้าไฟล์ที่อัปโหลดในแท็บ 2 (`importUpload`: ตรวจป้ายแถวที่ 3 ตาม `LABELS` อ่านตรงจากไฟล์ชั่วคราว ไม่สร้างโฟลเดอร์) ข้อความเมื่อ PHP ไม่รับไฟล์เพราะขนาด (`sizeRefusal`) นับ (`counts`: `files`, `rows`, `invoices`) ล้าง และทางของหน้าเก่า `vbrp.php` (`scan`, `extractBatch`, `saveUploads`, `files`, `rows`) ซึ่งผู้ใช้ไม่ใช้แล้ว | `XlsxReader`, `JobFolders`, `JobRepository`, `FileManager` |
| `XlsxReader` | อ่านคอลัมน์ที่เลือกของไฟล์ `.xlsx` ทีละแถวจาก XML ใน zip | ไม่มี |
| `JobManager` | ทางเข้าเดียวของหน้าเว็บ, `api.php`, `PrintManager`, `PdfFixer` และชุดทดสอบ ส่งทุกคำสั่งต่อให้ class เจ้าของ ทำเองเฉพาะงานที่ข้ามเจ้าของ: สร้าง ลบ และล้างข้อมูล Job (`createJob`, `deleteJob`, `clearJobData`) และตัวเลขที่รวมจากหลายเจ้าของ (`deleteSummary`, `statusCounts`) หน้าเว็บนับใบลดหนี้และแถว ASN ของใบลดหนี้ผ่าน `creditNoteCounts` การเทียบใบลดหนี้ส่งออกไปทั้งก้อนทาง `creditNoteCompare()` และ `clearCreditNotes` ล้างใบลดหนี้พร้อมสถานะ "เทียบแล้ว" ของใบลดหนี้ | `Database`, `JobFolders`, `JobRepository`, `PdfItemStore`, `AsnStore`, `DashboardBuilder`, `CreditNoteStore`, `CreditNoteAsnStore`, `CreditNoteCompare`, `VbrpStore`, `PrintManager`, `FileManager` |

ทิศทางหลักคือ หน้าเว็บและ `api.php` เรียก `JobManager`, `PdfFixer`, `PrintManager` แล้ว class เหล่านี้เรียก `AsnReconciler`, `FixPlan`, `FixLog` และ class ด้านไฟล์
ข้างหลัง `JobManager` ทิศทางเป็นทางเดียว: `DashboardBuilder` → `PdfItemStore`, `AsnStore` → `JobRepository` → `JobFolders` และ `CreditNoteCompare` → `CreditNoteAsnStore`, `CreditNoteReconciler`, `CreditNoteStore` → `JobRepository`, `JobFolders` ไม่มี class ใดในเก้าตัวนี้เรียก `JobManager`
มีเพียง constructor ของ `JobManager` ที่เรียก `Database::getConnection` แล้วส่ง `PDO` ให้ class เหล่านี้ทาง constructor

แต่ละตารางมีเจ้าของเดียวใน class ของ Job: `jobs` คือ `JobRepository`, `job_files` และ `job_items` คือ `PdfItemStore`, `job_asn_records` คือ `AsnStore`, `job_cn_files`, `job_cn_docs` และ `job_cn_items` คือ `CreditNoteStore`, `job_cn_asn_records` และ `job_cn_state` คือ `CreditNoteAsnStore`, `job_vbrp_files` และ `job_vbrp_rows` คือ `VbrpStore` (`VbrpFigures` อ่าน `job_vbrp_rows` อย่างเดียว), `job_bill_history` คือ `BillHistory`
คำสั่ง SQL ของ class ของ Job ที่อ่านหรือลบบันทึกการแก้ไข (`job_invoice_fixes`, `job_invoice_fix_steps`) อยู่ใน `PdfItemStore` เพราะบันทึกของบิลหายไปพร้อมแถว PDF ของบิลนั้น การเขียนบันทึกอยู่ที่ `PdfFixer` และ `FixLog`
class อื่นยังมี SQL ของตัวเองกับตารางเหล่านี้ ดูหัวข้อ "จุดที่ควรรู้"

มีการพึ่งพากลับสองจุด: `JobManager` กับ `PrintManager` ใช้กันและกัน (`JobManager::reconcileJob` สร้าง `PrintManager` จากตัวเองแล้วส่งให้ `DashboardBuilder::reconcileJob`) และ `FixLog`, `FixPlan` เรียก `JobManager::invoiceKey` (method static ที่ส่งต่อให้ `DashboardBuilder::invoiceKey`) ขณะที่ `DashboardBuilder` ใช้ `FixLog`

`FixPlan` เป็นจุดที่กติกา "ก่อนแก้ไขเท่ากับหลังแก้ไข" อยู่ `AsnReconciler` ใช้แผนเพื่อตัดสิน `PdfFixer` ใช้แผนเดียวกันเพื่อทำ ถ้าแก้การแบ่งหน้าให้แก้ที่ `FixPlan` ที่เดียว

## ข้อมูล

ฐานข้อมูลคือ `data/app.sqlite` (`Database::getConnection`) มีสิบห้าตาราง (สองตาราง VBRP สร้างโดย `VbrpStore` , `job_bill_history` สร้างโดย `BillHistory` และ `cover_settings` สร้างโดย `CoverSheet`):

| ตาราง | หนึ่งแถวคือ | หมายเหตุ |
| --- | --- | --- |
| `jobs` | หนึ่ง Job | เก็บตัวเลขสรุป (`total_items`, `asn_matched_count`, `asn_pending_fix_count`, `asn_mismatch_count`) และ `asn_reconciled` ว่าเทียบแล้วหรือยัง |
| `job_files` | ไฟล์ PDF ที่ดึงข้อมูลแล้ว | `UNIQUE(job_id, filename)`, `file_path` คือที่อยู่ของไฟล์ตอนดึงข้อมูล เก็บไว้ให้คนอ่านเท่านั้น ไม่มีโค้ดใดอ่าน ไฟล์จริงคือ `filename` ใน `TAX INVOICE/finished/` ของ Job ตอนนี้ |
| `job_items` | หนึ่งสินค้าบนหนึ่งหน้า PDF | ค่าตามที่พิมพ์ใน PDF ช่องยอดรวมท้ายบิลเป็นข้อความ (`'Null'` คือไม่มี) ปุ่มแก้ไขเขียนทับ `tax_invoice` และช่องยอดรวม |
| `job_asn_records` | หนึ่งแถวของไฟล์ ASN | `source_file` คือชื่อไฟล์และชีต `raw_json` เก็บแถวเดิมทั้งแถว |
| `job_invoice_fixes` | เลขบิลที่ปุ่มแก้ไขบันทึกไว้ | key คือ `(job_id, invoice_no)` |
| `job_invoice_fix_steps` | สิ่งที่ปุ่มแก้ไขทำกับหนึ่งหน้า | `UNIQUE(job_id, filename, page_no, kind)` |
| `job_cn_files` | ไฟล์ PDF ใบลดหนี้ที่ดึงข้อมูลแล้ว | `UNIQUE(job_id, filename)`, `file_path` คือที่อยู่ของไฟล์ตอนดึงข้อมูล เก็บไว้ให้คนอ่านเท่านั้น ไม่มีโค้ดใดอ่าน, `page_count` คือจำนวนหน้า |
| `job_cn_docs` | หนึ่งใบลดหนี้ (ไม่ใช่หนึ่งสินค้า) | `file_id` ไป `job_cn_files` (`ON DELETE CASCADE`), index `(job_id, doc_no)` `doc_no` เป็นเลขเต็ม เช่น `93250803-1` ช่องข้อความเก็บตามที่พิมพ์ (`-` คือไม่พบ) ช่องยอดเงิน `orig_amount`, `corr_amount`, `diff_amount`, `vat_amount`, `amount` เป็นตัวเลข `NULL` คือ PDF ไม่ได้พิมพ์ ช่องของ `CreditNoteReader` (`store_no`, `doc_date_iso`, `shipped_units`, `promo_discount`, `total_amount`, `total_discount`, `after_discount`, `excl_vat`, `vat`, `incl_vat`, `gp_percent`, `read_ok`, `read_note`, ชุดเดียวใน `Database::CN_READER_COLUMNS`) เป็น `NULL` ทั้งหมดในใบที่เก็บก่อน schema 9 `job_cn_docs` ตอบ `has_items` เป็นจริงเมื่อ `read_ok` ไม่ใช่ `NULL` |
| `job_cn_items` | หนึ่งบรรทัดสินค้าของใบลดหนี้ ตามลำดับที่พิมพ์ | `doc_id` ไป `job_cn_docs`, `file_id` ไป `job_cn_files` (ทั้งสอง `ON DELETE CASCADE`), index `(job_id, doc_no)` `units`, `unit_price`, `amount` เป็น `NULL` เมื่อบรรทัดไม่จบด้วยตัวเลขสามตัว (ใบนั้น `read_ok` เป็นเท็จ) Attachment Sheet ไม่มีบรรทัด |
| `job_cn_asn_records` | หนึ่งแถวของชีต ASN ที่นำเข้าในหน้าใบลดหนี้ | คอลัมน์เดียวกับ `job_asn_records` บวก `amount_kind` (`CHECK` ให้เป็น `cost` หรือ `retail` เท่านั้น) `source_file` คือ `<ไฟล์> / <ชีต>` `raw_json` เก็บแถวที่หน้าเว็บส่งมาทั้งแถว |
| `job_cn_state` | หนึ่ง Job ที่เคยนำเข้าหรือเทียบใบลดหนี้ | `asn_reconciled` ว่ากดเทียบใบลดหนี้แล้วหรือยัง แยกจาก `jobs.asn_reconciled` ของ Tax Invoice ไม่มีแถวเท่ากับยังไม่เทียบ |
| `job_vbrp_files` | ไฟล์ VBRP ที่นำเข้า | สร้างโดย `VbrpStore::createTables` ไม่ใช่ `initSchema` จึงไม่อยู่ใน `SCHEMA_VERSION` `UNIQUE(job_id, filename)` ไฟล์ที่นำเข้าในแท็บ 2 มี `file_path` เป็น `''` เพราะไม่เก็บไฟล์ |
| `job_bill_history` | หนึ่งการเปลี่ยนหมวดของบิลหลังการกระทำของผู้ใช้ | สร้างโดย `BillHistory::createTable` ไม่อยู่ใน `SCHEMA_VERSION` `invoice_no` ตามที่ ASN เขียน `at` เป็น `CURRENT_TIMESTAMP` (UTC หน้าเว็บแปลงเป็นเวลาของเครื่อง) `from_status` เป็น `NULL` ในแถวแรกของบิล `detail` คือปัญหาของบิลตอนนั้นไม่เกิน 300 ตัวอักษร index `(job_id, invoice_no, id)` Job ที่มีอยู่ก่อนเริ่มบันทึกตั้งแต่การกระทำแรกหลังเปลี่ยน |
| `job_vbrp_rows` | หนึ่งแถวข้อมูลของชีต VBRP | คอลัมน์ `A / C / E / H / CI / CJ / CK / CQ / EM` (`combine` ถึง `total_vat` และ `subtotal_3`) แถวที่เก็บก่อนมี `subtotal_3` ได้ `NULL` และนับในการเทียบ |
| `cover_settings` | ค่าส่วนหัวของใบปะหน้าหนึ่งช่อง (`contact`, `mobile`, `case_label`, `date`) | สร้างโดย `CoverSheet` ไม่อยู่ใน `SCHEMA_VERSION` ใช้ร่วมทุก Job (ค่าที่กรอกล่าสุด) |

ทุกตารางยกเว้น `jobs` มี `FOREIGN KEY ... ON DELETE CASCADE` ไปที่ `jobs` และการเชื่อมต่อเปิด `PRAGMA foreign_keys = ON`

### เวอร์ชันของ schema

`Database::SCHEMA_VERSION` คือ 9 เก็บใน `PRAGMA user_version` เวอร์ชัน 8 เพิ่ม `job_cn_files` และ `job_cn_docs` เวอร์ชัน 9 เพิ่มช่องของ `CreditNoteReader` ใน `job_cn_docs` และตาราง `job_cn_items`, `job_cn_asn_records`, `job_cn_state`

- ฐานข้อมูลใหม่: `Database::initSchema` สร้างทุกตาราง แล้วตั้ง `user_version = 9`
- ฐานข้อมูลเก่าที่ `user_version` ต่ำกว่า 9: `Database::migrateSchema` เพิ่มคอลัมน์ที่ขาด (`job_asn_records.total_vat`, `delivery_no`, `source_file`, `jobs.asn_reconciled`, `asn_pending_fix_count` และถ้ามี `job_cn_docs` อยู่แล้ว ช่องของ `CN_READER_COLUMNS` เป็น `NULL`) แล้วเรียก `initSchema` (ใช้ `CREATE TABLE IF NOT EXISTS` จึงได้ตารางที่ขาดทั้งหมด) แล้วตั้ง `user_version = 9` Job และใบลดหนี้ที่เก็บไว้ยังอยู่ ใบเหล่านั้นได้ `has_items` เป็นเท็จจนกว่าจะดึงข้อมูลใหม่จาก `CREDIT NOTE/finished`
- ถ้าจะเพิ่มตารางหรือคอลัมน์ ให้เพิ่มใน `initSchema` หรือ `migrateSchema` แล้วเพิ่ม `SCHEMA_VERSION`

ทดสอบ: `job_safety_test.php` "a new database starts at schema version 9", "a version 6 database gains the fix step table and moves to version 9", `credit_note_test.php` "a version 7 database gains the credit note tables and moves to version 9, its Jobs kept", `credit_note_compare_test.php` "a version 8 database moves to version 9: its Jobs and credit note documents stay…"

## เส้นทางของคำขอ

หน้า PHP สร้าง HTML ที่มีสถานะเริ่มต้นแล้ว (เช่นปุ่มที่ต้องเน้น จำนวนในแถบสถานะ) จากนั้น JavaScript โหลดแถวผ่าน `api.php`

ตัวอย่าง กดเทียบกับ PDF: `asn-import.js` เรียก `api.php?action=get_job_reconcile&force=1&event=compare` → `JobManager::recordBillHistory` (เฉพาะคำขอที่มี `event=compare` คือปุ่มเทียบ การโหลดหน้าไม่บันทึก) → `JobManager::reconcileJob` → `DashboardBuilder::reconcileJob` → `AsnReconciler::reconcile` (สร้าง `FixPlan`) → `api.php` ตัดคอลัมน์ที่หน้าเว็บไม่ใช้ (`compactReconcilePayload`) → `asn-table.js` วาดตาราง

`api.php` เรียกทุก method ในตารางนี้ผ่าน `JobManager` ซึ่งมี method ชื่อเดียวกัน คอลัมน์ "ทำงานที่" บอก class ที่ทำงานจริง

| action | เรียกจาก | ทำงานที่ |
| --- | --- | --- |
| `list_jobs` | `index.js` | `JobRepository::listJobs` |
| `create_job` | `index.js` | `JobManager::createJob` |
| `job_delete_summary` | `index.js` | `JobManager::deleteSummary` |
| `delete_job` | `index.js` | `JobManager::deleteJob` (`api.php` ตรวจ `confirm_name` ก่อน) |
| `clear_job` | ไม่มีหน้าใดเรียก | `JobManager::clearJobData` |
| `scan_job_files` | `workspace/extraction.js` | `PdfItemStore::scanSourceFiles` |
| `extract_job_batch` | `workspace/extraction.js` | `PdfExtractor::extractDetailedRows`, `PdfItemStore::recordExtractedBatch` (การย้ายไฟล์และบิลซ้ำเขียนใน `api.php`) |
| `get_job_items` | `workspace/pdf-table.js` | `PdfItemStore::getJobItems` |
| `delete_job_items` | `workspace/clear-modal.js` | `PdfItemStore::deleteJobItems`, `DashboardBuilder::reconcileJob` |
| `import_asn` | `workspace/asn-import.js` | `AsnStore::saveAsnRecords` |
| `asn_overlap` | `workspace/asn-import.js` | `AsnStore::findAsnOverlap`, `AsnStore::describeStoredAsnFile` |
| `get_asn_records` | `workspace/clear-modal.js` | `AsnStore::getAsnRecords` |
| `delete_asn_records` | `workspace/clear-modal.js` | `AsnStore::deleteAsnRecords` |
| `get_job_reconcile` | `workspace/asn-import.js` | `DashboardBuilder::reconcileJob` (กับ `event=compare` บันทึก `compare` ก่อนด้วย `JobManager::recordBillHistory`) |
| `get_job_dashboard` | `workspace/dashboard.js` | `DashboardBuilder::getInvoiceDashboard` |
| `job_vbrp_import` | `workspace/vbrp-import.js` | `VbrpStore::importUpload` (อ่านไฟล์ที่อัปโหลดตรงจากไฟล์ชั่วคราว ไม่เก็บไฟล์ ไม่สร้างโฟลเดอร์ VBRP) |
| `job_vbrp_clear` | `workspace/vbrp-import.js`, `vbrp.js` | `VbrpStore::clear` |
| `fix_invoice`, `fix_all_invoices` | `workspace/asn-fix-export.js` | `PdfFixer::fixJob` |
| `job_cn_scan` | `credit-note/extraction.js` | `CreditNoteStore::scan` |
| `job_cn_extract_batch` | `credit-note/extraction.js` | `CreditNoteStore::extractBatch` (`CreditNoteReader::read`, ย้ายไฟล์, ใบลดหนี้ซ้ำ; ชื่อไฟล์ที่อยู่ใน `CREDIT NOTE/finished` แล้วถูกอ่านใหม่และแทนแถวเดิม) |
| `job_cn_docs` | `credit-note/page.js` | `CreditNoteStore::docs`, `JobManager::creditNoteCounts` (`{files, documents, asn: {files, rows}}`) |
| `job_cn_clear` | `credit-note/page.js` | `CreditNoteStore::clear`, `CreditNoteAsnStore::setReconciled` (แถว ASN ยังอยู่ การเทียบกลับเป็นยังไม่เทียบ) |
| `job_cn_import_asn` | `credit-note/compare.js` | `CreditNoteCompare::importAsn` (`CreditNoteAsnStore::save`) |
| `job_cn_asn_overlap` | `credit-note/compare.js` | `CreditNoteCompare::asnOverlap` |
| `job_cn_asn_records` | `credit-note/compare.js`, `credit-note/compare-table.js` | `CreditNoteCompare::asnRecords` |
| `job_cn_delete_asn` | `credit-note/compare.js` | `CreditNoteCompare::deleteAsn` |
| `job_cn_reconcile` | `credit-note/compare.js` | `CreditNoteCompare::reconcile` (`CreditNoteReconciler::reconcile`) |
| `job_cn_dashboard` | `credit-note/dashboard.js` | `CreditNoteCompare::dashboard` |
| `open_folder` | `ui-utils.js` | `FileManager::openInExplorer` (`target`: `job`, `ti`, `upload`, `finished`, `print`, `round`, `cn`, `cn_upload`, `cn_finished`, `root`; `ti` และ `cn` คือโฟลเดอร์ `TAX INVOICE` และ `CREDIT NOTE` ที่แถบล่างของ `workspace.php` และ `credit_note.php` เปิด) |
| `print_plan`, `print_start`, `print_chunk`, `print_finish` | `workspace/print.js` | `PrintManager::plan`, `start`, `processChunk`, `finish` |
| `cover_preview`, `cover_generate` | `cover-sheet.js` | `CoverSheet::preview`, `generate` (ไฟล์ PDF ส่งด้วย `cover_download.php` ไม่ผ่าน `api.php`) |
| `get_stats` | `index.js`, `workspace/init.js`, `cn-error.js` | `FileManager::getPdfFiles` |
| `list_files`, `extract_cn_batch` | `cn-error.js` | `FileManager::getPdfFiles`, `PdfExtractor::extractCnDocuments` |

`get_job_reconcile`, `get_job_dashboard` และ `print_plan` เป็นคำขออ่าน แต่ `AsnReconciler::reconcile` เขียนตัวเลขสรุปลง `jobs` ทุกครั้งที่เรียก

ประวัติของบิล (2026-10-28): หลัง `import_asn`, `delete_asn_records`, `job_vbrp_import`, `job_vbrp_clear`, `fix_invoice`, `fix_all_invoices` และ `get_job_reconcile` ที่มี `event=compare` `api.php` เรียก `recordBillHistory` (`asn_import`, `asn_delete`, `vbrp_import`, `vbrp_clear`, `fix`, `compare`) → `JobManager::recordBillHistory` เทียบ Job หนึ่งครั้ง (`DashboardBuilder::billCategories`) แล้ว `BillHistory::record` บันทึกเฉพาะ Job ที่เทียบแล้ว (ยกเว้น `compare`) การบันทึกที่ล้มเขียนลง `error_log` และไม่ทำให้ action ล้ม แถวของ `get_job_reconcile` ได้ `was` และแถวของ `get_job_dashboard` ได้ `was` กับ `history` เฉพาะเมื่อไม่ว่าง

action `job_cn_*` ของแท็บ 2 และ 3 เรียก `JobManager::creditNoteCompare()` แล้วเรียก method ของ `CreditNoteCompare` ซึ่งตอบทุกช่องที่ action ส่งหลัง `status` `api.php` แค่อ่านคำขอ `job_cn_reconcile` ที่มี `force` หรือ Job ที่เทียบแล้ว เทียบใหม่บนแถวปัจจุบันทุกครั้งและตั้ง `job_cn_state.asn_reconciled = 1` ส่วน `job_cn_dashboard` ไม่เขียนอะไร `CreditNoteReconciler` สร้างแถวผลในรูปที่หน้าเว็บใช้เลย จึงไม่มีขั้นตัดคอลัมน์แบบ `compactReconcilePayload`

## ทางเดินระหว่างหน้า

- `index.php` เปิด Job ที่ `job.php?job_id=N` ทั้งเมื่อคลิกแถว (`navigateToJob`) และหลังสร้าง Job (`handleCreateJob`) ใน `index.js`
- `job.php` คือหน้าเลือกงาน แสดงทุกครั้งที่เปิด Job ไม่จำและไม่ข้าม การ์ด TAX INVOICE ไป `workspace.php?job_id=N` นับจาก `JobManager::statusCounts` (ตัวเลขเดียวกับแถบสถานะของ `workspace.php`) การ์ด CREDIT NOTE ไป `credit_note.php?job_id=N` นับจาก `JobManager::creditNoteCounts` การ์ดเป็นลิงก์ `<a>` จึงกด Tab และ Enter ได้
- ลูกศรย้อนกลับของ `workspace.php` และ `credit_note.php` ไปที่ `job.php?job_id=N` ของ Job เดียวกัน ลูกศรของ `job.php` ไปที่ `index.php`
- `job.php` และ `credit_note.php` ตอบ Job ที่ไม่มี (id ไม่มี, เป็น 0, ไม่พบ, โฟลเดอร์หาย) เหมือน `workspace.php` ทุกตัวอักษร (check `portal_test.php` "an unknown Job id, a missing one and a Job whose folder is gone…")
- ลิงก์ `read_cn_error01.php` ยังอยู่ที่หัวของ `index.php` และ `workspace.php` เท่านั้น

## JavaScript ของหน้าเว็บ

ทุกไฟล์เป็น script แบบเดิม (ไม่ใช่ ES module) ใช้ตัวแปร global ร่วมกัน ลำดับโหลดจึงสำคัญ

| หน้า | ลำดับโหลด |
| --- | --- |
| `index.php` | Bootstrap (CDN), `window.INITIAL_JOBS`, `assets/js/ui-utils.js`, `assets/js/index.js` |
| `job.php` | Bootstrap (CDN), ค่าคงที่ `CURRENT_JOB_ID`, `assets/js/ui-utils.js` (ใช้แค่ `openJobFolder` ของลิงก์โฟลเดอร์ในแถบสถานะ) |
| `credit_note.php` | Bootstrap (CDN), `assets/vendor/xlsx.full.min.js` (`async`), ค่าคงที่ `CURRENT_JOB_ID`, `CN_JOB_NAME_CLEAN`, `CN_COLUMNS`, `CN_COMPARE_COLUMNS`, `CN_DASH_COLUMNS`, `assets/js/ui-utils.js`, แล้ว `assets/js/credit-note/` ตามลำดับ: `table`, `extraction`, `asn-sheets`, `compare-table`, `compare`, `dashboard`, `page` แล้ว `assets/js/workspace/column-highlight.js` |
| `workspace.php` | Bootstrap (CDN), `assets/vendor/xlsx.full.min.js` (`async`), ค่าคงที่ `CURRENT_JOB_ID`, `CURRENT_JOB_NAME_CLEAN`, `ASN_COLUMNS`, `assets/js/ui-utils.js`, แล้ว `assets/js/workspace/` ตามลำดับ: `state`, `init`, `pdf-table`, `extraction`, `clear-modal`, `pdf-search-export`, `asn-import`, `vbrp-import`, `asn-table`, `asn-fix-export`, `print`, `dashboard`, `column-highlight` |
| `read_cn_error01.php` | Bootstrap (CDN), `assets/vendor/xlsx.full.min.js` (`async`), `assets/js/ui-utils.js`, `assets/js/cn-error.js` |

- `state.js` ประกาศตัวแปร global และ `STATUS_DISPLAY`, `REMARK_LABELS` จึงต้องโหลดก่อนไฟล์อื่นใน `workspace/`
- `ASN_COLUMNS` ใน `workspace.php` เป็นรายการคอลัมน์ของตาราง ASN ชุดเดียว หัวตาราง แถว การเรียง การค้นหา และ Excel ใช้รายการนี้
- แหล่งที่สาม VBRP ในแท็บ 2 และ 3 (2026-10-28): `state.js` เก็บ `VERDICT_SOURCES` (PDF, ASN, VBRP ตามลำดับคอลัมน์), `VERDICT_DISPLAY` (`ok` เครื่องหมาย ✓ สี `--status-match-color`, `wrong` ✗ สี `--status-mismatch-color`, `na` `-` และคำใน tooltip กับ Excel `ตรง` / `ไม่ตรง` / `-`) และ `VERDICT_CHECKS` (`qty`, `amount`, `vat`) ชุดเดียวที่ทั้งสองตารางใช้ `asn-table.js` สร้างสี่คอลัมน์ VBRP ด้วย `vbrpFigureCell` (ค่าจาก `row.sources.vbrp` เป็นสีแดงเมื่อ check ชื่อเดียวกันตัดสินว่า VBRP ผิด ราคาขายไม่มี check จึงไม่เป็นสีแดง) และสามคอลัมน์ผลด้วย `lineVerdictCell` (`row.sources.overall` tooltip บอกผลของแต่ละ check) แถวที่ `sources` เป็น `null` หรือไม่มี (บิลที่ VBRP ไม่มี หรือยังไม่เทียบ) ได้ `-` ในเจ็ดคอลัมน์นี้และทุกเซลล์เดิมเหมือนก่อนมี VBRP หมายเหตุของแหล่งอื่น (`sources.notes` และ `notes` ของบิลในแท็บ 3) เป็นบรรทัด `remark-note` สีเทาใต้ปัญหา (`sourceNoteLines`) ค้นหาได้ และใน Excel ต่อท้าย Problem (`remarkExcelProblem`) หรือ Remark ของแท็บ 3 (check `job_safety_test.php` ที่มาจาก `tests/vbrp_view.js` เช่น "a row without sources … shows every old cell of the grid …", "a covered line shows each source as ✓ in green …")
- `vbrp-import.js` ส่งไฟล์ที่เลือกด้วยปุ่ม "เลือกไฟล์ VBRP (.xlsx)" ไป `job_vbrp_import` แบบ multipart (เบราว์เซอร์ไม่อ่านไฟล์) และล้างด้วย `job_vbrp_clear` หลังยืนยันในหน้าต่าง `vbrpClearModal` จำนวนแถวและบิล VBRP ของ Job อยู่ที่ `data-rows`, `data-invoices` ของ `#footer-vbrp-rows-item` แบบเดียวกับแถว ASN (`asnRowCount`) `showVbrpCounts` เขียนแถบสถานะ ป้าย VBRP และปุ่มล้าง แล้วเรียก `updateAsnToolbarStep` ถ้า Job เทียบแล้ว ผลถูกโหลดใหม่ผ่าน `checkExistingReconcile` (`get_job_reconcile`) ขั้นของแถบเครื่องมือมาจาก `asnToolbarStep` (`select`, `vbrp`, `compare`, `fix`, `print`, `done` ลำดับเดียวกับ `JobRepository::initialAsnStep`) และปุ่มเทียบกับ PDF กดได้เมื่อ `compareBlocker` คืนข้อความว่าง คือ Job มีทั้งแถว ASN และแถว VBRP `updateAsnToolbarStep` เป็นที่เดียวที่ตั้ง `disabled` และ `title` ของปุ่มนี้ (check "the toolbar step goes ASN (select), then VBRP …", "the compare button stays disabled until …")
- แท็บ 3 มีคอลัมน์ PDF, ASN, VBRP จาก `invoices[].verdict` (`DASH_VERDICT_COLUMNS`, แถวทำใน `dashboardRowHtml`) และแถบ `#dash-verdict-strip` ใต้การ์ดจาก `verdict_counts` (`dashboardVerdictStripHtml` ซ่อนเมื่อเป็น `null`) Excel ของแท็บ 3 เพิ่ม `Amt Excl VAT (VBRP)` (`vbrp.cost`) และ PDF, ASN, VBRP ส่วนในหน้าจอไม่มีคอลัมน์ยอด VBRP เพราะตารางต้องพอดีกล่องที่ 1366px (Remark เหลือ 175px ตั้งแต่มีคอลัมน์ Previous status 2026-10-28: Status 8rem, Previous status 100px, PDF / ASN / VBRP คอลัมน์ละ 60px หัวไม่ตัดบรรทัด, Source File ลดจาก 212 เป็น 150px ซึ่งพอสำหรับบรรทัดยาวที่สุดของชื่อไฟล์ 113px วัดในเบราว์เซอร์)
- รอแก้ไข PDF / รอแก้ไข ASN และสถานะเดิมของบิล (2026-10-28): `STATUS_DISPLAY` ใน `state.js` เป็นทะเบียนเดียวของป้าย ไอคอน สี (`tone`) และลำดับการเรียงของทั้งสองตาราง `PENDING_FIX` อ่านว่า `รอแก้ไข PDF` (สีเหลืองอำพันเดิม) และ `PENDING_ASN` อ่านว่า `รอแก้ไข ASN` (สีม่วง `--status-pending-asn-color` ใน `app.css` ไอคอน `fa-file-circle-exclamation`) `rank` ของ `PENDING_ASN` คือ 0.5 เพื่อให้เรียงระหว่างผิดพลาดกับรอแก้ไข PDF โดยค่าของสถานะอื่นไม่เปลี่ยน (แถวเดิมต้องได้ค่าเรียงเดิมตาม `workspace_rows_before_vbrp.json`) ปุ่มกรอง ตัวนับ (`RECON_COUNT_ELEMENTS`) ป้าย ค่าเรียง ข้อความค้นหา และ Excel ได้สถานะนี้จากทะเบียน ไม่มีโค้ดแยกตามสถานะ หมายเหตุของแถวรอแก้ไข ASN คือ `reason` และ `action` ของเซิร์ฟเวอร์ (`ปัญหา : ` / `วิธีแก้ไข : ` สีม่วง) บรรทัดสีเทาใต้นั้นคือ `sources.notes` ปุ่มแก้ไข (`btn-fix-all-count`, `getPendingFixScope`) นับเฉพาะ `PENDING_FIX` แถวรอแก้ไข ASN ไม่มีปุ่มใน Manage (`can_fix` เป็นเท็จ) `countReadyUnprintedInvoices` นับเฉพาะ `MATCH` และ `asnToolbarStep` อ่าน `summary.pending_fix` จึงไม่รอปุ่มแก้ไขเมื่อเหลือแต่บิลรอแก้ไข ASN `describeSkipped` (`print.js`) บอก `skipped.pending_asn` เมื่อมี `executeConfirmedFix` แสดง `message` ของเซิร์ฟเวอร์เมื่อคำตอบเป็น `success: false` (เช่นบิลรอแก้ไข ASN) หลังโหลดตารางใหม่ มีเพียง `executeConfirmedReconcile` (ปุ่มเทียบ) ที่ส่ง `event=compare` `previousStatusText` เขียน `was` เป็นป้ายคั่นด้วย `, ` แถวของแท็บ 2 ที่สมบูรณ์หรือแก้ไขแล้วและมี `was` ได้บรรทัด `เดิม: …` สีเทาต่อจากหมายเหตุของสถานะ (`previousStatusLines`, part `was` จึงไม่อยู่ใน Problem ของ Excel) Excel ของแท็บ 2 และ 3 มีคอลัมน์ `Previous status` หลัง `Status` แท็บ 3 มีการ์ด `รอแก้ไข PDF ทั้งหมด` (`needs_fix` กับแถบเล็กเดิม) และการ์ด `รอแก้ไข ASN` (`pending_asn` Job ที่เทียบแล้วแต่ไม่มีแถว VBRP แสดง 0) แถบรวมและคำอธิบายมีส่วน `pending_asn` คอลัมน์ Status ของตาราง ASN กว้าง `--frozen-2: 9.5rem` (เฉพาะ `#asn-table` ตารางใบลดหนี้ยัง 140px) และของแท็บ 3 กว้าง 8rem เป็น rem แบบป้าย จึงขยายตามขนาดตัวอักษรของเบราว์เซอร์ (ที่ 140px ป้าย `รอแก้ไข ASN` ถูกตัดเมื่อตัวอักษรราก 20px) ป้ายในสองตารางนี้ไม่ตัดบรรทัด ปุ่มกรองหกปุ่มของ `#reconFilterGroup` และ `#dashFilterGroup` ขึ้นบรรทัดใหม่ในกลุ่มเมื่อหน้าต่างแคบ (800px) ตารางบิลมีคอลัมน์ `Previous status` (`data-col="was"`) หลัง Status ที่ชี้แล้วเห็น `history` ทีละบรรทัด (`historyText`: วันที่ตามเวลาของเครื่องจาก UTC ของเซิร์ฟเวอร์ ชื่อเหตุการณ์ไทยจาก `HISTORY_EVENTS` การเปลี่ยน และปัญหาตอนนั้น) (check `job_safety_test.php` ที่มาจาก `tests/status_split_view.js`)
- `CREDIT_NOTE_COLUMNS` ใน `credit_note.php` (ส่งให้ JavaScript เป็น `CN_COLUMNS`) เป็นรายการคอลัมน์ของตารางใบลดหนี้ชุดเดียวแบบเดียวกัน PHP เขียนหัวตาราง ส่วน `credit-note/table.js` ใช้รายการนี้ทำเซลล์ (`CN_CELLS` ตาม key) การเรียงตาม `type` (`number`, `string`, `date`, `currency`) การค้นหา และ Excel
- `credit-note/table.js` มีแต่ฟังก์ชันและสถานะของตาราง (`creditNoteState`: แถวทั้งหมด แถวที่แสดง คำค้น การเรียง) ไม่แตะ DOM ตอนโหลด `tests/credit_note_table.js` จึงรันได้ใน Node วาดแถวแบบเป็นชุดเหมือน `pdf-table.js` (80 แถวแรก แล้วทีละ 220 แถวต่อเฟรม) ระหว่างดึงข้อมูลต่อแถวใหม่ท้ายตาราง แล้วโหลดใหม่จาก `job_cn_docs` ครั้งเดียวตอนจบ เพราะการแทนที่ไฟล์เดิมลบแถวเก่าที่ฝั่งเซิร์ฟเวอร์
- `credit-note/extraction.js` เก็บขั้นของการดึงข้อมูลใน `creditNoteRun.phase` (`idle`, `scanning`, `extracting`, `stopping`) และ `CN_PHASES` บอกว่าแต่ละขั้นแสดงปุ่มเริ่มและปุ่มหยุดอย่างไร ปุ่มหยุดเปลี่ยนเป็น `stopping` แล้วลูปหยุดก่อนชุดถัดไป ชุดละ 10 ไฟล์ (`CN_BATCH_SIZE`) ลูปเดียวกัน (`creditNoteExtractFiles`) ใช้กับปุ่ม "ดึงข้อมูลใหม่จาก finished" (`creditNoteReread`) ซึ่งส่งชื่อไฟล์ใน `CREDIT NOTE/finished` ของใบที่ `has_items` เป็น false ให้ `job_cn_extract_batch` แบบแทนที่ จบแล้วโหลดตารางและแท็บเทียบใหม่
- แท็บของ `credit_note.php` อยู่ใน `CN_TABS` (`credit-note/page.js`) แต่ละแท็บมี `leave` และ `enter` แท็บที่ออกไปเก็บตารางไว้ (`creditNoteParkTable` ลบแถวใน DOM) แล้ววาดใหม่ตอนกลับมา แท็บที่เปิดอยู่จำต่อ Job ใน `localStorage` คีย์ `credit_note_tab_job_<id>` และ `?tab=asn|dashboard` เปิดแท็บนั้นตรง ๆ แบบ `workspace.php`
- `CN_COMPARE_COLUMNS` (แท็บ 2) และ `CN_DASH_COLUMNS` (แท็บ 3) ใน `credit_note.php` เป็นรายการคอลัมน์แบบ `CREDIT_NOTE_COLUMNS` PHP เขียนหัวตารางด้วย `renderCreditNoteHeader` ชุดเดียว ส่วน JavaScript ทำเซลล์จาก `CN_COMPARE_CELLS` (`credit-note/compare-table.js`) และ `CN_DASH_CELLS` (`credit-note/dashboard.js`) ตาม key ทุกเซลล์มี `value(row)` ที่ใช้เรียง (ค่าว่างอยู่ท้ายทั้งสองทิศ, `creditNoteOrderBy` ใน `table.js` ใช้ร่วมทั้งสามตาราง) ค้นหา และ Excel ยกเว้นเซลล์ที่บอกเอง (VAT แยกเป็นสองคอลัมน์ใน Excel, Amount ต่อด้วย `Amount Basis`)
- ยอดเงินของแท็บ 2 และ 3 มีสองฐานตาม `amount_kind` ของแถว ASN (`CN_AMOUNT_BASIS`): `cost` (ชีตที่มี Total Cost Ex VAT) เทียบ `doc_excl_vat` ของ PDF และมี VAT ทั้งสองฝั่ง `retail` (ชีตที่ไม่มี เช่น ZG2T) เทียบ `doc_total_amount` และช่อง VAT ของ ASN ว่างพร้อม tooltip บอกเหตุผล สถานะ สีและหมายเหตุอยู่ใน `CN_STATUS_DISPLAY` และ `CN_REMARKS` (ผิดพลาด `ปัญหา : …` สีแดง, สมบูรณ์ `ข้อมูลถูกต้อง`, รอเทียบมีวิธีทำ ยกเว้นในตารางภาพรวม) สีมาจาก `--status-*-color` เดิมของ `app.css` ประโยคว่าเงินต้องตรงกันแค่ไหนไม่เขียนตายในหน้า `credit_note.php` ส่ง `CN_TOLERANCES` จาก `CreditNoteReconciler::AMOUNT_TOLERANCE` และ `VAT_TOLERANCE` (ตอนนี้ 0.00 ทั้งคู่) แล้ว `creditNoteMoneyRule` เขียน "ต้องตรงกันทุกสตางค์" หรือ "ต่างกันได้ไม่เกิน x บาท" ลงใน tooltip ของหัวคอลัมน์ยอดเงินและ VAT และหน้าต่างยืนยันการเทียบ (ทุกที่ที่มี `data-cn-rule`) ถ้าแก้ค่าใน `CreditNoteReconciler` ข้อความเปลี่ยนตามเอง
- `credit-note/asn-sheets.js` อ่านไฟล์ ASN ในเบราว์เซอร์: `creditNoteDetectSheetColumns` คัดลอกจาก `detectSheetColumns` ของ `workspace/asn-import.js` (หัวคอลัมน์เดิมได้ key เดิม เพิ่ม `invoice_date` จาก Invoice Date หรือ CN Date) ไม่ใช้ร่วมเพื่อไม่ต้องแก้โค้ด Tax Invoice `creditNoteWorkbookSheets` คือรายการในตัวเลือกชีต (ชีตที่มองเห็น มีคอลัมน์เลขใบลดหนี้และบาร์โค้ด ชีตแรกเป็นค่าเริ่มต้น ชีตที่ชื่อมี ZCR หรือ ZKR มี `hint`) แถวหนึ่งของ ASN เป็น record ที่ `job_cn_import_asn` รับ พร้อม `amount_kind` และแหล่งเป็น `<ไฟล์> / <ชีต>` (`creditNoteAsnSource`)
- `credit-note/compare.js` คือแท็บ 2 (`creditNoteCompare`: แถว แถวที่แสดง ตัวกรองสถานะ คำค้น การเรียง จำนวนชีตและแถว ASN) คำตอบของ `job_cn_reconcile`, `job_cn_import_asn` และ `job_cn_delete_asn` ผ่าน `creditNoteCompareAccept` ที่เดียว: ผลเทียบ (`data` หรือ `reconcile`) หรือแถว ASN ที่รอเทียบ (`raw_records`, `creditNotePendingRows` คิดยอดเงินของใบตามฐาน) ขั้นของแถบเครื่องมือ (`select`, `compare`, `done`) อยู่ใน `data-step` ของ `#credit-note-asn-section` และ `app.css` เน้นปุ่มของขั้นนั้น ไม่มีขั้นแก้ไขและสั่งพิมพ์ หลังนำเข้าชีตหนึ่ง ตัวเลือก "ชีตอื่นของไฟล์นี้" เปิดหน้าต่างยืนยันเดิมกับชีตอื่นของ workbook ที่อยู่ในหน่วยความจำ ป้าย ASN (`creditNoteAsnLabel`) เขียนชื่อเต็มเมื่อมีชีตเดียว และ `N ชีต` เมื่อมีหลายชีต ชื่อเต็มที่ `asn_filename` ส่งมาอยู่ใน tooltip
- ทุกชื่อ global ของหน้า `credit_note.php` ขึ้นต้นด้วย `creditNote` หรือ `CN_` (ยกเว้น `CURRENT_JOB_ID` ที่ `ui-utils.js` และ `column-highlight.js` อ่าน) และ id ขึ้นต้นด้วย `credit-note-` หรือ `creditNote` จึงไม่ชนกับ `cn-error.js` และ `read_cn_error01.php` และไม่ชนกับ `workspace.php` ยกเว้น id ที่ `ui-utils.js` หาเอง (หน้าต่างกำลังโหลด `app-loading-overlay`, `loading-overlay-*`) (check `portal_test.php` "the credit note page and the legacy page read_cn_error01.php share no element id", "no script of the credit note page declares a global name a legacy page script declares…", "the credit note page and workspace.php share only the ids the scripts both load look up…")
- `column-highlight.js` ไม่เรียก API สีที่ผู้ใช้เลือกให้คอลัมน์ (คลิกขวาที่หัวตาราง `#detailed-table`, `#asn-table`, `#dash-table` ใน `workspace.php` และ `#credit-note-table`, `#credit-note-asn-table`, `#credit-note-dash-table` ใน `credit_note.php`; สองหน้าใช้คีย์เดียวกัน สีของตารางที่ไม่อยู่ในหน้านั้นจึงคงอยู่) เก็บใน `localStorage` คีย์ `column_highlight_job_<id>` เป็น `{id ตาราง: {data-col: #rrggbb}}` และเขียนเป็น `<style id="column-highlight-style">` ชุดเดียวที่ระบายตามลำดับคอลัมน์ (`nth-child`) จึงต้องให้ทุก `th` มี `data-col` ไม่ซ้ำกัน และห้ามเพิ่มหรือสลับคอลัมน์โดยไม่แก้หัวตารางพร้อมกัน (`ASN_COLUMNS` ทำให้ตาราง ASN ตรงกันอยู่แล้ว) สีของหัวคอลัมน์ต้องใช้ `!important` เพราะกฎ `.table > :not(caption) > * > *` ของ Bootstrap มีน้ำหนักมากกว่า `table thead th` ใน `app.css` หัวตารางจึงเป็นสีขาว padding 8px และเส้นล่าง 1px จริง ไม่ใช่ `#f1f5f9`, `8px 12px`, 2px ตามที่ `app.css` เขียน (วัดจาก computed style ในเบราว์เซอร์ ค่าสามอย่างนี้ถูก Bootstrap ทับ)
- ไลบรารี Excel โหลดแบบ `async` ฟังก์ชัน Excel รอผ่าน `ensureXlsxLoaded` ใน `ui-utils.js` ซึ่งหา tag ด้วย `script[src*="xlsx.full.min.js"]`
- ทุก asset ในเครื่องมี `?v=<filemtime>` ต่อท้ายเพื่อให้เบราว์เซอร์โหลดใหม่เมื่อไฟล์เปลี่ยน ยกเว้น `app.css`, `ui-utils.js`, `cn-error.js` ในหน้า `read_cn_error01.php`

## โฟลเดอร์ของ Job

```
myfile/{ชื่อ Job}/
  TAX INVOICE/
    upload here/            PDF ที่รอดึงข้อมูล (มีโฟลเดอร์ย่อยได้)
    finished/               PDF ต้นฉบับหลังดึงข้อมูล และ {เลขบิล}.pdf ที่ปุ่มแก้ไขสร้าง
    print/
      print ครั้งที่ N/
        {Dest Store No.}/   1_{เลขบิล}.pdf, 2_{เลขบิล}.pdf, ...
  CREDIT NOTE/
    upload here/            PDF ใบลดหนี้ที่รอดึงข้อมูล (มีโฟลเดอร์ย่อยได้)
    finished/               PDF ใบลดหนี้หลังดึงข้อมูล
```

- `JobFolders` เป็น class เดียวที่รู้ชื่อเหล่านี้ (`TAX_INVOICE`, `CREDIT_NOTE`, `PRINT_ROUND_PREFIX` และ `LEGACY_PLACES`) ที่อื่นขอที่อยู่ผ่าน getter: `getJobTaxInvoiceDir`, `getJobUploadDir`, `getJobFinishedDir`, `getJobPrintDir`, `getJobPrintRoundDir`, `getJobCreditNoteDir`, `getJobCnUploadDir`, `getJobCnFinishedDir` (ทุกตัวมีชื่อเดียวกันใน `JobManager`) ข้อความโฟลเดอร์ที่หน้าเว็บแสดง (`upload_dir`, `finished_dir` ของการสแกน, `folder` ของการสั่งพิมพ์, แถบล่าง) มาจาก `JobFolders::displayPath` ของที่อยู่ที่ getter ตอบ จึงตรงกับที่อยู่จริงเสมอ
- `createJob` สร้างทั้งห้าโฟลเดอร์ ถ้าโฟลเดอร์ใดหายไป getter ที่ `$autoCreate` เป็นจริงสร้างใหม่ การสแกนของฝั่ง Tax Invoice อ่านเฉพาะ `TAX INVOICE/upload here/` จึงไม่เห็น PDF ใบลดหนี้ และฝั่งใบลดหนี้รับเฉพาะไฟล์ใน `CREDIT NOTE/`
- การล้างข้อมูล Tax Invoice (`JobFolders::removeProcessedJobFolder`) เก็บโฟลเดอร์ Job ไว้เมื่อ `TAX INVOICE/upload here/`, `TAX INVOICE/print/` หรือ `CREDIT NOTE/` มีไฟล์ แล้วลบเฉพาะ `TAX INVOICE/finished/` ไม่แตะ `CREDIT NOTE/` การลบ Job ลบทั้งโฟลเดอร์ Job

### Job ที่สร้างก่อนแยกโฟลเดอร์ (ก่อน 2026-10-28)

Job เหล่านี้มี `upload here/`, `finished/`, `print/` และ `credit note/` (ตัวเล็ก) อยู่ในโฟลเดอร์ Job โดยตรง `JobFolders::migrate` ย้ายให้ครั้งแรกที่คำขอหนึ่งขอที่อยู่ของ Job นั้นผ่าน getter (คำขอละครั้งต่อ Job ด้วย `JobFolders::$migrated`) `getJobFolderDir` และ `jobFolderExists` ไม่ย้าย

- ย้ายด้วย `rename` ไม่คัดลอก: `upload here`, `finished`, `print` ไปที่ `TAX INVOICE/` และ `credit note` เปลี่ยนชื่อเป็น `CREDIT NOTE` (บน NTFS การเปลี่ยนเฉพาะตัวพิมพ์ทำด้วย `rename` ครั้งเดียวได้ วัดบน PHP 8.2 และ 7.3.33 เครื่องนี้)
- ทุกขั้นอ่านสภาพบนดิสก์ใหม่ทุกครั้ง จึงทำซ้ำได้และสองคำขอทำพร้อมกันได้ โฟลเดอร์ที่คำขออื่นย้ายไปแล้วถูกข้าม ไม่ถือว่าผิดพลาด
- ถ้าที่ปลายทางมีโฟลเดอร์อยู่แล้ว ย้ายทีละรายการเข้าไป รายการที่ชื่อซ้ำกับของที่ปลายทาง (ไฟล์) อยู่ที่เดิม ไม่มีการเขียนทับหรือลบไฟล์ โฟลเดอร์เก่าถูกลบด้วย `rmdir` ซึ่งลบได้เฉพาะโฟลเดอร์ที่ว่างจริง
- ถ้า `rename` ไม่สำเร็จ (Windows ปฏิเสธเมื่อมีไฟล์ในโฟลเดอร์เปิดอยู่ วัดแล้วได้ "Access is denied" code 5) ไม่โยนข้อผิดพลาด โฟลเดอร์นั้นอยู่ที่เดิม `JobFolders::currentPlace` ตอบที่เดิมแทนที่ใหม่ตราบที่ที่เดิมยังอยู่และที่ใหม่ยังไม่มี (ดูชื่อจริงบนดิสก์ด้วย `scandir` เพราะ Windows ถือว่า `credit note` กับ `CREDIT NOTE` เป็นชื่อเดียวกัน) คำขอถัดไปลองย้ายอีก
- `job_files.file_path` และ `job_cn_files.file_path` ไม่ถูกแก้ตอนย้าย และไม่มีโค้ดใดอ่าน: `PdfFixer::fixJob` อ่านไฟล์ต้นฉบับจาก `getJobFinishedDir` + `filename` และ `PdfItemStore::deleteJobItems` ลบไฟล์ที่ไม่เหลือแถวที่ `getJobFinishedDir` + `filename` (ดู DECISIONS.md 2026-10-28)
- ชื่อโฟลเดอร์มาจากชื่อ Job โดยแทนอักขระที่ Windows ไม่รับด้วย `_` (`JobFolders::sanitizeFolderName`) สร้าง Job ที่ได้ชื่อโฟลเดอร์ซ้ำกับ Job ที่โฟลเดอร์ยังอยู่ไม่ได้ ถ้าโฟลเดอร์ของ Job เดิมหายไปแล้ว Job เดิมถูกลบและ Job ใหม่เริ่มว่าง (`JobManager::createJob`)
- Job มีอยู่สำหรับผู้ใช้เมื่อโฟลเดอร์ยังอยู่ (`JobFolders::jobFolderExists`)
- การดึงข้อมูลย้ายไฟล์ (ไม่ใช่คัดลอก) จาก `upload here` ไป `finished` และรับเฉพาะไฟล์ที่อยู่ใน `upload here` หรือ `finished` ของ Job นั้น (`FileManager::isSubpath`) ใบลดหนี้ทำแบบเดียวกันกับ `CREDIT NOTE/upload here` และ `CREDIT NOTE/finished` (`CreditNoteStore::extractBatch`)

ทดสอบ: `job_safety_test.php` "extraction moves the PDF from upload here to finished", "a path outside the job folders is not processed or moved", "a second job with the same folder name is rejected", "while the old finished folder of a Job made before the split cannot be moved…", "after the move the fix reads the master PDF from TAX INVOICE/finished…", "deleting every row of one file removes its PDF from TAX INVOICE/finished…", `job_folders_test.php` ทุก check, `credit_note_test.php` "a path outside the credit note folders, or a file in the Tax Invoice finished folder, is not read or moved", "clearing the Tax Invoice data (clear_job) keeps the credit note rows, the credit note PDFs and the Job folder", "credit notes waiting in the old "credit note" folder of a Job made before the split…"

## กลยุทธ์การทดสอบ

- ทุกชุดตั้ง `PDF_READER_ROOT` เป็นโฟลเดอร์ชั่วคราว จึงไม่แตะ `data/` และ `myfile/` จริง
- `job_safety_test.php` สร้างข้อมูลเล็ก ๆ ในโค้ดเพื่อทดสอบกติกาทีละข้อ ใช้ PDF ตัวอย่างสามไฟล์ เรียก `api.php` และ `workspace.php` ผ่าน PHP CLI (`--api`, `--page`, `--index`) และเรียก `excel_columns.js` ด้วย Node เพื่อรันฟังก์ชัน Excel จริงของหน้าเว็บ และ `vbrp_view.js` ซึ่งรันสคริปต์ของแท็บ 2 และ 3 บนแถวสามแหล่งแล้วส่ง 17 check กลับมาให้ `job_safety_test.php` รายงาน แถวที่ไม่มี `sources` ถูกเทียบกับ `tests/fixtures/workspace_rows_before_vbrp.json` ซึ่งสคริปต์รุ่นก่อนมี VBRP เขียนไว้ (ทุกเซลล์ ค่าที่ใช้เรียง ข้อความค้นหา แถว Excel และแถวของแท็บ 3; ตั้งแต่ 2026-10-28 ต่างจากเดิมแค่ป้าย `รอแก้ไข` เป็น `รอแก้ไข PDF` สี่เซลล์) และ `status_split_view.js` ซึ่งรันสคริปต์เดียวกันบนแถวรอแก้ไข PDF / รอแก้ไข ASN และ `was` / `history` แล้วส่ง 26 check (ทะเบียนสถานะ ปุ่มกรองทั้งสองแท็บ ตัวนับ หมายเหตุ Manage บรรทัด `เดิม:` การ์ด แถบรวม คอลัมน์ Previous status และ tooltip Excel การสั่งพิมพ์ ปุ่มแก้ไขที่เซิร์ฟเวอร์ปฏิเสธ และ `event=compare`)
- `verify_docs.py` อ่าน PDF ทั้ง 290 ไฟล์และไฟล์ ASN ด้วยตัวอ่านอิสระ (`pypdf`, `openpyxl`, `pdftotext`) แล้วเทียบกับผลของโปรแกรมที่ได้จาก `verify_docs_run.php`
- `verify_incremental.py` เปิด `php -S` บนสำเนาของโปรเจกต์ แล้วทำงานแบบผู้ใช้ผ่าน HTTP เป็นขั้น ๆ ผลสุดท้ายต้องเท่ากับการรันครั้งเดียวกับทุกไฟล์
- `credit_note_test.php` ใช้ใบลดหนี้จริงหกไฟล์จาก `tests/fixtures/cn/` (Standard CN สองร้าน, Attachment Sheet, Variance / Refund สองหน้า, ไฟล์ที่มีใบ -1 และ -2, ใบที่คืนเงิน 0.00) กับ PDF ที่ไม่ใช่ใบลดหนี้ที่สร้างด้วย FPDF และไฟล์ที่ไม่ใช่ PDF เรียกทุก action `job_cn_*` ผ่าน PHP CLI แบบเดียวกับ `job_safety_test.php` ค่าที่คาดไว้อ่านจาก PDF และเทียบกับ `pdftotext -layout` แล้ว
- `credit_note_reader_test.php` อ่านใบลดหนี้ทั้ง 545 ไฟล์ด้วย `CreditNoteReader` ทุกไฟล์ต้องได้ค่าเดิมของ `extractCnDocuments` และทุกใบที่ `read_ok` ต้องผ่านเลขคณิตที่ชุดทดสอบคิดเอง (ไฟล์ทั้งชุดคือการทดสอบ) แล้วเทียบบรรทัดสินค้าและยอดรวมของ 12 ไฟล์กับค่าที่อ่านจาก `pdftotext -layout` ไม่เขียนไฟล์ใด จึงไม่ต้องตั้ง `PDF_READER_ROOT`
- `portal_test.php` เรียก `job.php`, `credit_note.php`, `workspace.php` ผ่าน PHP CLI (`--page`) และดึงใบลดหนี้จริงสองไฟล์ผ่าน `api.php` (`--api`) แล้วรันสคริปต์จริงของหน้าใน Node: `index_navigation.js` (`index.js` ไปที่ไหน และบรรทัดของหน้าต่างลบ Job จากคำตอบจริงของ `job_delete_summary`), `credit_note_table.js` (`credit-note/table.js`: เซลล์ การเรียง การค้นหา และไฟล์ Excel ที่เขียนด้วย SheetJS แล้วอ่านกลับ), `credit_note_compare.js` (`credit-note/asn-sheets.js`, `compare-table.js`, `dashboard.js`: อ่านไฟล์ ASN ตัวอย่างจริงด้วย SheetJS แล้วดูตัวเลือกชีต การหาคอลัมน์เทียบกับ `detectSheetColumns` ของ Tax Invoice ทั้ง 19 ชีต record ของสี่ชีตใบลดหนี้ (จำนวนแถวและใบนับด้วย openpyxl แยกต่างหาก) เซลล์ของรายการเทียบทั้งสองฐานยอดเงิน การเรียง การค้นหา ตัวกรอง ตารางภาพรวม และไฟล์ Excel ทั้งสองที่อ่านกลับ), `column_highlight.js` (ส่งทั้งสองหน้า) ไม่ใช้ PDF ใบกำกับภาษีตัวอย่าง จึงรันในโฟลเดอร์โปรเจกต์จริงได้
- `job_folders_test.php` สร้างโฟลเดอร์ Job แบบเก่าด้วยไฟล์สุ่มแล้วเทียบ sha256 ของทุกไฟล์ก่อนและหลังย้าย ทุก "คำขอ" เป็น PHP process ใหม่ (`--paths`, `--api`) เพราะการย้ายเกิดคำขอละครั้ง แปดคำขอพร้อมกันเริ่มที่เวลาเดียวกัน และการย้ายไม่ได้ทำโดยเปิดไฟล์ในโฟลเดอร์ค้างไว้ด้วย `fopen` (เฉพาะ Windows) `open_folder` ทดสอบด้วย `-d disable_functions=proc_open` จึงไม่เปิด Explorer
- `open_folder_test.php` เปิดและปิดหน้าต่าง Explorer จริง
- `credit_note_compare_test.php` สร้างใบลดหนี้ตัวอย่างเล็ก ๆ ด้วย SQL ลงตารางของ `CreditNoteStore` (ทุกเส้นทางของสถานะ ทั้งแบบ cost และ retail) นำเข้าแถว ASN และเทียบผ่าน `api.php` แบบเดียวกับ `credit_note_test.php` แล้วตรวจข้อความทุกตัวอักษร ส่วนการอ่านใช้ใบลดหนี้จริงสามไฟล์ และการอัปเกรด schema 8 เป็น 9 ใช้ฐานข้อมูลแยกไฟล์หนึ่ง
- `verify_cn.py` เขียนจากข้อตกลงของการเทียบเท่านั้น ไม่อ่านโค้ด PHP: อ่าน PDF ใบลดหนี้ 545 ไฟล์ด้วย `pdftotext` และสี่ชีตของไฟล์ ASN ด้วย `openpyxl` แบบที่ `detectSheetColumns` ใน `asn-import.js` อ่าน ดึงข้อมูลและนำเข้าผ่าน `php -S` บนสำเนาของโปรเจกต์แบบ `verify_incremental.py` แล้วเทียบทุกใบและทุกแถวกับคำตัดสินของตัวเอง และลองแก้แถว ASN แปดแบบ (`seeded change`) ซึ่งแต่ละแบบต้องเปลี่ยนเฉพาะใบที่แก้
- `run_all.php` รันทุกชุดและสรุป ดูวิธีใช้ใน [README.md](../README.md)

## จุดที่ควรรู้

- `JobManager` เคยยาว 1,438 บรรทัด ตั้งแต่ 2026-10-27 แยกเป็น `JobFolders`, `JobRepository`, `PdfItemStore`, `AsnStore`, `DashboardBuilder` แล้ว `JobManager` เหลือ 318 บรรทัดที่ส่งต่องาน method สาธารณะทุกตัวยังชื่อเดิมและทำงานเหมือนเดิม จึงไม่มีไฟล์ที่เรียกใช้ต้องแก้ เมื่อเพิ่มใบลดหนี้ (method ส่งต่อไป `CreditNoteStore` และโฟลเดอร์ `credit note`) ยาว 340 บรรทัด และหลังแยกโฟลเดอร์ TAX INVOICE / CREDIT NOTE (getter ส่งต่ออีกสามตัว) ยาว 364 บรรทัด การเทียบใบลดหนี้ (2026-10-28) ไม่เพิ่ม method ส่งต่อทีละ action แต่ส่ง `CreditNoteCompare` ทั้งก้อน (`creditNoteCompare()`) `JobManager` จึงยาว 383 บรรทัด
- `PdfItemStore` ยาว 485 บรรทัด ยาวที่สุดในห้า class ใหม่ ส่วนใหญ่เป็น `recordExtractedFile` และ `scanSourceFiles`
- `AsnReconciler::reconcile` เป็น method เดียวยาว 426 บรรทัด
- ยังมี SQL นอก class เจ้าของตาราง ไม่ได้ย้ายในรอบนี้:
  - `AsnReconciler::reconcile` อ่าน `job_asn_records` และเขียนตัวเลขสรุปลง `jobs` (สองคำสั่ง)
  - `PdfFixer::fixJob` อ่าน `jobs`, `job_asn_records`, `job_items`, `job_files` และเขียน `job_items` (แก้เลขบิล ลบยอดรวมท้ายบิล) `PdfFixer::fetchAllItems` อ่าน `job_items` ด้วยคำสั่งเดียวกับ `PdfItemStore::getJobItems` และ `PdfFixer::recordFixedInvoices` เขียน `job_invoice_fixes`
  - `FixLog` อ่านและเขียน `job_invoice_fix_steps` และอ่าน `job_invoice_fixes` (`FixLog::splitDone`)
  - `PrintManager::buildInvoicePdf` อ่าน `job_items` ผ่าน `Database::getConnection` ของตัวเอง
  - `Database::migrateSchema` อัปเดต `job_asn_records` สองคำสั่งตอนอัปเกรด schema (เติม `source_file` จาก `jobs`, เติม `total_vat` และ `delivery_no` จาก `raw_json`)
- คำสั่งของ `JobRepository::listJobs` และ `JobRepository::updateJobStats` นับแถวใน `job_files`, `job_items` (และ `job_asn_records` สำหรับ `listJobs`) ด้วย subquery ในคำสั่งเดียวกับ `jobs` จึงยังอ่านตารางของ `PdfItemStore` และ `AsnStore`
- `api.php` เป็น `switch` เดียว 35 กรณี บางกรณีมีงานในตัว เช่น `extract_job_batch` ย้ายไฟล์และจัดการบิลซ้ำเอง ส่วน `job_cn_extract_batch` ทำงานเดียวกันของใบลดหนี้ใน `CreditNoteStore::extractBatch` และ `api.php` แค่อ่านรายการไฟล์
- `clear_job` (`JobManager::clearJobData`) ล้างเฉพาะข้อมูล Tax Invoice ไม่แตะแถวและไฟล์ใบลดหนี้ เพราะระบบใบลดหนี้มีปุ่มล้างของตัวเอง (`job_cn_clear`) การลบ Job ลบแถวใบลดหนี้ด้วย `ON DELETE CASCADE`
- ช่องเดิมของตารางใบลดหนี้ (`doc_no` ถึง `amount`) เก็บค่าตามที่ `PdfExtractor::extractCnDocuments` อ่านได้ (`CreditNoteReader` ให้ค่าเดียวกันทุกช่อง) หน้าเก่า (`extract_cn_batch`) ได้ค่าเดียวกันเป็นข้อความ (`"1,261.44"`, `"-"`) ส่วน `job_cn_docs` ได้ตัวเลขหรือ `null` วันที่มีสองรูปแบบตามที่พิมพ์ (`27.04.2026` และ `06-04-2026`) `doc_no` ของใบที่ไม่มี -N (เช่น `93240256`) เป็นตัวเลขใน JSON ของหน้าเก่า และเป็นข้อความใน `job_cn_docs`
- การสแกนใบลดหนี้ (`CreditNoteStore::scan`) อ่าน PDF ใหม่ทุกไฟล์ทั้งไฟล์เพื่อหาเลขใบลดหนี้ ไม่เก็บผลไว้ใช้ตอนดึงข้อมูล เพราะชื่อไฟล์และหน้าแรกบอกเลขไม่ครบ (ไฟล์ `RM Document-...` ไม่มี -N ในชื่อ และบางไฟล์มีสามใบ) วัดบน 545 ไฟล์: สแกน 6 วินาที ดึงข้อมูลทีละ 50 ไฟล์รวม 13.5 วินาที
- `CreditNoteReader` อ่านบรรทัดสินค้าและยอดรวมจากตำแหน่งของข้อความบนหน้า (`Page::getDataTm`) ไม่ใช่จากลำดับของ `getText` เพราะใบ Standard CN พิมพ์ตัวเลขยอดรวมก่อนชื่อช่องและคนละลำดับ ข้อความที่อยู่แถวเดียวกัน (ห่างกันไม่เกิน 2.5 pt) เป็นหนึ่งแถว ยอดรวมแต่ละช่องคือข้อความถัดจากชื่อช่องในแถวนั้น บรรทัดสินค้าเริ่มที่บาร์โค้ด 13 หลักใต้หัวตาราง `PRODUCT/SIZE` หรือ `PRODUCT/SZ` สามข้อความท้ายแถวคือ units, unit price, amount ข้อความใต้บรรทัดในคอลัมน์ description (หน้าถัดไปด้วย) ต่อเป็นคำอธิบาย ใบ Variance / Refund บางใบวาดยอดรวมซ้ำสองครั้งที่ตำแหน่งเดิม จึงตัดข้อความที่ซ้ำทั้งตำแหน่งและข้อความ
- `read_ok` ของ `CreditNoteReader` เป็นจริงเมื่อมีบรรทัดสินค้า ทุกช่องยอดรวมอ่านได้ (Promotion Discount ไม่พิมพ์ถือเป็น 0) และทุกข้อนี้ตรง: units × unit price = amount ทุกบรรทัด, ผลรวม amount − Promotion Discount = Total Amount, ผลรวม units = TOTAL SHIPPED, Total Amt Excluding VAT + VAT = Total Amt Include VAT ต่างได้ไม่เกิน 0.03 (ใบ 93251025-2 พิมพ์ขาด 0.03), Total Amount − Total Discount = Total Amt After Discount, After Discount = Include VAT ไม่เป็นจริง `read_note` บอกเหตุผลเป็นภาษาอังกฤษ Attachment Sheet ไม่มีบาร์โค้ดและยอดรวมด้านขวา จึงไม่ `read_ok` ข้อที่ `read_ok` จับไม่ได้: Excluding VAT กับ VAT สลับกัน (ผลบวกเท่าเดิม และ VAT ที่พิมพ์ไม่ใช่ 7% พอดี ต่างได้ถึง 0.44 บาท) ชุดทดสอบจับข้อนี้จากค่าที่อ่านจากหน้า
- `store_no` ของ `CreditNoteReader` คือเลขท้ายบรรทัดร้าน (`5002-CENTRAL PHUKET 10121` ได้ `10121`) ตัด 0 นำหน้า (`020123` ได้ `20123`) ตรงกับ `Dest Store No.` และ `Store No` ของไฟล์ ASN ร้าน 5152 NAKORN SAWAN ไม่พิมพ์เลขนี้ในบรรทัดร้าน จึงเป็น `null`
- เวลาอ่าน 545 ไฟล์ (2026-10-28, ค่ากลางของ 5 รอบสลับกัน PHP 8.2 / 7.3): `CreditNoteReader::read` 6.6 / 6.6 วินาที, `extractCnDocuments` 3.5 / 3.5 วินาที (ก่อนแยก `cnDocumentsOfPages` 5.0 / 4.8 วินาที เพราะเรียก `getText` สองรอบ) เวลาของ reader ส่วนใหญ่เป็นของ smalot (รอบเดียว: parse 2.7, `getText` 1.6, `getDataTm` 2.6 โค้ดของ reader เอง 0.6 วินาที) ช่องเดิมต้องได้ค่าตรงไบต์จึงยังต้องเรียก `getText`
- JavaScript เป็น script แบบเดิมที่ใช้ตัวแปร global ร่วมกัน ไม่มี module หรือ bundler ชื่อเดียวกัน (`displayData`, `isRunning`, `currentSort`) ประกาศทั้งใน `state.js` และ `cn-error.js` ซึ่งอยู่คนละหน้า
- `workspace.php`, `job.php` และ `credit_note.php` ตรวจ `job_id` และโฟลเดอร์ของ Job ด้วยโค้ดชุดเดียวกันสามที่ (ไม่มี id ไป `index.php`, ไม่พบพิมพ์ "not found", โฟลเดอร์หายไป `index.php`) ถ้าจะเปลี่ยนต้องแก้พร้อมกัน `portal_test.php` เทียบคำตอบของสามหน้านี้ว่าตรงกัน
- ปุ่มแก้ไขเขียนทับค่าเดิมในแถว PDF ไม่มีปุ่มย้อน การเริ่มใหม่ต้องดึงข้อมูล PDF ใหม่ และการล้างแถว PDF ทั้งหมดลบไฟล์ต้นฉบับใน `finished/` (ลำดับที่ถูกอยู่ใน BUSINESS_RULES.md หัวข้อ "เริ่มใหม่ตั้งแต่ต้น")
- การแปลงเลขบิลเป็น key มีหลายที่: `DashboardBuilder::invoiceKey` (`CreditNoteReconciler::key` ใช้ตัวนี้กับเลขใบลดหนี้), `PdfItemStore::invoiceRoot` (สองตัวนี้เรียกผ่าน `JobManager::invoiceKey` และ `JobManager::invoiceRoot` ได้ด้วย), `FixPlan::root`, closure `getNormInv` และ `getRootInv` ใน `AsnReconciler::reconcile` (`invoiceRoot` เก็บแค่ตัวเลข ส่วน `FixPlan::root` เก็บตัวอักษรและตัวเลข)
- ชิ้นส่วนจากรุ่นก่อน: `JobFolders::deleteJobFolders` ลบ `jobs/job_{id}` ถ้ามี, action `clear_job` ไม่มีหน้าใดเรียก
- หน้า `vbrp.php` (`assets/js/vbrp.js`, action `job_vbrp_upload`, `job_vbrp_scan`, `job_vbrp_extract_batch`, `job_vbrp_rows` และโฟลเดอร์ `VBRP/upload here`, `VBRP/finished`) ไม่ได้ใช้แล้วตั้งแต่ 2026-10-28 แต่ยังทำงานอยู่ (check ของ `vbrp_test.php`) การนำเข้า VBRP ทำที่แท็บ 2 ของ `workspace.php` ด้วย `job_vbrp_import` ซึ่งไม่สร้างโฟลเดอร์ VBRP
- หน้าใบลดหนี้ (legacy credit-note page: `read_cn_error01.php`, `cn-error.js`, action `list_files` และ `extract_cn_batch`, ตัวนับ `invoice_count` และ `cn_count` ของ `get_stats`, `Config::getInvoicePath`, `Config::getCnPath`) สถานะยังไม่ได้ตัดสินใจ
- กติการาคาขายติดลบและสาขาไม่ตรงยังไม่มี check เฉพาะในชุดทดสอบ

ข้อสังเกตจากการตรวจโค้ดรอบ 2026-10-27 (ยังไม่ได้แก้ ต้องให้ผู้ใช้ตัดสินใจเรื่องไหนทำก่อน):

- ขั้นของแถบเครื่องมือหน้า ASN คำนวณสองที่: `JobRepository::initialAsnStep` ทำตอนเรนเดอร์หน้า จากค่าที่เก็บใน `jobs` และ `updateAsnToolbarStep()` ใน `asn-table.js` ทำจากแถวที่โหลดมา สองตัวนี้ได้คำตอบต่างกันได้เมื่อมีบิลพร้อมสั่งพิมพ์ใหม่หลังสั่งพิมพ์ไปแล้ว (หน้าจอเดาเป็น "เสร็จแล้ว" แล้ว JS แก้เป็น "สั่งพิมพ์") แนวทาง: คำนวณที่เดียวใน PHP แล้วส่งไปกับหน้าและกับทุกคำตอบ API
- `JobManager::$lastAsnImport` และ `AsnStore::$lastAsnImport` ส่งจำนวนที่นำเข้า (`imported`, `skipped`, ...) เป็นสถานะสาธารณะ ด้านหน้าคัดลอกใน `finally` แนวทาง: ให้ `saveAsnRecords` คืนค่าจำนวนเหล่านี้แล้ว `api.php` อ่านจากค่าที่คืน
- `PdfFixer::fixJob` จับ `\Exception` ตอนแยกไฟล์ (`extractInvoice`) แล้วเขียนลง `error_log` เท่านั้น ผู้ใช้ไม่เห็นว่าไฟล์แยกบางไฟล์ไม่เกิดขึ้น ทั้งที่ทุกข้อผิดพลาดที่ `extractInvoice` โยนเป็นความล้มเหลวจริง (ไม่พบไฟล์ต้นทาง หน้าเกินช่วง ผลลัพธ์ว่าง ย้ายไฟล์ไม่ได้) แนวทาง: คืน `split_failed` ใน `fixJob` แล้วแสดงในหน้า หรือให้ข้อผิดพลาดหลุดออกไป (เปลี่ยนพฤติกรรม ต้องคุยก่อน)
- คอลัมน์ที่ล็อกของหน้า ASN ผูกกันด้วยตำแหน่งข้ามไฟล์: สามรายการแรกของ `ASN_COLUMNS` (`workspace.php`) กับ `#asn-table :nth-child(-n+3)` และ `--frozen-1` ถึง `--frozen-3` ใน `app.css` แนวทาง: ใส่ความกว้างที่ล็อกไว้ใน `ASN_COLUMNS` แล้วให้ PHP ออกตัวแปร CSS
- `FileManager::explorerCommand` ใส่รหัสออกของ PowerShell (`exit 2` ถึง `exit 5`) เป็นตัวเลขซ้ำกับ key ของ `NOT_OPENED_REASONS` แนวทาง: สร้างรหัสจาก map เดียว
- `FileCombobox.commitTicks` (`clear-modal.js`) ใช้ `this.list.children[i]` ให้ตรงกับ `this.visible[i]` แนวทาง: หาแถวจาก data value
- การแปลงเลขบิลเป็น key ใช้ `strtoupper(preg_replace('/[^A-Z0-9]/', '', $s))` ทำให้ตัวพิมพ์เล็กถูกลบทิ้งแทนที่จะแปลงเป็นตัวพิมพ์ใหญ่ (`strtoupper` ไม่ได้ทำอะไร) ไม่กระทบข้อมูลตอนนี้เพราะเลขบิลทั้งหมดเป็นตัวเลข แนวทาง: `strtoupper` ก่อน `preg_replace` ในทุกที่ที่ใช้ (ต้องเปลี่ยนพร้อมกัน เพราะ key ของ PDF และ ASN ต้องตรงกัน)
- `JobFolders::deleteJobFolders` ลบโฟลเดอร์ `jobs/job_{id}` ของรุ่นเก่า ยังไม่ได้พิสูจน์ว่าเครื่องจริง (PHP 7.3.10) ไม่มีโฟลเดอร์นี้
