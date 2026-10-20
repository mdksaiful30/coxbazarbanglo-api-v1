<?php
namespace Tests\Feature;
use Tests\TestCase;
class FlutterCorsTest extends TestCase {
 public function test_configured_flutter_origin_accepts_bearer_and_idempotency_headers():void {
  $this->withHeaders(['Origin'=>'http://127.0.0.1:8085','Access-Control-Request-Method'=>'POST','Access-Control-Request-Headers'=>'authorization,content-type,idempotency-key'])
   ->options('/api/v1/bookings')->assertNoContent()->assertHeader('Access-Control-Allow-Origin','http://127.0.0.1:8085');
 }
 public function test_unconfigured_origin_is_not_granted_access():void {
  $this->withHeaders(['Origin'=>'https://untrusted.invalid','Access-Control-Request-Method'=>'POST'])
   ->options('/api/v1/bookings')->assertHeaderMissing('Access-Control-Allow-Origin');
 }
}
