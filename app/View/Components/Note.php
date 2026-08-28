<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * A "Good to know" box. Anyone signed in can hide one for good; a `warn` box
 * ("Careful") cannot be hidden, because it is there to be read every time.
 */
class Note extends Component
{
    public function __construct(public string $id, public bool $warn = false) {}

    public function shouldRender(): bool
    {
        return $this->warn || ! auth()->user()?->hasDismissed($this->id);
    }

    public function render(): View
    {
        return view('components.note', ['dismissable' => ! $this->warn && auth()->check()]);
    }
}
