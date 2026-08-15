<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
{{-- The one page that makes an account, and it only exists for
     somebody already holding a link the owner sent them.

     No email field: the address was decided when the invitation was
     issued, and letting it be typed here would turn a permission for
     one person into a sign-up for anybody. It is shown, not asked. --}}
<head>
    @include('partials.head', ['title' => __('Set your password')])
</head>
<body class="viz-root min-h-screen antialiased">
    <main class="mx-auto flex min-h-screen max-w-sm items-center px-4">
        <form method="POST" action="{{ route('invite.accept', ['token' => $token]) }}" class="card w-full">
            @csrf
            <h1 class="text-lg font-bold tracking-tight">hybridlog</h1>
            <p class="mt-1 text-sm text-secondary">{{ __('Choose a password and the account is yours.') }}</p>

            @error('password')
                <p class="pill mt-3" data-status="serious">{{ $message }}</p>
            @enderror

            <label class="mt-4 block text-xs text-muted" for="email">{{ __('Email') }}</label>
            {{-- Disabled rather than hidden: the reader should see which
                 address this account will be, and nothing they type here
                 would be read anyway. --}}
            <input id="email" type="email" value="{{ $invitation->email }}" disabled class="field mt-1">

            <label class="mt-3 block text-xs text-muted" for="password">{{ __('Password') }}</label>
            <input id="password" name="password" type="password" required autofocus autocomplete="new-password" class="field mt-1">
            <p class="mt-1 text-xs text-muted">{{ __('At least ten characters.') }}</p>

            <label class="mt-3 block text-xs text-muted" for="password_confirmation">{{ __('Password again') }}</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="field mt-1">

            <button type="submit" class="btn btn-primary mt-5 w-full">{{ __('Create the account') }}</button>

            <p class="mt-4 text-xs text-muted leading-relaxed">
                {{ __('Nobody else learns this password, and there is no page to change it later: keep it somewhere.') }}
            </p>
        </form>
    </main>
</body>
</html>
