<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Check;
use App\Models\Component;
use App\Models\ProbeLocation;
use App\Services\PageContext;
use App\Services\PageUrls;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProbeLocationController extends Controller
{
    private function authorizePage(Request $r): void
    {
        abort_unless($r->user()->canAdministerPage(app(PageContext::class)->id()), 403);
    }

    public function index(Request $r)
    {
        $this->authorizePage($r);

        return view('admin.probe-locations', ['locations' => ProbeLocation::with('checks.component')->orderBy('name')->get(), 'checks' => Check::whereHas('component')->where('type', '!=', 'heartbeat')->with('component')->get()]);
    }

    public function store(Request $r)
    {
        $this->authorizePage($r);
        $data = $r->validate(['name' => ['required', 'string', 'max:100'], 'checks' => ['required', 'array', 'min:1', 'max:100'], 'checks.*' => ['integer', Rule::exists('checks', 'id')->whereIn('component_id', Component::pluck('id'))]]);
        abort_if(ProbeLocation::count() >= 20, 422);
        $token = Str::random(64);
        $location = ProbeLocation::create(['name' => $data['name'], 'token_hash' => hash('sha256', $token)]);
        $location->checks()->sync($data['checks']);
        $r->session()->put('probe.setup', ['token' => $token, 'id' => $location->id]);

        return redirect()->to(PageUrls::route('admin.locations'))->with('status', __('Location added. Download its credential file now; it is available once.'));
    }

    public function credential(Request $r)
    {
        $this->authorizePage($r);
        $setup = $r->session()->pull('probe.setup');
        abort_unless($setup && ProbeLocation::find($setup['id']), 404);

        return response('PHAROS_PROBE_HUB='.rtrim(config('app.url'), '/')."\nPHAROS_PROBE_TOKEN=".$setup['token']."\n", 200, ['Content-Type' => 'text/plain', 'Content-Disposition' => 'attachment; filename=pharos-probe.env', 'Cache-Control' => 'no-store']);
    }

    public function destroy(Request $r, ProbeLocation $location)
    {
        $this->authorizePage($r);
        $location->delete();

        return redirect()->to(PageUrls::route('admin.locations'))->with('status',__('Location removed and credentials revoked.'));
    }
}
