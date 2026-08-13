<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Nurse extends Model
{
    use HasFactory;

    protected $fillable = [
        'username',
        'password',
        'full_name',
        'qr_code_nurse',
    ];

    protected $hidden = [
        'password',
    ];
}
