<?php

namespace Tests\Support;

use Illuminate\Support\Timebox;

/** A time box that writes down what it was asked for and never sleeps. */
class RecordingTimebox extends Timebox
{
    /** @var list<int> microseconds per call */
    public array $calls = [];

    public function call(callable $callback, int $microseconds)
    {
        $this->calls[] = $microseconds;

        return $callback($this);
    }
}
