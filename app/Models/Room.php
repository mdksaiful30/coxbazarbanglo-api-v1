<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    protected $table = 'rooms';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['bookable' => 'boolean', 'capacity' => 'integer'];
    }

    public function floor()
    {
        return $this->belongsTo(Floor::class);
    }
}
