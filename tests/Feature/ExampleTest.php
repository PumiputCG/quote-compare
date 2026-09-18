<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://localhost']);
        url()->forceRootUrl('http://localhost');
    }

    public function test_root_redirects_to_login(): void
    {
        $this->get('/')
            ->assertRedirect('/login');
    }
}
