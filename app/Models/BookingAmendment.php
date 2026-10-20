<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingAmendment extends Model
{
    protected $table = 'booking_amendments';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['proposal' => 'array'];
    }
}
