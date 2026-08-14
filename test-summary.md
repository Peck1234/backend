# สรุปผลการทดสอบหน่วย Backend (PHPUnit) — สำหรับบทที่ 4

## 1. โครงสร้างที่สำรวจพบ (ก่อนเริ่มเขียน test)

**Framework**: Laravel 8.83.29, PHP 7.4.33, PHPUnit 9.5.10 (ติดตั้งมาพร้อมโปรเจกต์อยู่แล้ว — `phpunit.xml` และโฟลเดอร์ `tests/Unit`, `tests/Feature` มีอยู่ก่อน ไม่ต้องตั้งค่าใหม่)

**Endpoint ทั้งหมด** (`routes/api.php`): 17 route ครอบคลุม 7 controller (`AuthController`, `PatientController`, `DispenseController`, `ReminderController`, `StatsController`, `DispenseLogController`, `SlotController`)

**แบ่งการทดสอบเป็น 2 ระดับ**:

| ระดับ | จำนวน | ขอบเขต |
|---|---|---|
| **Unit** (`tests/Unit`) | 31 test | ตรรกะการตัดสินใจล้วนๆ แยกจากฐานข้อมูลโดยสิ้นเชิง |
| **Feature** (`tests/Feature`) | 43 test | ยิง HTTP request จริงผ่าน route → controller → SQLite in-memory database (migrate สดทุก test) |

## 2. Refactor ที่ทำ (จำเป็น เพราะ logic เดิมฝังอยู่ใน Controller ปนกับ DB query)

**หลักการ**: แยกเฉพาะ "ตรรกะการตัดสินใจ" (decision logic) ออกมาเป็นคลาสแยก — Controller ยังคง query ฐานข้อมูล, เขียน log, ส่ง response เหมือนเดิมทุกประการ **API response และผลลัพธ์ไม่เปลี่ยนแปลง** (ยืนยันด้วยการทดสอบยิง request จริงหลัง refactor)

| ไฟล์ใหม่ | หน้าที่ | Controller ที่แก้ตาม |
|---|---|---|
| `app/Services/DispenseVerificationService.php` | ตัดสินผลการสแกน QR (`evaluate()`) จาก nurse/patient/medication ที่หามาได้ | `DispenseController::verify()` |
| `app/Services/SlotAssignmentService.php` | ตรวจ optimistic lock + สถานะช่อง (`evaluateAssign()`, `evaluateClear()`) | `SlotController::assign()`, `clear()` |
| `app/Support/NurseCodeGenerator.php` | จัดรูปแบบรหัส QR พยาบาล (`NURSE-XXX`) | `AuthController::register()` |

**หมายเหตุพฤติกรรม**: `DispenseController` เปลี่ยนจาก query แบบ short-circuit (หยุดค้นทันทีที่พบ QR ที่ไม่ถูกต้อง) เป็น query ทั้ง 3 ค่าล่วงหน้าเสมอ เพื่อให้ตรรกะการตัดสินใจแยกออกมาเป็น pure function ได้ — **ไม่กระทบ response หรือผลลัพธ์ที่ผู้ใช้เห็นเลย** เป็นเพียงการ query เพิ่มขึ้นเล็กน้อย (index lookup) ซึ่งไม่มีนัยสำคัญบนขนาดข้อมูลของระบบนี้

**ตรวจสอบพฤติกรรมเดิมด้วยการรัน backend จริงหลัง refactor**: ยิง `POST /api/verify-dispense`, `POST /api/login`, `GET /api/slots` ผ่าน HTTP จริง เทียบกับพฤติกรรมก่อนแก้ — ผลลัพธ์ตรงกันทุกกรณี (โครงสร้าง response ไม่มีฟิลด์เกิน)

## 3. บั๊กจริง 3 จุดที่ Feature test ชุดนี้ตรวจพบ (แก้แล้ว)

ระหว่างเขียน Feature test (รันผ่าน SQLite in-memory) พบว่า 12 test ล้มเหลวโดยไม่คาดคิด สาเหตุคือ **driver ของ SQLite คืนค่าคอลัมน์ประเภท INTEGER ที่ไม่ใช่ primary key เป็น PHP string** (เช่น `"3"`) ในขณะที่ MySQL (ที่ระบบใช้งานจริง) คืนค่าเป็น PHP int (`3`) เสมอ — เมื่อโค้ดในระบบเทียบค่าด้วย `!==` (strict comparison) เช่นใน `SlotAssignmentService` และ `DispenseVerificationService` การเทียบ `"3" !== 3` จะได้ผลเป็นจริงเสมอแม้ค่าจริงจะตรงกัน

**ระบบจริง (MySQL) ไม่เคยเจอบั๊กนี้** เพราะ Laravel ตั้งค่า MySQL driver ให้คืนค่าตัวเลขเป็น native type อยู่แล้ว (ยืนยันด้วยการยิง `GET /api/slots` จริงหลังแก้ — response ตัวเลขยังไม่มีเครื่องหมายคำพูดเหมือนเดิม) แต่การเทียบด้วย `!==` แบบนี้คือ **จุดเปราะที่แฝงอยู่จริงในโค้ด** — ถ้าคอลัมน์เหล่านี้ถูก cast ผิดชนิดในอนาคต (เช่น เปลี่ยน DB driver, หรือมีค่า null ปนมา) จะทำให้ optimistic locking หรือการตรวจสอบเจ้าของยาใช้งานไม่ได้แบบเงียบๆ

**การแก้ไข**: เพิ่ม `protected $casts` ระบุชนิดข้อมูลให้ชัดเจนใน 3 คอลัมน์ (ไม่กระทบ API เพราะ MySQL คืนค่าเป็น int อยู่แล้ว — เป็นการ "บังคับ" ให้ถูกต้องเสมอไม่ว่าจะใช้ driver ไหน):

| Model | คอลัมน์ | เดิม | แก้เป็น |
|---|---|---|---|
| `CartSlot` | `version` | ไม่ cast | `integer` |
| `CartSlot` | `slot_no` | ไม่ cast | `integer` |
| `Medication` | `patient_id` | ไม่ cast | `integer` |

## 4. ภาพรวมผลการทดสอบ

| รายการ | ผลลัพธ์ |
|---|---|
| ไฟล์ test ที่เขียนใหม่ | 11 ไฟล์ (4 Unit + 7 Feature) |
| จำนวน test case ที่เขียนใหม่ | **74 รายการ** (31 Unit + 43 Feature) |
| ผ่าน | **74 / 74 (100%)** |
| เวลารันทั้งหมด | ~3–4 วินาที |

**ยืนยันว่า Unit test ไม่ต้องพึ่งฐานข้อมูลจริง**: ทดสอบด้วยการ **หยุด MySQL container จริง** (`docker stop`) แล้วรัน `php artisan test --testsuite=Unit` ซ้ำ — **ผ่านครบ 31/31 เหมือนเดิมทุกประการ**

**Feature test ใช้ SQLite in-memory** (`:memory:`) แทน MySQL จริง — migrate schema สดใหม่ทุก test (ผ่าน `RefreshDatabase` trait, wrap ด้วย transaction แล้ว rollback อัตโนมัติ) จึงไม่แตะฐานข้อมูล production เลย และรันเร็วมากเพราะอยู่ใน memory ทั้งหมด

### Code Coverage (PCOV 1.0.11 — วัดผลจริงจากการรันทั้ง Unit + Feature)

| Class | % Methods | % Lines |
|---|---|---|
| `App\Services\DispenseVerificationService` | 100% (2/2) | 100% (22/22) |
| `App\Services\SlotAssignmentService` | 100% (2/2) | 100% (12/12) |
| `App\Support\NurseCodeGenerator` | 100% (1/1) | 100% (1/1) |
| `App\Http\Controllers\AuthController` | 100% (2/2) | 100% (33/33) |
| `App\Http\Controllers\DispenseController` | 100% (2/2) | 100% (25/25) |
| `App\Http\Controllers\DispenseLogController` | 100% (1/1) | 100% (17/17) |
| `App\Http\Controllers\ReminderController` | 100% (2/2) | 100% (44/44) |
| `App\Http\Controllers\StatsController` | 100% (6/6) | 100% (56/56) |
| `App\Http\Controllers\SlotController` | 87.5% (7/8) | 98.5% (131/133) |
| `App\Http\Controllers\PatientController` | 66.7% (4/6) | 96.0% (95/99) |
| `App\Models\Medication` | 100% (4/4) | 100% (8/8) |
| `App\Models\DispenseLog` | 100% (3/3) | 100% (3/3) |
| `App\Models\Patient` | 100% (2/2) | 100% (2/2) |
| `App\Models\CartSlot` | 66.7% (2/3) | 66.7% (2/3) |

**Coverage รวมทั้งโปรเจกต์** (ทุกไฟล์ใน `app/`): **Methods 79.03% (49/62), Lines 87.76% (473/539)** — เพิ่มขึ้นอย่างมากจากรอบก่อน (22.58% / 11.13%) เพราะตอนนี้ครอบคลุมทั้ง Service/Support class (Unit) และ Controller ทุกตัว (Feature) ส่วนที่เหลือที่ยังไม่ cover คือไฟล์นอกขอบเขตงานนี้โดยตั้งใจ เช่น `app/Console/Commands/BackfillCartSlots.php` (สคริปต์ backfill ครั้งเดียว ไม่ใช่ business logic ของ API) และ Middleware ของ Laravel framework เอง

## 5. รายละเอียดการทดสอบแต่ละไฟล์

### 5.1 Unit Tests (`tests/Unit/`) — 31 test, ไม่พึ่งฐานข้อมูล

#### `DispenseVerificationServiceTest.php` (8 test case) — หัวใจของระบบ AR

ทดสอบการตัดสินผลการสแกน QR 3 ระดับ (พยาบาล/ผู้ป่วย/ตลับยา) ครบทุก branch: ไม่พบพยาบาล/ผู้ป่วย/ตลับยา, ตลับยาเป็นของผู้ป่วยคนอื่น, ยาถูกจ่ายไปแล้ววันนี้, ยาที่จ่ายไปเมื่อวันก่อนจ่ายซ้ำได้, สแกนถูกต้องครบทุกอย่าง, ลำดับการตรวจสอบถูกต้อง

#### `SlotAssignmentServiceTest.php` (9 test case) — ผูก/เคลียร์ช่องยา + Optimistic Locking

**assign**: version ไม่ตรงปฏิเสธก่อนเสมอ, ผู้ป่วยผูกกับช่องอื่นอยู่แล้ว, ช่องมีคนอยู่แล้ว, กรณีปกติสำเร็จ. **clear**: ช่องว่างอยู่แล้วถือว่าสำเร็จไม่สนใจ version, version ไม่ตรงบนช่องที่มีคนอยู่ปฏิเสธ, กรณีปกติสำเร็จ

#### `MedicationTest.php` (11 test case) — ยาที่ถึงเวลาแต่ยังไม่จ่าย

ทดสอบ 3 เมธอด (`matchedDueTime`, `isDispensedToday`, `isDueNow`) แช่แข็งเวลาด้วย `Carbon::setTestNow()`

#### `NurseCodeGeneratorTest.php` (2 test case)

เติมศูนย์ให้ครบ 3 หลัก, ไม่ตัดทอนเลขที่เกิน 3 หลัก

### 5.2 Feature Tests (`tests/Feature/`) — 43 test, ยิง HTTP ผ่าน SQLite in-memory

#### `AuthControllerTest.php` (7 test) — `POST /api/register`, `/login`

สร้างพยาบาลใหม่พร้อมเลข QR ต่อเนื่อง, ปฏิเสธ username ซ้ำ/มีอักขระต้องห้าม/รหัสผ่านสั้นเกินไป, login สำเร็จ/ผิดรหัส/ไม่มีบัญชี

#### `DispenseControllerTest.php` (5 test) — `POST /api/verify-dispense`

สแกนถูกต้องจ่ายยาสำเร็จและ response มีแค่ 3 ฟิลด์ตามสัญญา (`result`, `drug_name`, `message` — ไม่มี `should_dispense` หลุดออกมา), QR ตลับยาไม่รู้จัก, ตลับยาเป็นของผู้ป่วยอื่น, จ่ายซ้ำในวันเดียวกัน, validation error เมื่อข้อมูลไม่ครบ — ทุกเคสตรวจสอบด้วยว่ามีการเขียน `dispense_logs` ถูกต้อง

#### `SlotControllerTest.php` (11 test) — `GET/POST/PUT /api/slots/*`

list ช่องพร้อมข้อมูลผู้ป่วยปัจจุบัน, สร้างช่องใหม่/ปฏิเสธรหัสซ้ำ, assign สำเร็จเพิ่ม version/ปฏิเสธ version เก่าด้วย 409 พร้อม server_state/ปฏิเสธผู้ป่วยที่ผูกช่องอื่นอยู่/ปฏิเสธช่องที่มีคนอยู่แล้ว, clear สำเร็จ/no-op เมื่อว่างอยู่แล้ว/ปฏิเสธ version เก่า, ประวัติการใช้งานเรียงล่าสุดก่อน

#### `PatientControllerTest.php` (9 test) — `GET/POST/PUT /api/patients/*`

list ผู้ป่วยพร้อมข้อมูลช่องที่ผูกอยู่ (derive จาก `cart_slots` ไม่ใช่คอลัมน์เก่า), เรียงยาตามเวลาที่ใกล้ที่สุดก่อน (ยา PRN ไม่มีเวลาอยู่ท้ายสุด), สร้างผู้ป่วยพร้อมรายการยาในคำขอเดียว, ปฏิเสธ QR ซ้ำ, แก้ไขแทนที่รายการยา (เก็บ id เดิมที่ยังอยู่ ลบที่หายไป เพิ่มที่ใหม่), อนุญาตให้ผู้ป่วยคงรหัส QR เดิมของตัวเองตอนแก้ไข

#### `ReminderControllerTest.php` (4 test) — `GET /api/due-medications`, `/api/reminder-times`

แสดงเฉพาะยาที่ถึงเวลาแล้วและยังไม่จ่าย, รวมเวลาแจ้งเตือนที่ไม่ซ้ำและเรียงลำดับ (ข้ามรูปแบบเวลาที่ไม่ใช่ HH:MM เช่นข้อความ PRN ภาษาไทย), ไม่รวมยาที่จ่ายไปแล้ววันนี้

#### `StatsControllerTest.php` (3 test) — `GET /api/dispense-stats`

สรุปยอดรวม/อัตราการจ่ายยา/แยกตามรอบเวลา-วอร์ด-ชื่อยา, กรณีไม่มีข้อมูลเลย, แนวโน้ม 7 วันนับเฉพาะการจ่ายที่ถูกต้อง (`result = correct`)

#### `DispenseLogControllerTest.php` (3 test) — `GET /api/dispense-logs`

เรียงลำดับล่าสุดก่อน พร้อมชื่อยา/พยาบาล/ผู้ป่วยที่ resolve จากความสัมพันธ์, กรณีไม่มี log, จำกัดผลลัพธ์ไม่เกิน 100 รายการ

## 6. คำสั่งรันเทส

```bash
php artisan test                          # รันทั้ง Unit + Feature
php artisan test --testsuite=Unit         # เฉพาะ Unit (ไม่พึ่ง DB)
php artisan test --testsuite=Feature      # เฉพาะ Feature (SQLite in-memory)
vendor/bin/phpunit --coverage-text        # พร้อม coverage (ต้องมี PCOV)
```
