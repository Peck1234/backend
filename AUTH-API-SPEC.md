# Auth API เพิ่มเติม — สำหรับ Frontend Session

เอกสารนี้ครอบคลุมเฉพาะสิ่งที่ **เปลี่ยน/เพิ่มใหม่** ใน endpoint กลุ่ม auth เท่านั้น (endpoint อื่นๆ ของระบบไม่เปลี่ยน)

## สรุปการเปลี่ยนแปลง

1. `POST /api/register` — ตอนนี้**ต้องส่ง `email` มาด้วย** (required, unique) และ response มีฟิลด์ `email` + `token` เพิ่มมา
2. `POST /api/login` — response มีฟิลด์ `token` เพิ่มมา (field เดิมทั้งหมดยังอยู่ครบ ไม่มีอะไรถูกลบ)
3. `POST /api/forgot-password`, `POST /api/reset-password` — endpoint ใหม่ (public, ไม่ต้อง token)
4. `PUT /api/profile`, `POST /api/change-password` — endpoint ใหม่ **ต้องแนบ `Authorization: Bearer <token>`**

`token` คือ Laravel Sanctum personal access token — เก็บไว้หลัง login/register สำเร็จ แล้วแนบ header `Authorization: Bearer <token>` ทุกครั้งที่เรียก `/api/profile` หรือ `/api/change-password`

---

## `POST /api/register`

**Request**
```json
{
  "full_name": "สมหญิง ใจดี",
  "username": "somying",
  "email": "somying@example.com",
  "password": "secret123"
}
```

**Response 201**
```json
{
  "nurse_id": 12,
  "full_name": "สมหญิง ใจดี",
  "username": "somying",
  "email": "somying@example.com",
  "qr_code_nurse": "NURSE-012",
  "token": "12|AbCdEf123456..."
}
```

**Response 422** (validation — username/email ซ้ำ, รูปแบบ email ผิด, รหัสผ่านสั้นกว่า 6 ตัว ฯลฯ)
```json
{ "message": "The given data was invalid.", "errors": { "email": ["The email has already been taken."] } }
```

---

## `POST /api/login`

**Request**
```json
{ "username": "somying", "password": "secret123" }
```

**Response 200** — เหมือนเดิมทุกฟิลด์ + เพิ่ม `token`
```json
{
  "nurse_id": 12,
  "full_name": "สมหญิง ใจดี",
  "username": "somying",
  "email": "somying@example.com",
  "qr_code_nurse": "NURSE-012",
  "token": "13|XyZ987654..."
}
```

**Response 401**
```json
{ "detail": "ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง" }
```

---

## `POST /api/forgot-password`

ไม่ต้อง token — ส่งรหัสยืนยัน 6 หลักไปทางอีเมล อายุ 15 นาที **response เหมือนกันทุกกรณี ไม่ว่าอีเมลนี้จะมีในระบบหรือไม่** (กัน email enumeration) — ฝั่งแอปควรแสดงข้อความเดียวกันเสมอ ไม่ต้องแยก error/success ตามว่าเจออีเมลไหม

**Request**
```json
{ "email": "somying@example.com" }
```

**Response 200** (เสมอ ไม่ว่าอีเมลจะมีจริงหรือไม่)
```json
{ "message": "หากอีเมลนี้มีอยู่ในระบบ เราได้ส่งรหัสยืนยันไปให้แล้ว" }
```

ขอรหัสซ้ำได้เรื่อยๆ — รหัสเก่าจะถูกยกเลิกอัตโนมัติทุกครั้งที่ขอใหม่ (ใช้ได้แค่รหัสล่าสุด)

---

## `POST /api/reset-password`

**Request**
```json
{ "email": "somying@example.com", "code": "482913", "new_password": "newpass456" }
```

**Response 200**
```json
{ "message": "ตั้งรหัสผ่านใหม่สำเร็จ" }
```

**Response 422** (รหัสผิด, หมดอายุ, หรือใช้ไปแล้ว — ข้อความเดียวกันหมดไม่แยกสาเหตุ)
```json
{ "detail": "รหัสยืนยันไม่ถูกต้องหรือหมดอายุ" }
```

รหัสใช้ได้ครั้งเดียว — พอ reset สำเร็จแล้ว รหัสเดิม (และรหัสเก่าอื่นๆ ที่ค้างของอีเมลนั้น) จะถูกล้างทิ้งทันที ต้องขอใหม่ถ้าจะ reset อีกรอบ

---

## `PUT /api/profile` — ต้อง Bearer token

**Headers**: `Authorization: Bearer <token>`

**Request**
```json
{ "full_name": "สมหญิง ใจดี (แก้ไข)", "username": "somying2", "email": "new@example.com" }
```

ส่งได้ทั้ง 3 ฟิลด์เสมอ (แม้ไม่ได้เปลี่ยนบางฟิลด์ ก็ส่งค่าเดิมมาด้วย — endpoint นี้ update ทั้งก้อน ไม่ใช่ partial update) — คงค่า username/email เดิมของตัวเองได้ ไม่ต้องเปลี่ยนทุกครั้ง

**Response 200**
```json
{
  "nurse_id": 12, "full_name": "สมหญิง ใจดี (แก้ไข)",
  "username": "somying2", "email": "new@example.com", "qr_code_nurse": "NURSE-012"
}
```

**Response 401** (ไม่มี/token ผิด) — `{"message":"Unauthenticated."}`
**Response 422** (username/email ซ้ำกับคนอื่น) — shape เดียวกับ validation error ปกติ

---

## `POST /api/change-password` — ต้อง Bearer token

**Headers**: `Authorization: Bearer <token>`

**Request**
```json
{ "current_password": "secret123", "new_password": "newpass456" }
```

**Response 200**
```json
{ "message": "เปลี่ยนรหัสผ่านสำเร็จ" }
```

**Response 422** (current_password ผิด)
```json
{ "detail": "รหัสผ่านปัจจุบันไม่ถูกต้อง" }
```

---

## curl ตัวอย่าง (ทดสอบเองได้)

```bash
# สมัครสมาชิก
curl -X POST http://<host>/api/register -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"full_name":"Test","username":"testuser","email":"test@example.com","password":"secret123"}'

# เข้าสู่ระบบ (เก็บ token จาก response)
curl -X POST http://<host>/api/login -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"username":"testuser","password":"secret123"}'

# ลืมรหัสผ่าน
curl -X POST http://<host>/api/forgot-password -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"email":"test@example.com"}'

# ตั้งรหัสผ่านใหม่ (เอารหัส 6 หลักจากอีเมล/log)
curl -X POST http://<host>/api/reset-password -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"email":"test@example.com","code":"123456","new_password":"newpass456"}'

# แก้ไขโปรไฟล์ (ต้องมี token)
curl -X PUT http://<host>/api/profile -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Authorization: Bearer <token>" \
  -d '{"full_name":"Test Updated","username":"testuser","email":"test@example.com"}'

# เปลี่ยนรหัสผ่าน (ต้องมี token)
curl -X POST http://<host>/api/change-password -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Authorization: Bearer <token>" \
  -d '{"current_password":"secret123","new_password":"newpass456"}'
```

## รันเทสอัตโนมัติ

```bash
php artisan test --filter=AuthControllerTest   # เฉพาะ auth (23 test case ใหม่/แก้ไข)
php artisan test                                # ทั้งหมด (91 test case)
```

## หมายเหตุสภาพแวดล้อม dev เครื่องนี้

- `MAIL_MAILER` เปลี่ยนเป็น `log` ชั่วคราว (เดิมชี้ไป `mailhog` ที่ไม่มี container จริง) — อีเมลที่ "ส่ง" ตอนนี้จะไปโผล่ที่ `storage/logs/laravel.log` แทนการส่งจริง เปิดดูรหัส 6 หลักได้จากในนั้นระหว่างทดสอบ ก่อนขึ้นใช้งานจริงต้องเปลี่ยนกลับเป็น SMTP จริง (Gmail, SendGrid ฯลฯ)
- รัน `php artisan migrate` ไปแล้ว ซึ่งได้รันย้อน migration ที่ค้างอยู่ก่อนหน้านี้ไปด้วย 1 ตัว (`drop_legacy_slot_fields_from_patients_table` — ลบคอลัมน์เก่าที่ไม่ใช้แล้วออกจากตาราง patients ไม่เกี่ยวกับงานนี้โดยตรง แต่เป็น migration ที่รออยู่ก่อนแล้วและปลอดภัยตามที่ระบุไว้ในตัวไฟล์เอง)
