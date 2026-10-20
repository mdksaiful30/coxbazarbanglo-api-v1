<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TariffVersion extends Model
{
    protected $table = 'tariff_versions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'vat_inclusive' => 'boolean', 'policy' => 'array'];
    }

    public function rates()
    {
        return $this->hasMany(TariffRate::class);
    }
}
