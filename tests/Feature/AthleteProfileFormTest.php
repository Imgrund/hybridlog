<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AthleteProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The profile page after the focus cut: the interface language is the
 * one thing it asks for. The form saves, it refuses languages the
 * interface is not translated into, an empty answer really clears the
 * column (back to the browser's Accept-Language), and the whole page
 * stays behind the login.
 */
class AthleteProfileFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_form_shows_what_is_stored(): void
    {
        $user = User::factory()->create();
        AthleteProfile::for($user)->update(['locale' => 'de']);

        // No assertSee on the section title: storing "de" renders the
        // whole page in German, so the markup is asserted instead.
        $html = $this->actingAs($user)
            ->get('/profile')
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('name="locale"', $html);
        // The stored answer comes back selected, and only it: three
        // radios sharing a name must arrive with exactly one mark.
        $this->assertMatchesRegularExpression('/value="de"\s+checked/', $html);
        $this->assertSame(1, substr_count($html, 'checked'));
    }

    public function test_the_cohort_questions_are_gone(): void
    {
        // Sex and birth year fed the cohort comparison, which left with
        // the focus cut; a page still asking for them would be collecting
        // answers nothing reads.
        $this->actingAs(User::factory()->create())
            ->get('/profile')
            ->assertStatus(200)
            ->assertDontSee('Year of birth')
            ->assertDontSee('name="sex"', false);
    }

    public function test_saving_the_language_persists_it(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/profile/language', ['locale' => 'de'])
            ->assertRedirect('/profile')
            ->assertSessionHas('locale_saved');

        $this->assertSame('de', AthleteProfile::for($user)->fresh()->locale);
    }

    public function test_no_answer_clears_the_column_instead_of_keeping_the_old_one(): void
    {
        $user = User::factory()->create();
        AthleteProfile::for($user)->update(['locale' => 'de']);

        // What the "follow my browser" radio sends.
        $this->actingAs($user)
            ->post('/profile/language', ['locale' => ''])
            ->assertRedirect('/profile');

        $this->assertNull(AthleteProfile::for($user)->fresh()->locale);
    }

    public function test_an_untranslated_language_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/profile/language', ['locale' => 'fr'])
            ->assertSessionHasErrors('locale');

        $this->assertNull(AthleteProfile::for($user)->fresh()->locale);
    }

    public function test_the_profile_stays_behind_the_login(): void
    {
        $this->get('/profile')->assertRedirect('/login');
        $this->post('/profile/language', ['locale' => 'de'])->assertRedirect('/login');
    }
}
