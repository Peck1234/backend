<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

// Nurse is the app's only real user/principal, so it plays the role Laravel's
// stock User model normally would: implementing Authenticatable (via the
// trait, since column names - id/password - already match the trait's
// defaults) lets HasApiTokens/Sanctum treat it as a first-class token holder
// without needing a separate User row per nurse.
class Nurse extends Model implements AuthenticatableContract
{
    use HasFactory, HasApiTokens, Authenticatable;

    protected $fillable = [
        'username',
        'email',
        'password',
        'full_name',
        'qr_code_nurse',
        'profile_photo_path',
    ];

    protected $hidden = [
        'password',
    ];
}
