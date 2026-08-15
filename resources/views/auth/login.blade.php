<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => __('Sign in')])
</head>
<body class="viz-root min-h-screen antialiased">
    <main class="mx-auto flex min-h-screen max-w-sm items-center px-4">
        <form method="POST" action="{{ route('login.attempt') }}" class="card w-full">
            @csrf
            <h1 class="text-lg font-bold tracking-tight">hybridlog</h1>
            <p class="mt-1 text-sm text-secondary">{{ __('Sign in to see your data.') }}</p>

            @error('email')
                <p class="pill mt-3" data-status="serious">{{ $message }}</p>
            @enderror

            {{-- The invitation, and only where there is one to make.
                 $demoAccount is null on every installation that is not
                 the public demo (App\Http\Controllers\AuthController says
                 why the credentials are printable at all), so a normal
                 sign-in page renders neither this block nor a value in
                 the fields below: there is nothing here to render.

                 Above the fields rather than under the button, because
                 it is the answer to the question a visitor arrives with.
                 The values stand in type as well as in the fields: the
                 password field shows dots, and a visitor who wants to
                 know what they are signing in as should not have to
                 guess or view source. --}}
            @if ($demoAccount)
                <div class="mt-4">
                    <span class="pill" data-status="neutral">{{ __('Public demo') }}</span>
                    <p class="mt-2 text-sm text-secondary leading-relaxed">
                        {{ __('This installation is a public demo: everybody signs in to the same account, and it is filled in below already.') }}
                    </p>
                    <pre class="mt-2 overflow-x-auto rounded-md bg-black/20 p-3 text-xs text-primary">{{ $demoAccount['email'] }}
{{ $demoAccount['password'] }}</pre>
                </div>
            @endif

            <label class="mt-4 block text-xs text-muted" for="email">{{ __('Email') }}</label>
            <input id="email" name="email" type="email" value="{{ old('email', $demoAccount['email'] ?? '') }}" required autofocus class="field mt-1">

            <label class="mt-3 block text-xs text-muted" for="password">{{ __('Password') }}</label>
            {{-- The attribute itself is conditional, not its content: a
                 normal installation carries no empty value="" here that a
                 later edit could quietly start filling. --}}
            <input id="password" name="password" type="password" required class="field mt-1" @if ($demoAccount) value="{{ $demoAccount['password'] }}" @endif>

            <button type="submit" class="btn btn-primary mt-5 w-full">{{ __('Sign in') }}</button>
        </form>
    </main>
</body>
</html>
