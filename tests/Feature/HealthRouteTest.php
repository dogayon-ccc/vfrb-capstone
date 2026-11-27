<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthRouteTest extends TestCase
{
    public function test_root_answers_with_a_minimal_status_payload(): void
    {
        $this->get('/')->assertOk()->assertExactJson(['service' => 'vfrb-api', 'status' => 'ok']);
    }
}
