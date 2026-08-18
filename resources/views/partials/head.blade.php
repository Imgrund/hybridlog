{{-- Everything every page needs in its head, in one place. It lived copied
     across six views before, and had drifted: two of them carried no icon
     and no manifest at all, so the same app was installable from one page
     and not from the next.

     Pass a $title for anything but the dashboard; it gets the suffix.

     The .ico is pinned to a size so a browser that understands SVG picks
     the SVG instead, which is the one that stays sharp at any scale. The
     .ico still carries 16 through 256 for everything else, including the
     bookmark bars that never ask. --}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ isset($title) ? $title.' · hybridlog' : 'hybridlog' }}</title>
<link rel="icon" href="/favicon.ico" sizes="32x32">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#0b0c0f">
@vite(['resources/css/app.css', 'resources/js/app.js'])
{{-- The only script here that is not this app's own, and only where an
     operator asked for it by filling both lines (see config/analytics.php).
     Unset is the default and means this tag is never written: no request
     leaves for anywhere but this installation. Both are required together,
     because a tracker without a site to report to is a download and a
     404. --}}
@if (config('analytics.umami.script_url') && config('analytics.umami.website_id'))
    <script defer src="{{ config('analytics.umami.script_url') }}" data-website-id="{{ config('analytics.umami.website_id') }}"></script>
@endif
