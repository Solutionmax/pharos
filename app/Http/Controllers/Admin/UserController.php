<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index()
    {
        return view('admin.users', ['users' => User::orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)],
            'role' => ['sometimes', Rule::enum(UserRole::class)],
        ]);

        User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'] ?? UserRole::User,
        ]);

        return redirect()->route('admin.users')->with('status', "{$data['name']} can now sign in.");
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
