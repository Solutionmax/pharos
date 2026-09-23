<?php

namespace App\Services;

use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The global search behind Ctrl K. It only ever looks inside pages the user
 * holds a role on (every page for an administrator), and each page is searched
 * inside its own PageContext, so the model scopes keep the rows apart.
 */
class AdminSearch
{
    public const MIN_LENGTH = 2;

    public const PER_KIND = 6;

    public const MAX_RESULTS = 30;

    /** @return list<array{type: string, label: string, context: string, url: string}> */
    public function search(User $user, string $term): array
    {
        $term = trim(mb_substr($term, 0, 100));
        if (mb_strlen($term) < self::MIN_LENGTH) {
            return [];
        }
        $like = '%'.$term.'%';
        $pages = $this->pages($user);

        $results = [];
        foreach ($pages->filter(fn (StatusPage $p) => $this->matches($term, [$p->name, $p->slug, $p->tag_label]))->take(self::PER_KIND) as $page) {
            $results[] = [
                'type' => 'Status page',
                'label' => $page->name,
                'context' => $page->archived_at ? 'Archived' : ($page->is_published ? 'Published' : 'Draft'),
                // Only administrators get archived pages here; they reopen them from Edit.
                'url' => $page->archived_at
                    ? route('admin.pages.edit', $page)
                    : route('page.admin.overview', ['statusPage' => $page->id]),
            ];
        }

        $components = $groups = $incidents = [];
        foreach ($pages->whereNull('archived_at') as $page) {
            app(PageContext::class)->run($page->id, function () use ($user, $page, $like, &$components, &$groups, &$incidents) {
                $edit = $user->canEditPage($page->id);
                foreach (Component::query()->where('name', 'like', $like)->orderBy('name')->limit(self::PER_KIND)->get() as $component) {
                    $components[] = $this->row('Component', $component->name, $page,
                        $edit ? PageUrls::route('admin.components.edit', $component) : PageUrls::route('admin.components'));
                }
                foreach (ComponentGroup::query()->where('name', 'like', $like)->orderBy('name')->limit(self::PER_KIND)->get() as $group) {
                    $groups[] = $this->row('Service', $group->name, $page,
                        $edit ? PageUrls::route('admin.groups.edit', $group) : PageUrls::route('admin.groups'));
                }
                foreach (Incident::query()->where('name', 'like', $like)->orderByDesc('occurred_at')->limit(self::PER_KIND)->get() as $incident) {
                    $incidents[] = $this->row('Incident', $incident->name, $page,
                        $edit ? PageUrls::route('admin.incidents.update-form', $incident) : PageUrls::route('admin.incidents', ['q' => $incident->name]),
                        ($incident->isOpen() ? 'Open' : 'Resolved').', ');
                }
            });
        }
        array_push($results, ...array_slice($components, 0, self::PER_KIND), ...array_slice($groups, 0, self::PER_KIND), ...array_slice($incidents, 0, self::PER_KIND));

        if ($user->isAdmin()) {
            foreach (User::query()->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like))
                ->orderBy('name')->limit(self::PER_KIND)->get() as $account) {
                $results[] = ['type' => 'User', 'label' => $account->name, 'context' => $account->email, 'url' => route('admin.users', ['q' => $account->email])];
            }
        }

        return array_slice($results, 0, self::MAX_RESULTS);
    }

    /** @return Collection<int, StatusPage> */
    protected function pages(User $user): Collection
    {
        return $user->isAdmin()
            ? StatusPage::query()->orderBy('name')->get()
            : $user->statusPages()->whereNull('archived_at')->orderBy('name')->get();
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

    /** @return array{type: string, label: string, context: string, url: string} */
    protected function row(string $type, string $label, StatusPage $page, string $url, string $prefix = ''): array
    {
        return ['type' => $type, 'label' => $label, 'context' => $prefix.$page->name, 'url' => $url];
    }
}
