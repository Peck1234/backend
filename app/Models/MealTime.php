<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// An admin's override of one meal round's time; see the meal_times migration.
class MealTime extends Model
{
    protected $primaryKey = 'meal';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['meal', 'time', 'updated_by'];
}
