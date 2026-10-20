<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingAmendment;
use App\Models\CreditNote;
use App\Models\Guest;
use App\Models\Invoice;
use App\Models\NotificationDelivery;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomCalendarEntry;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingService
{
    public function __construct(public PricingService $pricing, public AvailabilityService $availability) {}

    public function assertCurrentArrival(string $arrival): void
    {
        $date = \Carbon\CarbonImmutable::parse($arrival, 'Asia/Dhaka')->setTimezone('Asia/Dhaka');
        if ($date->startOfDay()->lt(\Carbon\CarbonImmutable::now('Asia/Dhaka')->startOfDay())) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'check_in_at' => 'Arrival cannot be before today (Asia/Dhaka). Choose today or a future date.',
            ]);
        }
    }

    public function payload(Booking $b): array
    {
        return ['guest_id' => $b->guest_id, 'guest_category_id' => $b->guest_category_id, 'room_ids' => $b->rooms()->pluck('rooms.id')->all(), 'check_in_at' => $b->check_in_at, 'check_out_at' => $b->check_out_at, 'tariff_version_id' => $b->tariff_version_id];
    }

    public function create(array $data): Booking
    {
        $this->assertCurrentArrival($data['check_in_at']);
        return DB::transaction(function () use ($data) {
            $guest = Guest::findOrFail($data['guest_id']);
            $quote = $this->pricing->quote($data);
            $this->availability->assertAvailable($data['room_ids'], $data['check_in_at'], $data['check_out_at']);
            $booking = Booking::create(collect($data)->except(['room_ids'])->merge(['number' => 'CB-'.now()->format('ymd').'-'.Str::upper(Str::random(6)), 'created_by' => auth()->id(), 'guest_snapshot' => $guest->only(['name', 'designation', 'organization', 'mobile', 'email', 'address']), 'quote' => $quote, 'total_minor' => $quote['total_minor'], 'tariff_version_id' => $quote['tariff_version_id'], 'status' => 'draft'])->all());
            $booking->rooms()->sync($data['room_ids']);
            Audit::booking($booking, 'created');

            return $booking->fresh()->load('rooms');
        });
    }


    public function updateApproved(int $id, array $data): Booking
    {
        abort_unless(auth()->user()->role === 'admin', 403, 'Only an administrator can edit approved bookings.');
        return DB::transaction(function () use ($id, $data) {
            $b = Booking::lockForUpdate()->findOrFail($id);
            $this->version($b, $data);
            abort_unless(in_array($b->status, ['confirmed', 'checked_in', 'checked_out']), 409, 'Only approved bookings can be edited here.');
            $before = $b->load('rooms')->toArray();
            $oldRooms = $b->rooms->pluck('id')->all();
            $this->availability->lockRooms(array_values(array_unique(array_merge($oldRooms, $data['room_ids']))));
            if ($b->status !== 'checked_out') {
                $this->availability->assertAvailable($data['room_ids'], $data['check_in_at'], $data['check_out_at'], $b->id);
            } else {
                abort_if(now()->lt($data['check_out_at']), 422, 'A completed stay cannot be moved into the future.');
            }
            if ($b->status === 'checked_in') {
                abort_if(now()->lt($data['check_in_at']) || now()->gte($data['check_out_at']), 422, 'An occupied booking must include the current time.');
                $added = array_diff($data['room_ids'], $oldRooms);
                if ($added) {
                    abort_if(Room::whereIn('id', $added)->where('housekeeping', '!=', 'ready')->exists(), 409, 'New rooms must be ready before moving an occupied guest.');
                    abort_if(Booking::where('status', 'checked_in')->where('id', '!=', $id)->whereHas('rooms', fn ($q) => $q->whereIn('rooms.id', $added))->exists(), 409, 'Another guest still occupies a selected room.');
                }
            }
            $tariff = \App\Models\TariffVersion::findOrFail($data['tariff_version_id'] ?? $b->tariff_version_id);
            abort_unless($tariff->approved_at && $tariff->calculation_mode && $tariff->effective_from && substr($data['check_in_at'], 0, 10) >= $tariff->effective_from, 422, 'Select an approved tariff effective on arrival.');
            $data['tariff_version_id'] = $tariff->id;
            $quote = $this->pricing->quote($data);
            $quote['provisional'] = false;
            $capacity = (int) Room::whereIn('id', $data['room_ids'])->sum('capacity');
            abort_if(($data['guest_count'] ?? $b->guest_count) > $capacity, 422, 'Guest count exceeds selected room capacity.');
            $delta = $quote['total_minor'] - $b->total_minor;
            $finance = app(FinanceService::class)->summary($b);
            abort_if($finance['charges_minor'] + $delta < 0, 422, 'Existing credits exceed the revised charges. Reconcile the financial adjustments first.');
            if ($delta > 0) {
                Invoice::create(['number' => 'INV-'.Str::upper(Str::random(10)), 'booking_id' => $id, 'total_minor' => $delta, 'lines' => [['description' => 'Approved booking revision: '.$data['reason'], 'date' => now()->toDateString(), 'room_code' => 'Adjustment', 'amount_minor' => $delta]]]);
            } elseif ($delta < 0) {
                CreditNote::create(['booking_id' => $id, 'number' => 'CRN-'.Str::upper(Str::random(10)), 'amount_minor' => -$delta, 'reason' => 'Booking revision: '.$data['reason'], 'created_by' => auth()->id()]);
            }
            $guest = Guest::findOrFail($data['guest_id']);
            $snapshot = $data['guest_details'] ?? ((int) $data['guest_id'] === $b->guest_id ? $b->guest_snapshot : $guest->only(['name', 'designation', 'organization', 'mobile', 'email', 'address']));
            $b->fill(collect($data)->only(['guest_id', 'guest_category_id', 'check_in_at', 'check_out_at', 'reference_letter', 'reference_date', 'guest_count', 'purpose', 'notes', 'tariff_version_id'])->all());
            $b->guest_snapshot = $snapshot;
            $b->quote = $quote;
            $b->total_minor = $quote['total_minor'];
            $b->version++;
            $b->save();
            $b->rooms()->sync($data['room_ids']);
            if ($b->status !== 'checked_out') {
                RoomCalendarEntry::where('booking_id', $id)->where('active', true)->update(['active' => false]);
                foreach ($data['room_ids'] as $roomId) {
                    RoomCalendarEntry::create(['room_id' => $roomId, 'booking_id' => $id, 'kind' => 'booking', 'starts_at' => $b->check_in_at, 'ends_at' => $b->check_out_at, 'active' => true]);
                }
            }
            if ($b->status === 'checked_in') {
                Room::whereIn('id', array_diff($oldRooms, $data['room_ids']))->update(['housekeeping' => 'needs_cleaning']);
            }
            Audit::booking($b, 'admin_updated', $data['reason'], ['before' => $before, 'after' => $b->fresh()->load('rooms')->toArray(), 'price_difference_minor' => $delta]);
            if ($data['notify_guest'] ?? false) $this->notify($b);
            return $b->fresh()->load('rooms', 'events');
        });
    }

    public function updateDraft(int $id, array $data): Booking
    {
        $this->assertCurrentArrival($data['check_in_at']);
        return DB::transaction(function () use ($id, $data) {
            $b = Booking::lockForUpdate()->findOrFail($id);
            $this->owner($b);
            abort_unless(in_array($b->status, ['draft', 'rejected']), 409, 'Only drafts and rejected requests can be edited.');
            $this->version($b, $data);
            $quote = $this->pricing->quote($data);
            $this->availability->assertAvailable($data['room_ids'], $data['check_in_at'], $data['check_out_at']);
            $b->fill(collect($data)->except(['room_ids', 'version'])->all());
            $b->guest_snapshot = Guest::findOrFail($data['guest_id'])->only(['name', 'designation', 'organization', 'mobile', 'email', 'address']);
            $b->quote = $quote;
            $b->total_minor = $quote['total_minor'];
            $b->tariff_version_id = $quote['tariff_version_id'];
            $b->status = 'draft';
            $b->version++;
            $b->save();
            $b->rooms()->sync($data['room_ids']);
            Audit::booking($b, 'draft_updated');

            return $b->load('rooms');
        });
    }

    public function owner(Booking $b): void
    {
        abort_unless(auth()->user()->role === 'admin' || $b->created_by === auth()->id(), 403, 'This request belongs to another staff member.');
    }

    public function version(Booking $b, array $data): void
    {
        abort_unless(isset($data['version']) && (int) $data['version'] === $b->version, 409, 'This booking changed. Refresh before continuing.');
    }

    public function transition(int $id, string $action, array $data): Booking
    {
        return DB::transaction(function () use ($id, $action, $data) {
            $b = Booking::lockForUpdate()->findOrFail($id);
            $this->version($b, $data);
            $admin = auth()->user()->role === 'admin';
            if ($action === 'submit') {
                $this->owner($b);
                abort_unless($b->status === 'draft', 409, 'Only a draft can be submitted.');
                $this->assertCurrentArrival($b->check_in_at);
                $b->status = 'pending_approval';
            } else {
                abort_unless($admin, 403, 'Administrator approval is required.');
                if ($action === 'approve') {
                    abort_unless($b->status === 'pending_approval' || ($b->status === 'draft' && $b->created_by === auth()->id()), 409, 'This booking cannot be approved.');
                    $this->assertCurrentArrival($b->check_in_at);
                    $this->allocate($b, $data['reservation_id'] ?? null);
                    $b->status = 'confirmed';
                    $b->approved_by = auth()->id();
                    $b->approved_at = now();
                } elseif (in_array($action, ['reject', 'return-for-correction'])) {
                    abort_unless($b->status === 'pending_approval', 409, 'Only a pending request can be reviewed.');
                    abort_if(empty($data['reason']), 422, 'A reason is required.');
                    $b->status = $action === 'reject' ? 'rejected' : 'draft';
                } elseif (in_array($action, ['cancel', 'mark-no-show'])) {
                    abort_unless(in_array($b->status, ['draft', 'pending_approval', 'confirmed']), 409, 'An occupied or completed booking cannot be cancelled.');
                    abort_if(empty($data['reason']), 422, 'A reason is required.');
                    if ($action === 'mark-no-show') {
                        abort_if(now()->lt($b->check_in_at), 409, 'Arrival time has not passed.');
                    }
                    $this->availability->lockRooms($b->rooms()->pluck('rooms.id')->all());
                    $b->status = $action === 'cancel' ? 'cancelled' : 'no_show';
                    RoomCalendarEntry::where('booking_id', $b->id)->update(['active' => false]);
                    // Preserve the original invoice. An authorized credit is a separate financial action.
                } elseif ($action === 'check-in') {
                    abort_unless($b->status === 'confirmed', 409, 'Only a confirmed booking can check in.');
                    abort_if(now()->lt($b->check_in_at) || now()->gte($b->check_out_at), 409, 'Check-in is outside the approved stay. Amend the booking first.');
                    $ids = $b->rooms()->pluck('rooms.id')->all();
                    $this->availability->lockRooms($ids);
                    abort_if(Room::whereIn('id', $ids)->where('housekeeping', '!=', 'ready')->exists(), 409, 'All rooms must be marked ready before check-in.');
                    abort_if(Booking::where('status', 'checked_in')->where('id', '!=', $b->id)->whereHas('rooms', fn ($q) => $q->whereIn('rooms.id', $ids))->exists(), 409, 'A previous guest is still checked in.');
                    $b->status = 'checked_in';
                    $b->actual_check_in_at = now();
                } elseif ($action === 'check-out') {
                    abort_unless($b->status === 'checked_in', 409, 'Only an occupied booking can check out.');
                    $this->availability->lockRooms($b->rooms()->pluck('rooms.id')->all());
                    $balance = app(FinanceService::class)->summary($b)['balance_minor'];
                    abort_if($balance > 0 && empty($data['reason']), 422, 'Provide a reason for checkout with an outstanding balance.');
                    $b->status = 'checked_out';
                    $b->actual_check_out_at = now();
                    RoomCalendarEntry::where('booking_id', $b->id)->update(['active' => false]);
                    Room::whereIn('id', $b->rooms()->pluck('rooms.id'))->update(['housekeeping' => 'needs_cleaning']);
                } else {
                    abort(404);
                }
            }
            $b->version++;
            $b->save();
            Audit::booking($b, $action, $data['reason'] ?? null);
            if ($action === 'approve') {
                $this->notify($b);
            }

            return $b->load('rooms', 'events');
        });
    }

    public function allocate(Booking $b, ?int $reservationId = null): void
    {
        $data = $this->payload($b);
        $this->availability->lockRooms($data['room_ids']);
        if ($reservationId) {
            $block = RoomBlock::lockForUpdate()->findOrFail($reservationId);
            abort_unless($block->kind === 'reservation' && $block->status === 'active', 409, 'Reservation is not active.');
            $entries = $block->entries()->where('active', true)->get();
            abort_unless($entries->pluck('room_id')->sort()->values()->all() === collect($data['room_ids'])->sort()->values()->all(), 409, 'Reservation rooms must match.');
            abort_if($entries->contains(fn ($e) => $e->starts_at > $b->check_in_at || $e->ends_at < $b->check_out_at), 409, 'Booking dates exceed the reservation.');
        }
        $this->availability->assertAvailable($data['room_ids'], $b->check_in_at, $b->check_out_at, $b->id, $reservationId);
        $quote = $this->pricing->quote($data, true);
        $capacity = (int) Room::whereIn('id', $data['room_ids'])->sum('capacity');
        abort_if($b->guest_count > $capacity, 422, 'Guest count exceeds the verified capacity of the selected rooms.');
        $b->quote = $quote;
        $b->total_minor = $quote['total_minor'];
        $b->tariff_version_id = $quote['tariff_version_id'];
        RoomCalendarEntry::where('booking_id', $b->id)->where('active', true)->update(['active' => false]);
        foreach ($data['room_ids'] as $room) {
            RoomCalendarEntry::create(['room_id' => $room, 'booking_id' => $b->id, 'kind' => 'booking', 'starts_at' => $b->check_in_at, 'ends_at' => $b->check_out_at, 'active' => true]);
        }
        if ($reservationId) {
            $block->update(['status' => 'converted']);
            $block->entries()->update(['active' => false]);
        }
        Invoice::create(['number' => 'INV-'.Str::upper(Str::random(10)), 'booking_id' => $b->id, 'total_minor' => $quote['total_minor'], 'lines' => $quote['lines']]);
    }

    public function notify(Booking $b): void
    {
        $channel = Setting::where('key', 'operations')->value('value')['notification_channel'] ?? 'email';
        $destination = $b->guest_snapshot[$channel === 'email' ? 'email' : 'mobile'] ?? null;
        if (! $destination) {
            Audit::record('notification_missing_contact', 'booking', $b->id, ['channel' => $channel]);

            return;
        }
        NotificationDelivery::firstOrCreate(['dedupe_key' => 'confirmation-'.$b->id.'-'.$b->version.'-'.$channel], ['booking_id' => $b->id, 'channel' => $channel, 'destination' => $destination]);
    }

    public function propose(int $id, array $data): BookingAmendment
    {
        return DB::transaction(function () use ($id, $data) {
            $b = Booking::lockForUpdate()->findOrFail($id);
            $this->owner($b);
            $this->version($b, $data);
            abort_unless($b->status === 'confirmed', 409, 'Amendments are currently supported before check-in.');
            $proposal = collect($data)->except(['version', 'reason'])->all();
            $quote = $this->pricing->quote($proposal);
            $proposal['quote'] = $quote;
            $a = BookingAmendment::create(['booking_id' => $b->id, 'base_version' => $b->version, 'proposal' => $proposal, 'reason' => $data['reason'], 'created_by' => auth()->id()]);
            Audit::booking($b, 'amendment_proposed', $data['reason'], ['amendment_id' => $a->id]);

            return $a;
        });
    }

    public function decideAmendment(int $id, string $action): BookingAmendment
    {
        return DB::transaction(function () use ($id, $action) {
            $a = BookingAmendment::findOrFail($id);
            $b = Booking::lockForUpdate()->findOrFail($a->booking_id);
            $a = BookingAmendment::lockForUpdate()->findOrFail($id);
            abort_unless($a->status === 'pending' && $b->status === 'confirmed', 409, 'This amendment cannot be applied.');
            if ($action === 'approve') {
                abort_unless($b->version === $a->base_version, 409, 'The original booking changed. Submit a new amendment.');
                abort_if($b->payments()->where('status', 'posted')->exists() || CreditNote::where('booking_id', $b->id)->exists(), 409, 'Paid bookings require a financial adjustment workflow before amendment.');
                $oldRooms = $b->rooms()->pluck('rooms.id')->all();
                $data = $a->proposal;
                $this->availability->lockRooms(array_unique(array_merge($oldRooms, $data['room_ids'])));
                $before = $b->toArray();
                $b->fill(collect($data)->except(['room_ids', 'quote'])->all());
                $b->guest_snapshot = Guest::findOrFail($b->guest_id)->only(['name', 'designation', 'organization', 'mobile', 'email', 'address']);
                $b->rooms()->sync($data['room_ids']);
                $b->invoices()->where('status', 'issued')->update(['status' => 'superseded']);
                $this->allocate($b);
                $b->version++;
                $b->save();
                Audit::booking($b,'amendment_approved',$a->reason,['before' => $before, 'after' => $b->toArray()]);
                $this->notify($b);
            }
            $a->update(['status' => $action === 'approve' ? 'approved' : 'rejected', 'decided_by' => auth()->id()]);

            return $a;
        });
    }
}
