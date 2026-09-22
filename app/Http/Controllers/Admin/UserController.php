<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\StatusPage;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index()
    {
        return view('admin.users', [
            'users' => User::with('statusPages')->orderBy('name')->get(),
            'pages' => StatusPage::whereNull('archived_at')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)],
            'role' => ['sometimes', Rule::enum(UserRole::class)],
            ...$this->pageRules(),
        ]);

        DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role' => $data['role'] ?? UserRole::User,
            ]);
            if (! $user->isAdmin()) {
                $this->syncPages($user, $data);
                Audit::record('user.page_access_changed', $user, ['pages' => [
                    'from' => [],
                    'to' => $user->statusPages()->pluck('status_page_user.role', 'status_pages.id')->all(),
                ]]);
            }
        });

        return redirect()->route('admin.users')->with('status', "{$data['name']} can now sign in.");
    }

    public function editPages(User $user)
    {
        abort_if($user->isAdmin(), 403, 'Administrators can access every page. Use the User role for restricted access.');

        return view('admin.user-pages', [
            'member' => $user,
            'pages' => StatusPage::whereNull('archived_at')->orderBy('name')->get(),
            'selectedPageIds' => $user->statusPages()->pluck('status_pages.id')->all(),
            'selectedPageRoles' => $user->statusPages()->pluck('status_page_user.role', 'status_pages.id')->all(),
        ]);
    }

    public function updatePages(Request $request, User $user)
    {
        abort_if($user->isAdmin(), 403, 'Administrators can access every page. Use the User role for restricted access.');
        $data = $request->validate($this->pageRules());
        DB::transaction(function () use ($user, $data) {
            $before = $user->statusPages()->pluck('status_page_user.role', 'status_pages.id')->all();
            $this->syncPages($user, $data);
            $after = $user->statusPages()->pluck('status_page_user.role', 'status_pages.id')->all();
            Audit::record('user.page_access_changed', $user, ['pages' => ['from' => $before, 'to' => $after]]);
        });

        return redirect()->route('admin.users')->with('status', "Page access for {$user->name} saved.");
    }

    private function syncPages(User $user, array $data): void
    {
        $existing = $user->statusPages()->pluck('status_page_user.role', 'status_pages.id')->all();
        $assignments = [];
        foreach ($data['status_page_ids'] ?? [] as $id) {
            $assignments[$id] = ['role' => $data['page_roles'][$id] ?? $existing[$id] ?? 'editor'];
        }
        $user->statusPages()->sync($assignments);
    }

    private function pageRules(): array
    {
        return [
            'page_roles' => ['sometimes', 'array'],
            'page_roles.*' => ['required', Rule::in(['viewer', 'editor', 'admin'])],
            'status_page_ids' => ['sometimes', 'array'],
            'status_page_ids.*' => ['integer', 'distinct', Rule::exists('status_pages', 'id')->whereNull('archived_at')],
        ];
    }

    public function updateRole(Request $request, User $user)
    {
        $data = $request->validate(['role' => ['required', Rule::enum(UserRole::class)]]);
        $role = UserRole::from($data['role']);

        // Same reasoning as the last account: an install with no administrator left
        // cannot be repaired through the interface, only from the CLI.
        if ($role === UserRole::User && $user->isAdmin() && User::where('role', UserRole::Admin)->count() <= 1) {
            return back()->withErrors(['role' => 'This is the only administrator. Promote someone else first.']);
        }

        $user->update(['role' => $role]);

        return redirect()->route('admin.users')
            ->with('status', "{$user->name} is now {$role->label()}.");
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->is($request->user())) {
            return back()->withErrors(['user' => 'You cannot delete the account you are signed in with.']);
        }

        // Locking everyone out of a self-hosted install is not recoverable
        // through the interface, so the last account stays.
        if (User::count() <= 1) {
            return back()->withErrors(['user' => 'This is the only account. Create another one first.']);
        }

        $name = $user->name;
        $user->delete();

        return redirect()->route('admin.users')->with('status', "{$name} removed.");
    }
}
