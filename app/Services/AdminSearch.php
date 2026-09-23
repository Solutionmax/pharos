<?php

namespace App\Services;

use App\Enums\ComponentStatus;
use App\Enums\IncidentStatus;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\Maintenance;
use App\Models\StatusPage;
use App\Models\User;
use App\Services\Search\SearchCommands;
use App\Services\Search\SearchRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * The global search behind Ctrl K. It only ever looks inside pages the user
 * holds a role on (every page for an administrator), and each page is searched
 * inside its own PageContext, so the model scopes keep the rows apart.
 *
 * Rows carry what the palette shows and nothing more: a name, the page it
 * belongs to, a status in words and a link. Incident and maintenance messages
 * never leave the server here.
 */
class AdminSearch
{
    public const MIN_LENGTH = 2;

    public const PER_KIND = 6;

    public const MAX_RESULTS = 48;

    public function __construct(protected SearchCommands $commands) {}

    /**
     * @return list<array<string, mixed>> rows in group order, the current page's rows first within each group
     */
    public function search(User $user, string $term, ?int $currentPageId = null): array
    {
        $term = trim(mb_substr($term, 0, 100));
        if (mb_strlen($term) < self::MIN_LENGTH) {
            return [];
        }
        $pages = $this->pages($user);
        $current = $this->currentPage($pages, $currentPageId);
        $like = '%'.$term.'%';
        $states = PageStatus::worstByPage();

        $rows = [];
        $matchingPages = $pages->filter(fn (StatusPage $p) => $this->matches($term, [$p->name, $p->slug, $p->tag_label]));
        foreach ($this->currentFirst($matchingPages, $current)->take(self::PER_KIND) as $page) {
            $rows[] = $this->pageRow($page, $current, $states);
        }

        foreach ($this->currentFirst($pages->whereNull('archived_at'), $current) as $page) {
            array_push($rows, ...app(PageContext::class)->run($page->id,
                fn () => $this->pageRows($user, $page, $like, $page->id === $current?->id)));
        }

        if ($user->isAdmin()) {
            foreach (User::query()->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like))
                ->orderBy('name')->limit(self::PER_KIND)->get() as $account) {
                $rows[] = SearchRow::make('User', $account->name, route('admin.users', ['q' => $account->email]), [
                    'context' => $account->email,
                    'status' => SearchRow::status($account->isAdmin() ? 'Administrator' : 'Page user', 'off'),
                ]);
            }
        }

        array_push($rows, ...$this->commands->matching($user, $current, $term, self::PER_KIND));

        return array_slice(SearchRow::grouped($rows, self::PER_KIND), 0, self::MAX_RESULTS);
    }

    /**
     * What the palette offers before anything is typed.
     *
     * @return array{page: array<string, mixed>|null, actions: list<array<string, mixed>>}
     */
    public function start(User $user, ?int $currentPageId = null): array
    {
        $pages = $this->pages($user);
        $current = $this->currentPage($pages, $currentPageId);

        return [
            'page' => $current ? SearchRow::page($current) : null,
            'actions' => $this->commands->actions($user, $current, $pages->whereNull('archived_at')),
        ];
    }

    /** @return list<array<string, mixed>> components, services, incidents and maintenance of one page */
    protected function pageRows(User $user, StatusPage $page, string $like, bool $isCurrent): array
    {
        $edit = $user->canEditPage($page->id);
        $extra = ['page' => SearchRow::page($page), 'context' => $page->name, 'current' => $isCurrent];
        $rows = [];

        foreach (Component::query()->where('name', 'like', $like)->orderBy('name')->limit(self::PER_KIND)->get() as $component) {
            $rows[] = SearchRow::make('Component', $component->name,
                $edit ? PageUrls::route('admin.components.edit', $component) : PageUrls::route('admin.components'),
                $extra + [
                    'status' => $component->enabled
                        ? SearchRow::status($component->status->label(), $component->status->tone())
                        : SearchRow::status('Hidden', 'off'),
                    'hint' => $edit ? 'Edit' : 'Open',
                ]);
        }

        foreach (ComponentGroup::query()->with('components')->where('name', 'like', $like)->orderBy('name')->limit(self::PER_KIND)->get() as $group) {
            $status = $group->status();
            $rows[] = SearchRow::make('Service', $group->name,
                $edit ? PageUrls::route('admin.groups.edit', $group) : PageUrls::route('admin.groups'),
                $extra + ['status' => SearchRow::status($status->label(), $status->tone()), 'hint' => $edit ? 'Edit' : 'Open']);
        }

        foreach (Incident::query()->where('name', 'like', $like)->orderByDesc('occurred_at')->limit(self::PER_KIND)->get() as $incident) {
            $rows[] = SearchRow::make('Incident', $incident->name,
                $edit ? PageUrls::route('admin.incidents.update-form', $incident) : PageUrls::route('admin.incidents', ['q' => $incident->name]),
                $extra + [
                    'status' => SearchRow::status($incident->status->label(), self::incidentTone($incident->status)),
                    'meta' => 'Started '.$incident->occurred_at->diffForHumans(),
                    'hint' => $edit ? 'Post update' : 'Open',
                ]);
        }

        if (class_exists(Maintenance::class) && Route::has('admin.maintenance')) {
            foreach (Maintenance::query()->where('title', 'like', $like)->orderByDesc('starts_at')->limit(self::PER_KIND)->get() as $window) {
                $editable = $edit && $window->isOpen() && Route::has('admin.maintenance.edit');
                $rows[] = SearchRow::make('Maintenance', $window->title,
                    $editable ? PageUrls::route('admin.maintenance.edit', $window) : PageUrls::route('admin.maintenance'),
                    $extra + [
                        'status' => SearchRow::status($window->stateLabel(), $window->isOpen() ? 'm' : 'off'),
                        'meta' => ($window->starts_at->isFuture() ? 'Starts ' : 'Started ').$window->starts_at->diffForHumans(),
                        'hint' => $editable ? 'Edit' : 'Open',
                    ]);
            }
        }

        return $rows;
    }

    /** @param array<int, ComponentStatus> $states */
    protected function pageRow(StatusPage $page, ?StatusPage $current, array $states): array
    {
        $state = $states[$page->id] ?? null;

        return SearchRow::make('Status page', $page->name,
            // Only administrators get archived pages here; they reopen them from Edit.
            $page->archived_at ? route('admin.pages.edit', $page) : route('page.admin.overview', ['statusPage' => $page->id]), [
                'context' => $page->archived_at ? 'Archived' : ($page->is_published ? 'Published' : 'Draft'),
                'page' => SearchRow::page($page),
                'status' => $page->archived_at
                    ? SearchRow::status('Archived', 'off')
                    : SearchRow::status(PageStatus::label($state), $state?->tone() ?? 'off'),
                'meta' => $page->archived_at ? null : ($page->is_published ? 'Published' : 'Draft, not public'),
                'current' => $page->id === $current?->id,
                'hint' => $page->id === $current?->id ? 'Open' : 'Switch',
            ]);
    }

    public static function incidentTone(IncidentStatus $status): string
    {
        return match ($status) {
            IncidentStatus::Investigating => 'b',
            IncidentStatus::Identified => 'p',
            IncidentStatus::Watching => 'm',
            IncidentStatus::Resolved => 'ok',
        };
    }

    /** @return Collection<int, StatusPage> */
    protected function pages(User $user): Collection
    {
        return $user->isAdmin()
            ? StatusPage::query()->orderBy('name')->get()
            : $user->statusPages()->whereNull('archived_at')->orderBy('name')->get();
    }

    /**
     * The page the palette was opened on, if the user may open it. Anything
     * else falls back to the page the user would land on, never to a page
     * outside their own list.
     *
     * @param  Collection<int, StatusPage>  $pages
     */
    protected function currentPage(Collection $pages, ?int $pageId): ?StatusPage
    {
        $open = $pages->whereNull('archived_at');

        return $open->firstWhere('id', $pageId)
            ?? $open->firstWhere('id', StatusPage::defaultId())
            ?? $open->first();
    }

    /**
     * @param  Collection<int, StatusPage>  $pages
     * @return Collection<int, StatusPage>
     */
    protected function currentFirst(Collection $pages, ?StatusPage $current): Collection
    {
        return $pages->sortBy(fn (StatusPage $p) => $p->id === $current?->id ? 0 : 1)->values();
    }

    /** @param list<?string> $haystacks */
    protected function matches(string $term, array $haystacks): bool
    {
        foreach ($haystacks as $haystack) {
            if ($haystack !== null && mb_stripos($haystack, $term) !== false) {
                return true;
            }
        }

        return false;
    }
}
