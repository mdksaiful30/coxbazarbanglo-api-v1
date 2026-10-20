<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TariffRate extends Model
{
    protected $table = 'tariff_rates';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }
}
