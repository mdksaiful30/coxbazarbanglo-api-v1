<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingEvent extends Model
{
    protected $table = 'booking_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
