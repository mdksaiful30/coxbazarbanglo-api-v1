<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Guest;
use App\Models\GuestCategory;
use App\Models\NotificationDelivery;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomCalendarEntry;
use App\Models\TariffRate;
use App\Models\TariffVersion;
use App\Models\User;
use App\Services\PricingService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $staff;

    protected Guest $guest;

    protected Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->staff = User::factory()->create(['role' => 'staff_admin', 'active' => true]);
        $this->guest = Guest::create(['name' => 'Test Guest', 'mobile' => '+8801712345678', 'email' => 'guest@example.com']);
        Room::query()->update(['bookable' => true, 'capacity' => 2, 'housekeeping' => 'ready']);
        $this->room = Room::where('code', 'R201')->firstOrFail();
        TariffVersion::query()->update(['active' => true, 'approved_at' => now(), 'approved_by' => $this->admin->id, 'calculation_mode' => 'progressive', 'effective_from' => '2026-01-01', 'policy' => json_encode(['rate_unit' => 'room_night'])]);
        $this->actingAs($this->admin, 'sanctum');
    }

    protected function data(array $extra = []): array
    {
        return array_replace(['guest_id' => $this->guest->id, 'guest_category_id' => GuestCategory::where('code', 'A')->value('id'), 'room_ids' => [$this->room->id], 'check_in_at' => '2027-01-01T14:00:00+06:00', 'check_out_at' => '2027-01-05T12:00:00+06:00', 'guest_count' => 1], $extra);
    }

    protected function send(string $url, array $data = [], ?string $key = null)
    {
        return $this->withHeader('Idempotency-Key', $key ?? Str::uuid()->toString())->postJson('/api/v1/'.$url, $data);
    }

    protected function draft(array $extra = []): array
    {
        return $this->send('bookings', $this->data($extra))->assertCreated()->json('data');
    }

    protected function approved(): array
    {
        $b = $this->draft();

        return $this->send('bookings/'.$b['id'].'/approve', ['version' => $b['version']])->assertOk()->json('data');
    }

    public function test_reference_catalog_matches_source(): void
    {
        $this->assertSame(14, Room::count());
        $this->assertSame(6, Room::where('ac_type', 'ac')->count());
        $this->assertSame(14, TariffRate::count());
    }

    public function test_all_booking_roles_reject_past_arrivals_in_dhaka(): void
    {
        // UTC is still the previous day at this Dhaka midnight boundary.
        $this->travelTo(Carbon::parse('2027-01-02 00:30:00', 'Asia/Dhaka'));
        foreach ([$this->admin, $this->staff] as $user) {
            $this->actingAs($user, 'sanctum');
            $this->send('bookings', $this->data([
                'check_in_at' => '2027-01-01T17:59:00Z',
                'check_out_at' => '2027-01-04T12:00:00+06:00',
            ]))->assertUnprocessable()->assertJsonValidationErrors('check_in_at');
            $this->send('bookings', $this->data([
                'check_in_at' => '2027-01-01T18:00:00Z',
                'check_out_at' => '2027-01-04T12:00:00+06:00',
            ]))->assertCreated();
        }
    }

    public function test_stale_draft_cannot_be_submitted_or_approved(): void
    {
        $this->travelTo(Carbon::parse('2027-01-01 10:00:00', 'Asia/Dhaka'));
        $booking = $this->draft();
        $this->travelTo(Carbon::parse('2027-01-02 10:00:00', 'Asia/Dhaka'));
        foreach (['submit', 'approve'] as $action) {
            $this->send('bookings/'.$booking['id'].'/'.$action, ['version' => $booking['version']])
                ->assertUnprocessable()->assertJsonValidationErrors('check_in_at');
        }
        $this->assertDatabaseHas('bookings', ['id' => $booking['id'], 'status' => 'draft']);
    }

    public function test_progressive_and_whole_stay_pricing(): void
    {
        $p = app(PricingService::class);
        $this->assertSame(125000, $p->quote($this->data())['total_minor']);
        TariffVersion::query()->update(['calculation_mode' => 'whole_stay']);
        $this->assertSame(200000, $p->quote($this->data())['total_minor']);
    }

    public function test_category_boundaries_and_vat_inclusion(): void
    {
        $p = app(PricingService::class);
        foreach ([3 => 75000, 4 => 125000, 10 => 425000, 11 => 495000] as $n => $expected) {
            $this->assertSame($expected, $p->quote($this->data(['check_out_at' => CarbonImmutable::parse('2027-01-01')->addDays($n)->format('Y-m-d').'T12:00']))['total_minor']);
        }
        $b = GuestCategory::where('code', 'B')->value('id');
        $this->assertSame(780000, $p->quote($this->data(['guest_category_id' => $b, 'check_out_at' => '2027-01-08T12:00']))['total_minor']);
        $this->assertSame(930000, $p->quote($this->data(['guest_category_id' => $b, 'check_out_at' => '2027-01-09T12:00']))['total_minor']);
    }

    public function test_category_b_and_c_reject_more_than_ten_nights(): void
    {
        foreach (['B', 'C'] as $code) {
            $this->postJson('/api/v1/booking-quotes', $this->data(['guest_category_id' => GuestCategory::where('code', $code)->value('id'), 'check_out_at' => '2027-01-12T12:00']))->assertUnprocessable();
        }
    }

    public function test_staff_submits_but_cannot_approve(): void
    {
        $this->actingAs($this->staff, 'sanctum');
        $b = $this->draft();
        $b = $this->send('bookings/'.$b['id'].'/submit', ['version' => 1])->assertOk()->json('data');
        $this->send('bookings/'.$b['id'].'/approve', ['version' => $b['version']])->assertForbidden();
        $this->assertDatabaseCount('room_calendar_entries', 0);
        $this->actingAs($this->admin, 'sanctum');
        $this->send('bookings/'.$b['id'].'/approve', ['version' => $b['version']])->assertOk();
    }

    public function test_pending_requests_do_not_block_and_only_one_can_confirm(): void
    {
        $one = $this->draft();
        $two = $this->draft();
        $this->send('bookings/'.$one['id'].'/approve', ['version' => 1])->assertOk();
        $this->send('bookings/'.$two['id'].'/approve', ['version' => 1])->assertConflict();
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_adjacent_boundaries_are_allowed(): void
    {
        $this->approved();
        $this->draft(['check_in_at' => '2027-01-05T12:00:00+06:00', 'check_out_at' => '2027-01-06T12:00:00+06:00']);
        $this->assertSame(2, Booking::count());
    }

    public function test_multi_room_conflict_rolls_back_everything(): void
    {
        $one = $this->draft();
        $other = Room::where('code', 'R301')->first();
        $two = $this->draft(['room_ids' => [$this->room->id, $other->id]]);
        $this->send('bookings/'.$one['id'].'/approve', ['version' => 1])->assertOk();
        $this->send('bookings/'.$two['id'].'/approve', ['version' => 1])->assertConflict();
        $this->assertSame(0, RoomCalendarEntry::where('booking_id', $two['id'])->count());
    }

    public function test_stale_version_is_rejected(): void
    {
        $b = $this->draft();
        $this->send('bookings/'.$b['id'].'/submit', ['version' => 1])->assertOk();
        $this->send('bookings/'.$b['id'].'/approve', ['version' => 1])->assertConflict();
    }

    public function test_approval_requires_active_tariff(): void
    {
        $b = $this->draft();
        TariffVersion::query()->update(['active' => false]);
        $this->send('bookings/'.$b['id'].'/approve', ['version' => 1])->assertUnprocessable();
    }

    public function test_approved_rates_cannot_be_changed(): void
    {
        $t = TariffVersion::first();
        $this->patchJson('/api/v1/tariffs/'.$t->id.'/rates/'.$t->rates()->first()->id, ['amount_minor' => 1])->assertConflict();
    }

    public function test_payment_is_idempotent_and_does_not_change_occupancy(): void
    {
        $b = $this->approved();
        $key = Str::uuid()->toString();
        $body = ['amount_minor' => 50000, 'method' => 'cash'];
        $one = $this->send('bookings/'.$b['id'].'/payments', $body, $key)->assertCreated();
        $two = $this->send('bookings/'.$b['id'].'/payments', $body, $key)->assertCreated();
        $this->assertSame($one->json('data.id'), $two->json('data.id'));
        $this->assertSame(1, Payment::count());
        $this->assertDatabaseHas('bookings', ['id' => $b['id'], 'status' => 'confirmed']);
        $this->assertDatabaseHas('room_calendar_entries', ['booking_id' => $b['id'], 'active' => true]);
        $this->send('bookings/'.$b['id'].'/payments', ['amount_minor' => 1, 'method' => 'cash'], $key)->assertConflict();
    }

    public function test_refund_and_reversal_reconcile_balances(): void
    {
        $b = $this->approved();
        $p = $this->send('bookings/'.$b['id'].'/payments', ['amount_minor' => 125000, 'method' => 'cash'])->assertCreated()->json('data');
        $this->send('payments/'.$p['id'].'/refunds', ['amount_minor' => 25000, 'reason' => 'Adjustment'])->assertCreated();
        $this->getJson('/api/v1/bookings/'.$b['id'])->assertJsonPath('finance.balance_minor', 25000);
        $this->send('payments/'.$p['id'].'/reverse', ['reason' => 'Invalid'])->assertConflict();
        $this->send('payments/'.$p['id'].'/refunds', ['amount_minor' => 100001, 'reason' => 'Too much'])->assertUnprocessable();
    }

    public function test_credit_preserves_invoice_and_clears_cancelled_charge(): void
    {
        $b = $this->approved();
        $this->send('bookings/'.$b['id'].'/cancel', ['version' => $b['version'], 'reason' => 'Cancelled by guest'])->assertOk();
        $this->send('bookings/'.$b['id'].'/credit-notes', ['amount_minor' => 125000, 'reason' => 'Approved cancellation waiver'])->assertCreated();
        $this->getJson('/api/v1/bookings/'.$b['id'])->assertJsonPath('finance.balance_minor', 0);
        $this->assertDatabaseHas('invoices', ['booking_id' => $b['id'], 'total_minor' => 125000, 'status' => 'issued']);
    }

    public function test_checkout_releases_allocation_but_requires_cleaning(): void
    {
        $this->travelTo(Carbon::parse('2027-01-01 15:00', 'Asia/Dhaka'));
        $b = $this->approved();
        $b = $this->send('bookings/'.$b['id'].'/check-in', ['version' => $b['version']])->assertOk()->json('data');
        $this->send('bookings/'.$b['id'].'/check-out', ['version' => $b['version']])->assertUnprocessable();
        $this->send('bookings/'.$b['id'].'/check-out', ['version' => $b['version'], 'reason' => 'Balance to collect later'])->assertOk();
        $this->assertDatabaseHas('rooms', ['id' => $this->room->id, 'housekeeping' => 'needs_cleaning']);
        $this->assertDatabaseHas('room_calendar_entries', ['booking_id' => $b['id'], 'active' => false]);
    }

    public function test_early_checkin_and_unready_room_are_rejected(): void
    {
        $b = $this->approved();
        $this->send('bookings/'.$b['id'].'/check-in', ['version' => $b['version']])->assertConflict();
        $this->travelTo(Carbon::parse('2027-01-01 15:00', 'Asia/Dhaka'));
        $this->room->update(['housekeeping' => 'needs_cleaning']);
        $this->send('bookings/'.$b['id'].'/check-in', ['version' => $b['version']])->assertConflict();
    }

    public function test_reservation_conversion_is_atomic(): void
    {
        $b = $this->draft();
        $block = $this->send('room-blocks', ['kind' => 'reservation', 'reason' => 'Official visit', 'room_ids' => [$this->room->id], 'starts_at' => '2027-01-01T14:00:00+06:00', 'ends_at' => '2027-01-05T12:00:00+06:00'])->assertCreated()->json('data');
        $this->send('bookings/'.$b['id'].'/approve', ['version' => 1])->assertConflict();
        $this->send('bookings/'.$b['id'].'/approve', ['version' => 1, 'reservation_id' => $block['id']])->assertOk();
        $this->assertDatabaseHas('room_blocks', ['id' => $block['id'], 'status' => 'converted']);
        $this->assertSame(1, RoomCalendarEntry::where('active', true)->count());
    }

    public function test_maintenance_blocks_booking(): void
    {
        $this->send('room-blocks', ['kind' => 'maintenance', 'reason' => 'Repair', 'room_ids' => [$this->room->id], 'starts_at' => '2027-01-01T10:00', 'ends_at' => '2027-01-10T10:00'])->assertCreated();
        $this->send('bookings', $this->data())->assertConflict();
    }

    public function test_unapproved_amendment_does_not_change_booking(): void
    {
        $b = $this->approved();
        $proposal = $this->send('bookings/'.$b['id'].'/amendments', $this->data(['version' => $b['version'], 'reason' => 'One more night', 'check_out_at' => '2027-01-06T12:00:00+06:00']))->assertCreated()->json('data');
        $this->assertDatabaseHas('bookings', ['id' => $b['id'], 'check_out_at' => '2027-01-05 12:00:00']);
        $this->send('booking-amendments/'.$proposal['id'].'/approve')->assertOk();
        $this->assertDatabaseHas('bookings', ['id' => $b['id'], 'check_out_at' => '2027-01-06 12:00:00']);
        $this->assertDatabaseHas('invoices', ['booking_id' => $b['id'], 'status' => 'superseded']);
    }

    public function test_guest_edits_do_not_rewrite_booking_snapshot(): void
    {
        $b = $this->draft();
        $this->guest->update(['name' => 'Changed Directory Name']);
        $this->assertSame('Test Guest', Booking::find($b['id'])->guest_snapshot['name']);
    }

    public function test_failed_approval_does_not_enqueue_notification(): void
    {
        $b = $this->draft();
        TariffVersion::query()->update(['active' => false]);
        $this->send('bookings/'.$b['id'].'/approve', ['version' => 1])->assertUnprocessable();
        $this->assertSame(0, NotificationDelivery::count());
    }

    public function test_disabled_account_is_forbidden(): void
    {
        $this->staff->update(['active' => false]);
        $this->actingAs($this->staff, 'sanctum');
        $this->getJson('/api/v1/catalog')->assertForbidden();
    }

    public function test_staff_cannot_change_rooms_or_post_payments(): void
    {
        $b = $this->approved();
        $this->actingAs($this->staff, 'sanctum');
        $this->patchJson('/api/v1/rooms/'.$this->room->id, ['bookable' => false])->assertForbidden();
        $this->send('bookings/'.$b['id'].'/payments',['amount_minor' => 1, 'method' => 'cash'])->assertForbidden();
    }

    public function test_anonymous_requests_do_not_expose_guest_information(): void
    {
        Auth::forgetGuards();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/catalog')->assertUnauthorized();
    }

    public function test_admin_can_edit_confirmed_details_without_losing_approval_or_payment(): void
    {
        $b=$this->approved();
        $payment=$this->send('bookings/'.$b['id'].'/payments',['amount_minor'=>50000,'method'=>'cash'])->assertCreated()->json('data');
        $invoice=\App\Models\Invoice::where('booking_id',$b['id'])->first();
        $body=$this->data(['version'=>$b['version'],'reason'=>'Correct guest details','reference_letter'=>'UPDATED/001','notes'=>'Revised note','guest_details'=>['name'=>'Corrected Guest','designation'=>'Director','organization'=>'BIWTA','mobile'=>'01712345678','email'=>'corrected@example.com','address'=>'Dhaka']]);
        $this->withHeader('Idempotency-Key',\Illuminate\Support\Str::uuid()->toString())->patchJson('/api/v1/bookings/'.$b['id'].'/approved-details',$body)->assertOk()->assertJsonPath('data.status','confirmed')->assertJsonPath('data.guest_snapshot.name','Corrected Guest')->assertJsonPath('data.guest_snapshot.mobile','+8801712345678')->assertJsonPath('data.version',3);
        $this->assertDatabaseHas('payments',['id'=>$payment['id'],'amount_minor'=>50000,'status'=>'posted']);
        $this->assertSame(1,\App\Models\Invoice::where('booking_id',$b['id'])->count());
        $this->assertSame($invoice->lines,$invoice->fresh()->lines);
        $this->assertSame('Test Guest',$this->guest->fresh()->name);
        $this->assertDatabaseHas('booking_events',['booking_id'=>$b['id'],'action'=>'admin_updated','reason'=>'Correct guest details']);
    }

    public function test_staff_cannot_edit_approved_details(): void
    {
        $b=$this->approved();$this->actingAs($this->staff,'sanctum');
        $this->withHeader('Idempotency-Key',\Illuminate\Support\Str::uuid()->toString())->patchJson('/api/v1/bookings/'.$b['id'].'/approved-details',$this->data(['version'=>$b['version'],'reason'=>'Unauthorized change']))->assertForbidden();
    }

    public function test_admin_edit_reprices_paid_booking_and_preserves_financial_history(): void
    {
        $b=$this->approved();$this->send('bookings/'.$b['id'].'/payments',['amount_minor'=>125000,'method'=>'cash'])->assertCreated();
        $original=\App\Models\Invoice::where('booking_id',$b['id'])->first();
        $response=$this->withHeader('Idempotency-Key',\Illuminate\Support\Str::uuid()->toString())->patchJson('/api/v1/bookings/'.$b['id'].'/approved-details',$this->data(['version'=>$b['version'],'reason'=>'Extend stay','check_out_at'=>'2027-01-06T12:00:00+06:00']))->assertOk();
        $this->getJson('/api/v1/bookings/'.$b['id'])->assertJsonPath('finance.balance_minor',50000);
        $this->assertSame(2,\App\Models\Invoice::where('booking_id',$b['id'])->where('status','issued')->count());
        $this->assertSame($original->total_minor,$original->fresh()->total_minor);
        $version=$response->json('data.version');
        $this->withHeader('Idempotency-Key',\Illuminate\Support\Str::uuid()->toString())->patchJson('/api/v1/bookings/'.$b['id'].'/approved-details',$this->data(['version'=>$version,'reason'=>'Shorten stay','check_out_at'=>'2027-01-04T12:00:00+06:00']))->assertOk();
        $this->getJson('/api/v1/bookings/'.$b['id'])->assertJsonPath('finance.charges_minor',75000)->assertJsonPath('finance.balance_minor',-50000);
        $this->assertDatabaseHas('credit_notes',['booking_id'=>$b['id'],'amount_minor'=>100000]);
        $this->assertDatabaseHas('payments',['booking_id'=>$b['id'],'amount_minor'=>125000,'status'=>'posted']);
    }

    public function test_conflicting_admin_edit_rolls_back_booking_and_charges(): void
    {
        $b=$this->approved();$other=Room::where('code','R301')->firstOrFail();
        $second=$this->draft(['room_ids'=>[$other->id]]);$this->send('bookings/'.$second['id'].'/approve',['version'=>1])->assertOk();
        $this->withHeader('Idempotency-Key',\Illuminate\Support\Str::uuid()->toString())->patchJson('/api/v1/bookings/'.$b['id'].'/approved-details',$this->data(['version'=>$b['version'],'reason'=>'Move rooms','room_ids'=>[$other->id],'check_out_at'=>'2027-01-06T12:00:00+06:00']))->assertConflict();
        $this->assertSame([$this->room->id],Booking::findOrFail($b['id'])->rooms()->pluck('rooms.id')->all());
        $this->assertDatabaseHas('bookings',['id'=>$b['id'],'version'=>$b['version'],'total_minor'=>125000]);
        $this->assertSame(1,\App\Models\Invoice::where('booking_id',$b['id'])->count());
    }

    public function test_admin_save_checks_version_and_idempotency(): void
    {
        $b=$this->approved();$body=$this->data(['version'=>$b['version'],'reason'=>'Extension','check_out_at'=>'2027-01-06T12:00:00+06:00']);$key=\Illuminate\Support\Str::uuid()->toString();
        $this->withHeader('Idempotency-Key',$key)->patchJson('/api/v1/bookings/'.$b['id'].'/approved-details',$body)->assertOk();
        $this->withHeader('Idempotency-Key',$key)->patchJson('/api/v1/bookings/'.$b['id'].'/approved-details',$body)->assertOk();
        $this->assertSame(2,\App\Models\Invoice::where('booking_id',$b['id'])->count());
        $this->withHeader('Idempotency-Key',\Illuminate\Support\Str::uuid()->toString())->patchJson('/api/v1/bookings/'.$b['id'].'/approved-details',$body)->assertConflict();
    }

    public function test_occupied_admin_edit_preserves_status_and_moves_rooms_safely(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2027-01-01 15:00','Asia/Dhaka'));
        $b=$this->approved();$b=$this->send('bookings/'.$b['id'].'/check-in',['version'=>$b['version']])->assertOk()->json('data');$room=Room::where('code','R301')->first();
        $this->withHeader('Idempotency-Key',\Illuminate\Support\Str::uuid()->toString())->patchJson('/api/v1/bookings/'.$b['id'].'/approved-details',$this->data(['version'=>$b['version'],'reason'=>'Approved room transfer','room_ids'=>[$room->id]]))->assertOk()->assertJsonPath('data.status','checked_in');
        $this->assertDatabaseHas('rooms',['id'=>$this->room->id,'housekeeping'=>'needs_cleaning']);
        $this->assertDatabaseHas('room_calendar_entries',['booking_id'=>$b['id'],'room_id'=>$room->id,'active'=>true]);
    }

    public function test_guest_confirmation_is_available_to_staff_without_internal_details(): void
    {
        $b=$this->approved();Booking::whereKey($b['id'])->update(['notes'=>'CONFIDENTIAL INTERNAL NOTE']);
        $this->actingAs($this->staff,'sanctum');
        $response=$this->getJson('/api/v1/bookings/'.$b['id'].'/guest-confirmation')->assertOk()->assertJsonPath('data.number',$b['number'])->assertJsonPath('data.rooms.0.code','R201')->assertJsonPath('data.rooms.0.name','বাঁকখালী')->assertJsonPath('data.guest.name','Test Guest')->assertJsonPath('data.finance.charges_minor',125000);
        $response->assertJsonMissingPath('data.notes')->assertJsonMissingPath('data.events')->assertJsonMissingPath('data.attachments')->assertJsonMissingPath('data.payments')->assertJsonMissingPath('data.created_by')->assertDontSee('CONFIDENTIAL INTERNAL NOTE');
        $this->assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
    }

    public function test_guest_confirmation_reports_current_payment_balance(): void
    {
        $b=$this->approved();$this->send('bookings/'.$b['id'].'/payments',['amount_minor'=>50000,'method'=>'cash'])->assertCreated();
        $this->getJson('/api/v1/bookings/'.$b['id'].'/guest-confirmation')->assertOk()->assertJsonPath('data.finance.paid_minor',50000)->assertJsonPath('data.finance.balance_minor',75000);
    }

    public function test_guest_confirmation_requires_authentication_and_active_account(): void
    {
        $b=$this->approved();$this->staff->update(['active'=>false]);$this->actingAs($this->staff,'sanctum');
        $this->getJson('/api/v1/bookings/'.$b['id'].'/guest-confirmation')->assertForbidden();
        \Illuminate\Support\Facades\Auth::forgetGuards();$this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/bookings/'.$b['id'].'/guest-confirmation')->assertUnauthorized();
    }
}
