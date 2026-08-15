<?php

namespace App\Console\Commands;

use App\Garmin\Mirror;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

class CreateUser extends Command
{
    protected $signature = 'app:create-user {email} {--name=} {--admin}';

    protected $description = 'Create (or reset the password of) a dashboard user; --admin marks the installation owner';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $password = (string) $this->secret('Password');

        if (strlen($password) < 10) {
            $this->components->error('Password must have at least 10 characters.');

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);
        $created = ! $user->exists;

        // Running this again is the documented way to reset a forgotten
        // password, and a reset must not rename the account it unlocks. So the
        // name is only written when it was asked for, or when there is no
        // account yet to take one from.
        $name = (string) $this->option('name');
        if ($name !== '' || $created) {
            $user->name = $name !== '' ? $name : Str::before($email, '@');
        }

        // Only ever granted here, never withdrawn: a password reset run
        // without the flag must not quietly demote the owner.
        if ($this->option('admin')) {
            $user->is_admin = true;
        }

        $user->password = Hash::make($password);
        $user->save();

        $this->components->info("User {$user->email} ".($created ? 'created' : 'updated').'.');

        if ($created) {
            $this->provisionTheirMirror($user);
        }

        return self::SUCCESS;
    }

    /**
     * Give the new athlete their schema and their reader role now, rather
     * than on their first page load.
     *
     * The application provisions lazily and would get there by itself, so
     * this is about where the failure shows up. Creating a schema and a
     * role needs privileges the connecting user may not have (an
     * installation running the full role split deliberately withholds
     * them), and that is worth learning here, at a terminal, with the
     * remedy in the message, rather than as a five-hundred on somebody
     * else's first visit.
     *
     * Not fatal. The account exists and can sign in; what it may not yet
     * have is anywhere to keep watch data.
     */
    private function provisionTheirMirror(User $user): void
    {
        try {
            Mirror::ensure($user->id);
            $this->components->info('Mirror '.Mirror::schema($user->id).' is ready.');
        } catch (Throwable $exception) {
            $this->components->warn(
                'The account exists, but its mirror could not be created: '.$exception->getMessage()
            );
        }
    }
}
