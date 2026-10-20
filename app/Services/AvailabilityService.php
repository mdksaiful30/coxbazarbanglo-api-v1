<?php

namespace App\Services;

use App\Models\Room;
use App\Models\RoomCalendarEntry;
use App\Models\Setting;
use Carbon\CarbonImmutable;

class AvailabilityService
{
    public function lockRooms(array $ids): void
    {
        Room::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
    }

    public function assertAvailable(array $ids, string $start, string $end, ?int $ignoreBooking = null, ?int $ignoreBlock = null): void
    {
        $rooms = Room::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        abort_if($rooms->count() !== count(array_unique($ids)), 422, 'Invalid rooms.');
        abort_if($rooms->contains(fn ($r) => ! $r->bookable), 409, 'A selected room is not verified for booking.');
        $query = $this->conflicts($start, $end)->whereIn('room_id', $ids);
        if ($ignoreBooking) {
            $query->where(fn ($q) => $q->whereNull('booking_id')->orWhere('booking_id', '!=', $ignoreBooking));
        }
        if ($ignoreBlock) {
            $query->where(fn ($q) => $q->whereNull('room_block_id')->orWhere('room_block_id', '!=', $ignoreBlock));
        }
        // Current reads are essential under MySQL REPEATABLE READ after waiting for room locks.
        abort_if($query->lockForUpdate()->first() !== null, 409, 'A selected room is already booked, reserved or under maintenance for these dates.');
    }

    public function conflicts(string $start, string $end)
    {
        $minutes = (int) (Setting::where('key', 'operations')->value('value')['turnaround_minutes'] ?? 0);
        $from = CarbonImmutable::parse($start)->subMinutes($minutes)->format('Y-m-d H:i:s');
        $until = CarbonImmutable::parse($end)->addMinutes($minutes)->format('Y-m-d H:i:s');

        return RoomCalendarEntry::where('active', true)->where('starts_at', '<', $until)->where('ends_at', '>', $from);
    }

    public function search(string $start, string $end, ?int $excludeBooking = null)
    {
        $query = $this->conflicts($start, $end);
        if ($excludeBooking) $query->where(fn ($q) => $q->whereNull('booking_id')->orWhere('booking_id', '!=', $excludeBooking));
        $conflicts = $query->get()->groupBy('room_id');

        return Room::with('floor')->orderBy('code')->get()->map(function ($room) use ($conflicts) {
            $room->setAttribute('available', $room->bookable && ! $conflicts->has($room->id));
            $room->setAttribute('conflicts', $conflicts->get($room->id, collect()));

            return $room;
        });
    }
}
