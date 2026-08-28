@extends('layouts.admin')
@section('title', 'Users')
@section('content')
@include('partials.pagehead', [
  'title' => 'Users',
  'sub' => $users->count().' '.\Illuminate\Support\Str::plural('account', $users->count()).' with access to this admin',
])

<div class="panel">
  <div class="panel-hd"><h3>Accounts</h3></div>
  <div class="scroll">
    <table>
      <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Added</th><th></th></tr></thead>
      <tbody>
      @foreach ($users as $user)
        <tr>
          <td>{{ $user->name }}@if($user->is(auth()->user())) <span class="sub">— you</span>@endif</td>
          <td class="mono" style="font-size:13px">{{ $user->email }}</td>
          <td>{{ $user->role->label() }}</td>
          <td class="num">{{ $user->created_at?->format('d M Y') }}</td>
          <td>
            <span class="rowacts">
              <form method="POST" action="{{ route('admin.users.role', $user) }}"
                    @if ($user->is(auth()->user()) && $user->isAdmin())
                      data-confirm-title="Give up your own admin rights?"
                      data-confirm="You lose the admin screens the moment you save this — another admin has to give them back."
                      data-confirm-action="Make me a user"
                    @endif>
                @csrf @method('PUT')
                <input type="hidden" name="role" value="{{ $user->isAdmin() ? 'user' : 'admin' }}">
                <button type="submit">{{ $user->isAdmin() ? 'Make user' : 'Make administrator' }}</button>
              </form>
              @unless ($user->is(auth()->user()))
                <form method="POST" action="{{ route('admin.users.destroy', $user) }}"
                      data-confirm-title="Remove {{ $user->name }}?"
                      data-confirm="They lose access <strong>immediately</strong> and any open session stops working. Nothing they created is deleted."
                      data-confirm-action="Remove account">
                  @csrf @method('DELETE')
                  <button type="submit">Remove</button>
                </form>
              @endunless
            </span>
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
  </div>
</div>

<div class="panel">
  <div class="panel-hd"><h3>Add someone</h3><span class="hint">A user runs the status page; an administrator also manages accounts, tokens, updates and branding</span></div>
  <div class="panel-bd">
    <form method="POST" action="{{ route('admin.users.store') }}" style="display:flex;flex-direction:column;gap:16px">
      @csrf
      <div class="fields">
        <div class="field">
          <label for="u-name">Name</label>
          <input id="u-name" name="name" type="text" value="{{ old('name') }}" required>
        </div>
        <div class="field">
          <label for="u-email">Email</label>
          <input id="u-email" name="email" type="email" value="{{ old('email') }}" required autocomplete="off">
        </div>
      </div>
      <div class="fields">
        <div class="field">
          <label for="u-password">Password</label>
          <input id="u-password" name="password" type="password" required autocomplete="new-password">
          <span class="help">At least 12 characters.</span>
        </div>
        <div class="field">
          <label for="u-password-confirm">Repeat password</label>
          <input id="u-password-confirm" name="password_confirmation" type="password" required autocomplete="new-password">
        </div>
      </div>
      <div class="fields">
        <div class="field">
          <label for="u-role">Role</label>
          <select id="u-role" name="role">
            @foreach (\App\Enums\UserRole::cases() as $role)
              <option value="{{ $role->value }}" @selected(old('role', 'user') === $role->value)>{{ $role->label() }}</option>
            @endforeach
          </select>
          <span class="help">Administrators can manage accounts, API tokens, updates and branding.</span>
        </div>
      </div>
      <div class="actions">
        <button class="btn" type="submit">Add user</button>
        <button class="btn ghost" type="reset">Clear</button>
      </div>
    </form>
  </div>
</div>

@endsection
