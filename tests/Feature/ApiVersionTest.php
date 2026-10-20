<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiVersionTest extends TestCase
{
    public function test_version_is_public_and_contains_only_release_metadata(): void
    {
        $this->getJson('/api/v1/version')->assertOk()->assertExactJson(['data' => [
            'application_version' => config('release.application_version'),
            'api_version' => 'v1',
            'api_base_path' => '/api/v1',
        ]])->assertHeader('Cache-Control', 'no-store, private');
    }
}
