<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class FoundationPageTest extends TestCase
{
    public function test_foundation_page_renders_without_identity_or_business_context(): void
    {
        $this->withoutVite();

        $response = $this->get('/');

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Foundation')
                ->where('foundation', 'F0')
                ->missing('auth')
                ->missing('business')
                ->missing('membership'));
    }

    public function test_health_endpoint_is_available(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_f0_foundation_does_not_expose_authentication_routes(): void
    {
        $this->assertFalse(Route::has('login'));
        $this->assertFalse(Route::has('register'));
    }
}
