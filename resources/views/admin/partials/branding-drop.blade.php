{{-- One upload on the Branding screen: current image, a drop target, replace and
     remove. The file input stays a real, focusable input so the form works
     without JavaScript; pharos-branding.js adds dropping, checks and previews.
     Expects: $field, $label, $tone (light|dark|icon), $say, $current, $remove,
     $hint, $accept, and optionally $fallback (the built in image shown when none is set). --}}
@php($shown = $current ?? ($fallback ?? null))
<div class="bx-drop {{ $tone }}" data-drop="{{ $field }}" data-fallback="{{ $fallback ?? '' }}" data-has="{{ $current ? '1' : '0' }}" @error($field) data-invalid @enderror>
  <div class="bx-drop-art" aria-hidden="true">
    <img data-drop-img src="{{ $shown ?? '' }}" alt="" @unless ($shown) hidden @endunless>
    <span class="bx-drop-empty" @if ($shown) hidden @endif>No logo</span>
  </div>
  <div class="bx-drop-bd">
    <div class="bx-drop-hd">
      <label for="{{ $field }}" class="bx-drop-title">{{ $label }}</label>
      <span class="bx-src {{ $current ? 'set' : '' }}" data-drop-src>{{ $current ? 'Set for this page' : 'Pharos default' }}</span>
    </div>
    <p class="bx-drop-say">{{ $say }} <span class="bx-drop-dnd">Drop a file here or choose one.</span></p>
    <p class="help" id="{{ $field }}-help">{{ $hint }}</p>
    <p class="bx-err" data-drop-err role="alert" hidden></p>
    <div class="bx-drop-act">
      <label for="{{ $field }}" class="btn ghost bx-pick">{{ $current ? 'Replace' : 'Choose file' }}</label>
      <button type="button" class="linkbtn" data-drop-undo hidden>Keep the current one</button>
      @if ($current)
        <label class="check bx-remove"><input type="checkbox" name="{{ $remove }}" value="1" data-drop-remove> Remove on save</label>
      @endif
    </div>
  </div>
  <input class="bx-file" id="{{ $field }}" name="{{ $field }}" type="file" accept="{{ $accept }}" aria-describedby="{{ $field }}-help">
</div>
