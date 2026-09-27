<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        if (! filter_var(getenv('AUTH_TEST_DATABASE'), FILTER_VALIDATE_BOOL)) {
            throw new \RuntimeException('Tests reset auth_db. Run them with the isolated compose.test.yml stack (AUTH_TEST_DATABASE=true).');
        }
        parent::setUp();
    }
}
