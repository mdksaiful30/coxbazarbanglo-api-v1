<?php

namespace App\Http\Controllers;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReferenceController extends Controller
{
    private function tables(): array
    {
        return ['properties' => 'properties', 'buildings' => 'buildings', 'floors' => 'floors', 'rooms' => 'rooms', 'guest-categories' => 'guest_categories'];
    }

    private function table(string $resource): string
    {
        abort_unless(isset($this->tables()[$resource]), 404);

        return $this->tables()[$resource];
    }

    public function index(string $resource)
    {
        return response()->json(['data' => DB::table($this->table($resource))->orderBy('id')->get()]);
    }

    public function show(string $resource, int $id)
    {
        $r = DB::table($this->table($resource))->find($id);
        abort_unless($r, 404);

        return response()->json(['data' => $r]);
    }

    public function save(Request $r, string $resource, ?int $id = null)
    {
        $table = $this->table($resource);
        $rules = match ($resource) {
            'properties' => ['name' => 'required|string|max:255', 'address' => 'nullable|string|max:255', 'timezone' => 'sometimes|in:Asia/Dhaka'],
            'buildings' => ['property_id' => 'required|integer|exists:properties,id', 'name' => 'required|string|max:255'],
            'floors' => ['building_id' => 'required|integer|exists:buildings,id', 'number' => ['required', 'integer', 'min:0', Rule::unique('floors')->where(fn ($q) => $q->where('building_id', $r->building_id))->ignore($id)], 'name' => 'required|string|max:255'],
            'rooms' => ['floor_id' => 'required|integer|exists:floors,id', 'code' => ['required', 'string', 'max:30', Rule::unique('rooms', 'code')->ignore($id)], 'name' => 'required|string|max:255', 'ac_type' => 'required|in:ac,non_ac', 'bed_type' => 'required|string|max:255', 'room_type' => 'required|string|max:100', 'capacity' => 'nullable|integer|min:1|max:20', 'bookable' => 'required|boolean', 'housekeeping' => 'required|in:unverified,ready,needs_cleaning,cleaning'],
            'guest-categories' => ['code' => ['required', 'string', 'max:20', Rule::unique('guest_categories', 'code')->ignore($id)], 'name' => 'required|string|max:255', 'max_nights' => 'nullable|integer|min:1|max:365'],
        };
        $d = $r->validate($rules);

        return DB::transaction(function () use ($table, $resource, $id, $d) {
            $before = $id ? DB::table($table)->where('id', $id)->lockForUpdate()->first() : null;
            if ($id) {
                abort_unless($before, 404);
            }
            if ($resource === 'rooms') {
                abort_if($d['bookable'] && empty($d['capacity']), 422, 'Verify capacity before making a room bookable.');
                if ($before && ($before->ac_type !== $d['ac_type'] || $before->floor_id !== $d['floor_id'])) {
                    abort_if(DB::table('room_calendar_entries')->where('room_id', $id)->where('active', true)->exists(), 409, 'Room class/location cannot change while allocations are active.');
                }
            }
            $d['updated_at'] = now();
            if ($id) {
                DB::table($table)->where('id', $id)->update($d);
            } else {
                $d['created_at'] = now();
                $id = DB::table($table)->insertGetId($d);
            }
            $after = DB::table($table)->find($id);
            Audit::record('reference_saved', $resource, $id, ['before' => $before, 'after' => $after]);

            return response()->json(['data' => $after], $before ? 200 : 201);
        });
    }

    public function invoices()
    {
        return Invoice::latest()->paginate(25);
    }

    public function invoice(int $id)
    {
        return response()->json(['data' => Invoice::findOrFail($id)]);
    }

    public function payments()
    {
        return Payment::with('refunds')->latest()->paginate(25);
    }

    public function credits()
    {
        return CreditNote::latest()->paginate(25);
    }

    public function settings()
    {
        return response()->json(['data' => Setting::all()]);
    }
}
