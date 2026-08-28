{{-- One list for the wizard and the settings page: every zone PHP knows, UTC first. --}}
<select id="timezone" name="timezone">
  @foreach (\App\Services\Clock::zones() as $region => $zones)
    <optgroup label="{{ $region }}">
      @foreach ($zones as $zone)
        <option value="{{ $zone }}" @selected($zone === $selected)>{{ $zone }}</option>
      @endforeach
    </optgroup>
  @endforeach
</select>
