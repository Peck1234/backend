<?php

namespace App\Support;

// Pure formatting logic extracted from AuthController::register() so the
// nurse QR code format (zero-padded, 3 digits minimum) can be unit tested
// directly.
class NurseCodeGenerator
{
    public static function forId(int $id): string
    {
        return 'NURSE-' . str_pad((string) $id, 3, '0', STR_PAD_LEFT);
    }
}
