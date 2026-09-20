<?php

namespace App\Services;

use App\Models\StatusPage;

class PageContext
{
    private ?int $selectedId = null;

    public function id(): int
    {
        return $this->selectedId ?? StatusPage::defaultId();
    }

    public function page(): StatusPage
    {
        return StatusPage::query()->findOrFail($this->id());
    }

    public function selectedId(): ?int
    {
        return $this->selectedId;
    }

    public function run(int $pageId, callable $callback): mixed
    {
        $previousId = $this->selectedId;
        $this->selectedId = $pageId;

        try {
            return $callback();
        } finally {
            $this->selectedId = $previousId;
        }
    }
}
