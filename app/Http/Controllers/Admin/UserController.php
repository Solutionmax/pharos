<?php

namespace App\Http\Controllers\Admin;

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
        ]);

        User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        return redirect()->route('admin.users')->with('status', "{$data['name']} can now sign in.");
    }

    public function updatePassword(Request $request, User $user)
    {
        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        $user->update(['password' => Hash::make($data['password'])]);

        return redirect()->route('admin.users')->with('status', "Password changed for {$user->name}.");
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
