<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http,Mail};
use App\Models\{User,Guest,GuestCategory,Room,Booking,NotificationDelivery,TariffVersion};
use App\Services\{GuestMessenger,BookingService};
use App\Jobs\SendGuestNotification;
use Twilio\Security\RequestValidator;
class MessagingTest extends TestCase {
 use RefreshDatabase;
 protected function booking():Booking {
  $this->seed();$u=User::factory()->create(['role'=>'admin']);$this->actingAs($u);
  $g=Guest::create(['name'=>'Test Guest','mobile'=>'+8801712345678','email'=>'guest@example.com']);Room::query()->update(['bookable'=>true,'capacity'=>2,'housekeeping'=>'ready']);
  TariffVersion::query()->update(['active'=>true,'approved_at'=>now(),'approved_by'=>$u->id,'effective_from'=>'2026-01-01','calculation_mode'=>'progressive']);
  $s=app(BookingService::class);$b=$s->create(['guest_id'=>$g->id,'guest_category_id'=>GuestCategory::first()->id,'room_ids'=>[Room::where('code','R201')->first()->id],'check_in_at'=>'2027-01-01 14:00:00','check_out_at'=>'2027-01-05 12:00:00']);
  return $s->transition($b->id,'approve',['version'=>1]);
 }
 protected function setupProvider():void {config(['messaging.account_sid'=>'AC'.str_repeat('a',32),'messaging.auth_token'=>'test-token','messaging.sms_from'=>'+15005550006','messaging.whatsapp_from'=>'whatsapp:+15005550006','messaging.whatsapp_content_sid'=>'HX'.str_repeat('b',32),'messaging.status_callback'=>'https://resthouse.example/api/v1/messaging/twilio/status']);}
 public function test_sms_uses_provider_and_preserves_message_id():void {
  $b=$this->booking();$this->setupProvider();Http::fake(['api.twilio.com/*'=>Http::response(['sid'=>'SM'.str_repeat('c',32)],201)]);
  $n=NotificationDelivery::create(['booking_id'=>$b->id,'channel'=>'sms','destination'=>'+8801712345678','dedupe_key'=>'test-sms','status'=>'processing']);
  (new SendGuestNotification($n->id))->handle(app(GuestMessenger::class));$n->refresh();$this->assertSame('submitted',$n->status);$this->assertSame('SM'.str_repeat('c',32),$n->provider_id);
  (new SendGuestNotification($n->id))->handle(app(GuestMessenger::class));Http::assertSentCount(1);
  Http::assertSent(fn($request)=>$request['To']==='+8801712345678'&&str_contains($request['Body'],$b->number));
 }
 public function test_whatsapp_requires_approved_template_and_variables():void {
  $b=$this->booking();$this->setupProvider();Http::fake(['api.twilio.com/*'=>Http::response(['sid'=>'SM'.str_repeat('d',32)],201)]);
  $n=NotificationDelivery::create(['booking_id'=>$b->id,'channel'=>'whatsapp','destination'=>'+8801712345678','dedupe_key'=>'test-whatsapp']);
  app(GuestMessenger::class)->send($n,$b);Http::assertSent(fn($r)=>$r['To']==='whatsapp:+8801712345678'&&$r['ContentSid']==='HX'.str_repeat('b',32)&&json_decode($r['ContentVariables'],true)['1']===$b->number&&!isset($r['Body']));
 }
 public function test_unconfigured_channel_never_pretends_delivery():void {
  $b=$this->booking();$n=NotificationDelivery::first();$this->expectException(\RuntimeException::class);$this->expectExceptionMessage('SMTP is not configured');app(GuestMessenger::class)->send($n,$b);
 }
 public function test_unsigned_callback_is_rejected():void {$this->setupProvider();$this->post('/api/v1/messaging/twilio/status',['MessageSid'=>'SMfake'])->assertForbidden();}
 public function test_signed_callbacks_are_deduplicated_and_do_not_regress():void {
  $b=$this->booking();$this->setupProvider();$n=NotificationDelivery::first();$n->update(['provider_id'=>'SM'.str_repeat('e',32),'status'=>'submitted']);
  foreach(['delivered','delivered','sent'] as $status){$params=['AccountSid'=>config('messaging.account_sid'),'MessageSid'=>$n->provider_id,'MessageStatus'=>$status];$signature=(new RequestValidator('test-token'))->computeSignature(config('messaging.status_callback'),$params);$this->call('POST','/api/v1/messaging/twilio/status',[],[],[],['HTTP_X_TWILIO_SIGNATURE'=>$signature,'CONTENT_TYPE'=>'application/x-www-form-urlencoded'],http_build_query($params))->assertOk();}
  $this->assertSame('delivered',$n->fresh()->status);$this->assertNotNull($n->fresh()->delivered_at);
 }
}
