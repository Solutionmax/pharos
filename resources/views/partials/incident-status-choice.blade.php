<fieldset class="status-choice">
  <legend>{{ $legend ?? 'New status' }}</legend>
  <div class="status-options">
    @foreach (\App\Enums\IncidentStatus::cases() as $case)
      <label class="status-option status-{{ $case->value }}">
        <input type="radio" name="status" value="{{ $case->value }}" @checked((int) old('status', $selectedStatus ?? 1) === $case->value) required>
        <span><i aria-hidden="true"></i>{{ $case->label() }}</span>
      </label>
    @endforeach
  </div>
</fieldset>
