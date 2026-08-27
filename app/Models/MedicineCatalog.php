<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MedicineCatalog extends Model
{
    use HasFactory;

    protected $table = 'medicine_catalog';

    protected $fillable = [
        'drug_name',
        'generic_name',
        'standard_dose',
        'category',
        'purpose',
        'contraindication',
        'is_favorite',
    ];

    protected $casts = [
        'is_favorite' => 'boolean',
    ];
}
