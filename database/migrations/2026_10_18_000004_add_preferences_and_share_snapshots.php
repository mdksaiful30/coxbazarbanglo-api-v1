<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{
  Schema::create('user_preferences',function(Blueprint $t){$t->id();$t->foreignId('user_id')->unique()->constrained();$t->string('theme')->default('system');$t->string('language')->default('en');$t->timestamps();});
  Schema::table('notification_deliveries',function(Blueprint $t){$t->string('format')->default('text');$t->json('confirmation_snapshot')->nullable();});
 }
 public function down():void{Schema::table('notification_deliveries',fn(Blueprint $t)=>$t->dropColumn(['format','confirmation_snapshot']));Schema::dropIfExists('user_preferences');}
};
