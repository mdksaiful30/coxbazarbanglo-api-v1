<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\DB;

class Idempotent
{
    public function handle($request, Closure $next)
    {
        $key = $request->header('Idempotency-Key');
        abort_if(! $key || strlen($key) > 100, 422, 'A unique Idempotency-Key header of at most 100 characters is required.');
        $fingerprint = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());

        return DB::transaction(function () use ($request, $next, $key, $fingerprint) {
            // Serializes retries for this actor across workers, including the first insertion.
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $entry = DB::table('idempotency_keys')->where('user_id', $request->user()->id)->where('key', $key)->first();
            if ($entry) {
                abort_unless(hash_equals($entry->fingerprint, $fingerprint), 409, 'Idempotency key was used for a different request.');

                return response()->json(json_decode($entry->response, true), $entry->status_code);
            }
            $response = $next($request);
            if ($response->isSuccessful()) {
                DB::table('idempotency_keys')->insert(['user_id' => $request->user()->id, 'key' => $key, 'fingerprint' => $fingerprint, 'response' => $response->getContent(), 'status_code' => $response->getStatusCode(), 'created_at' => now(), 'updated_at' => now()]);
            }

            return $response;
        });
    }
}
