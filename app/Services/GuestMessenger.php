<?php
namespace App\Services;
use App\Models\{Booking,NotificationDelivery};
use Illuminate\Support\Facades\{Http,Mail};
class GuestMessenger {
 public function send(NotificationDelivery $n,Booking $b):?string {

  $d=$n->confirmation_snapshot??app(BookingConfirmation::class)->data($b);$rooms=implode(', ',array_column($d['rooms'],'code'));$amount=number_format($d['quote_total_minor']/100,2);
  $text=app(BookingConfirmation::class)->text($d);
  if($n->channel==='email') {
   if(in_array(config('mail.default'),['log','array']))throw new \RuntimeException('SMTP is not configured. No guest message was sent.');
   $attachment=in_array($n->format,['pdf','jpg'])?app(BookingExport::class)->render($d,$n->format):null;
   Mail::raw($text,function($m)use($n,$d,$attachment){$m->to($n->destination)->subject('Rest-house booking '.$d['number']);if($attachment!==null)$m->attachData($attachment,'Booking-'.$d['number'].'.'.$n->format,['mime'=>$n->format==='pdf'?'application/pdf':'image/jpeg']);});return null;
  }
  $sid=config('messaging.account_sid');$token=config('messaging.auth_token');$callback=config('messaging.status_callback');
  if(!$sid||!$token||!$callback)throw new \RuntimeException('Twilio account credentials and public HTTPS status callback must be configured.');
  if(!preg_match('/^AC[0-9a-fA-F]{32}$/',$sid)||!str_starts_with($callback,'https://'))throw new \RuntimeException('Invalid Twilio account SID or HTTPS callback URL.');
  $payload=['To'=>$n->destination,'StatusCallback'=>$callback];
  if($n->channel==='sms'){
   $payload['From']=config('messaging.sms_from');$payload['Body']=$text;
  }elseif($n->channel==='whatsapp'){
   $payload['To']='whatsapp:'.$n->destination;$payload['From']=config('messaging.whatsapp_from');$payload['ContentSid']=config('messaging.whatsapp_content_sid');
   if(!$payload['ContentSid'])throw new \RuntimeException('An approved WhatsApp Content SID is required.');
   $payload['ContentVariables']=json_encode(['1'=>$d['number'],'2'=>$d['check_in_at'],'3'=>$d['check_out_at'],'4'=>$rooms,'5'=>$amount]);
  }else throw new \RuntimeException('Unsupported notification channel.');
  if(empty($payload['From']))throw new \RuntimeException('Configure an approved Twilio sender for this channel.');
  // No automatic HTTP retries: uncertain provider acceptance must not create duplicate messages.
  $response=Http::asForm()->withBasicAuth($sid,$token)->connectTimeout(10)->timeout(30)->post('https://api.twilio.com/2010-04-01/Accounts/'.$sid.'/Messages.json',$payload);
  if(!$response->successful())throw new \RuntimeException('Twilio rejected the message (HTTP '.$response->status().'). Check sender, template, consent and account configuration.');
  $providerId=$response->json('sid');if(!is_string($providerId)||!str_starts_with($providerId,'SM'))throw new \RuntimeException('Provider response did not contain a message SID.');
  return $providerId;
 }
}
