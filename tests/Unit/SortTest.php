<?php

namespace Tests\Unit;

use App\Http\Sorts\Sort;
use Illuminate\Http\Request;
use Tests\TestCase;

class SortTest extends TestCase
{
    private function sortFor(string $uri, string $default = 'asc'): Sort
    {
        return new class(Request::create($uri), $default) extends Sort
        {
            public function __construct(Request $request, string $default)
            {
                $this->defaultDirection = $default;
                parent::__construct($request);
            }
        };
    }

    public function test_default_direction_is_used_when_request_omits_it(): void
    {
        $this->assertSame('asc', $this->sortFor('/', 'asc')->getDirection());
        $this->assertSame('desc', $this->sortFor('/', 'desc')->getDirection());
    }

    public function test_explicit_valid_direction_overrides_default(): void
    {
        $this->assertSame('desc', $this->sortFor('/?direction=desc', 'asc')->getDirection());
        $this->assertSame('asc', $this->sortFor('/?direction=asc', 'desc')->getDirection());
    }

    public function test_invalid_direction_falls_back_to_default(): void
    {
        $this->assertSame('asc', $this->sortFor('/?direction=bogus', 'asc')->getDirection());
    }
}
