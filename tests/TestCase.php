<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests never talk to the real internet: a test that does hits the
        // production release manifest from every CI runner and pollutes the
        // install counter. Fake what you need with Http::fake() instead.
        Http::preventStrayRequests();
    }
}
