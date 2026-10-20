<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\FinanceService;
class ApiReadController extends Controller {
 public function guest(int $id){return response()->json(['data'=>\App\Models\Guest::findOrFail($id)]);}
 public function tariff(int $id){return response()->json(['data'=>\App\Models\TariffVersion::with('rates')->findOrFail($id)]);}
 public function tariffs(){return \App\Models\TariffVersion::with('rates')->latest()->paginate(25);}
 public function detail(string $entity,int $id){
  $models=['users'=>\App\Models\User::class,'payments'=>\App\Models\Payment::class,'credit-notes'=>\App\Models\CreditNote::class,'room-blocks'=>\App\Models\RoomBlock::class,'booking-amendments'=>\App\Models\BookingAmendment::class];
  abort_unless(isset($models[$entity]),404);return response()->json(['data'=>$models[$entity]::findOrFail($id)]);
 }
 public function csv(Request $r,FinanceService $f){
  $data=app(AdminController::class)->report($r,$f)->getData(true)['data'];
  return response()->streamDownload(function()use($data){$out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,['Booking','Guest','Status','Arrival','Departure','Rooms','Charges BDT','Balance BDT'],',','"','');foreach($data['bookings'] as $b){$row=[$b['number'],$b['guest'],$b['status'],$b['check_in_at'],$b['check_out_at'],$b['rooms'],$b['finance']['charges_minor']/100,$b['finance']['balance_minor']/100];$row=array_map(fn($v)=>is_string($v)&&preg_match('/^[\s]*[=+@-]/u',$v)?"'".$v:$v,$row);fputcsv($out,$row,',','"','');}fclose($out);},'rest-house-report.csv',['Content-Type'=>'text/csv; charset=UTF-8','Cache-Control'=>'private, no-store']);
 }
}
