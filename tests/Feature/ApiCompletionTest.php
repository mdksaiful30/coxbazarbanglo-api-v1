<?php
namespace Tests\Feature;
use App\Models\{Booking,NotificationDelivery,User};
use App\Services\{BookingExport,GuestMessenger};
use App\Jobs\SendGuestNotification;
use Illuminate\Support\Facades\{Password,Hash};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use App\Models\{Guest,Room,GuestCategory,TariffVersion};
class ApiCompletionTest extends \Tests\TestCase {
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


 public function test_preferences_are_validated_and_isolated_per_account():void{
  $this->patchJson('/api/v1/auth/preferences',['theme'=>'dark','language'=>'bn'])->assertOk()->assertJsonPath('data.theme','dark');
  $this->patchJson('/api/v1/auth/preferences',['theme'=>'bad'])->assertUnprocessable();
  $this->actingAs($this->staff,'sanctum')->getJson('/api/v1/auth/preferences')->assertOk()->assertJsonPath('data.theme','system')->assertJsonPath('data.persisted',false);
 }
 public function test_staff_can_download_private_files_and_get_safe_share_options():void{
  $b=$this->draft(['notes'=>'PRIVATE INTERNAL NOTE']);$this->actingAs($this->staff,'sanctum');
  $this->mock(BookingExport::class,fn($m)=>$m->shouldReceive('render')->once()->withArgs(fn($d,$f)=>$f==='pdf'&&!isset($d['notes']))->andReturn('%PDF-test'));
  $this->get('/api/v1/bookings/'.$b['id'].'/exports/pdf')->assertOk()->assertHeader('Content-Type','application/pdf')->assertSee('%PDF-test');
  $r=$this->getJson('/api/v1/bookings/'.$b['id'].'/share-options')->assertOk()->assertJsonPath('data.downloads_require_authentication',true);
  $this->assertStringNotContainsString('PRIVATE INTERNAL NOTE',$r->getContent());
  $this->assertStringContainsString($this->room->code,$r->json('data.text'));
  $this->get('/api/v1/bookings/'.$b['id'].'/exports/png')->assertNotFound();
 }
 public function test_share_is_versioned_idempotent_and_cancels_stale_snapshot():void{
  config(['mail.default'=>'smtp']);$b=$this->draft();$path='bookings/'.$b['id'].'/share';
  $data=['channel'=>'email','format'=>'pdf','version'=>$b['version']];
  $n=$this->send($path,$data,'share-test-key')->assertStatus(202)->json('data');
  $this->send($path,$data,'share-test-key')->assertStatus(202)->assertJsonPath('data.id',$n['id']);
  $this->assertSame(1,NotificationDelivery::where('booking_id',$b['id'])->count());
  $this->send($path,array_replace($data,['version'=>99]))->assertConflict();
  $this->send($path,array_replace($data,['recipient'=>'someone@example.com']))->assertUnprocessable();
  $this->send($path,array_replace($data,['channel'=>'sms']))->assertUnprocessable();
  Booking::find($b['id'])->increment('version');
  $messenger=$this->mock(GuestMessenger::class,fn($m)=>$m->shouldNotReceive('send'));
  (new SendGuestNotification($n['id']))->handle($messenger);
  $this->assertSame('cancelled',NotificationDelivery::find($n['id'])->status);
  $this->getJson('/api/v1/bookings/'.$b['id'].'/shares')->assertOk()->assertJsonPath('data.0.status','cancelled');
 }
 public function test_unconfigured_email_is_not_reported_as_queued_delivery():void{
  config(['mail.default'=>'log']);$b=$this->draft();$this->send('bookings/'.$b['id'].'/share',['channel'=>'email','version'=>$b['version']])->assertStatus(503);
  $this->assertSame(0,NotificationDelivery::count());
 }
 public function test_report_csv_is_admin_only_and_escapes_spreadsheet_formulas():void{
  $this->guest->update(['name'=>'=HYPERLINK("bad")']);$this->draft();
  $r=$this->get('/api/v1/reports/export?start=2027-01-01&end=2027-01-31')->assertOk();
  $this->assertStringContainsString("'=HYPERLINK",$r->streamedContent());
  $this->actingAs($this->staff,'sanctum')->getJson('/api/v1/reports/export?start=2027-01-01&end=2027-01-31')->assertForbidden();
 }
 public function test_rest_password_reset_revokes_mobile_tokens():void{
  $token=Password::createToken($this->staff);$this->staff->createToken('phone');
  $this->postJson('/api/v1/auth/reset-password',['email'=>$this->staff->email,'token'=>$token,'password'=>'Changed-password-2027','password_confirmation'=>'Changed-password-2027'])->assertOk();
  $this->assertTrue(Hash::check('Changed-password-2027',$this->staff->fresh()->password));$this->assertSame(0,$this->staff->tokens()->count());
 }
}
