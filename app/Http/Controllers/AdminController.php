<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\GuestCategory;
use App\Models\NotificationDelivery;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomCalendarEntry;
use App\Models\Setting;
use App\Models\TariffVersion;
use App\Models\User;
use App\Services\Audit;
use App\Services\AvailabilityService;
use App\Services\FinanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function catalog()
    {
        return response()->json(['data' => ['rooms' => Room::with('floor')->orderBy('code')->get(), 'floors' => DB::table('floors')->get(), 'buildings' => DB::table('buildings')->get(), 'properties' => DB::table('properties')->get(), 'categories' => GuestCategory::all(), 'tariffs' => TariffVersion::with('rates')->get(), 'operations' => Setting::where('key', 'operations')->value('value')]]);
    }

    public function guests(Request $r)
    {
        $r->validate(['search' => 'nullable|string|max:100']);
        $q = Guest::latest();
        if ($r->search) {
            $q->where(fn ($x) => $x->where('name', 'like', '%'.$r->search.'%')->orWhere('mobile', 'like', '%'.$r->search.'%'));
        }

return $q->paginate(25);
    }

    public function guest(Request $r, ?int $id = null)
    {
        $d = $r->validate(['name' => 'required|string|max:255', 'designation' => 'nullable|string|max:255', 'organization' => 'nullable|string|max:255', 'mobile' => 'required|string|max:25', 'email' => 'nullable|email|max:255', 'address' => 'nullable|string|max:2000']);
        $phone = preg_replace('/[\s()\-]/', '', $d['mobile']);
        if (preg_match('/^01[3-9]\d{8}$/', $phone)) {
            $phone = '+88'.$phone;
        } if (str_starts_with($phone, '880')) {
            $phone = '+'.$phone;
        }
        abort_unless(preg_match('/^\+[1-9]\d{7,14}$/', $phone), 422, 'Use a valid international mobile number, such as +88017XXXXXXXX.');
        $d['mobile'] = $phone;
        if ($id) {
            $guest = Guest::findOrFail($id);
            $guest->update($d);
        } else {
            $guest = Guest::create($d);
        }
        Audit::record($id ? 'guest_updated' : 'guest_created', 'guest', $guest->id);

        return response()->json(['data' => $guest], $id ? 200 : 201);
    }

    public function room(Request $r, int $id)
    {
        $d = $r->validate(['name' => 'sometimes|required|string|max:255', 'capacity' => 'nullable|integer|min:1|max:20', 'bookable' => 'sometimes|boolean', 'housekeeping' => 'sometimes|in:ready,needs_cleaning,cleaning,unverified']);

        return DB::transaction(function () use ($d, $id) {
            $room = Room::lockForUpdate()->findOrFail($id);
            $before = $room->toArray();
            if (($d['bookable'] ?? false) && empty($d['capacity'] ?? $room->capacity)) {
                abort(422, 'Verify room capacity before enabling booking.');
            }
            $room->update($d);
            Audit::record('room_updated', 'room', $id, ['before' => $before, 'after' => $room->toArray()]);

            return response()->json(['data' => $room]);
        });
    }

    public function activate(Request $r, int $id)
    {
        $d = $r->validate(['calculation_mode' => 'required|in:progressive,whole_stay', 'effective_from' => 'required|date', 'check_in_time' => 'required|date_format:H:i', 'check_out_time' => 'required|date_format:H:i', 'turnaround_minutes' => 'required|integer|min:0|max:1440', 'notification_channel' => 'required|in:email,sms,whatsapp', 'policy_acknowledged' => 'required|accepted', 'reason' => 'required|string|max:2000']);

        return DB::transaction(function () use ($id, $d) {
            // Lock the property as the single activation mutex before touching tariff rows.
            DB::table('properties')->where('id', 1)->lockForUpdate()->first();
            $t = TariffVersion::findOrFail($id);
            abort_if($t->approved_at, 409, 'Approved tariff versions are immutable. Clone a version for changes.');
            TariffVersion::where('active', true)->update(['active' => false]);
            $policy = ['rate_unit' => 'room_night', 'day_definition' => 'overnight', 'same_day' => 'not_allowed', 'mid_stay_tariff' => 'arrival_version', 'extensions' => 'requote_before_arrival_only', 'continuous_stay' => 'manual_admin_review', 'check_in_time' => $d['check_in_time'], 'check_out_time' => $d['check_out_time'], 'turnaround_minutes' => $d['turnaround_minutes'], 'reason' => $d['reason']];
            $t->update(['active' => true, 'calculation_mode' => $d['calculation_mode'], 'effective_from' => $d['effective_from'], 'policy' => $policy, 'approved_by' => auth()->id(), 'approved_at' => now()]);
            Setting::updateOrCreate(['key' => 'operations'], ['value' => collect($d)->only(['check_in_time', 'check_out_time', 'turnaround_minutes', 'notification_channel'])->all()]);
            Audit::record('tariff_activated', 'tariff', $id, $d);

            return response()->json(['data' => $t]);
        });
    }

    public function cloneTariff(Request $r, int $id)
    {
        $d = $r->validate(['name' => 'required|string|max:255', 'reference' => 'required|string|max:255']);

        return DB::transaction(function () use ($id, $d) {
            $old = TariffVersion::with('rates')->findOrFail($id);
            $new = TariffVersion::create($d + ['vat_inclusive' => true, 'active' => false]);
            foreach ($old->rates as $rate) {
                $new->rates()->create($rate->only(['guest_category_id', 'ac_type', 'min_nights', 'max_nights', 'amount_minor']));
            } Audit::record('tariff_cloned', 'tariff', $new->id);

            return response()->json(['data' => $new->load('rates')], 201);
        });
    }

    public function rate(Request $r, int $id, int $rateId)
    {
        $d = $r->validate(['amount_minor' => 'required|integer|min:0|max:100000000']);

        return DB::transaction(function () use ($id, $rateId, $d) {
            $t = TariffVersion::lockForUpdate()->findOrFail($id);
            abort_if($t->approved_at, 409, 'Approved tariff rates are immutable.');
            $rate = $t->rates()->findOrFail($rateId);
            $before = $rate->amount_minor;
            $rate->update($d);
            Audit::record('rate_updated', 'tariff_rate', $rateId, ['before' => $before, 'after' => $rate->amount_minor]);

            return response()->json(['data' => $rate]);
        });
    }

    public function blocks()
    {
        return response()->json(['data' => RoomBlock::with('entries')->latest()->limit(200)->get()]);
    }

    public function block(Request $r, AvailabilityService $a)
    {
        $d = $r->validate(['kind' => 'required|in:reservation,maintenance', 'reason' => 'required|string|max:255', 'room_ids' => 'required|array|min:1', 'room_ids.*' => 'required|integer|distinct|exists:rooms,id', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at']);
        foreach (['starts_at', 'ends_at'] as $field) {
            $d[$field] = CarbonImmutable::parse($d[$field], 'Asia/Dhaka')->setTimezone('Asia/Dhaka')->format('Y-m-d H:i:s');
        }

        return DB::transaction(function () use ($d, $a) {
            $a->lockRooms($d['room_ids']);
            // Maintenance is allowed on unverified rooms but must still pass conflict checks.
            abort_if($a->conflicts($d['starts_at'], $d['ends_at'])->whereIn('room_id', $d['room_ids'])->lockForUpdate()->first() !== null, 409, 'These rooms have conflicting allocations.');
            $b = RoomBlock::create(['kind' => $d['kind'], 'reason' => $d['reason'], 'created_by' => auth()->id()]);
            foreach ($d['room_ids'] as $room) {
                RoomCalendarEntry::create(['room_id' => $room, 'room_block_id' => $b->id, 'kind' => $d['kind'], 'starts_at' => $d['starts_at'], 'ends_at' => $d['ends_at'], 'active' => true]);
            }
            Audit::record('block_created', 'room_block', $b->id, $d);

            return response()->json(['data' => $b->load('entries')], 201);
        });
    }

    public function release(Request $r, int $id, AvailabilityService $a)
    {
        $d = $r->validate(['reason' => 'required|string|max:255']);

        return DB::transaction(function () use ($id, $a, $d) {
            $b = RoomBlock::findOrFail($id);
            $a->lockRooms($b->entries()->pluck('room_id')->all());
            $b = RoomBlock::lockForUpdate()->findOrFail($id);
            abort_unless($b->status === 'active', 409, 'Block is not active.');
            $b->entries()->update(['active' => false]);
            $b->update(['status' => 'cancelled']);
            Audit::record('block_released', 'room_block', $id, $d);

            return response()->json(['data' => $b]);
        });
    }

    public function users()
    {
        return response()->json(['data' => User::orderBy('name')->get()]);
    }

    public function user(Request $r, ?int $id = null)
    {
        $d = $r->validate(['name' => 'required|string|max:255', 'email' => 'required|email|max:255|unique:users,email,'.($id ?? 'NULL'), 'role' => 'required|in:admin,staff_admin', 'active' => 'required|boolean', 'password' => ($id ? 'nullable' : 'required').'|string|min:12']);

        return DB::transaction(function () use ($id, $d) {
            User::where('role', 'admin')->orderBy('id')->lockForUpdate()->get();
            if ($id) {
                $u = User::findOrFail($id);
                abort_if($u->id === auth()->id() && (! $d['active'] || $d['role'] !== 'admin'), 422, 'You cannot disable or demote your own admin account.');
                if (empty($d['password'])) {
                    unset($d['password']);
                } $u->update($d);
                if (! $u->active || $u->role !== 'admin') {
                    $u->tokens()->delete();
                }
            } else {
                $u = User::create($d);
            }
            Audit::record('staff_saved', 'user', $u->id, collect($d)->except('password')->all());

            return response()->json(['data' => $u], $id ? 200 : 201);
        });
    }

    public function dashboard(FinanceService $finance)
    {
        $today = now()->toDateString();
        $receivable = 0;
        foreach (Booking::whereIn('status', ['confirmed', 'checked_in', 'checked_out', 'cancelled', 'no_show'])->cursor() as $b) {
            $receivable += max(0, $finance->summary($b)['balance_minor']);
        }

        return response()->json(['data' => ['rooms' => Room::count(), 'pending' => Booking::where('status', 'pending_approval')->count(), 'occupied' => Booking::where('status', 'checked_in')->count(), 'arrivals' => Booking::whereDate('check_in_at', $today)->where('status', 'confirmed')->count(), 'departures' => Booking::whereDate('check_out_at', $today)->where('status', 'checked_in')->count(), 'collections_minor' => Payment::whereDate('created_at', $today)->where('status', 'posted')->sum('amount_minor'), 'receivables_minor' => $receivable, 'tariff_ready' => TariffVersion::where('active', true)->exists(), 'bookable_rooms' => Room::where('bookable', true)->count()]]);
    }

    public function audit()
    {
        return AuditLog::latest()->paginate(50);
    }

    public function notifications()
    {
        return NotificationDelivery::latest()->paginate(50);
    }

    public function report(Request $r, FinanceService $f)
    {
        $d = $r->validate(['start' => 'required|date', 'end' => 'required|date|after_or_equal:start']);
        abort_if(CarbonImmutable::parse($d['start'])->diffInDays(CarbonImmutable::parse($d['end'])) > 366, 422, 'Maximum report range is 366 days.');
        $start = CarbonImmutable::parse($d['start'])->startOfDay();
        $end = CarbonImmutable::parse($d['end'])->endOfDay();
        $rows = Booking::with('rooms')->where('check_in_at', '<=', $end)->where('check_out_at', '>', $start)->orderBy('check_in_at')->get()->map(fn ($b) => ['id' => $b->id, 'number' => $b->number, 'guest' => $b->guest_snapshot['name'], 'category' => $b->guest_category_id, 'status' => $b->status, 'check_in_at' => $b->check_in_at, 'check_out_at' => $b->check_out_at, 'rooms' => $b->rooms->pluck('code')->join(', '), 'finance' => $f->summary($b)]);
        $collections = (int) Payment::whereBetween('created_at', [$start, $end])->where('status', 'posted')->sum('amount_minor');
        $refunds = (int) Refund::whereBetween('created_at',[$start, $end])->sum('amount_minor');

        return response()->json(['data' => ['bookings' => $rows, 'collections_minor' => $collections, 'refunds_minor' => $refunds, 'net_collections_minor' => $collections - $refunds, 'receivables_minor' => $rows->sum(fn ($r) => max(0,$r['finance']['balance_minor']))]]);
    }
}
