@php
    $monthDate = \Carbon\CarbonImmutable::createFromFormat('!Y-m', $report['month'], 'UTC');
    $latestMonth = now('UTC')->format('Y-m');
@endphp
<div class="report-field" data-month-field>
  <label id="report-month-field-label" for="report-month">{{ __('Month') }}</label>
  <input id="report-month" type="month" name="month" value="{{ $report['month'] }}" min="2000-01" max="{{ $latestMonth }}" required>
  <details class="month-picker" data-month-picker hidden>
    <summary aria-labelledby="report-month-field-label report-month-selected" aria-controls="report-month-popover">
      <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 3v4M17 3v4M3 11h18"/></svg>
      <span id="report-month-selected" data-month-label>{{ $monthDate->translatedFormat('F Y') }}</span>
    </summary>
    <div class="month-popover" id="report-month-popover">
      <div class="month-years">
        <button type="button" data-year-step="-1" aria-label="{{ __('Previous year') }}">←</button>
        <select data-month-year aria-label="{{ __('Year') }}">
          @for ($year = (int) substr($latestMonth, 0, 4); $year >= 2000; $year--)
            <option value="{{ $year }}" @selected($year === $monthDate->year)>{{ $year }}</option>
          @endfor
        </select>
        <button type="button" data-year-step="1" aria-label="{{ __('Next year') }}">→</button>
      </div>
      <div class="month-grid" role="group" aria-label="{{ __('Month') }}">
        @for ($monthNumber = 1; $monthNumber <= 12; $monthNumber++)
          @php $monthName = $monthDate->setMonth($monthNumber)->translatedFormat('F'); @endphp
          <button type="button" data-month="{{ str_pad((string) $monthNumber, 2, '0', STR_PAD_LEFT) }}" data-month-name="{{ $monthName }}" aria-pressed="false">{{ $monthDate->setMonth($monthNumber)->translatedFormat('M') }}</button>
        @endfor
      </div>
    </div>
  </details>
</div>
