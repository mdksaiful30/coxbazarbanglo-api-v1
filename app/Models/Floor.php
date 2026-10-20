<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Floor extends Model
{
    protected $table = 'floors';

    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }
}
