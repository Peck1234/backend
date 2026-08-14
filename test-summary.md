# สรุปผลการทดสอบหน่วย Backend (PHPUnit) — สำหรับบทที่ 4

## 1. โครงสร้างที่สำรวจพบ (ก่อนเริ่มเขียน test)

**Framework**: Laravel 8.83.29, PHP 7.4.33, PHPUnit 9.5.10 (ติดตั้งมาพร้อมโปรเจกต์อยู่แล้ว — `phpunit.xml` และโฟลเดอร์ `tests/Unit`, `tests/Feature` มีอยู่ก่อน ไม่ต้องตั้งค่าใหม่)

**Endpoint ทั้งหมด** (`routes/api.php`): 17 route ครอบคลุม 7 controller (`AuthController`, `PatientController`, `DispenseController`, `ReminderController`, `StatsController`, `DispenseLogController`, `SlotController`)

**เลือกทดสอบ 4 จุดตามที่ระบุไว้ในบรีฟ** เพราะเป็น business logic ที่แท้จริง (ไม่ใช่แค่ query/serialize ข้อมูล):

| ลำดับ | Endpoint / Class | เหตุผลที่เลือก |
|---|---|---|
| 1 | `POST /api/verify-dispense` → `DispenseController::verify()` | หัวใจของระบบ AR — ตัดสินว่าการสแกน QR ถูกต้องหรือไม่ |
| 2 | `PUT /api/slots/{slot}/assign`, `/clear` → `SlotController` | การผูก/เคลียร์ผู้ป่วยกับช่องยา มี optimistic locking (`version` field) จริง |
| 3 | `POST /api/register`, `/login` → `AuthController` | Validation + การสร้างรหัส QR พยาบาล |
| 4 | `GET /api/due-medications` → ตรรกะใน `Medication` model | คำนวณ/กรองยาที่ถึงเวลาแต่ยังไม่จ่าย |

**สิ่งที่พบและตรงกับที่คาดไว้**: มี `version` column ใน `cart_slots` (optimistic locking) จริงตามที่คาดไว้ — โครงสร้างไม่ต่างจากที่คาดการณ์ไว้มาก จึงไม่ได้หยุดถามก่อนเขียน test เต็มรูปแบบ (ตรงตามเงื่อนไขในบรีฟ)

## 2. Refactor ที่ทำ (จำเป็น เพราะ logic เดิมฝังอยู่ใน Controller ปนกับ DB query)

**หลักการ**: แยกเฉพาะ "ตรรกะการตัดสินใจ" (decision logic) ออกมาเป็นคลาสแยก — Controller ยังคง query ฐานข้อมูล, เขียน log, ส่ง response เหมือนเดิมทุกประการ **API response และผลลัพธ์ไม่เปลี่ยนแปลง** (ยืนยันด้วยการทดสอบยิง request จริงหลัง refactor)

| ไฟล์ใหม่ | หน้าที่ | Controller ที่แก้ตาม |
|---|---|---|
| `app/Services/DispenseVerificationService.php` | ตัดสินผลการสแกน QR (`evaluate()`) จาก nurse/patient/medication ที่หามาได้ | `DispenseController::verify()` |
| `app/Services/SlotAssignmentService.php` | ตรวจ optimistic lock + สถานะช่อง (`evaluateAssign()`, `evaluateClear()`) | `SlotController::assign()`, `clear()` |
| `app/Support/NurseCodeGenerator.php` | จัดรูปแบบรหัส QR พยาบาล (`NURSE-XXX`) | `AuthController::register()` |

**หมายเหตุพฤติกรรม**: `DispenseController` เปลี่ยนจาก query แบบ short-circuit (หยุดค้นทันทีที่พบ QR ที่ไม่ถูกต้อง) เป็น query ทั้ง 3 ค่าล่วงหน้าเสมอ เพื่อให้ตรรกะการตัดสินใจแยกออกมาเป็น pure function ได้ — **ไม่กระทบ response หรือผลลัพธ์ที่ผู้ใช้เห็นเลย** เป็นเพียงการ query เพิ่มขึ้นเล็กน้อย (index lookup) ซึ่งไม่มีนัยสำคัญบนขนาดข้อมูลของระบบนี้

**ตรวจสอบพฤติกรรมเดิมด้วยการรัน backend จริงหลัง refactor**: ยิง `POST /api/verify-dispense`, `POST /api/login`, `GET /api/slots` ผ่าน HTTP จริง เทียบกับพฤติกรรมก่อนแก้ — ผลลัพธ์ตรงกันทุกกรณี (โครงสร้าง response ไม่มีฟิลด์เกิน)

## 3. ภาพรวมผลการทดสอบ

| รายการ | ผลลัพธ์ |
|---|---|
| ไฟล์ test ที่เขียนใหม่ | 4 ไฟล์ |
| จำนวน test case ที่เขียนใหม่ | **30 รายการ** |
| ผ่าน | **30 / 30 (100%)** |
| รวมกับ test เดิมของ Laravel (`ExampleTest`) | 31 / 31 |
| เวลารันทั้งหมด | ~1–5 วินาที |

**ยืนยันว่าไม่ต้องพึ่งฐานข้อมูลจริง**: ทดสอบด้วยการ **หยุด MySQL container จริง** (`docker stop`) แล้วรัน `php artisan test --testsuite=Unit` ซ้ำ — **ผ่านครบ 31/31 เหมือนเดิมทุกประการ** พิสูจน์ว่า unit test ชุดนี้ไม่มีการเชื่อมต่อฐานข้อมูลใดๆ เลย (เป็นไปตามข้อจำกัดที่กำหนดไว้)

### Code Coverage (PCOV 1.0.11 — ติดตั้งเพิ่มเพื่อวัดผลจริง)

| Class | % Methods | % Lines |
|---|---|---|
| `App\Services\DispenseVerificationService` | 2/2 (100%) | 22/22 (100%) |
| `App\Services\SlotAssignmentService` | 2/2 (100%) | 12/12 (100%) |
| `App\Support\NurseCodeGenerator` | 1/1 (100%) | 1/1 (100%) |
| `App\Models\Medication` | 3/4 (75%) | 7/8 (87.5%) |

เมธอดเดียวของ `Medication` ที่ไม่ถูก cover คือ `patient()` (ความสัมพันธ์ Eloquent `belongsTo` ไม่ใช่ตรรกะทางธุรกิจ ต้องมี database จริงถึงจะทดสอบได้ — อยู่นอกขอบเขต unit test) ส่วนตรรกะจริงทั้ง 3 เมธอด (`matchedDueTime`, `isDispensedToday`, `isDueNow`) covered 100%

**Coverage รวมทั้งโปรเจกต์** (ทุกไฟล์ใน `app/`): Methods 22.58% (14/62), Lines 11.13% (60/539) — ตัวเลขนี้ต่ำเพราะรวม Controller/Middleware ที่ต้อง query ฐานข้อมูลจริงถึงจะทดสอบได้ (ต้องใช้ Feature test ซึ่งอยู่นอกขอบเขตงานนี้) ตัวเลขที่สะท้อนงานนี้จริงๆ คือตารางด้านบน

## 4. รายละเอียดการทดสอบแต่ละไฟล์

### 4.1 `DispenseVerificationServiceTest.php` (8 test case) — หัวใจของระบบ AR

ทดสอบการตัดสินผลการสแกน QR 3 ระดับ (พยาบาล/ผู้ป่วย/ตลับยา) ครบทุก branch:

- ไม่พบพยาบาลจาก QR → "ไม่พบข้อมูลพยาบาลจาก QR นี้"
- ไม่พบผู้ป่วยจาก QR → "ไม่พบข้อมูลผู้ป่วยจาก QR นี้"
- ไม่พบตลับยาจาก QR → "QR code ไม่ถูกต้อง"
- ตลับยาเป็นของผู้ป่วยคนอื่น → "ตลับยานี้ไม่ใช่ของผู้ป่วยรายนี้" (ยังคงแสดงชื่อยาให้พยาบาลรู้ว่าสแกนอะไรไป)
- ยาถูกจ่ายไปแล้ววันนี้ → "ยานี้ถูกจ่ายไปแล้ววันนี้" ไม่อนุญาตจ่ายซ้ำ
- ยาที่จ่ายไปเมื่อวันก่อน → จ่ายซ้ำได้ในวันใหม่ (สำเร็จ)
- สแกนถูกต้องครบทุกอย่าง ยังไม่เคยจ่าย → "จ่ายยาสำเร็จ" พร้อม flag ให้บันทึกเวลาจ่าย
- ลำดับการตรวจสอบถูกต้อง (พยาบาล → ผู้ป่วย → ยา ตามลำดับเดิม)

### 4.2 `SlotAssignmentServiceTest.php` (9 test case) — ผูก/เคลียร์ช่องยา + Optimistic Locking

- **assign**: version ไม่ตรง (ถูกแก้โดยเครื่องอื่น) → ปฏิเสธ แม้เงื่อนไขอื่นจะผ่านก็ตาม (ตรวจสอบว่า version check มาก่อนเสมอ), ผู้ป่วยผูกกับช่องอื่นอยู่แล้ว → ปฏิเสธพร้อมบอกรหัสช่องเดิม, ช่องมีคนอยู่แล้ว → ปฏิเสธ, กรณีปกติ → ผูกสำเร็จ
- **clear**: ช่องว่างอยู่แล้ว → ถือว่าสำเร็จ (no-op) ไม่สนใจ version เลย (ตรวจสอบว่าเช็คสถานะว่างก่อน version), version ไม่ตรงบนช่องที่มีคนอยู่ → ปฏิเสธ, กรณีปกติ → เคลียร์สำเร็จ

### 4.3 `MedicationTest.php` (11 test case) — ยาที่ถึงเวลาแต่ยังไม่จ่าย

ทดสอบ 3 เมธอดของ `Medication` model (แช่แข็งเวลาด้วย `Carbon::setTestNow()` เพื่อผลลัพธ์คงที่):

- `matchedDueTime()`: ไม่มีตารางเวลา → null, ทุกรอบยังไม่ถึงเวลา → null, มีรอบที่ถึงเวลาแล้ว → คืนรอบแรกที่ตรง, ตรงเป๊ะกับเวลาที่กำหนด → นับว่าตรง
- `isDispensedToday()`: ไม่เคยจ่าย → false, จ่ายวันนี้ → true, จ่ายเมื่อวานนี้ → false (จ่ายซ้ำวันใหม่ได้)
- `isDueNow()`: ยังไม่จ่าย+ถึงเวลา → true, จ่ายไปแล้ว+ถึงเวลา → false (จ่ายแล้วชนะ), ยังไม่จ่าย+ยังไม่ถึงเวลา → false, ไม่มีตารางเวลาเลย → false

### 4.4 `NurseCodeGeneratorTest.php` (2 test case)

- เติมศูนย์ให้ครบ 3 หลัก (`1` → `NURSE-001`, `42` → `NURSE-042`)
- ไม่ตัดทอนเลขที่เกิน 3 หลัก (`1000` → `NURSE-1000`)

## 5. คำสั่งรันเทส

```bash
php artisan test --testsuite=Unit
# หรือ
vendor/bin/phpunit --testsuite Unit
```
