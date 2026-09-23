{{-- Side panel for "Add someone" ($member null) and "Edit access" ($member set).
     Account type as two radio cards, then one segmented choice per page. Plain
     radios underneath, so it posts the same with or without JavaScript. --}}
@php
  $adding = $member === null;
  $drawerId = $adding ? 'user-add' : 'user-edit-'.$member->id;
  $oldHere = old('_drawer') === $drawerId;
  $roleNow = $oldHere ? old('role', 'user') : ($member?->role->value ?? 'user');
  $current = $member ? $member->statusPages->pluck('pivot.role', 'id')->all() : [];
  $roleLabels = ['none' => 'None', 'viewer' => 'Read only', 'editor' => 'Editor', 'admin' => 'Page admin'];
  $isSelf = $member && $member->is(auth()->user());
@endphp
<div class="pui-modal" id="{{ $drawerId }}" data-modal role="dialog" aria-modal="true" aria-labelledby="{{ $drawerId }}-title" hidden
     @if ($oldHere && $errors->any()) data-autoopen @endif>
  <div class="pui-scrim" data-close></div>
  <form class="pui-drawer" method="POST" action="{{ $adding ? route('admin.users.store') : route('admin.users.access', $member) }}">
    @csrf
    @unless ($adding) @method('PUT') @endunless
    <input type="hidden" name="_drawer" value="{{ $drawerId }}">
    <header>
      <h2 id="{{ $drawerId }}-title">{{ $adding ? 'Add someone' : 'Edit access for '.$member->name }}</h2>
      <button type="button" class="pui-x" data-close aria-label="Close">&times;</button>
    </header>
    <div class="bd">
      @if ($oldHere && $errors->any())
        <div class="errors" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
      @endif

      @if ($adding)
        <div class="ix-row">
          <div><label class="ix-lbl" for="{{ $drawerId }}-name">Name</label>
            <input class="ix-input" id="{{ $drawerId }}-name" name="name" type="text" value="{{ $oldHere ? old('name') : '' }}" required autofocus></div>
          <div><label class="ix-lbl" for="{{ $drawerId }}-email">Email</label>
            <input class="ix-input" id="{{ $drawerId }}-email" name="email" type="email" value="{{ $oldHere ? old('email') : '' }}" required autocomplete="off"></div>
        </div>
        <div>
          <span class="ix-lbl">Signing in</span>
          <div class="ix-note">They get an email with a link to choose their own password. Nobody has to share one.</div>
          <details class="ix-more">
            <summary>Set a password yourself instead</summary>
            <div>
              <label class="ix-lbl" for="{{ $drawerId }}-password">Password</label>
              <input class="ix-input" id="{{ $drawerId }}-password" name="password" type="password" autocomplete="new-password" minlength="12">
              <label class="ix-lbl" for="{{ $drawerId }}-password2">Repeat password</label>
              <input class="ix-input" id="{{ $drawerId }}-password2" name="password_confirmation" type="password" autocomplete="new-password" minlength="12">
              <span class="sub">At least 12 characters. With a password here, no invitation is sent.</span>
            </div>
          </details>
        </div>
      @else
        <div class="pp-who">
          <span class="pp-av" style="background:{{ $avatarColor($member) }}" aria-hidden="true">{{ mb_strtoupper(mb_substr($member->name, 0, 1)) }}</span>
          <span><b>{{ $member->name }}</b><span>{{ $member->email }}</span></span>
        </div>
      @endif

      <fieldset class="pp-fieldset">
        <legend class="ix-lbl">Account type</legend>
        <div class="pp-role">
          <label><input type="radio" name="role" value="user" @checked($roleNow === 'user')><span><b>User</b><small>Works on the pages you pick below.</small></span></label>
          <label><input type="radio" name="role" value="admin" @checked($roleNow === 'admin')><span><b>Administrator</b><small>Everything, on every page, including users and settings.</small></span></label>
        </div>
        @if ($isSelf)
          <p class="sub" style="margin-top:8px">This is your own account. Making yourself a user takes the installation screens away at once.</p>
        @endif
      </fieldset>

      <fieldset class="pp-fieldset">
        <legend class="ix-lbl">Page access</legend>
        <div class="pp-matrix">
          <div class="pp-allnote">Administrators always have every page.</div>
          @forelse ($pages as $page)
            @php($value = $oldHere ? old('access.'.$page->id, 'none') : ($current[$page->id] ?? 'none'))
            <div class="pp-mrow">
              <span><b>{{ $page->name }}</b> @include('partials.page-tag', ['tagPage' => $page])</span>
              <span class="pp-seg" role="radiogroup" aria-label="Role on {{ $page->name }}">
                @foreach ($roleLabels as $roleValue => $roleLabel)
                  <label><input type="radio" name="access[{{ $page->id }}]" value="{{ $roleValue }}" @checked($value === $roleValue)><span>{{ $roleLabel }}</span></label>
                @endforeach
              </span>
            </div>
          @empty
            <p class="sub" style="padding:12px 14px">No active pages yet.</p>
          @endforelse
        </div>
        <p class="sub" style="margin-top:8px">Read only: look, never change. Editor: day to day work. Page admin: also branding, email and API tokens.</p>
      </fieldset>

      @if ($adding)
        <label class="check"><input type="checkbox" name="require_two_factor" value="1" @checked($oldHere ? old('require_two_factor') : true)> Ask them to turn on two factor at their first sign in</label>
      @endif
    </div>
    <footer>
      <button type="button" class="btn ghost" data-close>Cancel</button>
      <button type="submit" class="btn">{{ $adding ? 'Add and invite' : 'Save access' }}</button>
    </footer>
  </form>
</div>
