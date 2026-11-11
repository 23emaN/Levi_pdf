# Levi's Billing PDF กับ ASN

โปรแกรมนี้อ่านบิล PDF (Tax Invoice) ของ Levi's แล้วเทียบกับไฟล์ ASN (Excel)
ไฟล์ ASN เป็นข้อมูลหลัก ทุกบิลได้สถานะ สมบูรณ์ รอแก้ไข หรือ ผิดพลาด
ปุ่ม "แก้ไข" ปรับหน้าของบิลที่เลขบิลผิดหรือมียอดรวมซ้ำ แล้วแยก PDF เป็นไฟล์ละบิล
บิลที่ถูกต้องสั่งพิมพ์เป็นรอบ แยกโฟลเดอร์ตามสาขา
งานแต่ละชุดเรียกว่า Job มีโฟลเดอร์ของตัวเองใน `myfile/` และข้อมูลอยู่ใน SQLite ไฟล์เดียว

กติกาทั้งหมดอยู่ใน [docs/BUSINESS_RULES.md](docs/BUSINESS_RULES.md)
โครงสร้างโค้ดอยู่ใน [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
การตัดสินใจของผู้ใช้เรียงตามวันที่อยู่ใน [docs/DECISIONS.md](docs/DECISIONS.md)

## สิ่งที่ต้องมี

- PHP 7.3 ขึ้นไป เครื่องจริงของผู้ใช้เป็น PHP 7.3 จึงห้ามใช้ syntax ของ PHP 7.4 ขึ้นไป (arrow function, typed property, `??=`, `match`, `str_contains`)
- ส่วนขยายของ PHP: `pdo_sqlite` (`App\Database`), `zlib` และ `iconv` (ไลบรารี PDF ใน `composer.lock` ต้องใช้), `mbstring` (ถ้าไม่มี `symfony/polyfill-mbstring` ใน `vendor/` ทำงานแทน) และ `fileinfo` ชุดทดสอบ `job_safety_test.php`, `verify_docs.py`, `verify_incremental.py` ผ่านบน PHP 7.3.33 ที่เปิดส่วนขยายชุดนี้
- `setasign/fpdf` ระบุ `ext-gd` ไว้ใน `composer.lock` แต่โปรแกรมไม่ได้ใช้ gd ตอนทำงาน (PHP 7.3.33 ที่ทดสอบไม่มี gd) ถ้าจะรัน `composer install` ใหม่บนเครื่องที่ไม่มี gd ให้เพิ่ม `--ignore-platform-req=ext-gd`
- ไลบรารีจาก composer อยู่ใน `vendor/` แล้ว: `smalot/pdfparser` (อ่าน PDF), `setasign/fpdi` กับ `setasign/fpdf` (ตัดหน้าและเขียน PDF)
- เว็บเซิร์ฟเวอร์ที่รัน PHP ได้ เช่น Laravel Herd หรือ XAMPP
- โฟลเดอร์ `data/` และ `myfile/` โปรแกรมสร้างเองเมื่อใช้งานครั้งแรก (`Database::getConnection`, `JobManager::createJob`)

สำหรับชุดทดสอบเท่านั้น:

- Python 3 กับแพ็กเกจใน `tests/requirements.txt` (`pypdf`, `openpyxl`) ติดตั้งด้วย `pip install -r tests/requirements.txt`
- `pdftotext` ของ poppler หรือ xpdf อยู่ใน PATH
- Node.js อยู่ใน PATH (`job_safety_test.php` ใช้ตรวจไฟล์ Excel ถ้าไม่มีจะข้ามส่วนนั้นและพิมพ์ SKIP)

## วิธีเปิดโปรแกรม

ชี้เว็บเซิร์ฟเวอร์ (Herd, XAMPP หรือ AppServ) ไปที่โฟลเดอร์นี้ แล้วเปิด `index.php` ในเบราว์เซอร์

บน AppServ ทำตาม `วิธีติดตั้งบน AppServ.txt` (php.ini, `AllowOverride All` ใน httpd.conf) แล้วเปิด `check.php` เพื่อตรวจเวอร์ชัน PHP ส่วนขยาย สิทธิ์เขียนโฟลเดอร์ ค่าใน php.ini และว่า `data/app.sqlite` เปิดผ่านเว็บไม่ได้
`.htaccess` ห้ามเปิด `data/`, `myfile/`, `date_edit/`, `app/`, `vendor/`, `tests/`, `docs/` ผ่านเว็บ (ต้องมี mod_rewrite ส่วน `data/.htaccess` กันฐานข้อมูลแม้ไม่มี mod_rewrite) และตั้งขนาดอัปโหลด หน่วยความจำ และเวลาเมื่อ PHP เป็น mod_php

ถ้าจะลองบนเครื่องโดยไม่ใช้ Herd หรือ XAMPP ให้รันคำสั่งนี้ที่โฟลเดอร์โปรเจกต์ แล้วเปิด `http://127.0.0.1:8000/`

```
php -S 127.0.0.1:8000
```

หน้าเว็บมีห้าหน้า:

- `index.php` รายการ Job สร้าง Job และลบ Job
- `job.php?job_id=N` หน้าเลือกงานของ Job มีสองปุ่ม TAX INVOICE และ CREDIT NOTE พร้อมตัวเลขสรุปของแต่ละงาน
- `workspace.php?job_id=N` งาน TAX INVOICE มีสามแท็บ: "1. ดึงข้อมูลจาก PDF", "2. เปรียบเทียบกับไฟล์ ASN" และ "3. ภาพรวม" (`&tab=asn` และ `&tab=dashboard` เปิดแท็บนั้นตรง ๆ)
- `credit_note.php?job_id=N` งาน CREDIT NOTE มีสามแท็บแบบ `workspace.php`: "1. ดึงข้อมูลจากใบลดหนี้ (PDF)" (หนึ่งแถวต่อใบลดหนี้), "2. เปรียบเทียบกับไฟล์ ASN" และ "3. ภาพรวม" (`&tab=asn` และ `&tab=dashboard` เปิดแท็บนั้นตรง ๆ) ไม่มีปุ่มแก้ไขและไม่มีการสั่งพิมพ์
- `read_cn_error01.php` หน้าอ่านใบลดหนี้แบบเก่า (legacy credit-note page) สถานะยังไม่ได้ตัดสินใจ ลิงก์อยู่ที่หัวของ `index.php` และ `workspace.php` เอกสารชุดนี้ไม่ได้อธิบายหน้านี้

ทางเดินของหน้า: คลิก Job ใน `index.php` (หรือสร้าง Job ใหม่) ไปที่ `job.php` ทุกครั้ง ไม่จำว่าเคยเลือกอะไร จาก `job.php` ไป `workspace.php` หรือ `credit_note.php` ลูกศรย้อนกลับของสองหน้านี้กลับไปที่ `job.php` ของ Job เดียวกัน และลูกศรของ `job.php` กลับไปที่ `index.php`

## ลำดับการทำงานของ Job

1. สร้าง Job ที่ `index.php` โปรแกรมสร้างโฟลเดอร์ `myfile/{ชื่อ Job}/` ที่มีโฟลเดอร์ละระบบ: `TAX INVOICE/` (`upload here`, `finished`, `print`) และ `CREDIT NOTE/` (`upload here`, `finished`) (`JobManager::createJob`) ดูหัวข้อ "โฟลเดอร์ของ Job"
2. วางไฟล์ PDF ใน `myfile/{ชื่อ Job}/TAX INVOICE/upload here/` วางในโฟลเดอร์ย่อยก็ได้
3. กด "ดึงข้อมูลจาก upload here" โปรแกรมอ่าน PDF ย้ายไฟล์จาก `upload here` ไป `finished` และลบโฟลเดอร์ย่อยที่ว่าง (`api.php` action `extract_job_batch`)
4. ที่แท็บ 2 กด "เลือกไฟล์ ASN เพิ่ม (.xlsx)" แล้วกด "เลือกไฟล์ VBRP (.xlsx)" (โปรแกรมเก็บแถวตามเลขบิล ไม่เก็บไฟล์) แล้วกด "เทียบกับ PDF" ซึ่งกดได้เมื่อมีทั้ง ASN และ VBRP ผลบอกแต่ละแหล่ง (PDF, ASN, VBRP) เป็น ✓ ตรง, ✗ ไม่ตรง หรือ - และหมายเหตุสีเทาบอกว่า ASN หรือ VBRP ไม่ตรงตรงไหน ปุ่ม "ล้างข้อมูล VBRP" ลบแถว VBRP ทั้งหมด
5. กด "แก้ไข (N)" ถ้ามีบิลรอแก้ไข ไฟล์ที่แยกแล้วอยู่ใน `finished/` ชื่อ `{เลขบิล}.pdf` ไฟล์ต้นฉบับยังอยู่
6. กด "สั่งพิมพ์" ได้รอบใหม่ใน `TAX INVOICE/print/print ครั้งที่ N/{Dest Store No.}/`

งานใบลดหนี้ (ปุ่ม CREDIT NOTE ใน `job.php`):

1. วางไฟล์ PDF ใบลดหนี้ใน `myfile/{ชื่อ Job}/CREDIT NOTE/upload here/` (ปุ่ม "upload here" ที่แถบเครื่องมือเปิดโฟลเดอร์นี้)
2. กด "ดึงข้อมูลจาก upload here" โปรแกรมอ่านทีละ 10 ไฟล์ หยุดได้หลังจบชุดที่กำลังอ่าน ถ้ามีไฟล์ที่เคยดึงแล้วหรือเลขใบลดหนี้ซ้ำกับไฟล์อื่น จะถามก่อนว่าข้ามหรือแทนที่ จบแล้วบอกจำนวนที่ได้ และไฟล์ที่ข้าม ไม่ใช่ใบลดหนี้ หรืออ่านไม่สำเร็จ (ไฟล์เหล่านั้นยังอยู่ใน `upload here`)
3. "ล้างข้อมูลใบลดหนี้" ลบแถวทั้งหมดของ Job ไฟล์ PDF ยังอยู่ใน `CREDIT NOTE/finished/` ย้ายกลับไป `CREDIT NOTE/upload here/` ถ้าต้องการอ่านใหม่
4. ที่แท็บ 2 กด "เลือกไฟล์ ASN เพิ่ม (.xlsx)" เลือกไฟล์ ASN เดียวกับของ Tax Invoice แล้วเลือกชีตเอง ชีตที่ชื่อมี ZCR หรือ ZKR มีคำว่า "น่าจะเป็นใบลดหนี้" ต่อท้าย (ไฟล์ตัวอย่างมีสี่ชีต: `CDS ZG2T (ZCR) done`, `RBS ZG2T (ZCR) done`, `CDS ZFP3 (ZKR) done 11.10.2026`, `RBS ZFP3 (ZKR) done 13.10.2026`) นำเข้าทีละชีต ชีตถัดไปของไฟล์เดิมเลือกได้จาก "ชีตอื่นของไฟล์นี้" ที่แถบเครื่องมือ ไม่ต้องเลือกไฟล์ใหม่ เมื่อนำเข้ามากกว่าหนึ่งชีต แถบเครื่องมือเขียนแค่ `4 ชีต` ชื่อเต็มทุกชีตอยู่ใน tooltip ของป้ายนั้นและของ "ASN: 4 ชีต" ที่แถบล่าง
5. กด "เทียบกับ PDF" ผลออกเป็นรายการต่อ (เลขใบลดหนี้, บาร์โค้ด) สถานะ สมบูรณ์ หรือ ผิดพลาด ปุ่ม "ล้างข้อมูล ASN" ลบทีละชีต แท็บ 3 สรุปหนึ่งแถวต่อใบลดหนี้ของ ASN
6. ถ้าแถบสีเหลืองใต้หัวหน้าบอกว่าใบลดหนี้ดึงข้อมูลไว้ก่อนอัปเดต กด "ดึงข้อมูลใหม่จาก finished" โปรแกรมอ่านไฟล์ใน `CREDIT NOTE/finished/` ใหม่โดยไม่ย้ายไฟล์ แล้วจึงเทียบ

## ข้อมูลอยู่ที่ไหน

| ที่ | มีอะไร | โค้ดที่กำหนด |
| --- | --- | --- |
| `data/app.sqlite` | ฐานข้อมูล SQLite ทุก Job: แถวที่อ่านจาก PDF, แถว ASN, บันทึกการแก้ไข, ใบลดหนี้และบรรทัดสินค้าของใบ, แถว ASN ของใบลดหนี้ (แยกจากของ Tax Invoice), แถว VBRP (ไม่เก็บไฟล์ VBRP) | `Database::getConnection`, `VbrpStore::createTables` |
| `myfile/{ชื่อ Job}/TAX INVOICE/upload here/` | PDF ที่รอดึงข้อมูล | `JobFolders::getJobUploadDir` |
| `myfile/{ชื่อ Job}/TAX INVOICE/finished/` | PDF ต้นฉบับหลังดึงข้อมูล และ PDF ที่แยกแล้ว `{เลขบิล}.pdf` | `JobFolders::getJobFinishedDir`, `PdfFixer::fixJob` |
| `myfile/{ชื่อ Job}/TAX INVOICE/print/` | รอบสั่งพิมพ์ `print ครั้งที่ N` | `JobFolders::getJobPrintDir`, `JobFolders::getJobPrintRoundDir`, `PrintManager` |
| `myfile/{ชื่อ Job}/CREDIT NOTE/upload here/` | PDF ใบลดหนี้ที่รอดึงข้อมูล วางในโฟลเดอร์ย่อยก็ได้ ระบบ Tax Invoice ไม่อ่านโฟลเดอร์นี้ | `JobFolders::getJobCnUploadDir` |
| `myfile/{ชื่อ Job}/CREDIT NOTE/finished/` | PDF ใบลดหนี้หลังดึงข้อมูล ล้างข้อมูลใบลดหนี้แล้วไฟล์ยังอยู่ | `JobFolders::getJobCnFinishedDir`, `CreditNoteStore::extractBatch` |

### โฟลเดอร์ของ Job

```
myfile/{ชื่อ Job}/
  TAX INVOICE/
    upload here/
    finished/
    print/
  CREDIT NOTE/
    upload here/
    finished/
```

ในโฟลเดอร์ Job มีแค่สองโฟลเดอร์นี้ แถบล่างของหน้า TAX INVOICE แสดงและเปิด `myfile/{ชื่อ Job}/TAX INVOICE/` แถบล่างของหน้า CREDIT NOTE แสดงและเปิด `myfile/{ชื่อ Job}/CREDIT NOTE/`

Job ที่สร้างก่อน 2026-10-28 มี `upload here`, `finished`, `print` และ `credit note` อยู่ในโฟลเดอร์ Job โดยตรง โปรแกรมย้ายให้เองครั้งแรกที่มีหน้าใดขอโฟลเดอร์ของ Job นั้น (เปิดรายการ Job ก็นับ) ด้วยการเปลี่ยนชื่อโฟลเดอร์ ไม่คัดลอก ไฟล์จึงไม่เปลี่ยนแม้แต่ไบต์เดียว `upload here`, `finished`, `print` ไปอยู่ใน `TAX INVOICE/` และ `credit note` เปลี่ยนชื่อเป็น `CREDIT NOTE`

- ถ้า Windows ไม่ยอมให้ย้ายโฟลเดอร์ไหน (มีโปรแกรมเปิดไฟล์ในนั้นอยู่ หรือหน้าต่าง Explorer ค้างอยู่ในนั้น) โฟลเดอร์นั้นอยู่ที่เดิม โปรแกรมใช้ที่เดิมต่อไปตามปกติ และลองย้ายใหม่ทุกครั้งที่เปิด ควรปิดหน้าต่าง Explorer ที่อยู่ในโฟลเดอร์ Job ก่อนเปิดโปรแกรมครั้งแรกหลังอัปเดต
- ถ้ามีทั้งโฟลเดอร์เก่าและโฟลเดอร์ใหม่ ไฟล์ในโฟลเดอร์เก่าย้ายเข้าโฟลเดอร์ใหม่ทีละไฟล์ ไฟล์ที่ชื่อซ้ำกับไฟล์ในโฟลเดอร์ใหม่อยู่ที่เดิม ไม่มีไฟล์ใดถูกเขียนทับหรือลบ โฟลเดอร์เก่าหายไปเมื่อว่างจริงเท่านั้น
- โค้ด: `JobFolders` ทดสอบ: `tests/job_folders_test.php`

Job ที่โฟลเดอร์ใน `myfile/` หายไปจะไม่แสดงในรายการ (`JobRepository::listJobs`, check ใน `job_safety_test.php` "a job whose folder was deleted is not listed")

ตัวแปรสภาพแวดล้อม `PDF_READER_ROOT` เปลี่ยนที่ของ `data/` และ `myfile/` ไปโฟลเดอร์อื่น (`Config::getRootPath`) ชุดทดสอบใช้ตัวแปรนี้ จึงไม่แตะข้อมูลจริง

## วิธีรันชุดทดสอบ

คำสั่งเดียว รันที่โฟลเดอร์โปรเจกต์:

```
php tests/run_all.php
```

หรือ `composer test` ซึ่งเรียกคำสั่งเดียวกัน composer หยุดสคริปต์ที่ใช้เวลาเกิน 300 วินาที ถ้าเครื่องช้าให้รัน `php tests/run_all.php` ตรง ๆ

`tests/run_all.php` รันชุดทดสอบตามลำดับนี้ แล้วพิมพ์ตารางสรุป (PASS, FAIL, SKIP, วินาที) กับ 15 บรรทัดสุดท้ายของชุดที่ไม่ผ่าน

| ชุดทดสอบ | ตรวจอะไร | จำนวน check (2026-10-28) |
| --- | --- | --- |
| `tests/job_safety_test.php` | กติกาทีละข้อบนข้อมูลเล็ก ๆ และ PDF ตัวอย่างสี่ไฟล์ รวมไฟล์ Excel ผ่าน `tests/excel_columns.js` สีคอลัมน์ผ่าน `tests/column_highlight.js` และตาราง แท็บภาพรวม แถบเครื่องมือของการเทียบสามแหล่ง (PDF, ASN, VBRP) ผ่าน `tests/vbrp_view.js` (17 check: แถวที่ไม่มี VBRP เหมือนก่อนมี VBRP ทุกเซลล์ตาม `tests/fixtures/workspace_rows_before_vbrp.json`, ✓ ✗ - ของแต่ละแหล่ง หมายเหตุ การเรียง Excel ขั้นของแถบเครื่องมือ ปุ่มเทียบ แถบสรุปใต้การ์ด) สถานะรอแก้ไข PDF / รอแก้ไข ASN และสถานะเดิมของบิลผ่าน `tests/status_split_view.js` (26 check: ทะเบียนสถานะ ปุ่มกรองทั้งสองแท็บ ตัวนับ หมายเหตุ ปุ่ม Manage บรรทัด `เดิม:` การ์ดสองใบ แถบรวม คอลัมน์ Previous status และ tooltip ประวัติ Excel การสั่งพิมพ์ ปุ่มแก้ไขที่เซิร์ฟเวอร์ปฏิเสธ `event=compare`) และ Job ที่สร้างก่อนแยกโฟลเดอร์: ดึงข้อมูลตอนย้าย `finished` ไม่ได้ แล้วแก้ไข สั่งพิมพ์ ลบแถว ล้าง และลบ Job หลังย้าย | 200 |
| `tests/job_folders_test.php` | โฟลเดอร์ของ Job ไม่ใช้ PDF ตัวอย่าง: Job ใหม่ได้ `TAX INVOICE` กับ `CREDIT NOTE` การย้าย Job เก่า (ทุกไฟล์ไบต์ตรงกัน) ทำซ้ำ แปดคำขอพร้อมกัน มีโฟลเดอร์ทั้งสองแบบ ย้ายไม่ได้เพราะไฟล์เปิดอยู่ (เฉพาะ Windows) เป้าหมายของ `open_folder` `clear_job` และการลบ Job | 19 |
| `tests/credit_note_test.php` | ระบบใบลดหนี้ของ Job (action `job_cn_*`) บนใบลดหนี้จริงหกไฟล์จาก `tests/fixtures/cn/`: โฟลเดอร์ สแกน ดึงข้อมูล ใบลดหนี้ซ้ำ ล้าง ลบ Job ใบลดหนี้ของ Job ที่สร้างก่อนแยกโฟลเดอร์ schema 9 และคำตอบของ action หน้าเก่า | 31 |
| `tests/credit_note_reader_test.php` | `CreditNoteReader` บนใบลดหนี้ทั้ง 545 ไฟล์ของ `tests/fixtures/cn/` (557 ใบ): ทุกไฟล์ได้ค่าเดิมของ `extractCnDocuments` ทุกใบยกเว้น Attachment Sheet ผ่านเลขคณิตของใบเอง (บรรทัดสินค้ากับยอดรวมด้านขวา) และ 12 ไฟล์ได้บรรทัดสินค้าและยอดรวมตามที่พิมพ์บนหน้า (ทุกรูปแบบ ไฟล์ที่มีสองและสามใบ ร้านที่ไม่พิมพ์เลขร้าน) | 18 |
| `tests/credit_note_compare_test.php` | การเทียบใบลดหนี้กับ ASN และหน้าภาพรวม (action `job_cn_import_asn` ถึง `job_cn_dashboard`) บนใบลดหนี้ตัวอย่างเล็ก ๆ: ทุกสถานะและทุกข้อความ ยอดเงินตรงทุกสตางค์ ฐาน cost และ retail แถว ASN ที่รวมกัน บาร์โค้ดข้างเดียว Attachment Sheet ชีตซ้ำ เลขใบซ้ำ ลบ ASN ล้าง ลบ Job (และบรรทัดของหน้าต่างลบ Job ผ่าน `tests/index_navigation.js`) คำตอบของ Tax Invoice ไม่เปลี่ยน schema 8 เป็น 9 และสิ่งที่การดึงข้อมูลเก็บจากใบลดหนี้จริงสามไฟล์ | 34 |
| `tests/portal_test.php` | หน้า `job.php` และ `credit_note.php` ผ่าน PHP CLI และสคริปต์ของหน้าผ่าน Node (`tests/index_navigation.js`, `tests/credit_note_table.js`, `tests/credit_note_compare.js`, `tests/column_highlight.js`): ลิงก์และตัวเลขของสองปุ่ม Job ที่ไม่มีอยู่ สามแท็บและ `?tab=` หัวตารางทั้งสามของหน้าใบลดหนี้ แถบเครื่องมือ ลูกศรย้อนกลับ โฟลเดอร์ในแถบล่างและในแถบ "ดึงข้อมูลใหม่จาก finished" id และชื่อ global ไม่ชนกับหน้าเก่าและ `workspace.php` บรรทัดของหน้าต่างลบ Job สีคอลัมน์ แถว วันที่ dd/mm/yyyy การเรียง การค้นหา ไฟล์ Excel ชีตที่ตัวเลือกชีตเสนอและแถว ASN ที่ได้จากสี่ชีตใบลดหนี้ของไฟล์ ASN ตัวอย่าง (ต้องมีไฟล์ ASN ใน `tests/fixtures/`) เซลล์ของรายการเทียบทั้งสองฐานยอดเงิน และตารางภาพรวม ป้าย ASN `4 ชีต` และคำว่าเงินต้องตรงกันที่มาจากค่าความคลาดเคลื่อนของ `CreditNoteReconciler` | 53 |
| `tests/vbrp_test.php` | หน้าเก่า `vbrp.php` ที่ไม่ใช้แล้ว (`VbrpStore`, `XlsxReader`, action `job_vbrp_*` ของหน้านั้น): การอ่านไฟล์ Excel ตั้งแต่แถวที่ 4 เฉพาะคอลัมน์ `A / C / E / H / CI / CK / CQ / EM` การเช็คซ้ำจากคอลัมน์ `C` (`Invoice`) เพื่อลบแถวเก่าและแทนที่ด้วยข้อมูลใหม่ การแสดงรายการไฟล์ใน Dropdown `finished` และหน้า Job ที่ไม่มีปุ่มไปหน้านี้แล้ว | 32 |
| `tests/vbrp_compare_test.php` | VBRP เป็นแหล่งที่สามของการเทียบ Tax Invoice บน Job เล็ก ๆ และไฟล์ Excel ที่เขียนในชุดเอง: กติกาใครผิดทุกกรณี (ค่าที่มีหรือไม่มี เท่ากัน ตัดสินทีละคู่ สตางค์) แถวที่ Subtotal 3 ตั้งแต่ -1 ขึ้นไป (-1, 0, บวก, ไม่มีค่า, -1.01) การนำเข้าผ่าน `job_vbrp_import` (ไฟล์ถูก ชีตที่ข้าม ป้ายแถวที่ 3 ผิด ไม่ใช่ `.xlsx` ไฟล์ซ้ำ แทนตามเลขบิล ไม่สร้างโฟลเดอร์ VBRP multipart จริงผ่าน `php -S` ไฟล์เกิน `upload_max_filesize` และ `post_max_size`) สถานะ (รวมรอแก้ไข ASN) ข้อความ หมายเหตุ (ไม่มีหมายเหตุในข้อที่ PDF ผิด ราคาขายไม่เทียบ) ตาราง แผนสั่งพิมพ์ และหน้าภาพรวม | 40 |
| `tests/status_split_test.php` | สถานะรอแก้ไข PDF / รอแก้ไข ASN และประวัติของบิล บน Job เล็ก ๆ ที่มีแถว VBRP: ทะเบียน `BillStatus` (ลำดับ ป้าย แก้ไขได้ พิมพ์ได้ สถานะที่แย่ที่สุด) บิลที่ ASN ผิดรอแก้ไข ASN (ยอดก่อน VAT จำนวนรายบาร์โค้ด บาร์โค้ดที่ ASN ไม่มี ข้อความ วิธีแก้ หมายเหตุ "หลังแก้ ASN แล้ว…") PDF ผิดยังผิดพลาด บิลที่ VBRP ไม่ครอบคลุมเหมือนเดิม ปุ่มแก้ไขข้ามไฟล์ที่มีแต่บิลรอแก้ไข ASN แก้ไฟล์ที่มีบิลรอแก้ไข PDF ทั้งไฟล์ และปฏิเสธการแก้บิลรอแก้ไข ASN ใบเดียว แผนสั่งพิมพ์และ `print_start` ข้ามบิลรอแก้ไข ASN ตัวเลขของหน้าภาพรวม ทุกส่วนเห็นสถานะเดียวกัน ประวัติ (`job_bill_history`: บันทึกแรก การเปลี่ยน ไม่ซ้ำ `was` และ `history` บันทึกเฉพาะเมื่อกดเทียบ นำเข้าหรือลบ ASN นำเข้าหรือล้าง VBRP และแก้ไข การโหลดหน้าไม่บันทึก บันทึกไม่ได้ก็ไม่ทำให้คำขอล้ม ลบไปกับการล้างข้อมูลและการลบ Job) | 38 |
| `tests/open_folder_test.php` | การเปิดโฟลเดอร์ใน Explorer จะเปิดและปิดหน้าต่าง Explorer จริง จึงรันเฉพาะเมื่อใส่ `--with-explorer` | 18 |
| `tests/verify_docs.py` | อ่าน PDF ตัวอย่าง 290 ไฟล์ (เฉพาะ `tests/fixtures/CDS ZFP (ZKE)/` และ `CDS ZFP (ZKE) เพิ่มเติม/`) และไฟล์ ASN ด้วยตัวอ่านอิสระ (`pypdf`, `openpyxl`, `pdftotext`) แล้วเทียบกับผลของโปรแกรม | 28 |
| `tests/verify_incremental.py` | ทำงานจริงผ่าน `api.php` เป็นขั้น ๆ: PDF ทีละชุด ASN สองไฟล์ แก้ไข สั่งพิมพ์ ทำซ้ำ บิลซ้ำ | 78 |
| `tests/verify_cn.py` | การเทียบใบลดหนี้ทั้งชุด เขียนจากข้อตกลงโดยไม่อ่านโค้ด PHP: อ่านใบลดหนี้ 545 ไฟล์ด้วย `pdftotext` และสี่ชีตใบลดหนี้ของไฟล์ ASN ด้วย `openpyxl` แบบที่หน้าเว็บอ่าน ดึงข้อมูล นำเข้า และเทียบผ่าน `api.php` บนสำเนาของโปรเจกต์ แล้วเทียบทุกใบ ทุกแถว และหน้าภาพรวมกับคำตัดสินของตัวเอง รวมการแก้แถว ASN แปดแบบที่ต้องเปลี่ยนเฉพาะใบที่แก้ | 31 |
| `tests/verify_vbrp.py` | การเทียบสามแหล่งทั้งชุด เขียนจากข้อตกลงโดยไม่อ่านโค้ด PHP: อ่านไฟล์ VBRP และชีต ASN `CDS ZFP (ZKE) done 13.10.2026` ด้วย `openpyxl` ดึงข้อมูล PDF 290 ไฟล์ นำเข้า เทียบ และกดแก้ไขผ่าน `api.php` บนสำเนาของโปรเจกต์ แล้วเทียบทุกรายการ (สถานะรวมรอแก้ไข ASN ข้อความ วิธีแก้ คำตัดสินทุกข้อ หมายเหตุ) หน้าภาพรวม และแผนสั่งพิมพ์กับคำตัดสินของตัวเอง ก่อนกดแก้ไข หลังกดแก้ไข (ไฟล์ที่ไม่มีบิลสมบูรณ์หรือรอแก้ไข PDF ต้องไม่ถูกแตะ หมายเหตุ "หลังแก้ ASN แล้ว…") และเมื่อแก้ไขครบทุกไฟล์ ตัวเลขของข้อตกลง (ก่อนกด 23 / 28 / 321 / 1 บิล พิมพ์ได้ 23 หลังกด 51) รวมการแก้ไฟล์เจ็ดแบบที่ต้องเปลี่ยนเฉพาะบิลที่แก้ (ในนั้น ASN ที่แก้ให้ตรงทำให้บิลพ้นรอแก้ไข ASN และ VBRP ที่แก้จน PDF ผิดทำให้บิลผิดพลาด) และไฟล์ VBRP ที่เขียนใหม่โดยไม่แก้ กับ Selling Price ของ ASN ที่แก้ ซึ่งต้องไม่เปลี่ยนอะไร | 24 |

ตัวเลือก:

- `php tests/run_all.php --with-explorer` รัน `open_folder_test.php` ด้วย
- `PHP_BIN=<ที่อยู่ php.exe>` เลือก PHP ที่ชุดทดสอบใช้ เช่น PHP 7.3 ส่วน `run_all.php` เองรันด้วย PHP ตัวไหนก็ได้

รหัสออก: 0 ผ่านทั้งหมด, 1 มีชุดที่ไม่ผ่านหรือหยุดกลางทาง, 2 มีชุดที่รันไม่ได้เพราะขาดเครื่องมือ (ตารางบอกว่าขาดอะไร)

รันทีละชุดได้เหมือนเดิม เช่น `php tests/job_safety_test.php` หรือ `python tests/verify_docs.py`

ชุดทดสอบทุกชุดทำงานบนโฟลเดอร์ชั่วคราว ไม่เปิด `data/app.sqlite` และ `myfile/` จริง ข้อมูลตัวอย่างอยู่ใน `tests/fixtures/`
`job_safety_test.php`, `verify_docs.py` และ `verify_incremental.py` ต้องมี PDF ใบกำกับภาษีตัวอย่างใน `tests/fixtures/CDS ZFP (ZKE)/` และ `CDS ZFP (ZKE) เพิ่มเติม/` ถ้าไม่มี ชุดเหล่านี้พิมพ์ SKIP และ `run_all.php` แสดง NOT RUN
`credit_note_reader_test.php` ต้องมีใบลดหนี้ตัวอย่างครบ 545 ไฟล์ใน `tests/fixtures/cn/` ถ้าไม่ครบ พิมพ์ SKIP และ `run_all.php` แสดง NOT RUN
`credit_note_compare_test.php` ต้องมีใบลดหนี้สามไฟล์ใน `tests/fixtures/cn/` และ `verify_cn.py` ต้องมีทั้ง 545 ไฟล์กับไฟล์ ASN ตัวอย่าง ถ้าไม่มี พิมพ์ SKIP และ `run_all.php` แสดง NOT RUN
`verify_vbrp.py` ต้องมี PDF ใบกำกับภาษีตัวอย่าง 290 ไฟล์ ไฟล์ `Report VBRP_SIS PROMOTION ZFP Recheck K.Big.xlsx` และไฟล์ ASN (ชื่อขึ้นต้นด้วย `ASN`) ใน `tests/fixtures/` ถ้าไม่มี พิมพ์ SKIP และ `run_all.php` แสดง NOT RUN `verify_docs.py` เลือกไฟล์ ASN จากชื่อที่ขึ้นต้นด้วย `ASN` เพราะโฟลเดอร์มีไฟล์ VBRP ด้วย

## โครงสร้างโฟลเดอร์

| โฟลเดอร์หรือไฟล์ | มีอะไร |
| --- | --- |
| `index.php`, `job.php`, `workspace.php`, `credit_note.php`, `read_cn_error01.php` | หน้าเว็บ |
| `api.php` | API JSON ทุก action ที่หน้าเว็บเรียก |
| `app/` | class PHP namespace `App\` (PSR-4 ใน `composer.json`) |
| `assets/css/app.css` | สไตล์ทั้งหมด |
| `assets/js/` | JavaScript ของหน้าเว็บ (`ui-utils.js`, `index.js`, `cn-error.js`, `workspace/*.js`, `credit-note/*.js`) |
| `assets/vendor/xlsx.full.min.js` | ไลบรารี SheetJS 0.18.5 อ่านและเขียนไฟล์ Excel ในเบราว์เซอร์ |
| `vendor/` | ไลบรารีจาก composer |
| `tests/` | ชุดทดสอบ, `run_all.php`, `requirements.txt` |
| `tests/fixtures/` | นับเมื่อ 2026-10-28: ไฟล์ ASN ตัวอย่าง, `workspace_rows_before_vbrp.json` (สิ่งที่สคริปต์ของ `workspace.php` แสดงก่อนมี VBRP ที่ `tests/vbrp_view.js` และ `tests/status_split_view.js` ใช้ ป้าย `รอแก้ไข` เปลี่ยนเป็น `รอแก้ไข PDF` สี่เซลล์เมื่อ 2026-10-28), `cn/` PDF ใบลดหนี้ 545 ไฟล์ (`credit_note_test.php`, `credit_note_reader_test.php`, `credit_note_compare_test.php`, `portal_test.php` และ `verify_cn.py` ใช้) และ `inv/` PDF 722 ไฟล์ที่ยังไม่มีชุดทดสอบใดอ่าน PDF ใบกำกับภาษีตัวอย่าง 290 ไฟล์ (`CDS ZFP (ZKE)/` 284 ไฟล์ และ `CDS ZFP (ZKE) เพิ่มเติม/` 6 ไฟล์) ไม่อยู่ในโฟลเดอร์นี้ตอนนี้ ต้องคัดลอกกลับมาก่อนรันสามชุดที่ใช้ |
| `docs/` | เอกสาร |
| `data/` | ฐานข้อมูล สร้างเมื่อใช้งาน |
| `myfile/` | โฟลเดอร์ของ Job สร้างเมื่อใช้งาน |
