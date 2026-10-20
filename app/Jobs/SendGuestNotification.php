<?php
namespace App\Jobs;
use App\Models\{Booking,NotificationDelivery};
use App\Services\GuestMessenger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
class SendGuestNotification implements ShouldQueue {
 use Queueable;
 public int $tries=4;
 public function __construct(public int $deliveryId){}
 public function backoff():array{return [60,300,900];}
 public function handle(GuestMessenger $messenger):void {
  $lock=Cache::lock('guest-message-'.$this->deliveryId,90);
  if(!$lock->get()){$this->release(30);return;}
  try{
   $n=NotificationDelivery::findOrFail($this->deliveryId);
   if(in_array($n->status,['sent','delivered','read','failed','submitted'])||$n->provider_id)return;
   $n->increment('attempts');$b=Booking::with('rooms')->findOrFail($n->booking_id);
   if($n->confirmation_snapshot && $n->confirmation_snapshot['version']!==$b->version){$n->update(['status'=>'cancelled','last_error'=>'Booking changed after sharing was requested. Request a fresh share.']);return;}
   if(!$n->confirmation_snapshot&&!in_array($b->status,['confirmed','checked_in','checked_out'])){$n->update(['status'=>'cancelled','last_error'=>'Booking is no longer confirmed.']);return;}
   try{$providerId=$messenger->send($n,$b);$n->update(['status'=>$providerId?'submitted':'sent','provider_id'=>$providerId,'sent_at'=>now(),'last_error'=>null]);}
   catch(\Throwable $e){$n->update(['last_error'=>mb_substr($e->getMessage(),0,1000)]);throw $e;}
  }finally{$lock->release();}
 }
 public function failed(?\Throwable $e):void {NotificationDelivery::whereKey($this->deliveryId)->whereNull('provider_id')->update(['status'=>'failed','last_error'=>mb_substr($e?->getMessage()??'Delivery failed',0,1000)]);}
}
