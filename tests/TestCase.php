<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\Fixtures;

abstract class TestCase extends BaseTestCase
{
    use Fixtures, RefreshDatabase;
}
