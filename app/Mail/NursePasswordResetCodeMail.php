<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NursePasswordResetCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $code;
    public int $expiresInMinutes;

    public function __construct(string $code, int $expiresInMinutes)
    {
        $this->code = $code;
        $this->expiresInMinutes = $expiresInMinutes;
    }

    public function build()
    {
        return $this
            ->subject('รหัสยืนยันสำหรับตั้งรหัสผ่านใหม่')
            ->view('emails.password-reset-code');
    }
}
