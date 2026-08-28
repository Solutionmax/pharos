{{-- One palette for the status page and the admin. Light is the base; dark only
     redefines tokens, so nothing can be styled into one theme and forgotten in
     the other. --}}
<style>
:root{
  --bg:#fbfcfe; --bg-tint:#f2f6fb; --card:#ffffff; --line:#e8edf4;
  --ink:#0e1726; --ink-2:#475467; --ink-3:#667085;
  --navy:#0a1729;
  --brand:{{ $branding->accent() }}; --brand-soft:#e8f3fc; --brand-ink:#ffffff;
  --green:#12b76a; --green-ink:#027a48; --green-soft:#e6f7ef;
  --amber:#f79009; --amber-ink:#b54708; --amber-soft:#fef4e6;
  --orange:#e86b1c; --orange-ink:#b34b0c; --orange-soft:#fdeee2;
  --red:#f04438; --red-ink:#b42318; --red-soft:#fee4e2;
  --blue:#2e90fa; --blue-ink:#175cd3; --blue-soft:#e8f1fd;
  --unknown:#d3dbe4;
  --tip:#0e1f34; --tip-ink:#e6edf6;
  --danger:#d92d20; --danger-ink:#ffffff;
  --radius:16px; --radius-lg:22px;
  --shadow-sm:0 1px 2px #10182808,0 1px 3px #1018280d;
  --shadow-md:0 12px 32px -12px #10182818,0 2px 6px -2px #10182808;
  --ease:cubic-bezier(.16,1,.3,1);
  --sans:'Plus Jakarta Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
  --mono:'JetBrains Mono',ui-monospace,SFMono-Regular,Menlo,monospace;
}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){
  --bg:#080e18; --bg-tint:#0b1523; --card:#0e1a2b; --line:#1c2b3f;
  --ink:#eaf0f7; --ink-2:#a7b6c7; --ink-3:#7d8fa3; --brand-soft:#0f2438; --unknown:#22303f;
  --tip:#243549; --tip-ink:#eaf0f7;
  --green:#32d583; --green-ink:#6ce9a6; --green-soft:#0d2a1e;
  --amber:#fdb022; --amber-ink:#fec84b; --amber-soft:#2c2211;
  --orange:#f38744; --orange-ink:#f9a875; --orange-soft:#2e1c11;
  --red:#f97066; --red-ink:#fda29b; --red-soft:#2d1614;
  --blue:#53b1fd; --blue-ink:#84caff; --blue-soft:#10233a;
  --shadow-md:0 12px 32px -12px #0004; --shadow-sm:0 1px 2px #00000030;
}}
:root[data-theme="dark"]{
  --bg:#080e18; --bg-tint:#0b1523; --card:#0e1a2b; --line:#1c2b3f;
  --ink:#eaf0f7; --ink-2:#a7b6c7; --ink-3:#7d8fa3; --brand-soft:#0f2438; --unknown:#22303f;
  --tip:#243549; --tip-ink:#eaf0f7;
  --green:#32d583; --green-ink:#6ce9a6; --green-soft:#0d2a1e;
  --amber:#fdb022; --amber-ink:#fec84b; --amber-soft:#2c2211;
  --orange:#f38744; --orange-ink:#f9a875; --orange-soft:#2e1c11;
  --red:#f97066; --red-ink:#fda29b; --red-soft:#2d1614;
  --blue:#53b1fd; --blue-ink:#84caff; --blue-soft:#10233a;
  --shadow-md:0 12px 32px -12px #0004; --shadow-sm:0 1px 2px #00000030;
}
</style>
