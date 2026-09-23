<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request, AdminSearch $search): JsonResponse
    {
        $term = $request->query('q');
        // The page the palette was opened on only decides the order; AdminSearch
        // ignores any page the user may not open.
        $page = $request->query('page');

        return response()->json([
            'results' => $search->search(
                $request->user(),
                is_string($term) ? $term : '',
                is_string($page) && ctype_digit($page) ? (int) $page : null,
            ),
        ]);
    }
}
