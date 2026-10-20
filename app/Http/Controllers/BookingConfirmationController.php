<?php
namespace App\Http\Controllers;
use App\Models\{Booking,NotificationDelivery};
use App\Services\{Audit,BookingConfirmation,BookingExport};
use Illuminate\Http\Request;
use Illuminate\Support\Str;
class BookingConfirmationController extends Controller {
 private function allowed(Request $r):void{abort_unless(in_array($r->user()->role,['admin','staff_admin']),403);}
 public function __invoke(Request $r,int $id,BookingConfirmation $service){$this->allowed($r);return response()->json(['data'=>$service->data(Booking::findOrFail($id))])->header('Cache-Control','private, no-store');}
 public function export(Request $r,int $id,string $format,BookingConfirmation $service,BookingExport $export){
  $this->allowed($r);$d=$service->data(Booking::findOrFail($id));$bytes=$export->render($d,$format);
  $name='Booking-'.preg_replace('/[^A-Za-z0-9_-]/','-',$d['number']).'.'.$format;
  return response($bytes)->withHeaders(['Content-Type'=>$format==='pdf'?'application/pdf':'image/jpeg','Content-Disposition'=>'attachment; filename="'.$name.'"','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
 }
 public function options(Request $r,int $id,BookingConfirmation $service){
  $this->allowed($r);$d=$service->data(Booking::findOrFail($id));$text=$service->text($d);$phone=preg_replace('/\D/','',$d['guest']['mobile']??'');$email=$d['guest']['email']??null;
  return response()->json(['data'=>['booking_number'=>$d['number'],'version'=>$d['version'],'text'=>$text,'whatsapp_url'=>$phone?'https://wa.me/'.$phone.'?text='.rawurlencode($text):null,'email_url'=>$email?'mailto:'.rawurlencode($email).'?subject='.rawurlencode('Rest-house booking '.$d['number']).'&body='.rawurlencode($text):null,'pdf_url'=>url('/api/v1/bookings/'.$id.'/exports/pdf'),'jpg_url'=>url('/api/v1/bookings/'.$id.'/exports/jpg'),'downloads_require_authentication'=>true]])->header('Cache-Control','private, no-store');
 }
 public function share(Request $r,int $id,BookingConfirmation $service){
  $this->allowed($r);$input=$r->validate(['channel'=>'required|in:email,sms,whatsapp','format'=>'sometimes|in:text,pdf,jpg','version'=>'required|integer','recipient'=>'prohibited']);
  $b=Booking::findOrFail($id);abort_unless($b->version===$input['version'],409,'Booking changed. Reload before sharing.');$d=$service->data($b);$format=$input['format']??'text';$channel=$input['channel'];
  abort_if($channel!=='email'&&$format!=='text',422,'SMS and WhatsApp API delivery support text/template messages. Download files and use native device sharing for attachments.');
  $destination=$d['guest'][$channel==='email'?'email':'mobile']??null;abort_unless($destination,422,'The guest has no contact for this channel.');
  if($channel==='email')abort_if(in_array(config('mail.default'),['log','array']),503,'Configure SMTP before queuing guest email.');
  else {abort_unless(config('messaging.account_sid')&&config('messaging.auth_token')&&config('messaging.status_callback'),503,'Configure Twilio credentials and HTTPS callbacks first.');abort_unless(config($channel==='sms'?'messaging.sms_from':'messaging.whatsapp_from'),503,'Configure an approved sender.');if($channel==='whatsapp')abort_unless(config('messaging.whatsapp_content_sid'),503,'Configure an approved WhatsApp template.');}
  $n=NotificationDelivery::create(['booking_id'=>$id,'channel'=>$channel,'destination'=>$destination,'format'=>$format,'confirmation_snapshot'=>$d,'dedupe_key'=>'manual-share-'.Str::uuid(),'status'=>'queued']);
  Audit::record('guest_share_queued','booking',$id,['delivery_id'=>$n->id,'channel'=>$channel,'format'=>$format]);
  return response()->json(['data'=>$n->only(['id','booking_id','channel','destination','format','status','created_at']),'message'=>'Queued for delivery by the scheduler and queue worker. This is not a delivery confirmation.'],202);
 }
 public function history(Request $r,int $id){$this->allowed($r);Booking::findOrFail($id);return NotificationDelivery::where('booking_id',$id)->latest()->paginate(25,['id','booking_id','channel','destination','format','status','attempts','last_error','sent_at','delivered_at','created_at']);}
}
