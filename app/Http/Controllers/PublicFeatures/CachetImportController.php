<?php

namespace App\Http\Controllers\PublicFeatures;

use App\Http\Controllers\Controller;
use App\Services\CachetImporter;
use App\Services\PageContext;
use App\Services\PageUrls;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class CachetImportController extends Controller
{
    private function authorize(Request $request): void
    {
        abort_unless($request->user()?->canAdministerPage(app(PageContext::class)->id()), 403);
    }

    private function key(Request $request, string $token): string
    {
        return 'cachet-preview:'.$request->user()->id.':'.app(PageContext::class)->id().':'.$token;
    }

    public function show(Request $request)
    {
        $this->authorize($request);

        return view('admin.cachet-import');
    }

    public function preview(Request $request, CachetImporter $importer)
    {
        $this->authorize($request);
        $request->validate(['export' => ['required', 'file', 'max:4096']]);
        $export = $importer->decode($request->file('export')->get());
        $preview = $importer->preview($export);
        $previewToken = Str::random(64);
        Cache::put($this->key($request, $previewToken), Crypt::encryptString(json_encode($export)), now()->addMinutes(20));

        return view('admin.cachet-import', compact('preview', 'previewToken'));
    }

    public function apply(Request $request, CachetImporter $importer)
    {
        $this->authorize($request);
        $data = $request->validate(['preview_token' => ['required', 'string', 'regex:/^[a-zA-Z0-9]{64}$/']]);
        // Pull consumes this preview before applying, so a replay never creates duplicates.
        $encrypted = Cache::pull($this->key($request, $data['preview_token']));
        abort_unless(is_string($encrypted), 404);
        $importer->apply($importer->decode(Crypt::decryptString($encrypted)));

        return redirect()->to(PageUrls::route('admin.integrations.cachet'))->with('status', __('Cachet import completed. No notifications were sent.'));
    }
}
