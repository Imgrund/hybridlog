<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => __('Confirm access')])
</head>
<body class="viz-root min-h-screen antialiased">
    <main class="mx-auto flex min-h-screen max-w-md items-center px-4">
        <div class="card w-full">
            <h1 class="text-lg font-bold tracking-tight">{{ __('Confirm access') }}</h1>
            <p class="mt-2 text-sm text-secondary leading-relaxed">
                {!! __('<b>:client</b> wants to connect to your hybridlog.', [
                    'client' => '<b class="text-primary">'.e($client->name).'</b>',
                ]) !!}
            </p>

            @if (count($scopes) > 0)
                <p class="card-title mt-4">{{ __('Permissions') }}</p>
                <ul class="mt-1 list-disc pl-5 text-sm text-secondary">
                    @foreach ($scopes as $scope)
                        <li>{{ $scope->description }}</li>
                    @endforeach
                </ul>
            @endif

            <div class="mt-5 flex gap-2">
                <form method="POST" action="{{ route('passport.authorizations.approve') }}" class="flex-1">
                    @csrf
                    <input type="hidden" name="state" value="{{ $request->state }}">
                    <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <button type="submit" class="btn btn-primary w-full">{{ __('Allow') }}</button>
                </form>
                <form method="POST" action="{{ route('passport.authorizations.deny') }}" class="flex-1">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="state" value="{{ $request->state }}">
                    <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <button type="submit" class="btn btn-ghost w-full">{{ __('Deny') }}</button>
                </form>
            </div>

            <p class="stat-ref mt-4">{{ __('Signed in as :email · access can be revoked at any time.', ['email' => auth()->user()->email]) }}</p>
        </div>
    </main>
</body>
</html>
