<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GuestCategory extends Model
{
    protected $table = 'guest_categories';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['max_nights' => 'integer'];
    }
}
