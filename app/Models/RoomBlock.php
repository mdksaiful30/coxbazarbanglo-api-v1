<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomBlock extends Model
{
    protected $table = 'room_blocks';

    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }

    public function entries()
    {
        return $this->hasMany(RoomCalendarEntry::class);
    }
}
