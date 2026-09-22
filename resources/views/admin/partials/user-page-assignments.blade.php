<fieldset style="border:1px solid var(--line);border-radius:12px;padding:16px;min-width:0">
  <legend style="padding:0 6px;font-weight:600">Assigned status pages</legend>
  <p class="help" style="margin-bottom:12px">Users can manage only the selected pages. No selection means no page access. Administrators always have access to all pages.</p>
  <div style="display:grid;gap:10px;max-height:260px;overflow-y:auto">
    @forelse ($pages as $page)
      <label class="check" style="display:flex;gap:10px;align-items:center">
        <input type="checkbox" name="status_page_ids[]" value="{{ $page->id }}" @checked(in_array($page->id, old('status_page_ids', $selectedPageIds ?? [])))>
        <span>{{ $page->name }} @include('partials.page-tag', ['tagPage' => $page])</span>
      </label>
    @empty
      <p class="help">No active pages available.</p>
    @endforelse
  </div>
</fieldset>
