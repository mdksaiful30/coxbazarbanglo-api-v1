<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    protected $table = 'refunds';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }
}
