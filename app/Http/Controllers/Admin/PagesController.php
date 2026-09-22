<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\StatusPage;
use App\Models\User;
use App\Services\License;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PagesController extends Controller
{
    public function index(): View
    {
        return view('admin.pages.index', [
            'pages' => StatusPage::query()->withCount('users')->orderBy('name')->get(),
            'defaultPageId' => StatusPage::defaultId(),
        ]);
    }

    public function create(): View
    {
        return view('admin.pages.form', [
            'statusPage' => new StatusPage,
            'users' => $this->assignableUsers(),
            'assignedUserIds' => [],
        ]);
    }

    public function store(Request $request, License $license): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data, $license): void {
            $this->lockDefaultPage();
            $this->ensureCapacity($license);

            $page = StatusPage::query()->create([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'domain' => $data['domain'] ?? null,
                'is_published' => $data['is_published'] ?? false,
            ]);
            $page->users()->sync($data['user_ids'] ?? []);
        });

        return redirect()->route('admin.pages.index')->with('status', "{$data['name']} created.");
    }

    public function edit(StatusPage $statusPage): View
    {
        return view('admin.pages.form', [
            'statusPage' => $statusPage,
            'users' => $this->assignableUsers(),
            'assignedUserIds' => $statusPage->users()->pluck('users.id')->all(),
        ]);
    }

    public function update(Request $request, StatusPage $statusPage, License $license): RedirectResponse
    {
        $data = $this->validated($request, $statusPage);

        DB::transaction(function () use ($request, $statusPage, $data, $license): void {
            $reactivate = $statusPage->archived_at !== null && $request->boolean('reactivate');

            if ($reactivate) {
                $this->lockDefaultPage();
                $this->ensureCapacity($license);
            }

            $statusPage->fill([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'domain' => $data['domain'] ?? null,
                'is_published' => $statusPage->archived_at !== null && ! $reactivate
                    ? false
                    : ($data['is_published'] ?? false),
            ]);

            if ($reactivate) {
                $statusPage->archived_at = null;
            }

            $statusPage->save();
            $statusPage->users()->sync($data['user_ids'] ?? []);
        });

        return redirect()->route('admin.pages.index')->with('status', "{$data['name']} saved.");
    }

    public function archive(StatusPage $statusPage): RedirectResponse
    {
        abort_if($statusPage->getKey() === StatusPage::defaultId(), 403, 'The default status page cannot be archived.');

        $statusPage->update([
            'archived_at' => now(),
            'is_published' => false,
        ]);

        return redirect()->route('admin.pages.index')->with('status', "{$statusPage->name} archived.");
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?StatusPage $statusPage = null): array
    {
        $domain = $this->normaliseDomain($request->input('domain'));
        $request->merge([
            'slug' => strtolower(trim((string) $request->input('slug'))),
            'domain' => $domain,
        ]);

        $domainChanged = $domain !== $statusPage?->domain;
        $domainConfirmation = $domain !== null && $domainChanged ? ['required', 'accepted'] : ['nullable'];

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                ...($statusPage ? [Rule::in([$statusPage->slug])] : []),
                Rule::unique('status_pages', 'slug')->ignore($statusPage),
            ],
            'domain' => [
                'nullable',
                'string',
                'max:253',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== null && ($value === strtolower((string) parse_url(config('app.url'), PHP_URL_HOST))
                        || filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false)) {
                        $fail('Use a valid customer host name different from the central installation host.');
                    }
                },
                Rule::unique('status_pages', 'domain')->ignore($statusPage),
            ],
            'domain_verified' => $domainConfirmation,
            'is_published' => ['sometimes', 'boolean'],
            'reactivate' => ['sometimes', 'boolean'],
            'user_ids' => ['sometimes', 'array'],
            'user_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')],
        ]);
    }

    protected function normaliseDomain(mixed $domain): ?string
    {
        if (! is_string($domain) || trim($domain) === '') {
            return null;
        }

        return strtolower(rtrim(trim($domain), '.'));
    }

    protected function lockDefaultPage(): void
    {
        StatusPage::query()->whereKey(StatusPage::defaultId())->lockForUpdate()->firstOrFail();
    }

    protected function ensureCapacity(License $license): void
    {
        $limit = $license->statusPageLimit();
        $activePages = StatusPage::query()->whereNull('archived_at')->count();

        abort_if($limit !== null && $activePages >= $limit, 403, 'The status-page licence limit has been reached.');
    }

    protected function assignableUsers()
    {
        return User::query()->where('role', UserRole::User)->orderBy('name')->get();
    }
}
