{{--
  Not found. One answer for every missing address: a page that was deleted,
  archived, never published or never existed reads the same, so the 404 gives
  nothing away about what was once there.

  The wall is the relief drawn by assets/not-found/scene.js. Without WebGL the
  flat wall of errors/shell is the whole picture.
--}}
@include('errors.shell', [
  'code' => 404,
  'title' => __('Page not found'),
  'heading' => __('This page does not exist'),
  'lede' => request()->is('admin', 'admin/*') && rescue(fn () => auth()->check(), false, false)
      ? __('This part of the admin is gone. The status page it belonged to may have been deleted.')
      : __('The address may be mistyped, or the page was moved or removed.'),
  'caption' => [__('Every check ran.'), __('Nothing answered.')],
  'scene' => true,
])
