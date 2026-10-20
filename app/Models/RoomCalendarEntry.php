<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomCalendarEntry extends Model
{
    protected $table = 'room_calendar_entries';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
