<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $table = 'invoices';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['lines' => 'array', 'total_minor' => 'integer'];
    }
}
