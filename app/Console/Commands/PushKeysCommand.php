<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Push\Vapid;
use Illuminate\Console\Command;

/**
 * Prints a fresh VAPID key pair as the two environment lines it belongs in.
 *
 * Printed rather than written: the .env is the operator's file, and on a
 * hosted deployment it is not a file at all but a panel of variables. What
 * this command owes them is the pair and the warning that comes with it.
 */
class PushKeysCommand extends Command
{
    protected $signature = 'push:keys';

    protected $description = 'Generate a VAPID key pair for push notifications';

    public function handle(): int
    {
        $keys = Vapid::generate();

        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$keys['public']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['private']);
        $this->newLine();

        $this->components->info('Add both to the environment, then restart. Set VAPID_SUBJECT to an address a push service can reach you at.');

        // Said plainly because it is not obvious: the browser stores the
        // public key with the subscription, and a new pair makes every
        // device that already agreed unreachable until it agrees again.
        $this->components->warn('Keep the pair. Replacing it silently breaks every device that has already allowed notifications.');

        return self::SUCCESS;
    }
}
