<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * app:create-user is the only way into the dashboard: there is no sign-up
 * page. That makes it two commands in one, and the second one is the reason
 * these tests exist. Running it again for an address that already has an
 * account resets that account's password, and a password reset must not
 * quietly rename the account it unlocks.
 */
class CreateUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_account_and_names_it_after_the_address(): void
    {
        $this->artisan('app:create-user', ['email' => 'athlete@example.com'])
            ->expectsQuestion('Password', 'correct-horse-battery')
            ->assertSuccessful();

        $user = User::whereEmail('athlete@example.com')->sole();

        $this->assertSame('athlete', $user->name);
        $this->assertTrue(Hash::check('correct-horse-battery', $user->password));
    }

    public function test_a_rerun_resets_the_password_without_renaming_the_account(): void
    {
        $this->artisan('app:create-user', ['email' => 'athlete@example.com', '--name' => 'Alex'])
            ->expectsQuestion('Password', 'the-first-password')
            ->assertSuccessful();

        $this->artisan('app:create-user', ['email' => 'athlete@example.com'])
            ->expectsQuestion('Password', 'the-second-password')
            ->assertSuccessful();

        $user = User::whereEmail('athlete@example.com')->sole();

        $this->assertSame('Alex', $user->name);
        $this->assertTrue(Hash::check('the-second-password', $user->password));
    }

    public function test_the_name_option_still_renames_when_it_is_given(): void
    {
        $this->artisan('app:create-user', ['email' => 'athlete@example.com', '--name' => 'Alex'])
            ->expectsQuestion('Password', 'the-first-password')
            ->assertSuccessful();

        $this->artisan('app:create-user', ['email' => 'athlete@example.com', '--name' => 'Alexandra'])
            ->expectsQuestion('Password', 'the-second-password')
            ->assertSuccessful();

        $this->assertSame('Alexandra', User::whereEmail('athlete@example.com')->sole()->name);
    }

    public function test_it_refuses_a_short_password_and_creates_nothing(): void
    {
        $this->artisan('app:create-user', ['email' => 'athlete@example.com'])
            ->expectsQuestion('Password', 'too-short')
            ->assertFailed();

        $this->assertNull(User::whereEmail('athlete@example.com')->first());
    }
}
