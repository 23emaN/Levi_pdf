# กติกาการตรวจบิล

เอกสารนี้เขียนจากโค้ดและชุดทดสอบ ณ 2026-10-26 ทุกข้อบอกโค้ดที่ทำ (class และ method) และ check ที่คุมไว้ (ไฟล์และชื่อ check ตามที่ชุดทดสอบพิมพ์ ชื่อยาวตัดด้วย "…")
ตัวเลขตัวอย่างมาจากข้อมูลใน `tests/fixtures/`: PDF 290 ไฟล์, ASN ชีตแรกที่มองเห็น 1,890 แถว, 373 เลขบิล, 1,745 คู่ของเลขบิลกับบาร์โค้ด
ตัวเลขเหล่านี้วัดจาก Job ที่ดึงข้อมูล PDF ทั้งหมด นำเข้า ASN เทียบ และกดแก้ไขหนึ่งครั้ง

คำที่ใช้ในเอกสาร:

- **บิล** คือเลข Tax Invoice หนึ่งเลข เช่น `93247359-1`
- **รายการ** คือหนึ่งแถวของผลการเทียบ คือเลขบิลหนึ่งเลขกับบาร์โค้ดหนึ่งตัว
- **ยอดรวมท้ายบิล** คือส่วนท้ายของบิล PDF (`TOTAL SHIPPED` ถึง `Total Amt Include VAT`) ในโค้ดเรียก footer
- **`upload here/`, `finished/`, `print/`** ในเอกสารนี้คือโฟลเดอร์ใน `myfile/{ชื่อ Job}/TAX INVOICE/` โฟลเดอร์ของใบลดหนี้คือ `myfile/{ชื่อ Job}/CREDIT NOTE/` (ดู README หัวข้อ "โฟลเดอร์ของ Job")

## ASN เป็นข้อมูลหลัก

ผลการเทียบเริ่มจากแถวของ ASN เสมอ แถว ASN ที่มีเลขบิลและบาร์โค้ดเดียวกันรวมเป็นรายการเดียว (จำนวนรวมกัน ราคาขายใช้ค่าต่ำสุด)
ไม่มีสถานะ "ไม่มีใน ASN" บิลใน PDF ที่ ASN ไม่มีจะไม่แสดงในผลการเทียบ
เลขบิลของ PDF กับ ASN จับคู่กันด้วยตัวอักษรภาษาอังกฤษตัวใหญ่และตัวเลขเท่านั้น

- โค้ด: `AsnReconciler::reconcile` (ขั้น 3 และ 4), `DashboardBuilder::invoiceKey`
- ทดสอบ: `verify_docs.py` "before the fix every ASN line has the status the independent check gives it…"

## สี่สถานะ และสถานะของบิล

ตั้งแต่ 2026-10-28 "รอแก้ไข" แยกเป็นสองสถานะ (ผู้ใช้ตัดสิน ดู DECISIONS.md) ทะเบียนเดียวของสถานะคือ `BillStatus` (รหัส คำบนหน้าจอ ลำดับ แก้ไขด้วยปุ่มแก้ไขได้ไหม พิมพ์ได้ไหม และสถานะที่แย่ที่สุด) ส่วนอื่นของโค้ดอ่านจากที่นี่:

| สถานะในโค้ด | คำบนหน้าจอ | ลำดับ | ปุ่มแก้ไข | พิมพ์ | ความหมาย |
| --- | --- | --- | --- | --- | --- |
| `MATCH` | สมบูรณ์ | 0 | - | พิมพ์ได้ | ตรงทุกข้อ และปุ่มแก้ไขไม่ต้องทำอะไรกับบิลนี้ |
| `PENDING_FIX` | รอแก้ไข PDF | 1 | แก้ได้ | ไม่พิมพ์ | หลังกดแก้ไขจะตรง แต่ปุ่มแก้ไขยังต้องปรับหน้าหรือแยกไฟล์ของบิลนี้ |
| `PENDING_ASN` | รอแก้ไข ASN | 2 | ไม่แก้ | ไม่พิมพ์ | PDF ไม่ผิด แต่ ASN ผิด (เฉพาะบิลที่ VBRP ครอบคลุม ดูหัวข้อ "รอแก้ไข ASN") ต้องแก้ ASN แล้วนำเข้าใหม่ |
| `MISMATCH` | ผิดพลาด | 3 | ไม่แก้ | ไม่พิมพ์ | PDF ไม่ตรง หรือ PDF ขัดกันเอง กดแก้ไขแล้วก็ยังผิด |

บนหน้าจอมีสถานะแสดงผลเพิ่มอีกสองแบบ สถานะที่เก็บในระบบยังเป็นสี่แบบนี้:

- **แก้ไขแล้ว** คือรายการที่เป็น `MATCH` และบิลมีบันทึกการแก้ไข (ดูหัวข้อ "แก้ไขแล้ว คืออะไร")
- **รอเทียบกับ PDF** คือแถว ASN ที่ยังไม่ได้กดเทียบ

สถานะของบิลคือสถานะที่แย่ที่สุดของรายการในบิล ลำดับจากดีไปแย่คือ `MATCH`, `PENDING_FIX`, `PENDING_ASN`, `MISMATCH` (`BillStatus::worst`)
หน้าภาพรวม การสั่งพิมพ์ และตัวเลขทุกตัวนับเป็นจำนวนบิล ไม่ใช่จำนวนแถว
Job ที่ไม่มีแถว VBRP ไม่มี `PENDING_ASN` คำตอบเหมือนก่อนแยกสถานะทุกไบต์ ยกเว้น `status_label` ของ `PENDING_FIX` ที่เปลี่ยนจาก `รอแก้ไข` เป็น `รอแก้ไข PDF` (วัดด้วย `eq_status.sh` บนข้อมูลตัวอย่าง: reconcile หน้าภาพรวม และแผนสั่งพิมพ์ ตรงกันทุกไบต์เมื่อเปลี่ยนป้าย 200 ป้ายนี้กลับ ทั้ง PHP 8.2 และ 7.3)

- โค้ด: `BillStatus`, `AsnReconciler::invoiceStatuses`, `STATUS_DISPLAY` และ `displayStatus` ใน `assets/js/workspace/state.js`
- ทดสอบ: `status_split_test.php` "the registry lists the four statuses best to worst…", "a bill has the worst status of its lines…", `verify_incremental.py` "the result of every row is the worst status of its lines", `job_safety_test.php` "every count is a number of invoices, the worst line decides…"

## ตัดสินบนหน้าที่ปุ่มแก้ไขจะจัดให้

ก่อนตัดสิน โปรแกรมสร้างแผนการแก้ไข (`FixPlan`) จากแถว ASN และแถว PDF แล้วตัดสินแถว PDF ในสภาพที่ปุ่มแก้ไขจะทำให้เป็น
ปุ่มแก้ไขใช้แผนเดียวกันนี้ ดังนั้นสถานะก่อนกดแก้ไขเท่ากับสถานะหลังกด โดยนับ "รอแก้ไข PDF" เป็น "สมบูรณ์"
แผนที่สร้างใหม่หลังกดแก้ไขไม่เหลือหน้าที่ต้องแก้เลขหรือยอดรวมที่ต้องลบ จึงไม่มีอะไรเปลี่ยนอีก

แผนแบ่งหน้าให้บิลแบบนี้ ทีละไฟล์และทีละหน้า:

1. แต่ละรายการในหน้าลองบิลตามลำดับ: บิลของหน้าก่อนหน้า, บิลตามเลขที่พิมพ์บนหน้า, แล้วบิลอื่นในเลขชุดเดียวกัน (เลขก่อน `-N`) เรียงตามเลขท้าย รายการไปอยู่กับบิลแรกที่ ASN ของบิลนั้นยังเหลือจำนวนของบาร์โค้ดนี้พอ แล้วหักจำนวนนั้นออก
2. หน้านั้นเป็นของบิลที่รายการเลือกมากที่สุด ถ้าไม่มีรายการใดเลือกบิลได้ หน้านั้นเป็นของเลขที่พิมพ์
3. หน้าที่ไม่ใช่หน้าสุดท้ายของบิลในไฟล์นั้นต้องไม่มียอดรวมท้ายบิล

ตัวอย่าง: ไฟล์ `RM Document-Customer Billing(PDF)-0093247359 3.pdf` หน้า 2 พิมพ์เลข `93247359-2` แต่รายการเป็นของ `93247359-1`
แผนให้หน้า 2 เป็นของ `93247359-1` และลบยอดรวมท้ายบิลของหน้า 1

- โค้ด: `AsnReconciler::reconcile` (สร้าง `FixPlan` แล้วเรียก `FixPlan::applyTo`), `FixPlan::planFile`, `FixPlan::changes`
- ทดสอบ: `job_safety_test.php` "before the fix every line already had the status it has after it…", `verify_incremental.py` "each compare line shows before the fix the status it has after it…", "no line waits for the fix while it is wrong after the fix", "a plan made again after the fix finds no page to renumber…"

ผลบนข้อมูลตัวอย่าง ไม่มี VBRP (ผลเมื่อมี VBRP อยู่ในหัวข้อ "VBRP: แหล่งที่สามของการเทียบ Tax Invoice"):

| | สมบูรณ์ | รอแก้ไข PDF | ผิดพลาด |
| --- | --- | --- | --- |
| รายการ ก่อนกดแก้ไข | 131 | 200 | 1,414 |
| รายการ หลังกดแก้ไข | 331 (131 + แก้ไขแล้ว 200) | 0 | 1,414 |
| บิล ก่อนกดแก้ไข | 23 | 28 | 322 |
| บิล หลังกดแก้ไข | 51 (23 + แก้ไขแล้ว 28) | 0 | 322 |

ทดสอบ: `verify_incremental.py` "final result equals one run over all files" (matched 331, pending_fix 0, mismatch 1414)

## สิ่งที่ตรวจในแต่ละรายการ

รายการเป็น "สมบูรณ์" เมื่อผ่านทุกข้อด้านล่าง และปุ่มแก้ไขไม่ต้องทำอะไรกับบิล
ข้อความเหตุผลและวิธีแก้ของรายการที่สมบูรณ์คือ `ข้อมูลถูกต้อง`

- โค้ด: `AsnReconciler::COMPLETE`
- ทดสอบ: `job_safety_test.php` "a complete bill only says ข้อมูลถูกต้อง, as reason and as action"

ถ้าไม่ผ่านข้อใด รายการเป็น "ผิดพลาด" (ยกเว้นสองกรณีท้ายหัวข้อ "เมื่อไหร่บิลเป็น รอแก้ไข PDF") ปัญหาเรียงตามลำดับนี้ คั่นด้วย `, `:
จำนวน, ราคาขายติดลบ, สาขา, บาร์โค้ดที่มีแต่ใน PDF, ยอดรวมท้ายบิลที่ไม่ใช่ของบิลนี้, ยอดรวม, VAT

- โค้ด: `AsnReconciler::reconcile` (ตัวแปร `$reasons` และ `$billProblems`), `FixLog::TOPICS`
- ทดสอบ: `job_safety_test.php` "the problems of a bill read quantity, barcode only in the PDF, footer of another bill, amount, VAT…"

### จำนวน

จำนวนชิ้นใน PDF (รวมทุกแถวของบิลและบาร์โค้ดนั้น) ต้องเท่ากับ Quantity ใน ASN
ข้อความ: `จำนวนไม่ตรง (PDF: 4 ชิ้น / ASN: 2 ชิ้น)`
ตัวอย่าง: บิล `93251539-1` บาร์โค้ด `5401221426009` PDF 4 ชิ้น ASN 2 ชิ้น เป็นบิลเดียวในข้อมูลตัวอย่างที่จำนวนไม่ตรง

- ทดสอบ: `job_safety_test.php` "a quantity difference is a mismatch even when cost matches", `verify_incremental.py` "before the fix bill 93251539-1 is wrong on every line, each naming barcode 5401221426009 with 4 pieces in the PDF and 2 in the ASN"

### ราคาขายติดลบใน ASN

Selling Price ใน ASN ต่ำกว่า 0 ทำให้รายการผิดพลาด ข้อความ: `Selling Price ติดลบใน ASN (฿x)`
ราคาขายใช้ตรวจข้อนี้ข้อเดียว ไม่ได้เทียบกับราคาใน PDF
ในบิลที่ VBRP ครอบคลุม ข้อนี้ไม่ทำให้รายการผิดและไม่เป็นหมายเหตุ เพราะสถานะตาม PDF และราคาขายไม่ได้เทียบกับแหล่งใด ช่อง Sell Price (ASN) ยังเป็นสีแดง (หัวข้อ "VBRP: แหล่งที่สามของการเทียบ Tax Invoice")

บิลที่ VBRP ไม่ครอบคลุมใน Job ที่มี VBRP ผิดพลาดอยู่แล้ว (`ไม่พบใน VBRP`) ราคาขายติดลบต่อท้ายเป็นปัญหาของบิล และช่อง ASN ของ `sources.overall` เป็นไม่ตรง

- ทดสอบ: `vbrp_compare_test.php` "a bill VBRP does not cover (its only row has Subtotal 3 of -1) is marked MISMATCH (ไม่พบใน VBRP) with 2-way PDF/ASN verdicts; in a covered bill a negative ASN price, compared with nothing, fails nothing and is no note"

### สาขา

เลขสาขาใน PDF คือตัวเลข 5 หลักที่ขึ้นต้นด้วย `10` หรือ `20` ใน Ship to Party (ถ้าไม่มี ดูใน Address) ต้องเท่ากับ Dest Store No. ใน ASN
ถ้าฝั่งใดไม่มีเลขสาขา ไม่ตรวจข้อนี้ ข้อความ: `รหัสสาขาไม่ตรง (PDF: x / ASN: y)`

- โค้ด: `AsnReconciler::extractStoreCode`
- ทดสอบ: ยังไม่มี check เฉพาะข้อนี้

### บาร์โค้ดที่มีแต่ใน PDF

บาร์โค้ดที่ PDF ของบิลพิมพ์ แต่ ASN ของบิลนั้นไม่มี ทำให้ทุกรายการของบิลผิดพลาด แม้ยอดรวมจะต่างกันไม่เกิน 1.00 บาท
ข้อความ: `บาร์โค้ด X มีใน PDF แต่ไม่มีใน ASN (2 ชิ้น)` แสดงได้ 3 บาร์โค้ด ที่เหลือเป็น `และอีก N รายการ` เรียงตามลำดับใน PDF
ข้อมูลตัวอย่างไม่มีบิลแบบนี้

- โค้ด: `AsnReconciler::reconcile` (ขั้น 3b), `AsnReconciler::PDF_ONLY`
- ทดสอบ: `job_safety_test.php` "a barcode of the PDF the ASN does not list…", "four such barcodes show the first three and the count of the rest", `verify_docs.py` "on the real sample no bill holds a barcode its ASN does not list, independently and in the app"

### ยอดรวมท้ายบิลต้องมาจากรายการของบิลเอง

ตัวเลข Total Amt Excluding VAT ท้ายบิลต้องเท่ากับค่าที่คำนวณจากรายการของบิลเอง ต่างกันได้ไม่เกิน 1.00 บาท (ปัดเป็นสตางค์ก่อนเทียบ):

```
(ผลรวม Amount ของทุกรายการในบิล − Promotion Discount) × (1 − Total Discount % / 100) / 1.07
```

- ถ้าบิลอื่นในไฟล์เดียวกันให้ค่านี้ได้ ข้อความคือ `ยอดรวมท้ายบิลเป็นของบิล 93248350-2 (ยอดรวมก่อน VAT ท้ายบิล: x / จากรายการของบิลนี้: y)`
- ถ้าไม่มีบิลใดในไฟล์ให้ค่านี้ ข้อความคือ `ยอดรวมท้ายบิลไม่ตรงกับรายการของบิลนี้ (ยอดรวมก่อน VAT ท้ายบิล: x / จากรายการของบิลนี้: y)`
- ยอดรวมท้ายบิลที่อ่าน Promotion Discount หรือเปอร์เซ็นต์ไม่ได้ หรือ Amt Excl VAT ไม่เป็นบวก ไม่ตรวจข้อนี้ Job ที่ดึงข้อมูลด้วยรุ่นก่อน 2026-10-26 เก็บ Promotion Discount เป็น `-` จึงไม่ถูกตรวจจนกว่าจะล้างข้อมูล PDF แล้วดึงใหม่

ข้อมูลตัวอย่างไม่มีบิลที่ผิดข้อนี้ ค่าที่ต่างมากที่สุดคือ 0.07

- โค้ด: `AsnReconciler::footerProblems`, `AsnReconciler::fromLines`, `AsnReconciler::footerFits`
- ทดสอบ: `job_safety_test.php` "two bills of one file with their footers exchanged each name the other…", "a footer without its Promotion Discount or its percentage is not judged…", "the printed total may be ฿1.00 away from what the lines give…", `verify_docs.py` "on every bill of the sample, from the PDF text alone…", "with the footers of 93247359-1 and 93247359-2 exchanged…"

### ยอดรวม (กติกาเข้ม)

Amt Excl VAT ท้ายบิลของ PDF กับ Total Cost Ex VAT ของ ASN ต่างกันได้ไม่เกิน 1.00 บาท ปัดผลต่างเป็นสตางค์ก่อนเทียบ
ถ้าค่าฝั่งใดไม่มีหรือไม่เป็นบวก ไม่ตรวจข้อนี้
ข้อความบอกทั้งสองค่า ไม่บอกว่าฝั่งไหนมากกว่าและไม่บอกผลต่าง: `ยอดรวมไม่ตรง (PDF: 7,504.15 / ASN: 13,028.05)` (บิล `93248362-1`)
ผลของข้อนี้ส่งไปที่หน้าเว็บเป็น `amount_ok` สีของช่อง Cost Ex VAT ในตาราง ASN ใช้ค่านี้

ASN ใส่ Total Cost Ex VAT ของทั้งบิลซ้ำในทุกแถวของบิล จึงเทียบระดับบิลได้
บนข้อมูลตัวอย่าง บิลผิดพลาดทั้ง 322 บิลผิดข้อนี้ และทุกบิลมียอด PDF ต่ำกว่า ASN

- โค้ด: `AsnReconciler::AMOUNT_TOLERANCE`, `AsnReconciler::amountAgrees`, `AsnReconciler::amountProblem`
- ทดสอบ: `job_safety_test.php` "Amt Excl VAT of the PDF and Cost Ex VAT of the ASN may differ by ฿1.00 at most…", "ASN cost equal to the list price / 1.07 but not to the PDF footer is wrong now…"

### VAT

VAT ท้ายบิลของ PDF กับ Total VAT ของ ASN ต่างกันได้ไม่เกิน 0.15 บาท ถ้าฝั่งใดไม่มีหรือไม่เป็นบวก ไม่ตรวจข้อนี้
ข้อความ: `VAT ไม่ตรง (PDF: ฿1,581.60 / ASN: ฿1,302.17)`

- โค้ด: `AsnReconciler::VAT_TOLERANCE`
- ทดสอบ: `job_safety_test.php` "several problems of a line are joined by…" (ลำดับ quantity, amount, VAT)

### ไม่พบใน PDF

รายการ ASN ที่ไม่มีแถว PDF ของบิลและบาร์โค้ดนั้น และไม่มีในบิลอื่นที่เลขชุดเดียวกัน เป็น "ผิดพลาด"
ข้อความ: `มีรายการในไฟล์ ASN แต่ไม่พบไฟล์ PDF ของบิลนี้ [PDF ไม่ออก หรือบิลตกหล่น]`

- โค้ด: `AsnReconciler::reconcile` (Case C)
- ทดสอบ: `job_safety_test.php` "a barcode billed instead of the one the ASN lists makes the missing line say so…"

### รายการที่ถูกเองในบิลที่ผิด

รายการที่ผ่านข้อของตัวเอง (จำนวน ราคาขาย สาขา) ยังเป็น "ผิดพลาด" เมื่อบิลผิดในข้อระดับบิล (บาร์โค้ดที่มีแต่ใน PDF, ยอดรวมท้ายบิล, ยอดรวม, VAT)
ถ้าบิลนั้นมีรายการอื่นที่จำนวนไม่ตรงหรือไม่พบใน PDF ข้อความของรายการที่ถูกบอกรายการนั้น:
`รายการนี้ถูกต้อง แต่บิลผิดพลาดเพราะบาร์โค้ด 5401221426009 จำนวนไม่ตรง (PDF: 4 / ASN: 2) จึงทำให้ยอดรวมบิลไม่ตรง (ยอดรวมไม่ตรง (PDF: 22,594.56 / ASN: 31,364.53), VAT ไม่ตรง (PDF: ฿1,581.60 / ASN: ฿1,302.17))` (บิล `93251539-1`)

- โค้ด: `AsnReconciler::RIGHT_LINE`, ตัวแปร `$culprits`
- ทดสอบ: `job_safety_test.php` "a line that matches on its own names the line that makes its bill fail"

### สิ่งที่ไม่ได้ตรวจ

- ราคาต่อชิ้นใน PDF กับ Selling Price ใน ASN ไม่ได้เทียบกัน (`$asnSellPrice` ใช้ตรวจราคาติดลบเท่านั้น) ในบิลที่ VBRP ครอบคลุม Selling Price ของ ASN กับ Subtotal 2 ของ VBRP ก็ไม่ได้เทียบ แสดงสองช่องให้ดูเท่านั้น
- ยอดเงินรายบาร์โค้ดไม่ได้เทียบ ASN ไม่มียอดเงินรายแถว
- เหตุผลของสองข้อนี้อยู่ใน [DECISIONS.md](DECISIONS.md)

## เมื่อไหร่บิลเป็น "รอแก้ไข PDF"

บิลรอแก้ไข PDF เมื่อหลังกดแก้ไขบิลจะตรงกับ ASN และปุ่มแก้ไขยังต้องทำอย่างใดอย่างหนึ่งนี้ (ในบิลที่ VBRP ครอบคลุม บิลที่ ASN ผิดเป็นรอแก้ไข ASN แทน ดูหัวข้อ "รอแก้ไข ASN"):

| เหตุ | ปัญหา (ตัวอย่างจริง) | วิธีแก้ไข (ตัวอย่างจริง) |
| --- | --- | --- |
| หน้าพิมพ์เลขของบิลอื่น | `หน้า 2 คาดว่าเลขบิลผิด เนื่องจากรวมกับหน้า 1 แล้วรายการถูกต้องตาม ASN` (`93247359-1`) | `แก้ไขเลขบิลของหน้า 2, ลบยอดรวมของหน้าที่ 1, แยกหน้าที่ 1 ถึง 2 ออกมาเป็นอีกไฟล์` |
| บิลที่เสียหน้านั้นไป | ประโยคเดียวกัน (`93247359-2`) | `ย้ายหน้า 2 ไปเป็นบิล 93247359-1, แยกหน้าที่ 3 ออกมาเป็นอีกไฟล์` |
| ยอดรวมท้ายบิลอยู่บนหน้าที่ไม่ใช่หน้าสุดท้ายของบิล | `หน้า 4 มียอดรวมท้ายบิล ทั้งที่ไม่ใช่หน้าสุดท้ายของบิล` (`93248169-3`) | `ลบยอดรวมของหน้าที่ 4, แยกหน้าที่ 4 ถึง 5 ออกมาเป็นอีกไฟล์` |
| ต้องแยกไฟล์อย่างเดียว | `ไฟล์ต้นฉบับมีหลายบิล บิลนี้อยู่หน้าที่ 4-5` (`93247359-3`) | `แยกหน้าที่ 4 ถึง 5 ออกมาเป็นอีกไฟล์` |

บิลที่ต้องแยกไฟล์อย่างเดียวไม่รอแก้ไข PDF อีก เมื่อมีบันทึกการแยก (`split`) หรือมีบันทึกการแก้ไขของบิลนั้นแล้ว

อีกสองกรณีที่แผนจัดหน้าให้ไม่ได้ก็เป็น "รอแก้ไข PDF" ด้วย ใช้ข้อความทั่วไป:

- รายการอยู่บนหน้าที่พิมพ์เลขบิลอื่นในชุดเดียวกัน และย้ายหน้าไม่ได้: `เลขที่พิมพ์ในบิล PDF คือ X แต่ใน ASN เป็น Y`
- บิลยังมียอดรวมท้ายบิลมากกว่าหนึ่งหน้า เช่นบิลที่อยู่ในสองไฟล์: `พบข้อมูลส่วนท้ายบิลซ้ำในหน้าต่อเนื่อง`

- ทดสอบ: `job_safety_test.php` "a line printed on the page of another bill, which no page renumbering can move, still waits for the fix…"

รายการที่ผิดพลาดหลังกดแก้ไขเป็น "ผิดพลาด" ตั้งแต่ก่อนกด และไม่มีวิธีแก้ไข แม้ปุ่มแก้ไขจะปรับหน้าหรือแยกไฟล์ของบิลนั้น
บิลที่รอแก้ไข PDF พิมพ์ไม่ได้จนกว่าจะกดแก้ไข

- โค้ด: `FixPlan::describe`, `AsnReconciler::beforeFix`, `FixLog::splitDone`, `FixLog::PLANNED`
- ทดสอบ: `job_safety_test.php` "every line of a bill waiting for the fix has the user's problem sentence…", "a right bill the fix only splits out of a file of several bills waits for the fix…", "the logged split alone keeps a bill out of waiting…", `verify_incremental.py` "every line of a waiting bill has one problem…", "every waiting line of a bill shows one problem and one solution, neither names a button…"

## ปุ่มแก้ไขทำอะไร

ปุ่ม "แก้ไข (N)" ใช้แผน `FixPlan` เดียวกับที่ใช้ตัดสิน ทำทีละไฟล์:

1. **แก้เลขบิล** แถวของหน้าที่พิมพ์เลขบิลอื่นได้เลขบิลที่ถูก (`job_items.tax_invoice`)
2. **ลบยอดรวมที่ซ้ำ** แถวของหน้าที่ไม่ใช่หน้าสุดท้ายของบิลได้ค่า `Null` ใน 10 ช่องยอดรวมท้ายบิล (`FixPlan::CLEARED_FOOTER`)
3. **แยกไฟล์** ไฟล์ที่ต้องแก้อะไรก็ตาม หรือมีมากกว่าหนึ่งบิล ได้ไฟล์ `{เลขบิล}.pdf` ละบิลใน `finished/` ยกเว้นบิลที่ชื่อไฟล์เป็นชื่อบิลนั้นอยู่แล้ว ไฟล์ต้นฉบับยังอยู่
4. ในไฟล์ที่แยก หน้าที่พิมพ์เลขบิลผิดได้กล่องขาวทับเลขเดิมและเขียนเลขที่ถูก หน้าที่ไม่ใช่หน้าสุดท้ายได้กล่องขาวทับยอดรวมท้ายบิล
5. เทียบกับ ASN ใหม่ แล้วบันทึกบิลที่แก้ไข

กดซ้ำได้ ครั้งที่สองไม่เปลี่ยนแถวและบันทึก เพียงเขียนไฟล์แยกเดิมทับ
บนข้อมูลตัวอย่างที่ไม่มี VBRP การกดครั้งแรกแก้เลขบิล 7 แถว ลบยอดรวม 131 แถว และสร้างไฟล์แยก 146 ไฟล์

ปุ่มแก้ไขไม่แก้บิลรอแก้ไข ASN (ผู้ใช้ตัดสิน 2026-10-28):

- ถ้า Job มีบิลรอแก้ไข ASN อย่างน้อยหนึ่งบิล ปุ่มแก้ไขทำเฉพาะไฟล์ที่มีบิลรอแก้ไข PDF อย่างน้อยหนึ่งบิล (สถานะก่อนกด) ไฟล์ที่มีแต่บิลรอแก้ไข ASN บิลผิดพลาด หรือบิลสมบูรณ์ ไม่ถูกแตะเลย (ไม่แก้แถว ไม่แยกไฟล์ ไม่บันทึก) ไฟล์ที่มีทั้งบิลรอแก้ไข PDF และบิลรอแก้ไข ASN ถูกแก้ทั้งไฟล์แบบเดิม เพราะการแก้ไฟล์แยกบิลเดียวออกไม่ได้ บิลรอแก้ไข ASN ในไฟล์นั้นยังรอแก้ไข ASN และพิมพ์ไม่ได้
- ถ้า Job ไม่มีบิลรอแก้ไข ASN (เช่นไม่มี VBRP) ปุ่มแก้ไขทำงานแบบเดิมทุกอย่าง
- ขอแก้บิลรอแก้ไข ASN บิลเดียว (`fix_invoice` ที่มี `invoice_no`) ได้คำตอบ `status: success` กับ `data` = `{"success": false, "message": "บิลนี้รอแก้ไข ASN แก้ไข PDF ไม่ได้ ต้องแก้ ASN แล้วนำเข้าใหม่ก่อน"}` และไม่มีอะไรเปลี่ยน
- บนข้อมูลตัวอย่างที่มี VBRP การกดครั้งแรกแก้เลขบิล 3 แถว ลบยอดรวม 43 แถว และสร้างไฟล์แยก 28 ไฟล์ของบิลรอแก้ไข PDF 28 บิล และไม่แตะ 255 จาก 290 ไฟล์ที่ไม่มีบิลสมบูรณ์หรือรอแก้ไข PDF

- โค้ด: `PdfFixer::fixJob` (ตัวแปร `$onlyWaitingFiles`, `PdfFixer::WAITS_FOR_ASN`), `FixPlan::planFile`, `PdfSplitter::planInvoiceEdits`, `PdfSplitter::applyEdits`
- ทดสอบ: `status_split_test.php` "the fix button leaves the file whose only bill waits for the ASN as it is and fixes the file of the bill waiting for the fix as a whole…", "fixing one bill that waits for the ASN is refused in the user's words and touches nothing", `verify_vbrp.py` "the fix button leaves every file that holds no matching bill as it was…", `verify_docs.py` "every split PDF holds exactly the lines of its ASN invoice", "every page of a split PDF shows its own invoice number", "a split PDF shows a footer on its last page only", "after the fix every footer sits on the last page of its invoice", `verify_incremental.py` "pressing fix again changes nothing", `job_safety_test.php` "a file whose only invoice needs nothing is left alone…"

### บันทึกการแก้ไข

ตาราง `job_invoice_fix_steps` เก็บหนึ่งแถวต่อหนึ่งหน้าและหนึ่งชนิด ชนิดคือ `renumber`, `clear_footer`, `split` แถวของ `renumber` เก็บเลขเดิมใน `from_value`
ตาราง `job_invoice_fixes` เก็บเลขบิลที่การกดครั้งนั้นทำอะไรด้วย (แก้เลข ลบยอดรวม แยกไฟล์ หรือเสียหน้าให้บิลอื่น รวมบิลรอแก้ไข ASN ในไฟล์ที่ถูกแก้ทั้งไฟล์) และบิลที่เปลี่ยนจากรอแก้ไข PDF เป็นไม่รอ หรือจากผิดพลาดเป็นสมบูรณ์
บันทึกทั้งสองของบิลหายไปเมื่อแถว PDF ของบิลถูกลบหรือดึงข้อมูลไฟล์นั้นใหม่แบบเขียนทับ

- โค้ด: `FixLog::record`, `PdfFixer::recordFixedInvoices`, `PdfItemStore::forgetInvoiceFixes`
- ทดสอบ: `job_safety_test.php` "the fix records every invoice it worked on…", "the fix logs the renumbered page with its old and new number…", "pressing fix again leaves the same step rows", "removing the rows of an invoice drops its fix record", "re-extracting a file with overwrite drops the fix records of its invoices"

## "แก้ไขแล้ว" คืออะไร

บิล "แก้ไขแล้ว" คือบิลที่มีบันทึกใน `job_invoice_fixes` และตอนนี้ไม่ได้รอแก้ไข PDF และไม่ได้รอแก้ไข ASN
บิลแก้ไขแล้วที่ตรงกับ ASN แสดงสถานะ "แก้ไขแล้ว" (สีน้ำเงิน ไอคอนประแจ) สถานะที่เก็บยังเป็น `MATCH`
บิลรอแก้ไข ASN ที่ปุ่มแก้ไขแก้ไฟล์ไปแล้วไม่ใช่ "แก้ไขแล้ว" (`fixed` เป็น `false` ไม่มีหมายเหตุการแก้ไข) จนกว่า ASN จะตรง แล้วจึงเป็นแก้ไขแล้ว
บิลที่แค่รอ ASN แล้ว ASN ตรง (ไม่มีบันทึกการแก้ไข) เป็น "สมบูรณ์" ไม่ใช่แก้ไขแล้ว แต่ยังบอกที่มาด้วยบรรทัด `เดิม: …` จากประวัติ (หัวหน้าทีมตัดสิน 2026-10-28 ดูหัวข้อ "ประวัติสถานะของบิล")
บิลแก้ไขแล้วที่ยังผิดพลาดแสดง "ผิดพลาด" ในตารางภาพรวม หมายเหตุของบิลนี้บอกสิ่งที่ปุ่มแก้ไขทำก่อน แล้วจึงบอกปัญหา
บิลที่ปุ่มแก้ไขแยกไฟล์อย่างเดียวนับเป็นแก้ไขแล้ว

หมายเหตุของบิลแก้ไขแล้วบอกสิ่งที่ปุ่มแก้ไขทำ ทีละหน้า:

- `แก้เลขบิลจากหน้า 2 (93247359-2 เป็น 93247359-1), ลบยอดรวมท้ายบิลจากหน้า 1, แยกออกมาจากหน้า 1-2` (`93247359-1`)
- `หน้า 2 ถูกย้ายไปเป็น 93247359-1, แยกออกมาจากหน้า 3` (`93247359-2`)
- `แยกออกมาจากหน้า 4-5` (`93247359-3`)

Job ที่แก้ไขก่อนมีตาราง `job_invoice_fix_steps` บอกได้แค่ "แยกออกมาจาก…" จากหน้าที่แถวอยู่ตอนนี้

- โค้ด: `DashboardBuilder::fixedInvoices`, `FixLog::describe`, `FixLog::PHRASES`, `displayStatus` ใน `state.js`
- ทดสอบ: `job_safety_test.php` "a fixed invoice says page by page what the fix did, and the invoice that lost a page says where it went", "an invoice fixed before steps were logged only says it was split…", `verify_incremental.py` "every invoice the fix renumbered, took a footer off and split names all three in its remark"

## ล้าง ASN ไม่ย้อนการแก้ไข

ปุ่มแก้ไขเขียนทับเลขบิลและยอดรวมท้ายบิลในแถว PDF (`job_items`) ค่าที่พิมพ์ใน PDF เดิมไม่ได้เก็บไว้
การล้างข้อมูล ASN ลบเฉพาะแถว ASN แถว PDF และบันทึกการแก้ไขยังอยู่
นำเข้า ASN เดิมแล้วเทียบใหม่ จะเห็นบิลเดิมเป็นแก้ไขแล้ว และไม่มีบิลรอแยกไฟล์ที่แยกไปแล้ว
หน้าต่างล้างข้อมูลฝั่ง ASN บอกว่า `การล้างข้อมูล ASN ไม่ย้อนการแก้ไขที่ทำกับ PDF แล้ว ถ้าต้องการเริ่มแก้ไขใหม่ ให้ล้างข้อมูล PDF แล้วดึงข้อมูลใหม่`
ไม่มีปุ่มย้อนการแก้ไข

- โค้ด: `AsnStore::deleteAsnRecords`, `PdfFixer::fixJob`
- ทดสอบ: `verify_incremental.py` "clearing the ASN after the fix, importing it again and comparing shows the same bills fixed…", `job_safety_test.php` "the clear-data modal says that clearing the ASN does not undo the fix"

## เริ่มใหม่ตั้งแต่ต้น

วิธีเดียวคือล้างข้อมูล PDF แล้วดึงข้อมูล PDF ใหม่ ต้องทำตามลำดับนี้:

1. ย้ายไฟล์ PDF ต้นฉบับ (ชื่อตามคอลัมน์ Source File) จาก `finished/` กลับไปที่ `upload here/` อย่าย้ายไฟล์ `{เลขบิล}.pdf` ที่ปุ่มแก้ไขสร้าง
2. กด "ล้างข้อมูล PDF" แล้วเลือกทุกแถว
3. กด "ดึงข้อมูลจาก upload here" แล้วเทียบและแก้ไขใหม่

ลำดับสำคัญ การล้างแถว PDF ทั้งหมดของไฟล์จะลบไฟล์ต้นฉบับนั้นใน `finished/` ด้วย
เมื่อ Job ไม่เหลือแถว PDF โปรแกรมลบ `finished/` ถ้า `upload here/`, `print/` และ `CREDIT NOTE/` ไม่มีไฟล์ด้วย โปรแกรมลบโฟลเดอร์ Job ทั้งโฟลเดอร์ และ Job หายจากรายการ
ผลนี้วัดบนโฟลเดอร์ทดสอบเมื่อ 2026-10-26: ล้างโดยไม่ย้ายไฟล์ก่อน ไฟล์ต้นฉบับและโฟลเดอร์ Job หาย ย้ายไฟล์กลับก่อน ไฟล์ยังอยู่และ Job ยังอยู่

- โค้ด: `PdfItemStore::deleteJobItems` (ลบไฟล์ชื่อเดียวกันใน `finished/` ของ Job เมื่อไฟล์นั้นไม่เหลือแถว ไม่ใช้ `job_files.file_path`), `JobFolders::removeProcessedJobFolder`
- ทดสอบ: `job_safety_test.php` "clearJobData keeps files still waiting in upload here", "clearJobData removes the folder when nothing is waiting"

อีกทางหนึ่งคือวางไฟล์ต้นฉบับชื่อเดิมใน `upload here/` แล้วดึงข้อมูลแบบเขียนทับ แถวเดิมและบันทึกการแก้ไขของไฟล์นั้นถูกแทนที่ บิลกลับไปรอแก้ไข PDF

- โค้ด: `PdfItemStore::recordExtractedFile` (`$overwrite`)
- ทดสอบ: `verify_incremental.py` "overwriting a fixed file sends it back to pending until fix is pressed again", "fixing again restores the exact fixed rows"

## การสั่งพิมพ์

บิลที่พิมพ์ได้คือบิลที่สถานะเป็น "สมบูรณ์" (รวมแก้ไขแล้ว `BillStatus::canPrint`) และยังไม่เคยพิมพ์ บิลรอแก้ไข PDF บิลรอแก้ไข ASN และบิลผิดพลาดไม่พิมพ์ หน้าต่างยืนยันบอกจำนวนที่ข้าม
แผน (`print_plan`) บอกที่ข้ามใน `skipped`: `pending_fix`, `mismatch`, `printed` และ `pending_asn` (มีคีย์นี้เฉพาะเมื่อมีบิลรอแก้ไข ASN อย่างน้อยหนึ่งบิล)
`print_start` ทำแผนใหม่ตอนกด บิลที่กลายเป็นรอแก้ไข ASN หลังเปิดหน้าต่างยืนยัน (เช่นนำเข้า VBRP ระหว่างนั้น) จึงไม่ถูกคัดลอก

- รอบใหม่อยู่ที่ `print/print ครั้งที่ N/` โดย N คือเลขรอบสูงสุดที่มีอยู่บวกหนึ่ง
- รอบสร้างในโฟลเดอร์ซ่อน `print/.tmp_{token}` แล้วเปลี่ยนชื่อเมื่อเสร็จ จึงไม่มีรอบที่ทำไม่ครบให้เห็น
- ในรอบ แยกโฟลเดอร์ตามสาขา ใช้ Dest Store No. ของ ASN ถ้าไม่มีใช้เลขสาขาใน PDF ถ้าไม่มีทั้งสองใช้ `ไม่ระบุสาขา`
- ในโฟลเดอร์สาขา ไฟล์เรียงตามเลขบิลและชื่อ `1_{เลขบิล}.pdf`, `2_{เลขบิล}.pdf`, … ไม่เติมศูนย์ข้างหน้า
- ไฟล์ของบิลมาจากไฟล์แยก `{เลขบิล}.pdf` ใน `finished/` ถ้ามี ถ้าไม่มีและไฟล์ต้นฉบับมีบิลนี้บิลเดียวที่ไม่ต้องแก้ ใช้ไฟล์ต้นฉบับ นอกนั้นตัดหน้าจากไฟล์ต้นฉบับพร้อมการแก้แบบเดียวกับปุ่มแก้ไข
- บิลที่พิมพ์แล้วดูจากไฟล์ในโฟลเดอร์รอบ ไม่ได้เก็บในฐานข้อมูล ไฟล์ชื่อเติมศูนย์ของรอบเก่ายังนับว่าพิมพ์แล้ว ลบโฟลเดอร์รอบเพื่อพิมพ์บิลเหล่านั้นใหม่ คอลัมน์ `Print` บอกเลขรอบ
- ล้างข้อมูลไม่ลบ `print/` การลบ Job เตือนจำนวนไฟล์ใน `print/`

บนข้อมูลตัวอย่างที่ไม่มี VBRP หลังกดแก้ไข รอบแรกพิมพ์ 51 บิล ใน 17 โฟลเดอร์สาขา เมื่อมี VBRP แผนก่อนกดแก้ไขพิมพ์ได้ 23 บิล (ข้ามรอแก้ไข PDF 28 รอแก้ไข ASN 321 ผิดพลาด 1) หลังกด 51 บิล (ข้ามรอแก้ไข ASN 321 ผิดพลาด 1) วัดด้วย `verify_vbrp.py` ซึ่งไม่ได้สั่งพิมพ์จริง

- โค้ด: `PrintManager::plan`, `PrintManager::start`, `PrintManager::finish`, `PrintManager::buildInvoicePdf`, `PrintManager::printedInvoices`, `PrintManager::storeFolder`, `AsnReconciler::invoiceStores`
- ทดสอบ: `verify_docs.py` "printed invoices are exactly the complete ones, each once", "files are numbered 1, 2, 3 without padding per store folder and sorted by invoice", "every file sits in the folder of its ASN Dest Store No.…", `job_safety_test.php` "deleting a round folder makes its invoices printable again", "a file of an older round with a zero padded name still counts as printed", "clearing data keeps the print folder, the job and its file count", `status_split_test.php` "a bill that turns to waiting for the ASN between the plan and the start of a print is not copied", "a print plan with no bill waiting for the ASN has no pending_asn key", `verify_vbrp.py` "the print plan counts the ready, waiting and wrong bills the grid shows, before (23 ready) and after the fix (51 ready)"

## ใบปะหน้า (`cover_sheet.php`)

ใบปะหน้า "สรุปการนำส่งเอกสารทำคืน-เข้าใหม่" ของหนึ่ง Job ใช้แบบฟอร์มของไฟล์ตัวอย่าง `Print ใบปะหน้า Promotion March 2026 จำนวน 2 ชุด.pdf` (ผู้ใช้ตัดสิน 2026-11-10)

- เอกสารบนใบ: Tax Invoice ที่สมบูรณ์ (`BillStatus::canPrint` รวมแก้ไขแล้ว) ของ Job ที่เทียบกับ ASN แล้ว และใบลดหนี้ที่สถานะ `MATCH` ของ Job ที่เทียบใบลดหนี้แล้ว ทุกใบของ Job ที่เลือก ไม่สนใจว่าเคยสั่งพิมพ์รอบใดแล้ว
- สาขา: Tax Invoice ใช้สาขาเดียวกับการสั่งพิมพ์ (`AsnReconciler::invoiceStores`) ใบลดหนี้ใช้ `store_no` ของหน้าภาพรวมใบลดหนี้ สาขาเดียวกันเมื่อเลขเท่ากันหลังตัด 0 นำหน้า เอกสารที่ไม่มีสาขาอยู่ท้ายในชื่อ `ไม่ระบุสาขา`
- เรียงสาขาจากน้อยไปมาก ในสาขาเรียง Tax Invoice ตามเลขบิล และใบลดหนี้ตามเลขใบ แยกกัน `-1` กับ `-2` เป็นคนละแถว แล้ววางสองรายการคู่กันทีละแถว
- ฝั่งที่สั้นกว่าเว้นว่างทั้งช่องเลขที่และช่องจำนวน (เช่น Tax Invoice 48 ใบลดหนี้ 37 แถว 38 ถึง 48 ฝั่งใบลดหนี้ว่าง Job ที่ไม่มีใบลดหนี้ฝั่งใบลดหนี้ว่างทุกแถว) ก่อน 2026-11-10 พิมพ์ `-` ผู้ใช้ขอให้เป็นช่องว่าง
- หน้าละ 19 แถว ทุกสาขาขึ้นหน้าใหม่ ลำดับนับต่อข้ามหน้าในสาขา "รวมทั้งหมด" อยู่หน้าสุดท้ายของสาขา นับแยกสองฝั่งไม่นับช่องว่าง
- ชื่อสาขามาจาก `assets/cover/store_names.json` ก่อน แล้วจากบรรทัดร้านของใบลดหนี้ใน Job (`5002-CENTRAL PHUKET 10121`) ไม่พบชื่อช่องว่างและหน้าเว็บเตือน
- ส่วนหัวที่แก้ได้: ผู้ติดต่อ เบอร์มือถือ ชื่องาน และวันที่ จำค่าล่าสุดไว้ใน `cover_settings` รหัสร้านค้า CDS บริษัท และเบอร์สำนักงานเป็นส่วนของแบบฟอร์ม
- ไฟล์อยู่ที่ `myfile/{Job}/COVER SHEET/ใบปะหน้า_{ปปปปดดววชชนนวว}.pdf` เวลาไทย สร้างใหม่ทุกครั้งที่กด ไม่ทับไฟล์เดิม

- โค้ด: `CoverSheet::build`, `CoverSheet::group`, `CoverSheet::pages`, `CoverSheetPdf::render`, `StoreNames`
- ทดสอบ: `cover_sheet_test.php`

## หน้าภาพรวม (Dashboard)

ทุกตัวเลขเป็นจำนวนเลขบิลใน ASN และบิลหนึ่งใช้สถานะที่แย่ที่สุดของรายการ

| ช่อง | ค่าใน API | นับอะไร |
| --- | --- | --- |
| เลขบิลทั้งหมด | `total` | เลขบิลทั้งหมดใน ASN |
| สมบูรณ์ | `complete` | บิลสมบูรณ์ที่ไม่เคยผ่านการแก้ไข |
| รอแก้ไข PDF ทั้งหมด | `needs_fix` | `not_fixed` + `fixed` ไม่ลดลงหลังกดแก้ไข |
| ยังแก้ไขไม่ได้ | `not_fixed` | บิลที่ยังรอแก้ไข PDF อยู่ตอนนี้ |
| แก้ไขแล้ว | `fixed` | บิลที่มีบันทึกการแก้ไข ไม่ได้รอแก้ไข PDF และไม่ได้รอแก้ไข ASN รวมบิลที่แก้ไขแล้วแต่ยังผิดพลาด |
| รอแก้ไข ASN | `pending_asn` | บิลรอแก้ไข ASN รวมบิลที่ปุ่มแก้ไขแก้ไฟล์ไปแล้ว (นับเฉพาะที่นี่) มีคีย์นี้เฉพาะ Job ที่มีแถว VBRP |
| ผิดพลาด | `error` | บิลผิดพลาด |
| (ใต้ช่องผิดพลาด) | `error_after_fix` | บิลผิดพลาดที่ผ่านการแก้ไขแล้ว ข้อความ `ในนี้ N เลขผ่านการแก้ไขแล้วแต่ข้อมูลยังไม่ตรงกับ ASN` |

บิลที่แก้ไขแล้วแต่ยังผิดพลาดนับทั้งใน "แก้ไขแล้ว" และ "ผิดพลาด"
แถบสีด้านบนและคำอธิบายสีใช้ "แก้ไขแล้ว" = `fixed − error_after_fix` ส่วนของแถบ (สมบูรณ์ แก้ไขแล้ว ยังแก้ไขไม่ได้ รอแก้ไข ASN ผิดพลาด) จึงรวมเป็นเลขบิลทั้งหมด
ก่อนกดเทียบกับ PDF รู้แค่เลขบิลทั้งหมด ช่องอื่นเป็น `–`

บนข้อมูลตัวอย่างหลังกดแก้ไข: ทั้งหมด 373, สมบูรณ์ 23, รอแก้ไข PDF ทั้งหมด 146 (ยังแก้ไขไม่ได้ 0, แก้ไขแล้ว 146), ผิดพลาด 322 (ในนี้ 118 แก้ไขแล้ว)
แถบสี: สมบูรณ์ 23, แก้ไขแล้ว 28, ยังแก้ไขไม่ได้ 0, ผิดพลาด 322
ก่อนกดแก้ไข "รอแก้ไข PDF ทั้งหมด" คือ 28 (บิลรอแก้ไข PDF 28 บิล และยังไม่มีบันทึกการแก้ไข) หลังกดเป็น 146 เพราะบิลผิดพลาด 118 บิลที่ปุ่มแก้ไขแยกไฟล์นับเป็นแก้ไขแล้วด้วย
ตัวเลขสามบรรทัดนี้เป็นของ Job ที่ไม่มี VBRP เมื่อมี VBRP: ก่อนกดแก้ไข สมบูรณ์ 23, รอแก้ไข PDF ทั้งหมด 28 (ยังแก้ไขไม่ได้ 28), รอแก้ไข ASN 321, ผิดพลาด 1 หลังกด สมบูรณ์ 23, รอแก้ไข PDF ทั้งหมด 28 (ยังแก้ไขไม่ได้ 0, แก้ไขแล้ว 28), รอแก้ไข ASN 321, ผิดพลาด 1

- โค้ด: `DashboardBuilder::getInvoiceDashboard`, `DashboardBuilder::fixedInvoices`, `renderDashboard` ใน `assets/js/workspace/dashboard.js`
- ทดสอบ: `job_safety_test.php` "before the first comparison only the invoice total is known…", "every count is a number of invoices…", `status_split_test.php` "the dashboard counts the bills waiting for the ASN apart…", "the grid, the dashboard, the print plan and the fix agree on every bill…", `verify_vbrp.py` "the dashboard status of every bill is the worst of its lines in the grid before and after the fix…", `verify_incremental.py` "dashboard after the second round adds the new fixes to the first ones", "the second fix leaves the complete count as it was shown before it, and the count needing a fix grows only by the fixed bills still wrong"

### ตารางสรุปรายเลขบิล

หนึ่งแถวต่อหนึ่งเลขบิลใน ASN คอลัมน์: `#`, `Dest Store No.`, `Tax Invoice No.`, `Source File`, `Total Qty`, `Total Cost Ex VAT`, `Pages`, `Status`, `Remark` ไฟล์ Excel มี `Branch Code` เพิ่มหลัง `Dest Store No.`

- `Dest Store No.` ใช้ Dest Store No. ของ ASN ถ้าไม่มีใช้เลขสาขาใน PDF
- `Total Qty` คือจำนวนชิ้นรวมใน PDF ไม่ใช่จำนวนแถว `Pages` คือจำนวนหน้า สองค่านี้นับจากแถว PDF ที่มีเลขบิลนี้ตรงตัว ถ้ายังไม่มี (เช่นหน้าที่พิมพ์เลขผิดก่อนกดแก้ไข) แสดง `–`
- `Total Cost Ex VAT` คือค่าบวกค่าแรกของ ASN ในบิลนั้น
- `Source File` คือชื่อไฟล์ PDF ต้นฉบับ ไม่ใช่ไฟล์แยก
- เรียงตาม `Dest Store No.` แล้วตาม `Tax Invoice No.` มีปุ่มกรองตามสถานะ ช่องค้นหา และ Excel ของแถวที่แสดงอยู่

- โค้ด: `DashboardBuilder::buildInvoiceRows`, `assets/js/workspace/dashboard.js`
- ทดสอบ: `verify_incremental.py` "the table has one row per ASN invoice number", "pages, pieces and branch code of every row equal what its printed PDF shows…", "the Total Cost Ex VAT of every row is the first positive Total Cost Ex VAT the ASN workbook lists…", "a split invoice names the original PDF its pages come from, never its split file", `job_safety_test.php` "the Excel file of the dashboard has its columns…", "the dashboard table keeps its exact header texts…"

## รูปแบบของ Remark

คอลัมน์ `Remark` ในตาราง ASN และตารางภาพรวมใช้รูปแบบเดียวกัน:

| สถานะ | สิ่งที่แสดง | สี |
| --- | --- | --- |
| สมบูรณ์ | `ข้อมูลถูกต้อง` ไม่มีป้าย (ตารางภาพรวมไม่แสดงอะไร) | เขียว `#15803d` |
| แก้ไขแล้ว | บันทึกการแก้ไข ไม่มีป้าย | น้ำเงิน `#1d4ed8` |
| รอแก้ไข PDF | สองบรรทัด `ปัญหา : …` และ `วิธีแก้ไข : …` | เหลืองอำพัน `#b45309` |
| รอแก้ไข ASN | สองบรรทัด `ปัญหา : ASN …` และ `วิธีแก้ไข : แก้ข้อมูลใน ASN แล้วนำเข้า ASN ใหม่` | ม่วง (`--status-pending-asn-color` ใน `app.css` วันนี้ `#7e22ce`) |
| ผิดพลาด | บรรทัดเดียว `ปัญหา : …` ไม่มีวิธีแก้ไข | แดง `#b91c1c` |

- ป้ายเขียนตรงตามนี้: `ปัญหา : ` และ `วิธีแก้ไข : `
- สีแต่ละสถานะกำหนดที่เดียวใน `app.css` เป็น `--status-match-color`, `--status-fixed-color`, `--status-pending-fix-color`, `--status-mismatch-color` ป้ายสถานะ ปุ่มกรอง และข้อความ Remark อ่านค่าจากตรงนี้
- หัวข้อคั่นด้วย `, ` หน้าในหัวข้อเดียวกันใช้ `และ` ไม่ใช้จุลภาค ช่วงหน้าเขียน `1-3` ในบันทึกการแก้ไข และ `1 ถึง 3` ในวิธีแก้ไข
- ไม่ใช้จุดกลาง (·) ไม่ใช้คำว่า "footer" ใช้ "ยอดรวมท้ายบิล"
- ไม่มีคำนำหน้า `แก้ไขแล้ว:` และไม่ใส่ชื่อไฟล์ (มีคอลัมน์ `Source File` แล้ว) ยกเว้นบิลที่หน้าอยู่หลายไฟล์ ซึ่งต้องบอกไฟล์ของหน้า (`หน้า 1-2 ของ a.pdf และ หน้า 1 ของ b.pdf`)
- ปัญหาบอกสิ่งที่ผิดจริง ไม่เขียนแค่ให้กดปุ่ม
- ตารางภาพรวมแสดงได้ 3 ปัญหา ที่เหลือเป็น `และอีก N ประเด็น` ปัญหาที่มีรายการเดียวในบิลที่ผิดพลาดได้ `บาร์โค้ด X: ` นำหน้า
- Excel ของหน้า ASN แยก Remark เป็นคอลัมน์ `Problem` และ `Solution` ไม่มีป้าย

- โค้ด: `REMARK_LABELS`, `reconRemark`, `remarkLines` ใน `state.js`, `FixLog::TOPICS`, `FixLog::pagesText`, `FixLog::PHRASES`, `FixLog::PLANNED`, `DashboardBuilder::buildInvoiceRows`, `:root` ใน `assets/css/app.css`
- ทดสอบ: `job_safety_test.php` "every Remark line, label included, is in the colour of its status in both tables…", "each status colour is defined once in app.css…", "a remark separates its topics with a comma and joins the pages of one topic with "และ"…", "the Excel file of the ASN page has the columns of the grid in its order, Previous status after Status, Problem and Solution in place of the Remark…", `verify_incremental.py` "no remark, reason or action separates its topics with a middle dot or says "footer"", "every invoice that is not complete says its problem; a waiting one its solution too, a wrong one none…"

## บิลซ้ำ และไฟล์ ASN ซ้ำ

- ไฟล์ PDF ที่มีบิล (เลขชุดก่อน `-N`) ซึ่ง Job มีอยู่แล้วจากไฟล์ชื่ออื่น ถูกเตือนตอนสแกน เลือกข้ามหรือแทนที่ไฟล์เดิมได้ (`replace_duplicates`)
- ไฟล์ ASN ที่มีเลขบิลซึ่งไฟล์ ASN อื่นใน Job มีอยู่แล้ว เลือกข้ามหรือแทนที่ได้ (`duplicate_mode`)
- นำเข้าไฟล์ ASN ชื่อและชีตเดิมซ้ำ โปรแกรมถามก่อน ข้ามคือไม่เปลี่ยนอะไร เขียนทับคือแทนแถวเดิม และถ้าเคยเทียบแล้วจะเทียบใหม่ให้ (`same_file_mode`)

- โค้ด: `PdfItemStore::scanSourceFiles`, `api.php` action `extract_job_batch` และ `asn_overlap`, `AsnStore::saveAsnRecords`, `AsnStore::describeStoredAsnFile`
- ทดสอบ: `job_safety_test.php` "scan flags a file that carries a bill already in the job under another name…", "skipping a file imported before changes nothing and keeps the comparison", `verify_incremental.py` "the same ASN file again is recognised as imported, identical and already compared", "replacing swaps the earlier file for the copy without doubling rows"

## ลบ Job

ปุ่มลบเปิดหน้าต่างที่แสดงสิ่งที่จะหายไปก่อน (จำนวนไฟล์และแถว PDF, ไฟล์ใน `TAX INVOICE/upload here`, ไฟล์และแถว ASN, ไฟล์ที่พิมพ์และจำนวนรอบ, บิลที่แก้ไขแล้ว และเมื่อมี ไฟล์และใบลดหนี้ กับแถว ASN ของใบลดหนี้ `cn_asn_rows`) คำตอบมี `vbrp_rows` (แถว VBRP) ด้วย แต่หน้าต่างใน `assets/js/index.js` ยังไม่แสดงบรรทัดนี้
ต้องพิมพ์ชื่อ Job ให้ตรงทุกตัวอักษร และปุ่มเปิดหลังรอ 3 วินาที API ตรวจชื่อซ้ำอีกครั้ง ถ้าไม่ตรงไม่ลบอะไร

- โค้ด: `JobManager::deleteSummary`, `api.php` action `delete_job` (`confirm_name`), `DELETE_JOB_WAIT_MS` ใน `assets/js/index.js`
- ทดสอบ: `job_safety_test.php` "the delete summary counts what the Job holds…", "deleting a Job without its exact name, or with another name, deletes nothing and answers a Thai error", "the Job list page renders the delete modal with the field for the Job name and its button disabled with the countdown"

## แถบสถานะของหน้า Job

แถบล่างของ `workspace.php` แสดง `PDF: N ไฟล์ | รายการ: N รายการ | ASN: N ไฟล์ | รายการ: N รายการ` ไม่มียอดเงินรวม
ฝั่ง PDF นับแถวที่ตาราง PDF แสดงอยู่ รวมผลค้นหา ฝั่ง ASN ใช้ `asn_counts` ที่ API ส่งมากับทุกคำตอบที่เปลี่ยนแถว ASN

- โค้ด: `JobManager::statusCounts`, `AsnStore::asnCounts`, `setStatusBarPdf` ใน `state.js`
- ทดสอบ: `job_safety_test.php` "the status bar of the Job page is rendered with its PDF files and rows and its ASN files and rows, no total amount", `verify_incremental.py` "the status bar of the Job page shows every PDF file and row and both ASN files with every ASN row…"

## VBRP: แหล่งที่สามของการเทียบ Tax Invoice (แท็บ 2 และ 3 ของ `workspace.php`)

ตั้งแต่ 2026-10-28 บิลที่ VBRP มีข้อมูล เทียบสามแหล่ง: PDF, ASN และ VBRP PDF ที่ผิดทำให้รายการผิดพลาด ASN ที่ผิดทำให้บิลรอแก้ไข ASN (หัวข้อ "รอแก้ไข ASN") VBRP ที่ผิดเป็นหมายเหตุ
กติกาแรกของวันเดียวกันที่ให้ ASN ผิดเป็นแค่หมายเหตุและสถานะตาม PDF อย่างเดียว ถูกแทนแล้วเมื่อ 2026-10-28 (ผู้ใช้ตอบ "ใช่ทั้งหมด" ดู DECISIONS.md)
ตัวเลขตัวอย่างในหัวข้อนี้วัดด้วย `verify_vbrp.py` บน PDF 290 ไฟล์ ชีต ASN `CDS ZFP (ZKE) done 13.10.2026` (1,890 แถว 373 บิล) และไฟล์ `Report VBRP_SIS PROMOTION ZFP Recheck K.Big.xlsx` (1,893 แถว 373 บิล) เมื่อ 2026-10-28

### นำเข้าไฟล์ VBRP

ที่แท็บ 2 กดปุ่มเลือกไฟล์ VBRP (`.xlsx`) หน้าเว็บส่งไฟล์ไปที่ action `job_vbrp_import` โปรแกรมอ่านตรงจากไฟล์ชั่วคราว ไม่เก็บไฟล์ และไม่สร้างโฟลเดอร์ `VBRP` (`job_vbrp_files.file_path` เป็น `''`)

- อ่านเฉพาะชีตที่แถวที่ 3 มีป้ายตามนี้ (ตัดช่องว่าง ไม่สนตัวพิมพ์เล็กใหญ่) ชีตอื่นอยู่ใน `ignored_sheets` ไม่มีชีตใดตรง ตอบ `ไม่ใช่ไฟล์ VBRP (ช่อง C3 ควรเป็น "Bill" แต่เป็น "...")` (ช่องแรกที่ไม่ตรงของชีตแรก ตรวจ C ก่อน)

| คอลัมน์ | ป้ายแถวที่ 3 | ช่องในระบบ |
| --- | --- | --- |
| `A` | Combine | `combine` |
| `C` | Bill | `invoice` |
| `E` | Bill. Doc. | `delivery_no` |
| `H` | Billed Quantity | `quantity` |
| `CI` | Subtotal 2 | `selling_price` |
| `CJ` | Subtotal 3 | `subtotal_3` |
| `CK` | Subtotal 4 | `total_cost_ex_vat` |
| `CQ` | EAN/UPC | `ean_upc` |
| `EM` | Tax Amount | `total_vat` |

- ข้อมูลเริ่มแถวที่ 4 แถวของเลขบิลที่ไฟล์ใหม่มี และแถวของไฟล์ชื่อเดียวกัน ถูกลบก่อนแล้วจึงเก็บแถวใหม่ ในธุรกรรมเดียว ไม่มีอะไรค้างครึ่งทาง
- ไม่รับไฟล์ที่ไม่ใช่ `.xlsx` (`รับเฉพาะไฟล์ Excel (.xlsx)`) ไฟล์เสีย และไฟล์ที่ชีตตรงแต่ไม่มีแถวข้อมูล (`ไฟล์นี้ไม่มีแถวข้อมูล VBRP ตั้งแต่แถวที่ 4`)
- ไฟล์ที่ใหญ่เกิน `upload_max_filesize` หรือ `post_max_size` ใน php.ini ตอบ `ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์รับได้ (ไฟล์ประมาณ x MB แต่ php.ini ตั้ง upload_max_filesize = ... และ post_max_size = ...) ...` ไม่ใช่ "ไม่พบ Job นี้" ไฟล์ตัวอย่างขนาด 2.3 MB (2,406,206 ไบต์) ค่าเริ่มต้นของ PHP คือ `upload_max_filesize = 2M` ซึ่งไม่รับไฟล์นี้ (PHP 7.3.33 ที่ใช้ทดสอบตั้งไว้แบบนั้น) เครื่องที่ใช้งานต้องตั้งให้มากกว่าขนาดไฟล์ VBRP
- คำตอบ: `filename`, `rows`, `invoices`, `replaced_invoices`, `replaced_rows`, `ignored_sheets`, `vbrp_counts` (`files`, `rows`, `invoices`)
- ขั้นของแถบเครื่องมือ: มี ASN แต่ยังไม่มีแถว VBRP และยังไม่เคยเทียบ คือขั้น `vbrp` Job ที่เทียบแล้วเปิดผลเดิมได้เสมอ ถ้าไม่มีแถว VBRP ผลเป็นการเทียบสองแหล่งแบบเดิมทุกไบต์
- การล้างข้อมูล Job (`clearJobData`) ลบแถว VBRP ด้วย สรุปก่อนลบ Job มี `vbrp_rows`

- โค้ด: `VbrpStore::importUpload`, `VbrpStore::LABELS`, `VbrpStore::sizeRefusal`, `VbrpStore::counts`, `JobRepository::initialAsnStep`, `JobManager::statusCounts`, `JobManager::clearJobData`, `api.php` action `job_vbrp_import`
- ทดสอบ: `vbrp_compare_test.php` "importing reads the sheets whose row 3 has the VBRP labels…", "a file that is not a VBRP report is refused…", "the import never makes a VBRP folder", "job_vbrp_import takes the file the page posts as multipart…", "a file over upload_max_filesize, and a request over post_max_size…", `verify_vbrp.py` "the real VBRP file is imported whole…", "the ASN workbook offered as a VBRP file is refused naming cell C3…"

### แถวที่ไม่คำนวณ (Subtotal 3)

แถวที่ Subtotal 3 (คอลัมน์ `CJ`) ตั้งแต่ -1 ขึ้นไป (`>= -1`) ไม่คำนวณเลย ไม่นับจำนวน ราคา หรือยอด นับเฉพาะแถวที่ต่ำกว่า -1 แถวที่ไม่มีค่า (แถวที่เก็บก่อนมีคอลัมน์นี้) นับ
ไฟล์ตัวอย่างมีสามแถวที่ Subtotal 3 เป็น 0 (แถว 1873, 1875, 1877 บิล `93252926-2`) เป็นแถวซ้ำที่ยังไม่หักส่วนลด ตัดออกแล้วแถว VBRP เท่ากับแถว ASN
บิลที่มีแถวที่นับอย่างน้อยหนึ่งแถวคือบิลที่ VBRP **ครอบคลุม** บนไฟล์ตัวอย่างครอบคลุมทั้ง 373 บิล

- โค้ด: `VbrpFigures::SKIP_FROM`, `VbrpFigures::fromRows`
- ทดสอบ: `vbrp_compare_test.php` "only rows with Subtotal 3 below -1 count…", `verify_vbrp.py` seeded changes "one counted VBRP row given Subtotal 3 -1…", "a VBRP row of Subtotal 3 0 given -1.01…"

### ใครผิด

แต่ละข้อตัดสินแยกกัน แหล่งหนึ่ง "ตรง" เมื่อเท่ากับแหล่งอื่นที่มีค่าอย่างน้อยหนึ่งแหล่ง (ภายในค่าความคลาดเคลื่อนของข้อนั้น เทียบเป็นสตางค์) ไม่เท่ากับแหล่งใดเลยคือ "ไม่ตรง" ตัดสินทีละคู่ 100 / 101 / 102 ที่ค่าความคลาดเคลื่อน 1.00 จึงตรงทั้งสาม
มีค่าแค่สองแหล่งและไม่เท่ากัน: ลำดับอ้างอิงคือ VBRP, ASN, PDF แหล่งที่ต่ำกว่าไม่ตรง มีค่าน้อยกว่าสองแหล่ง ไม่ตัดสิน (`na`) สามแหล่งต่างกันหมด ไม่ตรงทั้งสาม

| ข้อ | PDF | ASN | VBRP | ค่าความคลาดเคลื่อน |
| --- | --- | --- | --- | --- |
| จำนวน (`qty`) | ชิ้นของรายการหลังแผนแก้ไข รายการที่ PDF ไม่มีคือ 0 | Quantity ที่รวมแล้ว | ผลรวม `H` ของบาร์โค้ดในบิล ไม่มีคือ 0 | ตรงตัว |
| ยอดก่อน VAT (`amount`) | Amt Excl VAT ท้ายบิล | Total Cost Ex VAT | ผลรวม `CK` ของบิล | 1.00 |
| VAT (`vat`) | VAT ท้ายบิล | Total VAT | ผลรวม `EM` ของบิล | 0.15 |

ยอดเงินที่ไม่เป็นบวกถือว่าไม่มีค่า รายการที่ PDF พิมพ์ภายใต้เลขบิลอื่นในชุดเดียวกัน (กรณี "เลขที่พิมพ์ในบิล PDF คือ X") ไม่มีค่าฝั่ง PDF
ราคาขายไม่ได้เทียบ (ผู้ใช้ตัดสิน 2026-10-28: PDF ไม่พิมพ์ราคาขายต่อรายการ มีแค่ RSP จึงเทียบได้แค่ ASN กับ VBRP และผู้ใช้ไม่ต้องการ) ช่อง Sell Price (VBRP) แสดง `CI` ต่ำสุดของบาร์โค้ดในบิลให้ดูเท่านั้น ไม่มีสีและไม่มีในคำตัดสิน

- โค้ด: `SourceVerdict::judge`, `SourceVerdict::CHECKS` (ค่าความคลาดเคลื่อนอ่านจาก `AsnReconciler::AMOUNT_TOLERANCE`, `VAT_TOLERANCE`), `AsnReconciler::reconcile` (ขั้น 3c)
- ทดสอบ: `vbrp_compare_test.php` "judge: a source equal to another present source…", "the checks are the user's three with the tolerances of AsnReconciler…", "a PDF equal to the ASN is complete when VBRP differs … a selling price ฿50 apart is shown, not compared", `verify_vbrp.py` seeded change "one ASN Selling Price 5.00 higher, a figure no check compares, so nothing changes"

### สถานะตาม PDF แล้วตาม ASN

ในบิลที่ VBRP ครอบคลุม รายการเป็น "ผิดพลาด" เมื่อมีข้อใดตัดสินว่า PDF ไม่ตรง หรือมีปัญหาของ PDF เอง (สาขา บาร์โค้ดที่มีแต่ใน PDF ยอดรวมท้ายบิลที่ไม่ใช่ของบิลนี้) แม้ ASN จะไม่ตรงด้วย (เช่น `93251539-1`)
รายการที่ PDF ไม่ผิด ตัดสินแบบเดิมเป็นสมบูรณ์หรือรอแก้ไข PDF (แผนแก้ไขยังมาจากแถว ASN) แล้วถ้า ASN ของบิลผิด เป็นรอแก้ไข ASN (หัวข้อถัดไป)
กติกาเดิม "สมบูรณ์แม้ ASN จะไม่ตรง เหตุผลและวิธีแก้เป็น `ข้อมูลถูกต้อง`" ใช้ตั้งแต่ 2026-10-28 จนแยกสถานะในวันเดียวกัน แทนแล้ว
PDF ที่ไม่ตรงบอกทั้งสามค่า (ค่าที่ไม่มีเขียน `-`) ลำดับและคำอื่นเหมือนเดิม:

- `จำนวนไม่ตรง (PDF: 4 ชิ้น / ASN: 2 ชิ้น / VBRP: 2 ชิ้น)`
- `ยอดรวมไม่ตรง (PDF: 22,594.56 / ASN: 31,364.53 / VBRP: 18,602.26)`
- `VAT ไม่ตรง (PDF: ฿1,581.60 / ASN: ฿1,302.17 / VBRP: ฿1,302.17)`

กรณีพิเศษในบิลที่ครอบคลุม:

- Selling Price ติดลบใน ASN ไม่ทำให้รายการผิดและไม่เป็นหมายเหตุ (ราคาขายไม่ได้เทียบ)
- บาร์โค้ดที่ PDF พิมพ์แต่ ASN ไม่มี ถ้า VBRP มีบาร์โค้ดนั้นด้วยจำนวนเท่ากับ PDF ไม่ใช่ปัญหาของ PDF เป็นความผิดของ ASN: บิลรอแก้ไข ASN ปัญหา `ASN ไม่มีบาร์โค้ด X (n ชิ้น) ที่ PDF และ VBRP มี` ถ้า VBRP ไม่มีหรือจำนวนต่าง ยังเป็น `บาร์โค้ด X มีใน PDF แต่ไม่มีใน ASN` ตามเดิม
- รายการ ASN ที่ PDF ไม่มี: ถ้า VBRP มี ยังผิดพลาด `มีรายการในไฟล์ ASN แต่ไม่พบไฟล์ PDF ของบิลนี้ …` ถ้า VBRP ไม่มีด้วย PDF ไม่ผิด รายการตัดสินเป็นรายการ 0 ชิ้น ข้อจำนวนตัดสินว่า ASN ไม่ตรง (PDF 0 = VBRP 0) บิลจึงรอแก้ไข ASN (ถ้าไม่ผิดพลาดด้วยเรื่องอื่น)
- รายการที่ถูกเองในบิลที่ผิด เขียน `รายการนี้ถูกต้อง แต่บิลผิดพลาดเพราะบาร์โค้ด …` เฉพาะเมื่อบาร์โค้ดที่ชี้เป็นรายการที่ PDF ผิดเรื่องจำนวน

`amount_ok` ของรายการยังเป็นผลของ PDF กับ ASN (สีช่อง Cost Ex VAT ของ ASN) ทุกกรณี
บิลที่ VBRP ไม่ครอบคลุมใน Job ที่มี VBRP ผิดพลาด (VBRP เป็นข้อมูลหลัก ผู้ใช้ตัดสิน 2026-11-10) ทุกรายการของบิลได้ `status` `MISMATCH`, `can_fix` `false`, `action` ว่าง
`reason` ขึ้นต้นด้วย `ไม่พบใน VBRP` แล้วตามด้วยปัญหาของ PDF กับ ASN ถ้ามี คั่นด้วย `, ` เช่น `ไม่พบใน VBRP, ยอดรวมไม่ตรง (PDF: 300.00 / ASN: 500.00)` รายการที่ PDF กับ ASN ตรงกันได้ `ไม่พบใน VBRP` อย่างเดียว
`sources` เป็นคำตัดสินสองแหล่ง (PDF กับ ASN) ช่อง VBRP เป็น `na` ไม่มีหมายเหตุ ปุ่มแก้ไขไม่แก้ สั่งพิมพ์ไม่ได้ และไม่ขึ้นใบปะหน้าจนกว่า VBRP จะมีบิลนี้
Job ที่ไม่มีแถว VBRP เลยตัดสินสองแหล่งแบบเดิม ไม่มีบิลใดผิดเพราะข้อนี้

- โค้ด: `AsnReconciler::reconcile` (ขั้น 3b, 3c, ตัวแปร `$culprits`, `$absentRightly`), `SourceVerdict::problem`, `SourceVerdict::ASN_LACKS`, `SourceVerdict::ASN_ONLY`
- ทดสอบ: `vbrp_compare_test.php` "a PDF wrong against ASN and VBRP that agree is wrong; its problems name the three figures…", "a PDF equal to the ASN is complete when VBRP differs…", "a bill VBRP does not cover … is marked MISMATCH (ไม่พบใน VBRP)…", "a barcode the PDF prints with the pieces VBRP lists, which the ASN lacks, is no PDF problem but an ASN fault of the bill…", "an ASN line the PDF lacks while VBRP bills it stays wrong…", `status_split_test.php` "a PDF wrong while the ASN is wrong too stays wrong…", "a bill VBRP does not cover is wrong (VBRP is the reference)…", `verify_vbrp.py` "once the fix has worked on every file every line has the status, the problem…", seeded change "one ASN line taken out while the PDF and VBRP still bill it, so the bill waits for the ASN"

### รอแก้ไข ASN

ผู้ใช้ตัดสิน 2026-10-28 ("ใช่ทั้งหมด" ตอบคำถาม 7 ข้อ): ASN ที่ผิดไม่ใช่แค่หมายเหตุอีกต่อไป บิลที่ VBRP ครอบคลุมและ ASN ไม่ตรง รอแก้ไข ASN แม้ PDF จะตรงกับ VBRP
"ASN ไม่ตรง" คือมีรายการหนึ่งของบิลที่ข้อใดข้อหนึ่งตัดสินว่า ASN ไม่ตรง (`sources.overall.asn` เป็น `wrong`: ยอดก่อน VAT จำนวน หรือ VAT) หรือบิลมีบาร์โค้ดที่ PDF และ VBRP มีแต่ ASN ไม่มี (`SourceVerdict::ASN_LACKS`)

- ทุกรายการของบิลที่เป็นสมบูรณ์หรือรอแก้ไข PDF กลายเป็น `PENDING_ASN` (บิลพิมพ์ไม่ได้ ทุกรายการจึงแสดงสถานะเดียวกัน) รายการผิดพลาดยังผิดพลาด สถานะของบิลจึงเป็นผิดพลาดถ้ามีรายการผิดพลาด
- `status_label` `รอแก้ไข ASN`, `can_fix` `false`, `culprit` `''`, `amount_ok` ไม่เปลี่ยน (ยังเป็นผล PDF กับ ASN)
- `reason` คือปัญหาของ ASN ทั้งบิล ไม่ซ้ำ เรียงตาม จำนวน (ทีละรายการตามลำดับของ ASN) ยอดก่อน VAT VAT แล้วตามด้วยบาร์โค้ดที่ ASN ไม่มี คั่นด้วย `, ` แต่ละข้อเขียน `ASN <หัวข้อ> ไม่ตรง (ASN <ค่า> / PDF <ค่า> = VBRP <ค่า>)` ถ้า PDF กับ VBRP ตรงกันภายในค่าความคลาดเคลื่อนของข้อนั้นคั่นด้วย ` = ` ถ้าไม่ตรงคั่นด้วย ` / ` แหล่งที่ไม่มีค่าไม่เขียน ข้อจำนวนของบิลที่มีหลายรายการมี `บาร์โค้ด X: ` นำหน้า เช่น
  - `ASN ยอดก่อน VAT ไม่ตรง (ASN 13,028.05 / PDF 7,504.15 = VBRP 7,504.15)` (`93248362-1`)
  - `บาร์โค้ด 8850000000061: ASN จำนวน ไม่ตรง (ASN 3 ชิ้น / PDF 2 ชิ้น = VBRP 2 ชิ้น)`
  - `ASN ไม่มีบาร์โค้ด 8850000000079 (2 ชิ้น) ที่ PDF และ VBRP มี`
- `action` คือ `แก้ข้อมูลใน ASN แล้วนำเข้า ASN ใหม่`
- `sources.notes` ไม่มีหมายเหตุของข้อที่ปัญหาบอกแล้ว (ข้อที่ ASN ของรายการนั้นไม่ตรง และหมายเหตุบาร์โค้ดที่ ASN ไม่มี) แบบเดียวกับข้อที่ PDF ไม่ตรง รายการที่ก่อนนั้นรอแก้ไข PDF ได้หมายเหตุท้ายสุด `หลังแก้ ASN แล้วบิลนี้ยังต้องแก้ไข PDF: <ปัญหาที่รายการรอแก้ไข PDF จะแสดง>` เช่น `หลังแก้ ASN แล้วบิลนี้ยังต้องแก้ไข PDF: ไฟล์ต้นฉบับมีหลายบิล บิลนี้อยู่หน้าที่ 1` (`93248505-1`)
- `sources.overall.pdf` ใช้กติกาเดิมกับสถานะก่อนเป็นรอแก้ไข ASN จึงไม่ใช่ ✗ (รายการที่แค่รอ ไม่ใช่ PDF ผิด)
- `summary.pending_asn` คือจำนวนรายการรอแก้ไข ASN มีคีย์นี้เฉพาะ Job ที่มีแถว VBRP ตัวนับของ Job (`asn_matched_count`, `asn_pending_fix_count`, `asn_mismatch_count`) ไม่นับรายการรอแก้ไข ASN
- บิลที่ VBRP ไม่ครอบคลุมผิดพลาด (`ไม่พบใน VBRP` หัวข้อ "ใครผิด") ไม่มีรอแก้ไข ASN Job ที่ไม่มีแถว VBRP ตัดสินสองแหล่งแบบเดิม ASN เป็นข้อมูลหลัก
- ปุ่มแก้ไขไม่แก้บิลนี้ และไม่พิมพ์ (หัวข้อ "ปุ่มแก้ไขทำอะไร" และ "การสั่งพิมพ์")
- เมื่อแก้ ASN แล้ว (นำเข้า ASN ใหม่ หรือลบแถวที่ผิด) การโหลดหน้าและการเทียบตามปกติตัดสินบิลใหม่ เป็นสมบูรณ์หรือรอแก้ไข PDF ไม่มีปุ่มพิเศษ

- โค้ด: `AsnReconciler::waitForAsn`, `AsnReconciler::FIX_ASN`, `AsnReconciler::STILL_PDF`, `SourceVerdict::asnWrong`, `SourceVerdict::asnProblem`, `SourceVerdict::notes` (พารามิเตอร์ `$named`)
- ทดสอบ: `status_split_test.php` "ASN wrong while the PDF equals VBRP: the bill waits for the ASN…", "a bill that waits for the fix button and whose ASN is wrong waits for the ASN, and its note says…", "a quantity the ASN alone gets wrong makes every line of the bill wait for the ASN…", "a barcode the PDF and VBRP have and the ASN lacks makes the bill wait for the ASN…", "a bill whose ASN is right is untouched…", "the summary counts the lines waiting for the ASN, and the Job counters leave them out", "without VBRP rows there is no PENDING_ASN…", `vbrp_compare_test.php` "a bill whose ASN amount is wrong while the PDF equals VBRP waits for the ASN on every line…", `verify_vbrp.py` "a bill waiting for the ASN whose pages the plan would still change says so in one last note…", seeded changes "one VBRP Subtotal 4 1.01 higher on a bill waiting for the ASN, so no source agrees on the amount and the bill is wrong", "the ASN Total Cost Ex VAT of one bill waiting for the ASN made equal to its PDF, so it leaves รอแก้ไข ASN and is complete"

### หมายเหตุ และ `sources` ของรายการ

ทุกรายการของบิลที่ครอบคลุมมี `sources`: ค่าของ VBRP (`vbrp`), คำตัดสินทุกข้อ (`verdict`), คำตัดสินของแต่ละแหล่งทั้งรายการ (`overall`) และหมายเหตุ (`notes`)
`overall` ของ ASN และ VBRP คือ ไม่ตรงถ้ามีข้อใดไม่ตรง ไม่อย่างนั้นตรงถ้ามีข้อใดตรง ไม่อย่างนั้น `na` ของ PDF ไม่ตรงเมื่อมีข้อใดตัดสินว่า PDF ไม่ตรง หรือรายการผิดพลาด (สาขา บาร์โค้ด ยอดรวมท้ายบิล รายการที่ PDF ไม่มี) รายการที่แค่รอแก้ไข PDF หรือรอแก้ไข ASN ไม่ถูกนับว่าไม่ตรง เพราะแผนแก้ไขตัดสินตัวเลขในสภาพที่ปุ่มแก้ไขจะจัดให้แล้ว สิ่งที่รอคือหน้าของบิลหรือ ASN สถานะบอกเรื่องนั้นอยู่แล้ว (แก้เมื่อ 2026-10-28 หลังผู้ใช้ถามว่าทำไมแถวรอแก้ไขที่ตัวเลขถูกได้ ✗ ในช่อง PDF)
- ทดสอบ: `vbrp_compare_test.php` "a line that only waits for the fix button … is not wrong in its PDF…", `verify_vbrp.py` "…the PDF over the line is wrong exactly when the line is wrong or a check judges the PDF wrong, never merely because the line waits"
หมายเหตุหนึ่งประโยคต่อข้อที่ ASN หรือ VBRP ไม่ตรง เรียงตาม จำนวน ยอดก่อน VAT VAT แล้วตามด้วยหมายเหตุของบิล ในวงเล็บมีเฉพาะแหล่งที่มีค่า:
`ASN ไม่ตรง: ยอดก่อน VAT 9,953.29 (PDF: 5,877.10 / VBRP: 5,877.10)`, `VBRP ไม่ตรง: จำนวน 2 ชิ้น (PDF: 1 ชิ้น / ASN: 1 ชิ้น)`
ข้อที่ตัดสินว่า PDF ไม่ตรงไม่มีหมายเหตุ เพราะปัญหาของ PDF ข้อนั้นบอกทั้งสามค่าแล้ว (ผู้ใช้ตัดสิน 2026-10-28 หลังเห็นบิล `93251539-1` ที่ช่อง Remark บอกยอดสามค่าซ้ำสามครั้ง) ช่อง ASN และ VBRP ยังเป็น ✗ ตามคำตัดสิน
เช่นยอดก่อน VAT ที่ต่างกันทั้งสามแหล่ง แสดงแค่ `ยอดรวมไม่ตรง (PDF: 22,594.56 / ASN: 31,364.53 / VBRP: 18,602.26)` ไม่มี `ASN ไม่ตรง: ยอดก่อน VAT …` และ `VBRP ไม่ตรง: ยอดก่อน VAT …` ตามมา ส่วนข้อที่ PDF ตรง (หรือไม่ได้ตัดสิน) ในรายการเดียวกันยังมีหมายเหตุ หมายเหตุบาร์โค้ด (`ASN ไม่มีบาร์โค้ด …`, `ASN มีบาร์โค้ดนี้แต่ PDF และ VBRP ไม่มี`) เหมือนเดิม

- โค้ด: `SourceVerdict::notes`, `SourceVerdict::payload`
- ทดสอบ: `vbrp_compare_test.php` "the line carries the VBRP figures, its selling price among them…", "a check that judges the PDF wrong gives no note…", `verify_vbrp.py` "once the fix has worked on every file every line has…"

### หน้าภาพรวมกับ VBRP

แต่ละบิลได้ `verdict` (คำตัดสินที่แย่ที่สุดของแต่ละแหล่งในทุกรายการ ไม่ตรง แย่กว่า ตรง แย่กว่า `na` บิลที่ไม่ครอบคลุมได้คำตัดสินสองแหล่งของ PDF กับ ASN และ VBRP เป็น `na`), `vbrp` (`cost`, `vat`, `lines` หรือ `null`) และ `notes` (หมายเหตุของรายการ ไม่ซ้ำ หมายเหตุที่มีรายการเดียวในบิลที่มีหลายรายการได้ `บาร์โค้ด X: ` นำหน้า แสดงได้ 3 ข้อ ข้อที่สามต่อด้วย ` และอีก N ประเด็น`)
คำตอบมี `verdict_counts` (จำนวนบิลต่อแหล่งต่อคำตัดสิน) เป็น `null` เมื่อมีแถว VBRP แต่ไม่ครอบคลุมบิลใด และ `pending_asn` (จำนวนบิลรอแก้ไข ASN) Job ที่ไม่มีแถว VBRP ไม่มีคีย์ใหม่เหล่านี้เลย
แถวของบิลรอแก้ไข ASN ได้ `problem` เป็นปัญหาของ ASN และ `solution` เป็น `แก้ข้อมูลใน ASN แล้วนำเข้า ASN ใหม่` แบบเดียวกับสถานะอื่น (`fixed` เป็น `false` แม้ปุ่มแก้ไขแก้ไฟล์ไปแล้ว)

- โค้ด: `DashboardBuilder::sourceColumns`, `DashboardBuilder::verdictCounts`, `DashboardBuilder::buildInvoiceRows`
- ทดสอบ: `vbrp_compare_test.php` "each bill of the dashboard has the worst verdict of each source…", "the dashboard shows three notes of a bill…", "with VBRP rows that cover none of its bills…", `verify_vbrp.py` "once every file is fixed every dashboard row has the status, the worst verdict of each source…"

### ผลบนข้อมูลตัวอย่าง

| | สมบูรณ์ | รอแก้ไข PDF | รอแก้ไข ASN | ผิดพลาด |
| --- | --- | --- | --- | --- |
| บิล ก่อนกดแก้ไข ไม่มี VBRP | 23 | 28 | - | 322 |
| บิล ก่อนกดแก้ไข มี VBRP | 23 | 28 | 321 | 1 |
| บิล หลังกดแก้ไข มี VBRP | 51 (23 + แก้ไขแล้ว 28) | 0 | 321 | 1 |
| รายการ ก่อนกดแก้ไข มี VBRP | 131 | 200 | 1,404 | 10 |
| รายการ หลังกดแก้ไข มี VBRP | 331 | 0 | 1,404 | 10 |

ก่อนแยกสถานะ (กติกาแรกของ 2026-10-28) บิลเมื่อมี VBRP เป็น สมบูรณ์ 227 รอแก้ไข 145 ผิดพลาด 1 บิล 322 บิลที่ ASN ไม่ตรง (เฉพาะยอดก่อน VAT ทุกบิล 1,414 รายการ) คือ สมบูรณ์ 204 + รอแก้ไข 117 + ผิดพลาด 1 บิลผิดพลาดนั้นยังผิดพลาด อีก 321 บิลเป็นรอแก้ไข ASN 117 บิลในนั้นมีหมายเหตุ `หลังแก้ ASN แล้วบิลนี้ยังต้องแก้ไข PDF: …`
ปุ่มแก้ไขไม่แตะ 255 จาก 290 ไฟล์ ไฟล์เหล่านี้ไม่มีบิลสมบูรณ์หรือรอแก้ไข PDF (10 ไฟล์ในนั้นมีหน้าที่แผนจะแก้) อีก 35 ไฟล์มีแถวตามแผนแก้ไข บนข้อมูลตัวอย่างไม่มีไฟล์ใดที่มีทั้งบิลรอแก้ไข PDF และบิลรอแก้ไข ASN หลังกดแก้ไข 117 บิลยังมีหมายเหตุนั้น
บิลที่ยังผิดคือ `93251539-1` (PDF 4 ชิ้น ASN และ VBRP 2 ชิ้น ของบาร์โค้ด `5401221426009` และยอดก่อน VAT ต่างกันทั้งสามแหล่ง จึงไม่มีหมายเหตุ) หน้าภาพรวมบอก PDF ไม่ตรง 1 บิล ASN 322 บิล VBRP 1 บิล เหมือนกันทั้งก่อนและหลังกดแก้ไข (บิลที่รอไม่นับว่า PDF ไม่ตรง)
แผนสั่งพิมพ์: ก่อนกดแก้ไขพิมพ์ได้ 23 บิล หลังกด 51 บิล
ถ้าไม่มีแถว VBRP ผลการเทียบ หน้าภาพรวม และแผนสั่งพิมพ์เหมือนก่อนมี VBRP และก่อนแยกสถานะทุกไบต์ ยกเว้นป้าย `รอแก้ไข PDF` (วัดบนข้อมูลตัวอย่างด้วย `eq_status.sh` และบนทุกครั้งที่ `job_safety_test.php` เรียก reconcile และหน้าภาพรวม เทียบโค้ดก่อนและหลัง)

- ทดสอบ: `verify_vbrp.py` "on the sample 23 bills are complete, 28 wait for the fix (รอแก้ไข PDF), 321 wait for the ASN (รอแก้ไข ASN) and 1 is wrong before the fix…", "the fix button leaves every file that holds no matching bill as it was…" (ข้อความ INFO "bills before the fix", "lines before the fix", "verdict_counts", "files the fix left alone")

## ประวัติสถานะของบิล

ผู้ใช้ตัดสิน 2026-10-28: ระบบบันทึกทุกครั้งที่หมวดสถานะของบิลเปลี่ยนหลังการกระทำของผู้ใช้ และแสดงว่าบิลมาจากไหน "แก้ไขแล้ว" ยังเป็นสถานะแสดงผลเดียวและกลุ่มกรองเดียว
Job ที่มีอยู่ก่อนเริ่มบันทึกตั้งแต่การกระทำแรกหลังเปลี่ยนนี้ ก่อนหน้านั้นย้อนไม่ได้ บิลที่ไม่มีประวัติไม่แสดงอะไร

- ตาราง `job_bill_history` (สร้างเองครั้งแรกที่ใช้ แบบ `VbrpStore` ไม่เปลี่ยน `Database::SCHEMA_VERSION`): `id`, `job_id`, `invoice_no` (ตามที่ ASN เขียน), `at` (เวลา UTC ของ SQLite), `event`, `from_status` (`NULL` ในแถวแรกของบิล), `to_status`, `detail` (ปัญหาของบิลตอนนั้น ไม่เกิน 300 ตัวอักษร บิลสมบูรณ์และแก้ไขแล้วเป็น `''`) ดัชนี `(job_id, invoice_no, id)` ลบไปกับ Job (foreign key) และกับการล้างข้อมูล Tax Invoice (`clearJobData`)
- หมวดของบิล: `MATCH`, `FIXED` (สมบูรณ์และมีบันทึกใน `job_invoice_fixes`), `PENDING_FIX`, `PENDING_ASN`, `MISMATCH`
- `JobManager::recordBillHistory(jobId, event)` เทียบ Job หนึ่งครั้ง แล้วเพิ่มหนึ่งแถวต่อบิลที่หมวดต่างจากแถวล่าสุดของบิลนั้น (หรือยังไม่มีแถว) เรียกซ้ำโดยไม่มีอะไรเปลี่ยนไม่เพิ่มแถว บันทึกเฉพาะ Job ที่เทียบแล้ว (`jobs.asn_reconciled = 1`) หรือเมื่อ event เป็น `compare`
- event และ action ที่บันทึก: `compare` (`get_job_reconcile` ที่ส่ง `event=compare` คือปุ่ม "เทียบกับ PDF" การโหลดหน้าไม่บันทึก), `asn_import` (`import_asn`), `asn_delete` (`delete_asn_records`), `vbrp_import` (`job_vbrp_import`), `vbrp_clear` (`job_vbrp_clear`), `fix` (`fix_invoice`, `fix_all_invoices`) บันทึกหลังการเปลี่ยน และหลังการเทียบของ action นั้นเอง ถ้าบันทึกเพิ่มแถวแล้ว `import_asn` และ `delete_asn_records` เทียบใหม่อีกครั้งเพื่อให้คำตอบมี `was` ล่าสุด บันทึกไม่ได้ไม่ทำให้ action ล้ม (`error_log` อย่างเดียว)
- การนำเข้า ASN แบบไม่เทียบ และการลบแถว ASN ทำให้ Job กลับเป็น "ยังไม่เทียบ" อยู่แล้ว (`AsnStore::saveAsnRecords`, `AsnStore::deleteAsnRecords`) สองกรณีนี้จึงไม่บันทึก การเปลี่ยนไปบันทึกตอนกดเทียบครั้งถัดไปเป็น `compare`
- `was` ของบิล คือหมวด `PENDING_FIX`, `PENDING_ASN`, `MISMATCH` ในแถวประวัติก่อนแถวล่าสุด เรียงตามที่เห็นครั้งแรก ไม่ซ้ำ แถวของ reconcile ได้ `was` แถวของตารางภาพรวมได้ `was` และ `history` (`at`, `event`, `from`, `to`, `detail` เรียงจากเก่า) แต่ละคีย์มีเฉพาะเมื่อไม่ว่าง Job ที่ไม่มีประวัติจึงตอบเหมือนเดิมทุกไบต์
- หน้าเว็บแสดง `เดิม: …` ใต้บิลที่สมบูรณ์หรือแก้ไขแล้วที่มี `was` รวมบิลที่แค่รอ ASN แล้ว ASN ตรง (หัวหน้าทีมตัดสิน: "แก้ไขแล้ว" ยังหมายถึงปุ่มแก้ไขทำอะไรกับหน้าของบิล บิลที่รอแค่ ASN แล้วตรงเป็น "สมบูรณ์" แต่ยังบอกที่มา) และคอลัมน์ `Previous status` ในตารางภาพรวม (ARCHITECTURE.md)

- โค้ด: `BillHistory`, `JobManager::recordBillHistory`, `DashboardBuilder::billCategories`, `DashboardBuilder::reconcileJob`, `DashboardBuilder::buildInvoiceRows`, `recordBillHistory` ใน `api.php`
- ทดสอบ: `status_split_test.php` "page loads, the dashboard and the print plan record no history", "the compare button records every bill once…", "comparing again with nothing changed records nothing", "the fix records its change…", "a bill shows where it came from…", "importing the corrected ASN takes the bill out of waiting for the ASN to fixed…", "clearing VBRP records the bills it moves…", "importing VBRP records the bills it moves…", "deleting ASN rows undoes the compare (as before)…", "a history that cannot be written never fails the action…", "a Job never compared records nothing on an action…", "only the six events are recorded, and a detail keeps its first 300 characters", "was lists the waiting and wrong categories…", "clearing the Tax Invoice data of a Job deletes its history", "the history of a Job goes with its row (foreign key)", "deleting a Job deletes its history", "a Job without history has neither was nor history anywhere"

## ใบลดหนี้: เทียบกับไฟล์ ASN (แท็บ 2 และ 3 ของ `credit_note.php`)

หัวข้อนี้ใช้คำว่า **ใบ** แทนใบลดหนี้หนึ่งเลข (เช่น `93250403-1` หรือ `93240256`) และ **รายการ** แทนหนึ่งแถวของผลการเทียบ คือใบหนึ่งใบกับบาร์โค้ดหนึ่งตัว
ตัวเลขตัวอย่างมาจาก `tests/fixtures/cn/` (545 ไฟล์ 557 ใบ) กับสี่ชีตใบลดหนี้ของไฟล์ ASN ตัวอย่าง (4,204 แถว) วัดด้วย `verify_cn.py` เมื่อ 2026-10-28

### ASN เป็นข้อมูลหลัก

แถว ASN ของใบลดหนี้นำเข้าทีละชีตในหน้าใบลดหนี้ เก็บแยกจาก ASN ของ Tax Invoice แหล่งของแถวคือ `<ชื่อไฟล์> / <ชื่อชีต>`
ผลการเทียบเริ่มจากแถว ASN ใบใน PDF ที่ ASN ไม่มีไม่แสดง เลขใบของ PDF กับ ASN จับคู่ด้วย key ของ Tax Invoice (ตัวอักษรภาษาอังกฤษตัวใหญ่และตัวเลขเท่านั้น) แบบตรงตัว `-1` กับ `-2` จึงเป็นคนละใบ
แถว ASN ที่ใบและบาร์โค้ดเดียวกันรวมเป็นรายการเดียว จำนวนรวมกัน ราคาขายใช้ค่าต่ำสุด (ใช้แสดงเท่านั้น)

- โค้ด: `CreditNoteReconciler::reconcile`, `CreditNoteReconciler::key`, `CreditNoteAsnStore::save`
- ทดสอบ: `credit_note_compare_test.php` "-1 and -2 are two credit notes…", "a credit note PDF without ASN rows is not in the compare…", "a credit note whose pieces per barcode, Excl VAT and VAT equal the ASN is complete on every line, its two ASN rows of one barcode merged", `verify_cn.py` "the compare holds, for each of the 557 ASN credit notes, one row per ASN barcode then one per PDF-only barcode"

### สามสถานะ

| สถานะในโค้ด | คำบนหน้าจอ | ความหมาย |
| --- | --- | --- |
| `PENDING` | รอเทียบกับ PDF | นำเข้า ASN แล้ว ยังไม่ได้กดเทียบ (มีเฉพาะในหน้าภาพรวม ก่อนกดเทียบ) |
| `MATCH` | สมบูรณ์ | ไม่มีปัญหาข้อใดเลย |
| `MISMATCH` | ผิดพลาด | มีปัญหาอย่างน้อยหนึ่งข้อ |

ไม่มี "รอแก้ไข" และ "แก้ไขแล้ว" ไม่มีปุ่มแก้ไขและการสั่งพิมพ์ สถานะของใบคือสถานะที่แย่ที่สุดของรายการในใบ หน้าภาพรวมนับเป็นจำนวนใบ
หลังกดเทียบ ทุกครั้งที่หน้าโหลดผลจะเทียบใหม่บนข้อมูลตอนนั้น นำเข้าชีต ASN หรือล้างข้อมูลใบลดหนี้ (PDF) แล้วกลับเป็นยังไม่เทียบ ลบแถว ASN บางส่วนของ Job ที่เทียบแล้วยังเทียบแล้วอยู่ ลบหมดกลับเป็นยังไม่เทียบ

- โค้ด: `CreditNoteCompare::reconcile`, `CreditNoteCompare::deleteAsn`, `JobManager::clearCreditNotes`
- ทดสอบ: `credit_note_compare_test.php` "before the compare the dashboard lists every ASN credit note waiting…", "deleting the rows of one sheet of a compared Job keeps it compared…", "deleting every ASN row ends the compare…", "job_cn_clear forgets the credit note documents and their lines, keeps the ASN rows and sends the compare back to waiting"

### ใบที่ตัดสินรายบาร์โค้ดไม่ได้

สี่กรณีนี้ทุกรายการของใบผิดพลาดด้วยข้อความเดียว และไม่ตัดสินยอดเงิน:

| กรณี | ข้อความ |
| --- | --- |
| ไม่มีใบนี้ใน PDF | `ไม่พบใบลดหนี้นี้ใน PDF` |
| ใบที่ดึงข้อมูลไว้ก่อน 2026-10-28 (ไม่มีบรรทัดสินค้าและยอดท้ายใบ) | `ใบลดหนี้นี้ดึงข้อมูลไว้ก่อนรุ่นที่อ่านรายการสินค้าและยอดท้ายใบ จึงยังเทียบไม่ได้` ดึงข้อมูลใหม่จาก `CREDIT NOTE/finished` แล้วหายไป |
| Attachment Sheet | `ใบแนบ (Attachment Sheet) ไม่มีบาร์โค้ดและยอดท้ายใบให้เทียบ ตรวจด้วยตา` |
| ใบที่ตัวอ่านอ่านแล้วเลขไม่ลงกัน (`read_ok` เป็นเท็จ) | `อ่านใบลดหนี้ไม่ครบ: <เหตุผลภาษาอังกฤษของตัวอ่าน>` |

ข้อมูลตัวอย่างมีกรณีที่หนึ่งและสองเป็นศูนย์ใบ มี Attachment Sheet ใบเดียว (`93260445`, สามรายการ ผิดพลาดด้วยข้อความของใบแนบ) และไม่มีใบที่ `read_ok` เป็นเท็จนอกจาก Attachment Sheet

- โค้ด: `CreditNoteReconciler::wholeReason`
- ทดสอบ: `credit_note_compare_test.php` "-1 and -2 are two credit notes: the ASN number whose PDF is missing…", "a credit note stored before the line reader is not judged and says why", "an Attachment Sheet has nothing to compare…", "a credit note whose reading does not add up is not judged…", `verify_cn.py` "every compare row has the status and the exact reason the contract gives it, and no action"

### สิ่งที่ตรวจ

จำนวนเป็นข้อเดียวของรายการ ข้ออื่นเป็นของใบและแสดงในทุกรายการของใบ ปัญหาเรียงตามลำดับนี้ คั่นด้วย `, `:
จำนวน, สาขา, บาร์โค้ดที่มีแต่ใน ASN, บาร์โค้ดที่มีแต่ใน PDF, ยอดรวม, VAT

| ข้อ | กติกา | ข้อความ |
| --- | --- | --- |
| จำนวน | ผลรวม units ของบรรทัด PDF ที่บาร์โค้ดนั้นต้องเท่ากับ Quantity ที่รวมแล้ว | `จำนวนไม่ตรง (PDF: 2 ชิ้น / ASN: 1 ชิ้น)` |
| สาขา | เลขร้านท้ายบรรทัดร้านของ PDF กับ Dest Store No. / Store No ของ ASN หลังตัด 0 นำหน้าทั้งสองฝั่ง ไม่ตรวจถ้าฝั่งใดไม่มี (ร้าน 5152 NAKORN SAWAN ไม่พิมพ์เลขร้าน) | `รหัสสาขาไม่ตรง (PDF: 10121 / ASN: 10116)` |
| บาร์โค้ดข้างเดียว | บาร์โค้ดที่ใบใน ASN มีแต่ PDF ไม่มี และกลับกัน แสดงได้ข้างละ 3 ตัว ที่เหลือเป็น `และอีก N รายการ` บาร์โค้ดที่มีแต่ใน PDF ได้รายการของตัวเอง (ฝั่ง ASN ว่าง) | `บาร์โค้ด X มีใน ASN แต่ไม่มีใน PDF (1 ชิ้น)`, `บาร์โค้ด X มีใน PDF แต่ไม่มีใน ASN (1 ชิ้น)` |
| ยอดรวม | ดูหัวข้อถัดไป ตรงทุกสตางค์ | `ยอดรวมไม่ตรง (PDF: 149.53 / ASN: 149.54)` |
| VAT | เฉพาะชีตแบบ cost: VAT (7%) ของ PDF กับ Total VAT ของ ASN (ค่าบวกแรกของใบ) ตรงทุกสตางค์ | `VAT ไม่ตรง (PDF: ฿10.47 / ASN: ฿10.48)` |

วันที่ของ PDF และ ASN แสดงอย่างเดียว ไม่ตรวจ ราคาต่อชิ้นไม่ตรวจ

- โค้ด: `CreditNoteReconciler::judge`, `CreditNoteReconciler::listed`, ข้อความเป็นค่าคงที่ชื่อ `QUANTITY`, `STORE`, `ASN_ONLY`, `PDF_ONLY`, `AMOUNT`, `VAT`
- ทดสอบ: `credit_note_compare_test.php` "a quantity difference names both counts on its line, before the amount it causes", "a store that differs is wrong, a store the PDF does not print is not judged", "barcodes on one side only make every line of the document wrong even when the amount agrees…", `verify_cn.py` seeded changes "Dest Store No. of a ZG2T credit note changed", "four lines of a ZG2T credit note dropped", "quantity one higher on one line of a ZFP3 credit note"

### ยอดรวม: สองฐานตามชีต ตรงทุกสตางค์

| ชีต | `amount_kind` | ยอดของ ASN ต่อใบ | เทียบกับ PDF | VAT |
| --- | --- | --- | --- | --- |
| มีคอลัมน์ Total Cost Ex VAT (ZFP3, ZKR) | `cost` | Total Cost Ex VAT ค่าบวกแรกของใบ (ไฟล์ใส่ซ้ำทุกแถว) | Total Amt Excluding VAT | ตรวจ |
| ไม่มี (ZG2T, ZCR) | `retail` | ผลรวม Quantity × Unit Ret ทุกแถวของใบ | Total Amount (ก่อน Total Discount) | ไม่ตรวจ ASN ไม่มี VAT |

ใบหนึ่งใช้ `amount_kind` ของแถว ASN แรกของใบ ยอดของ ASN ไม่ว่างเลย (ไม่มีค่าบวกเป็น 0.00) เทียบเป็นสตางค์ ต่างกัน 0.01 ก็ผิด (`CreditNoteReconciler::AMOUNT_TOLERANCE = 0.00`, `VAT_TOLERANCE = 0.00` ฝั่ง Tax Invoice ยังเป็น 1.00 และ 0.15)
บนข้อมูลตัวอย่าง: 58 ใบผิดเพราะ VAT ต่าง 0.01 ถึง 0.07 อย่างเดียว ใบ ZFP3 106 ใบมี VAT ต่าง 0.01 ถึง 0.07 อยู่ 64 ใบ ไม่มีใบใดผิดเพราะยอดรวมต่าง 0.01 ถึง 1.00 อย่างเดียว

- โค้ด: `CreditNoteReconciler::asnDocuments`, `CreditNoteReconciler::agrees`
- ทดสอบ: `credit_note_compare_test.php` "money is exact: Total Cost Ex VAT one satang above the PDF Excl VAT is wrong…", "VAT is exact…", "on the retail basis the ASN amount is quantity x Unit Ret summed over the document…", `verify_cn.py` seeded change "Total Cost Ex VAT 0.01 higher on a ZFP3 credit note"

ผลบนข้อมูลตัวอย่าง:

| | สมบูรณ์ | ผิดพลาด |
| --- | --- | --- |
| ใบ | 403 | 154 |
| รายการ | 1,269 | 2,507 |

ใบผิดพลาด 154 ใบ: 87 ใบจำนวนไม่ตรง (ยอดรวมจึงไม่ตรงด้วย) 58 ใบ VAT ต่าง 0.01 ถึง 0.07 อย่างเดียว 7 ใบยอดเงินต่างเกิน 1.00 1 ใบมีบาร์โค้ดข้างเดียว 1 ใบคือ Attachment Sheet

- ทดสอบ: `verify_cn.py` "after the compare the dashboard gives every credit note the status, problem, store, pieces, amount, pages and source file of the contract…" (ข้อความ INFO "documents per status")

### หน้าภาพรวมของใบลดหนี้

หนึ่งแถวต่อหนึ่งใบใน ASN ช่อง `total` คือจำนวนใบ ก่อนกดเทียบทุกใบ `PENDING` และ `complete`, `error` เป็นค่าว่าง หลังกดเทียบ `pending` เป็น 0
คอลัมน์ `Dest Store No.` ใช้ Dest Store No. ของ ASN ถ้าไม่มีใช้เลขร้านของ PDF `Total Qty` คือจำนวนชิ้นรวมใน PDF (ว่างเมื่อใบตัดสินรายบาร์โค้ดไม่ได้) `Amount` คือยอดของ ASN ตามฐานของใบ `Pages` คือจำนวนหน้าของใบใน PDF
`Remark` แสดงได้ 3 ประเด็น ที่เหลือเป็น `และอีก N ประเด็น` ปัญหาจำนวนเป็นประเด็นละบาร์โค้ด นำหน้าด้วย `บาร์โค้ด X: ` ปัญหาของใบเป็นประเด็นละข้อ เรียงตามสาขาแล้วตามเลขใบ (เลขที่ไม่มีสาขาอยู่ท้าย)

- โค้ด: `CreditNoteCompare::dashboard`, `CreditNoteReconciler::document`
- ทดสอบ: `credit_note_compare_test.php` "after the compare the dashboard counts credit notes by their worst line and orders them by store, then number", "a dashboard row shows the PDF pieces and pages, the ASN amount on its basis…", `verify_cn.py` seeded change "two quantities and the store of one ZG2T credit note changed, four problems"

### ชีตซ้ำ และเลขใบซ้ำ

ทำแบบเดียวกับ ASN ของ Tax Invoice แต่ "ไฟล์เดิม" คือไฟล์และชีตเดียวกัน: ชีตเดิมซ้ำถามก่อน ข้ามไม่เปลี่ยนอะไร แทนที่เปลี่ยนแถวของชีตนั้น เลขใบที่ชีตอื่นมีอยู่แล้วข้ามหรือแทนที่ได้ แถวที่ไม่มี `amount_kind` เป็น `cost` หรือ `retail` ทำให้ทั้งการนำเข้าถูกปฏิเสธ ไม่มีแถวใดถูกเก็บ

- โค้ด: `CreditNoteAsnStore::save`, `CreditNoteAsnStore::describeStored`, `CreditNoteCompare::asnOverlap`
- ทดสอบ: `credit_note_compare_test.php` "the same sheet again is told as stored, identical and compared…", "replacing a sheet swaps its rows without doubling…", "a credit note number another sheet holds is named by the overlap question…", "an import with one record whose amount_kind is missing…", `verify_cn.py` "importing the same sheet with skip stores nothing…", "importing the same sheet with replace swaps its rows without doubling…"

## ระบบ VBRP (`vbrp.php`) หน้าเก่าที่ไม่ใช้แล้ว

ผู้ใช้ไม่ใช้หน้านี้แล้ว (2026-10-28) หน้า Job (`job.php`) ไม่มีปุ่มไปหน้านี้ VBRP นำเข้าที่แท็บ 2 ของ `workspace.php` แทน (หัวข้อ "VBRP: แหล่งที่สามของการเทียบ Tax Invoice") หน้าเก่ายังเปิดได้ที่ `vbrp.php?job_id=N` และทำงานเหมือนเดิม แถวที่หน้านี้อ่านใช้ในการเทียบด้วย และเก็บ Subtotal 3 (`CJ`) ด้วยแม้หน้าจะไม่แสดง (`VbrpStore::HIDDEN_COLUMNS`)

หน้า VBRP อ่านไฟล์ Excel (`.xlsx`) จากโฟลเดอร์ `myfile/{ชื่อ Job}/VBRP/upload here/` แล้วเก็บไฟล์ที่อ่านสำเร็จไว้ใน `myfile/{ชื่อ Job}/VBRP/finished/`

### เงื่อนไขการอ่านไฟล์

1. **อ่านตั้งแต่แถวที่ 4 ลงไป** (`VbrpStore::DATA_START_ROW = 4`) ข้ามแถวที่ 1–3 ซึ่งเป็นหัวตาราง และข้ามแถวที่ไม่มีข้อมูลในคอลัมน์ที่กำหนด
2. **อ่านเฉพาะคอลัมน์ที่กำหนดจากทุกชีตในไฟล์ Excel** แล้วแสดงในหน้าตารางตามลำดับนี้:

| คอลัมน์ใน Excel | คีย์ในระบบ | หัวตารางบนหน้าจอ | ชนิดข้อมูล |
| --- | --- | --- | --- |
| `A` | `combine` | `Combine` | ข้อความ (`string`) |
| `C` | `invoice` | `Invoice` | ข้อความ (`string`) |
| `E` | `delivery_no` | `Delivery No.` | ข้อความ (`string`) |
| `H` | `quantity` | `Quantity` | ตัวเลข (`number`) |
| `CI` | `selling_price` | `Selling Price` | จำนวนเงิน (`currency`) |
| `CK` | `total_cost_ex_vat` | `Total Cost EX VAT` | จำนวนเงิน (`currency`) |
| `CQ` | `ean_upc` | `EAN/UPC` | ข้อความ (`string`) |
| `EM` | `total_vat` | `Total VAT` | จำนวนเงิน (`currency`) |

### การเช็คข้อมูลซ้ำเมื่ออัปโหลดไฟล์ใหม่ และการแสดงรายการไฟล์ใน `finished`

- **เช็คข้อมูลซ้ำจากคอลัมน์ `C` (`Invoice`)**: เมื่อกดดึงข้อมูลไฟล์ใหม่จาก `VBRP/upload here/` ระบบจะนำค่าในคอลัมน์ `C` (`Invoice`) ของไฟล์ใหม่ไปเทียบกับข้อมูลที่มีอยู่ใน Job
  - **กรณีซ้ำ**: หากเลข `Invoice` (คอลัมน์ `C`) ตรงกับข้อมูลเดิมที่มีอยู่ใน Job ระบบจะ**ลบแถวข้อมูลเก่าของเลข `Invoice` นั้นออกทั้งหมด แล้วนำแถวข้อมูลจากไฟล์ใหม่ลงไปแทนที่** (และหากไฟล์เดิมถูกแทนที่ข้อมูลจนไม่เหลือแถวในระบบ รายการไฟล์เดิมจะถูกนำออกจากรายการ `finished`)
  - **กรณีไม่ซ้ำ**: ข้อมูลเดิมยังอยู่ครบ และไฟล์ใหม่จะถูกย้ายไปเก็บเพิ่มใน `VBRP/finished/`
- **ปุ่ม `finished` แสดงเป็น Dropdown**: เมื่อกดปุ่ม `finished` บนแถบเครื่องมือ จะแสดง Dropdown รายชื่อไฟล์ทั้งหมดที่อัปโหลดผ่านและอยู่ใน `VBRP/finished/` พร้อมจำนวนรายการของแต่ละไฟล์ สามารถคลิกเลือกดูข้อมูลเฉพาะไฟล์ที่ต้องการ เลือกดูรวมทุกไฟล์ (`แสดงทุกไฟล์รวมกัน`) หรือกด `เปิดโฟลเดอร์ finished ใน Explorer` ได้

- โค้ด: `VbrpStore::DATA_START_ROW`, `VbrpStore::COLUMNS`, `VbrpStore::extractBatch`, `VbrpStore::record`, `VbrpStore::files`, `XlsxReader::rows`, `vbrp.php`, `assets/js/vbrp.js`
- ทดสอบ: `tests/vbrp_test.php` "the reader gives the chosen columns of the rows from row 4 down…", "uploading a new file whose Column C (invoice) matches an existing row deletes the old row of that invoice and inserts the new one", "when a new file replaces every invoice of an earlier file, the earlier file is removed from the active finished list", "the finished button on vbrp.php opens a dropdown listing all uploaded files in finished"
