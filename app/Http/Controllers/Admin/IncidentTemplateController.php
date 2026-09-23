<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IncidentTemplate;
use App\Services\PageUrls;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Ready-made wording for incidents that happen more than once. The same
 * templates serve the report form and the API (template=<slug>).
 */
class IncidentTemplateController extends Controller
{
    public function index()
    {
        return view('admin.incident-templates', ['templates' => IncidentTemplate::orderBy('name')->get()]);
    }

    public function create()
    {
        return view('admin.incident-template-form', ['template' => new IncidentTemplate]);
    }

    public function edit(IncidentTemplate $template)
    {
        return view('admin.incident-template-form', ['template' => $template]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $template = IncidentTemplate::create($data + ['slug' => $this->uniqueSlug($data['name'])]);

        return redirect()->to(PageUrls::route('admin.incidents.templates'))
            ->with('status', "Template \"{$template->name}\" saved. Use it from Report an incident, or with template={$template->slug} in the API.");
    }

    /** The slug stays: API clients already call the template by it. */
    public function update(Request $request, IncidentTemplate $template)
    {
        $template->update($this->validated($request));

        return redirect()->to(PageUrls::route('admin.incidents.templates'))
            ->with('status', "Template \"{$template->name}\" saved.");
    }

    public function destroy(IncidentTemplate $template)
    {
        $name = $template->name;
        $template->delete();

        return redirect()->to(PageUrls::route('admin.incidents.templates'))
            ->with('status', "Template \"{$name}\" deleted. API calls that use it now fail with a validation error.");
    }

    /** @return array{name: string, title_template: string, body_template: string} */
    protected function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'title_template' => ['required', 'string', 'max:255'],
            'body_template' => ['required', 'string', 'max:20000'],
        ]);
    }

    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'template';
        $slug = $base;
        for ($i = 2; IncidentTemplate::where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
