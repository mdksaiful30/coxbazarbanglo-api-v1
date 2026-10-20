<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Booking;
use App\Models\NotificationDelivery;
use App\Models\RoomCalendarEntry;
use App\Services\Audit;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use App\Services\FinanceService;
use App\Services\PricingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BookingController extends Controller
{
    public function __construct(public BookingService $service) {}

    public function rules(bool $guest = true): array
    {
        return array_merge($guest ? ['guest_id' => 'required|integer|exists:guests,id'] : [], ['guest_category_id' => 'required|integer|exists:guest_categories,id', 'check_in_at' => 'required|date', 'check_out_at' => 'required|date|after:check_in_at', 'room_ids' => 'required|array|min:1|max:30', 'room_ids.*' => 'required|integer|distinct|exists:rooms,id', 'tariff_version_id' => 'nullable|integer|exists:tariff_versions,id', 'reference_letter' => 'nullable|string|max:255', 'reference_date' => 'nullable|date', 'guest_count' => 'sometimes|required|integer|min:1|max:100', 'purpose' => 'nullable|string|max:2000', 'notes' => 'nullable|string|max:5000']);
    }

    private function data(Request $r, bool $guest = true, array $extra = []): array
    {
        $d = $r->validate(array_merge($this->rules($guest), $extra));
        foreach (['check_in_at', 'check_out_at'] as $key) {
            $d[$key] = CarbonImmutable::parse($d[$key], 'Asia/Dhaka')->setTimezone('Asia/Dhaka')->format('Y-m-d H:i:s');
        }

        return $d;
    }

    public function index(Request $r)
    {
        $r->validate(['status' => 'nullable|in:draft,pending_approval,confirmed,checked_in,checked_out,rejected,cancelled,no_show', 'search' => 'nullable|string|max:100', 'per_page' => 'nullable|integer|min:1|max:100']);
        $q = Booking::with('rooms')->latest();
        if ($r->status) {
            $q->where('status', $r->status);
        }
        if ($r->search) {
            $q->where(fn ($x) => $x->where('number', 'like', '%'.$r->search.'%')->orWhere('guest_snapshot->name', 'like', '%'.$r->search.'%')->orWhere('guest_snapshot->mobile', 'like', '%'.$r->search.'%'));
        }

        return $q->paginate($r->integer('per_page', 25));
    }

    public function store(Request $r)
    {
        return response()->json(['data' => $this->service->create($this->data($r))], 201);
    }

    public function show(int $id, FinanceService $finance)
    {
        $b = Booking::with('rooms', 'events', 'payments.refunds', 'invoices', 'amendments', 'attachments', 'deliveries', 'credits')->findOrFail($id);

        return response()->json(['data' => $b, 'finance' => $finance->summary($b)]);
    }


    public function adminUpdate(Request $r, int $id)
    {
        $data = $this->data($r, true, [
            'version' => 'required|integer', 'reason' => 'required|string|max:2000', 'notify_guest' => 'sometimes|boolean',
            'guest_details' => 'sometimes|array:name,designation,organization,mobile,email,address',
            'guest_details.name' => 'required_with:guest_details|string|max:255',
            'guest_details.mobile' => 'required_with:guest_details|string|max:25',
            'guest_details.designation' => 'nullable|string|max:255', 'guest_details.organization' => 'nullable|string|max:255',
            'guest_details.email' => 'nullable|email|max:255', 'guest_details.address' => 'nullable|string|max:2000',
        ]);
        if (isset($data['guest_details'])) {
            $phone = preg_replace('/[\s()\-]/', '', $data['guest_details']['mobile']);
            if (preg_match('/^01[3-9]\d{8}$/', $phone)) $phone = '+88'.$phone;
            if (str_starts_with($phone, '880')) $phone = '+'.$phone;
            abort_unless(preg_match('/^\+[1-9]\d{7,14}$/', $phone), 422, 'Use a valid international mobile number.');
            $data['guest_details']['mobile'] = $phone;
        }
        return response()->json(['data' => $this->service->updateApproved($id, $data)]);
    }

    public function update(Request $r, int $id)
    {
        return response()->json(['data' => $this->service->updateDraft($id, $this->data($r, true, ['version' => 'required|integer']))]);
    }

    public function quote(Request $r, PricingService $p)
    {
        return response()->json(['data' => $p->quote($this->data($r, false))]);
    }

    public function transition(Request $r, int $id, string $action)
    {
        $d = $r->validate(['version' => 'required|integer', 'reason' => 'nullable|string|max:2000', 'reservation_id' => 'nullable|integer|exists:room_blocks,id']);

        return response()->json(['data' => $this->service->transition($id, $action, $d)]);
    }

    public function amendment(Request $r, int $id)
    {
        return response()->json(['data' => $this->service->propose($id, $this->data($r, true, ['version' => 'required|integer', 'reason' => 'required|string|max:2000']))], 201);
    }

    public function decide(int $id, string $action)
    {
        return response()->json(['data' => $this->service->decideAmendment($id, $action)]);
    }

    public function availability(Request $r, AvailabilityService $a)
    {
        $r->validate(['check_in_at' => 'required|date', 'check_out_at' => 'required|date|after:check_in_at', 'exclude_booking_id' => 'nullable|integer|exists:bookings,id']);
        $s = CarbonImmutable::parse($r->check_in_at, 'Asia/Dhaka')->setTimezone('Asia/Dhaka');
        $e = CarbonImmutable::parse($r->check_out_at, 'Asia/Dhaka')->setTimezone('Asia/Dhaka');
        abort_if($s->diffInDays($e) > 366, 422, 'Maximum query range is 366 days.');

        return response()->json(['data' => $a->search($s->format('Y-m-d H:i:s'), $e->format('Y-m-d H:i:s'), $r->filled('exclude_booking_id') ? $r->integer('exclude_booking_id') : null)]);
    }

    public function calendar(Request $r)
    {
        $r->validate(['start' => 'required|date', 'end' => 'required|date|after:start']);
        $start = CarbonImmutable::parse($r->start, 'Asia/Dhaka')->setTimezone('Asia/Dhaka');
        $end = CarbonImmutable::parse($r->end, 'Asia/Dhaka')->setTimezone('Asia/Dhaka');
        abort_if($start->diffInDays($end) > 93, 422, 'Calendar queries are limited to 93 days.');
        $entries = RoomCalendarEntry::where('active', true)->where('starts_at', '<', $end)->where('ends_at', '>', $start)->get();
        $bookings = Booking::with('rooms')->whereIn('status', ['confirmed', 'checked_in', 'pending_approval'])->where('check_in_at', '<', $end)->where('check_out_at', '>', $start)->get();

        return response()->json(['data' => ['entries' => $entries, 'bookings' => $bookings]]);
    }

    public function pay(Request $r, int $id, FinanceService $f)
    {
        $d = $r->validate(['amount_minor' => 'required|integer|min:1|max:10000000000', 'method' => 'required|in:cash,bank_transfer,other', 'reference' => 'nullable|string|max:150|unique:payments,reference']);

        return response()->json(['data' => $f->record($id, $d)], 201);
    }

    public function reverse(Request $r, int $id, FinanceService $f)
    {
        $d = $r->validate(['reason' => 'required|string|max:2000']);

        return response()->json(['data' => $f->reverse($id, $d['reason'])]);
    }

    public function refund(Request $r, int $id, FinanceService $f)
    {
        return response()->json(['data' => $f->refund($id, $r->validate(['amount_minor' => 'required|integer|min:1', 'reason' => 'required|string|max:2000']))], 201);
    }

    public function upload(Request $r, int $id)
    {
        $b = Booking::findOrFail($id);
        $this->service->owner($b);
        abort_unless(in_array($b->status, ['draft', 'rejected']), 409, 'Attachments may be added only to a draft.');
        $r->validate(['file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120']);
        $file = $r->file('file');
        $a = Attachment::create(['booking_id' => $id, 'name' => $file->getClientOriginalName(), 'path' => $file->store('reference-letters', 'local'), 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'created_by' => auth()->id()]);
        Audit::record('attachment_uploaded', 'attachment', $a->id);

        return response()->json(['data' => $a->only(['id', 'name', 'mime', 'size'])], 201);
    }

    public function download(int $id)
    {
        $a = Attachment::findOrFail($id);

        return Storage::disk('local')->download($a->path, $a->name, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function retry(int $id)
    {
        $n = NotificationDelivery::findOrFail($id);
        abort_unless($n->status === 'failed' && !$n->provider_id,409,'Only unaccepted failed attempts can be retried. Reconcile provider-accepted messages first.');
        $n->update(['status' => 'queued', 'attempts' => 0, 'last_error' => null]);
        Audit::record('notification_retry','notification',$n->id);

        return response()->json(['data' => $n]);
    }
}
