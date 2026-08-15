<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Console\Command;

class InviteUser extends Command
{
    protected $signature = 'app:invite {email} {--name=} {--days=7}';

    protected $description = 'Print a one-time link that lets somebody create their own account';

    /**
     * The other way to make an account, and the one that does not put a
     * password through anybody's hands.
     *
     * `app:create-user` asks the owner to think one up, say it out loud
     * over some chat, and hope it is changed later. There is no page to
     * change it on, so it never is. This hands over a link instead: the
     * person at the other end sets their own password, the owner never
     * learns it, and the link dies the moment it is used.
     */
    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $days = max(1, (int) $this->option('days'));

        if (User::query()->where('email', $email)->exists()) {
            // Not a password reset. Saying so plainly beats issuing a
            // link that would fail on redemption for a reason the
            // holder cannot see.
            $this->components->error("An account for {$email} already exists. Reset its password with app:create-user.");

            return self::FAILURE;
        }

        [$invitation, $token] = Invitation::issue($email, $this->option('name') ?: null, $days);

        $this->components->info("Invitation for {$email}, good until ".$invitation->expires_at->toDayDateTimeString().'.');
        $this->newLine();
        $this->line(route('invite.show', ['token' => $token]));
        $this->newLine();
        $this->components->warn('Send this link and nothing else: it is the whole credential, and it is shown here once.');

        return self::SUCCESS;
    }
}
