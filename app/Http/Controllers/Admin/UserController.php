<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\StatusPage;
use App\Models\User;
use App\Notifications\InviteUser;
use App\Services\Audit;
use App\Services\Clock;
use App\Services\UserSessions;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Throwable;

class UserController extends Controller
{
    public function index(Request $request, UserSessions $sessions)
    {
        $search = $request->query('q');

        return view('admin.users', [
            'users' => User::with('statusPages')->orderBy('name')->get(),
            'pages' => StatusPage::whereNull('archived_at')->orderBy('name')->get(),
            'lastSeen' => $sessions->lastSeen(),
            'sessionsKnown' => UserSessions::available(),
            'search' => is_string($search) ? $search : '',
        ]);
    }

    /**
     * Without a password the new account gets an invitation mail with a link
     * to choose one. A password typed here still works, as it always did.
     */
    public function store(Request $request)
    {
        $this->readAccessMatrix($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['nullable', 'confirmed', PasswordRule::min(12)],
            'role' => ['sometimes', Rule::enum(UserRole::class)],
            'require_two_factor' => ['sometimes', 'boolean'],
            ...$this->pageRules(),
        ]);
        $invite = blank($data['password'] ?? null);

        $user = DB::transaction(function () use ($data, $invite) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                // Nobody knows this one: the invitation replaces it.
                'password' => Hash::make($invite ? Str::random(64) : $data['password']),
                'role' => $data['role'] ?? UserRole::User,
            ]);
            $user->forceFill(['require_two_factor' => (bool) ($data['require_two_factor'] ?? false)])->save();
            if (! $user->isAdmin()) {
                $this->syncPages($user, $data);
                Audit::record('user.page_access_changed', $user, ['pages' => [
                    'from' => [],
                    'to' => $user->statusPages()->pluck('status_page_user.role', 'status_pages.id')->all(),
                ]]);
            }

            return $user;
        });

        if (! $invite) {
            return redirect()->route('admin.users')->with('status', "{$data['name']} can now sign in.");
        }

        return $this->sendInvitation($request, $user)
            ? redirect()->route('admin.users')->with('status', "Invitation sent to {$user->email}.")
            : redirect()->route('admin.users')->withErrors(['mail' => "{$user->name} was added, but the invitation could not be sent. Check Settings, Central mail, then use Send a new invitation."]);
    }

    /** A fresh invitation link, for someone who lost or never got the first one. */
    public function invite(Request $request, User $user)
    {
        return $this->sendInvitation($request, $user)
            ? redirect()->route('admin.users')->with('status', "A new invitation was sent to {$user->email}.")
            : redirect()->route('admin.users')->withErrors(['mail' => 'The invitation could not be sent. Check Settings, Central mail.']);
    }

    protected function sendInvitation(Request $request, User $user): bool
    {
        try {
            $broker = Password::broker(InvitationController::BROKER);
            // createToken() lives on the concrete broker, not on the contract.
            if (! $broker instanceof PasswordBroker) {
                throw new \RuntimeException('The invitations broker is not a token broker.');
            }
            $token = $broker->createToken($user);
            // For someone else: the installation zone, not the inviting admin's own.
            Clock::withInstallationZone(fn () => $user->notify(new InviteUser($token, $request->user()->name)));
            Audit::record('user.invited', $user);

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * The side panel's one form: account type and every page role together.
     * The same guards apply as on the separate forms.
     */
    public function updateAccess(Request $request, User $user)
    {
        $this->readAccessMatrix($request);
        $data = $request->validate([
            'role' => ['required', Rule::enum(UserRole::class)],
            ...$this->pageRules(),
        ]);
        $role = UserRole::from($data['role']);

        if ($role === UserRole::User && $user->isAdmin() && User::where('role', UserRole::Admin)->count() <= 1) {
            return back()->withErrors(['role' => 'This is the only administrator. Promote someone else first.']);
        }

        DB::transaction(function () use ($user, $role, $data) {
            if ($user->role !== $role) {
                $user->update(['role' => $role]);
            }
            if ($role === UserRole::User) {
                $before = $user->statusPages()->pluck('status_page_user.role', 'status_pages.id')->all();
                $this->syncPages($user, $data);
                $after = $user->statusPages()->pluck('status_page_user.role', 'status_pages.id')->all();
                if ($before !== $after) {
                    Audit::record('user.page_access_changed', $user, ['pages' => ['from' => $before, 'to' => $after]]);
                }
            }
        });

        return redirect()->route('admin.users')->with('status', "Access for {$user->name} saved.");
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

    /**
     * The side panel posts access[page id] = none|viewer|editor|admin. Turn that
     * into the status_page_ids + page_roles pair every other form already sends,
     * so one set of rules validates both.
     */
    private function readAccessMatrix(Request $request): void
    {
        $matrix = $request->input('access');
        if (! is_array($matrix)) {
            return;
        }
        $request->validate(['access.*' => ['required', Rule::in(['none', 'viewer', 'editor', 'admin'])]]);
        $chosen = array_filter($matrix, fn ($role) => $role !== 'none');
        $request->merge([
            'status_page_ids' => array_map('intval', array_keys($chosen)),
            'page_roles' => $chosen,
        ]);
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
