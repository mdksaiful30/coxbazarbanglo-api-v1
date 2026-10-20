<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BookingEvent;

class Audit
{
    public static function record(string $action, string $entity, ?int $id, array $data = []): void
    {
        AuditLog::create(['actor_id' => auth()->id(), 'action' => $action, 'entity' => $entity, 'entity_id' => $id, 'data' => $data]);
    }

    public static function booking($booking, string $action, ?string $reason = null, array $data = []): void
    {
        BookingEvent::create(['booking_id' => $booking->id, 'actor_id' => auth()->id(), 'action' => $action, 'reason' => $reason, 'data' => $data]);
        self::record($action, 'booking', $booking->id, $data);
    }
}
