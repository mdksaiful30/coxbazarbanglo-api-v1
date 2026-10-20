<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    protected $table = 'bookings';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['guest_snapshot' => 'array', 'quote' => 'array', 'total_minor' => 'integer', 'version' => 'integer'];
    }

    public function rooms()
    {
        return $this->belongsToMany(Room::class, 'booking_rooms')->withTimestamps();
    }

    public function events()
    {
        return $this->hasMany(BookingEvent::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function credits()
    {
        return $this->hasMany(CreditNote::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function amendments()
    {
        return $this->hasMany(BookingAmendment::class);
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class)->select(['id', 'booking_id', 'name', 'mime', 'size', 'created_at']);
    }

    public function deliveries()
    {
        return $this->hasMany(NotificationDelivery::class);
    }
}
