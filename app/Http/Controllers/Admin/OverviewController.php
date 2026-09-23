<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PageContext;
use App\Services\PageHealth;
use App\Services\PageOverview;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The landing screen for a page: its live state, history and loose ends. */
class OverviewController extends Controller
{
    public function show(Request $request, PageOverview $overview, PageHealth $health): View
    {
        $user = $request->user();
        $page = app(PageContext::class)->page();

        return view('admin.overview', [
            'page' => $page,
            'data' => $overview->build(),
            'health' => $health->checks($user, $page),
            'readiness' => $health->readiness($user, $page),
            'canEdit' => $user->canEditPage($page->id),
        ]);
    }
}
