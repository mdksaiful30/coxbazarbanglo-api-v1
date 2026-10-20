<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class PreferenceController extends Controller {
 public function show(Request $r){$row=DB::table('user_preferences')->where('user_id',$r->user()->id)->first();return response()->json(['data'=>['theme'=>$row?->theme??'system','language'=>$row?->language??'en','persisted'=>(bool)$row]]);}
 public function update(Request $r){$d=$r->validate(['theme'=>'sometimes|required|in:light,dark,system','language'=>'sometimes|required|in:en,bn']);abort_if(!$d,422,'Provide theme or language.');DB::transaction(function()use($r,$d){DB::table('users')->where('id',$r->user()->id)->lockForUpdate()->first();$existing=DB::table('user_preferences')->where('user_id',$r->user()->id)->exists();if($existing)DB::table('user_preferences')->where('user_id',$r->user()->id)->update($d+['updated_at'=>now()]);else DB::table('user_preferences')->insert($d+['user_id'=>$r->user()->id,'theme'=>'system','language'=>'en','created_at'=>now(),'updated_at'=>now()]);});return $this->show($r);}
}
