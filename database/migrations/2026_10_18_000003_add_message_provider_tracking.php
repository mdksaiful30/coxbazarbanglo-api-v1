<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::table('notification_deliveries',function(Blueprint $t){$t->string('provider_id')->nullable()->unique();$t->timestamp('delivered_at')->nullable();});}
 public function down():void {Schema::table('notification_deliveries',fn(Blueprint $t)=>$t->dropColumn(['provider_id','delivered_at']));}
};
