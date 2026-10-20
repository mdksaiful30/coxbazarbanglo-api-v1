<?php
namespace App\Http\Controllers;
use App\Models\NotificationDelivery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Twilio\Security\RequestValidator;
class MessageWebhookController extends Controller {
 public function __invoke(Request $request){
  $token=config('messaging.auth_token');$url=config('messaging.status_callback');abort_unless($token&&$url,503,'Messaging is not configured.');
  // Use the original form values, before Laravel trimming/empty-string transformations.
  parse_str($request->getContent(),$params);
  abort_unless((new RequestValidator($token))->validate($request->header('X-Twilio-Signature',''),$url,$params),403,'Invalid message provider signature.');
  abort_unless(($params['AccountSid']??null)===config('messaging.account_sid'),403,'Incorrect provider account.');
  return DB::transaction(function()use($params){
   $n=NotificationDelivery::where('provider_id',$params['MessageSid']??'')->lockForUpdate()->first();abort_unless($n,404,'Message is not registered.');
   $status=$params['MessageStatus']??'';$rank=['submitted'=>0,'accepted'=>0,'queued'=>0,'sending'=>1,'sent'=>2,'delivered'=>3,'read'=>4];
   if(in_array($status,['failed','undelivered'])&&!in_array($n->status,['delivered','read']))$n->update(['status'=>'failed','last_error'=>'Provider delivery failure '.($params['ErrorCode']??'unknown')]);
   elseif(isset($rank[$status])&&$rank[$status]>=($rank[$n->status]??-1))$n->update(['status'=>$status,'delivered_at'=>in_array($status,['delivered','read'])?($n->delivered_at??now()):$n->delivered_at]);
   return response()->json(['received'=>true]);
  });
 }
}
