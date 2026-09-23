{{-- One list for the wizard, the settings page and the profile: every zone PHP knows, UTC first.
     $default, when given, adds a first option with an empty value that follows the installation. --}}
<select id="{{ $id ?? 'timezone' }}" name="timezone" @isset($describedBy) aria-describedby="{{ $describedBy }}" @endisset>
  @isset($default)
    <option value="" @selected($selected === null || $selected === '')>Installation default ({{ $default }})</option>
  @endisset
  @foreach (\App\Services\Clock::zones() as $region => $zones)
    <optgroup label="{{ $region }}">
      @foreach ($zones as $zone)
        <option value="{{ $zone }}" @selected($zone === $selected)>{{ $zone }}</option>
      @endforeach
    </optgroup>
  @endforeach
</select>
