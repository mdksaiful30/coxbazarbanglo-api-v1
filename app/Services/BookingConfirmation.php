<?php
namespace App\Services;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;
class BookingConfirmation {
 public function data(Booking $b):array{
  $b->loadMissing('rooms.floor');$property=DB::table('properties')->first();
  return ['number'=>$b->number,'version'=>$b->version,'status'=>$b->status,
   'property'=>['name'=>$property?->name??"Cox's Bazar Inspection Bungalow",'address'=>$property?->address??"Cox's Bazar, Bangladesh"],
   'guest'=>collect($b->guest_snapshot)->only(['name','designation','organization','mobile','email'])->all(),
   'check_in_at'=>$b->check_in_at,'check_out_at'=>$b->check_out_at,'timezone'=>'Asia/Dhaka','actual_check_in_at'=>$b->actual_check_in_at,'actual_check_out_at'=>$b->actual_check_out_at,
   'guest_count'=>$b->guest_count,'reference_letter'=>$b->reference_letter,'reference_date'=>$b->reference_date,
   'category'=>$b->quote['category_snapshot']['name']??DB::table('guest_categories')->where('id',$b->guest_category_id)->value('name'),
   'rooms'=>$b->rooms->map(fn($r)=>['code'=>$r->code,'name'=>$r->name,'ac_type'=>$r->ac_type,'floor'=>$r->floor?->name])->values()->all(),
   'quote_total_minor'=>$b->total_minor,'provisional'=>(bool)($b->quote['provisional']??false),'finance'=>app(FinanceService::class)->summary($b),'generated_at'=>now()->toIso8601String()];
 }
 public function text(array $d):string{
  $money=fn($n)=>'BDT '.number_format($n/100,2);$f=$d['finance'];
  return implode("\n",array_filter([$d['property']['name'],'BOOKING DETAILS','Booking: '.$d['number'],'Status: '.str_replace('_',' ',$d['status']),
   'Guest: '.$d['guest']['name'],empty($d['guest']['designation'])?null:'Designation: '.$d['guest']['designation'],
   'Arrival (Dhaka): '.$d['check_in_at'],'Departure (Dhaka): '.$d['check_out_at'],
   'Rooms: '.implode(', ',array_map(fn($r)=>$r['code'].' — '.$r['name'],$d['rooms'])),'Guests: '.$d['guest_count'],
   empty($d['reference_letter'])?null:'Reference: '.$d['reference_letter'], 'Charges: '.$money($f['charges_minor']),'Net paid: '.$money($f['paid_minor']-$f['refunded_minor']),'Balance: '.$money($f['balance_minor']),
   $d['provisional']?'Provisional quote: '.$money($d['quote_total_minor']).'. Not an approved reservation.':null,'Room tariffs include VAT. Contact the rest-house office for assistance.']));
 }
}
