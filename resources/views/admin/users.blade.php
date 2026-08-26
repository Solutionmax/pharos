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
      <thead><tr><th>Name</th><th>Email</th><th>Added</th><th></th></tr></thead>
      <tbody>
      @foreach ($users as $user)
        <tr>
          <td>{{ $user->name }}@if($user->is(auth()->user())) <span class="sub">— you</span>@endif</td>
          <td class="mono" style="font-size:13px">{{ $user->email }}</td>
          <td class="num">{{ $user->created_at?->format('d M Y') }}</td>
          <td>
            <span class="rowacts">
              @unless ($user->is(auth()->user()))
                <form method="POST" action="{{ route('admin.users.destroy', $user) }}"
                      onsubmit="return confirm('Remove {{ $user->name }}? They lose access immediately.')">
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
  <div class="panel-hd"><h3>Add someone</h3><span class="hint">They can do everything you can</span></div>
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
      <div class="actions">
        <button class="btn" type="submit">Add user</button>
        <button class="btn ghost" type="reset">Clear</button>
      </div>
    </form>
  </div>
</div>

<div class="panel">
  <div class="panel-hd"><h3>Change your own password</h3></div>
  <div class="panel-bd">
    <form method="POST" action="{{ route('admin.users.password', auth()->user()) }}" style="display:flex;flex-direction:column;gap:16px">
      @csrf @method('PUT')
      <div class="fields">
        <div class="field">
          <label for="p-new">New password</label>
          <input id="p-new" name="password" type="password" required autocomplete="new-password">
        </div>
        <div class="field">
          <label for="p-confirm">Repeat</label>
          <input id="p-confirm" name="password_confirmation" type="password" required autocomplete="new-password">
        </div>
      </div>
      <div class="actions">
        <button class="btn ghost" type="submit">Change password</button>
        <button class="btn ghost" type="reset">Clear</button>
      </div>
    </form>
  </div>
</div>
@endsection
