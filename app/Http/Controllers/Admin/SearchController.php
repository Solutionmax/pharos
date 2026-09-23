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

        return response()->json([
            'results' => $search->search($request->user(), is_string($term) ? $term : ''),
        ]);
    }
}
