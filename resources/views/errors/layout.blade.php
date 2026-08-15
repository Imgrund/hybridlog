<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
{{-- The shell every error page wears.

     Without these views Laravel serves its own, which is white type on
     white. In an app that is graphite everywhere else that reads as a
     different site, or as the site having fallen over harder than it
     has: a mistyped address should not look like an outage.

     Deliberately thin. An error page runs at the moment least worth
     trusting, so it reads no database, asks after no session and names
     no athlete. What it needs is the stylesheet and a way back.

     Each code passes its three lines in (resources/views/errors/404 and
     its neighbours), because the difference between them is wording,
     never layout. --}}
<head>
    @include('partials.head', ['title' => $code])
</head>
<body class="viz-root min-h-screen antialiased">
    <main class="mx-auto flex min-h-screen w-full max-w-xl flex-col justify-center px-4 py-10 sm:px-6">
        <span class="brand-lockup">
            <span class="brand-slash" aria-hidden="true"></span>
            <span>hybridlog</span>
        </span>

        <p class="guide-eyebrow mt-8">{{ $code }}</p>
        <h1 class="guide-answer">{{ $headline }}</h1>
        <p class="guide-lede">{{ $body }}</p>

        <div>
            {{-- Ghost rather than bare: a plain .btn carries no surface
                 in this system, which is right in a toolbar and wrong
                 here, where this is the only way out of a dead end and
                 has nothing around it to be read against. Not primary
                 either: the accent is for something somebody came to
                 do, and nobody came here. --}}
            <a href="{{ route('dashboard') }}" class="btn btn-ghost btn-sm mt-6">
                {{ __('Back to the dashboard') }}
            </a>
        </div>
    </main>
</body>
</html>
