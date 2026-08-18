<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: sans-serif; background:#FAFAFF; padding: 24px; color:#171325;">
    <div style="max-width:480px; margin:0 auto; background:#fff; border:1px solid #E6E4F0; border-radius:14px; padding:32px;">
        <p>รหัสยืนยันสำหรับตั้งรหัสผ่านใหม่ของคุณคือ</p>
        <p style="font-size:32px; font-weight:800; letter-spacing:6px; color:#4338CA; text-align:center; margin:24px 0;">
            {{ $code }}
        </p>
        <p>รหัสนี้จะหมดอายุใน {{ $expiresInMinutes }} นาที และใช้ได้เพียงครั้งเดียว</p>
        <p style="color:#5B5773; font-size:13px;">หากคุณไม่ได้ร้องขอการตั้งรหัสผ่านใหม่ สามารถละเว้นอีเมลฉบับนี้ได้</p>
    </div>
</body>
</html>
