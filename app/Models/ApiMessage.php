<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'locale', 'text'])]
class ApiMessage extends Model
{
    public $timestamps = false;
}
