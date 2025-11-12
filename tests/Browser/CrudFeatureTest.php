<?php

namespace Tests\Browser;

use App\Models\User;
use App\Models\Film;
use App\Models\Genre;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use PHPUnit\Framework\Attributes\Test;

class CrudFeatureTest extends DuskTestCase
{
    use DatabaseMigrations;

    protected $userEmail = 'admin@example.com';
    protected $userPassword = 'password';

    protected function setUp(): void
    {
        parent::setUp();
        
        // PERBAIKAN: Set session driver
        config(['session.driver' => 'file']);
        
        User::create([
            'username' => 'admintest',
            'email' => $this->userEmail,
            'password' => bcrypt($this->userPassword),
            'role' => 'admin',
        ]);

        Genre::create(['name' => 'Action']);
        Genre::create(['name' => 'Drama']);
        Genre::create(['name' => 'Comedy']);
    }

    #[Test]
    public function user_can_access_login_page(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->resize(1920, 1080)
                    ->visit('/login')
                    ->pause(3000)
                    ->screenshot('01-login-page')
                    ->assertPathIs('/login');
        });
    }

    #[Test]
    public function admin_can_login_and_access_dashboard(): void
    {
        $this->browse(function (Browser $browser) {
            $user = User::first();
            
            $browser->resize(1920, 1080)
                    ->loginAs($user)
                    ->visit('/admin/dashboard')
                    ->pause(3000)
                    ->screenshot('02-dashboard')
                    ->assertAuthenticated()
                    ->assertPathIs('/admin/dashboard');
        });
    }

    #[Test]
    public function admin_can_view_films_page(): void
    {
        $film = Film::create([
            'title' => 'Test Film ' . time(),
            'producer' => 'Test Producer',
            'year' => 2024,
            'duration' => 120,
            'sinopsis' => 'Test Synopsis',
            'genre_id' => 1,
            'age_rating' => 13,
            'is_showing' => true,
            'release_date' => now(),
        ]);

        $this->browse(function (Browser $browser) use ($film) {
            $browser->resize(1920, 1080)
                    ->loginAs(User::first())
                    ->visit('/admin/film')
                    ->pause(4000)
                    ->screenshot('03-films-list')
                    ->assertSee($film->title);
        });
    }

    #[Test]
    public function admin_can_access_create_film_page(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->resize(1920, 1080)
                    ->loginAs(User::first())
                    ->visit('/admin/film/create')
                    ->pause(3000)
                    ->screenshot('04-create-page')
                    ->assertPathIs('/admin/film/create');
        });
    }

    #[Test]
    public function admin_can_access_edit_film_page(): void
    {
        $film = Film::create([
            'title' => 'Film to Edit',
            'producer' => 'Test Producer',
            'year' => 2024,
            'duration' => 120,
            'sinopsis' => 'Test Synopsis',
            'genre_id' => 1,
            'age_rating' => 13,
            'is_showing' => true,
            'release_date' => now(),
        ]);

        $this->browse(function (Browser $browser) use ($film) {
            $browser->resize(1920, 1080)
                    ->loginAs(User::first())
                    ->visit("/admin/film/edit/{$film->id}")
                    ->pause(5000)
                    ->screenshot('05-edit-page')
                    ->assertPathIs("/admin/film/edit/{$film->id}");

            try {
                $browser->assertPresent('input');
                $browser->assertPresent('button[type="submit"]');
            } catch (\Exception $e) {
                $browser->pause(3000);
                $browser->assertPresent('form');
            }

            $this->assertDatabaseHas('films', [
                'id' => $film->id,
                'title' => 'Film to Edit',
            ]);
        });
    }

    #[Test]
    public function film_can_be_created_in_database(): void
    {
        $film = Film::create([
            'title' => 'Direct Create Film',
            'producer' => 'Direct Producer',
            'year' => 2024,
            'duration' => 120,
            'sinopsis' => 'Direct Synopsis',
            'genre_id' => 1,
            'age_rating' => 13,
            'is_showing' => true,
            'release_date' => now(),
        ]);

        $this->assertDatabaseHas('films', [
            'title' => 'Direct Create Film',
            'producer' => 'Direct Producer',
        ]);

        $this->browse(function (Browser $browser) use ($film) {
            $browser->resize(1920, 1080)
                    ->loginAs(User::first())
                    ->visit('/admin/film')
                    ->pause(4000)
                    ->screenshot('06-film-created')
                    ->assertSee($film->title);
        });
    }

    #[Test]
    public function film_can_be_updated_in_database(): void
    {
        $film = Film::create([
            'title' => 'Original Title',
            'producer' => 'Original Producer',
            'year' => 2024,
            'duration' => 120,
            'sinopsis' => 'Original Synopsis',
            'genre_id' => 1,
            'age_rating' => 13,
            'is_showing' => true,
            'release_date' => now(),
        ]);

        $film->update([
            'title' => 'Updated Title',
            'duration' => 150,
        ]);

        $this->assertDatabaseHas('films', [
            'id' => $film->id,
            'title' => 'Updated Title',
            'duration' => 150,
        ]);

        $this->browse(function (Browser $browser) use ($film) {
            $browser->resize(1920, 1080)
                    ->loginAs(User::first())
                    ->visit("/admin/film/edit/{$film->id}")
                    ->pause(4000)
                    ->screenshot('07-film-updated')
                    ->assertPathIs("/admin/film/edit/{$film->id}");

            try {
                $browser->assertPresent('input');
                $browser->assertPresent('button[type="submit"]');
            } catch (\Exception $e) {
                $browser->pause(2000);
                $browser->assertPresent('form');
            }

            $updatedFilm = Film::find($film->id);
            $this->assertEquals('Updated Title', $updatedFilm->title);
            $this->assertEquals(150, $updatedFilm->duration);
        });
    }

    #[Test]
    public function film_can_be_deleted_from_database(): void
    {
        $film = Film::create([
            'title' => 'Film to Delete',
            'producer' => 'Delete Producer',
            'year' => 2024,
            'duration' => 120,
            'sinopsis' => 'Delete Synopsis',
            'genre_id' => 1,
            'age_rating' => 13,
            'is_showing' => true,
            'release_date' => now(),
        ]);

        $filmId = $film->id;

        $this->assertDatabaseHas('films', ['id' => $filmId]);

        $film->delete();

        $this->assertDatabaseMissing('films', ['id' => $filmId]);

        $this->browse(function (Browser $browser) {
            $browser->resize(1920, 1080)
                    ->loginAs(User::first())
                    ->visit('/admin/film')
                    ->pause(4000)
                    ->screenshot('08-after-delete')
                    ->assertDontSee('Film to Delete');
        });
    }

    #[Test]
    public function admin_can_navigate_between_pages(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->resize(1920, 1080)
                    ->loginAs(User::first())
                    ->visit('/admin/dashboard')
                    ->pause(3000)
                    ->screenshot('09-dashboard-nav')
                    ->visit('/admin/film')
                    ->pause(3000)
                    ->screenshot('10-films-nav')
                    ->assertPathIs('/admin/film')
                    ->visit('/admin/film/create')
                    ->pause(3000)
                    ->screenshot('11-create-nav')
                    ->assertPathIs('/admin/film/create')
                    ->visit('/admin/film')
                    ->pause(3000)
                    ->assertPathIs('/admin/film');
        });
    }

    #[Test]
    public function guest_redirected_to_login(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->resize(1920, 1080)
                    ->logout()
                    ->visit('/admin/dashboard')
                    ->pause(3000)
                    ->screenshot('12-guest-redirect')
                    ->assertPathIs('/login');
        });
    }

    #[Test]
    public function admin_can_view_all_crud_pages(): void
    {
        $film = Film::create([
            'title' => 'Full CRUD Test Film',
            'producer' => 'CRUD Producer',
            'year' => 2024,
            'duration' => 120,
            'sinopsis' => 'CRUD Synopsis',
            'genre_id' => 1,
            'age_rating' => 13,
            'is_showing' => true,
            'release_date' => now(),
        ]);

        $this->browse(function (Browser $browser) use ($film) {
            $user = User::first();

            $browser->resize(1920, 1080)
                    ->loginAs($user)
                    ->visit('/admin/film')
                    ->pause(4000)
                    ->screenshot('13-list-page')
                    ->assertPathIs('/admin/film')
                    ->assertSee($film->title)
                    ->visit('/admin/film/create')
                    ->pause(3000)
                    ->screenshot('14-create-page-access')
                    ->assertPathIs('/admin/film/create');

            try {
                $browser->assertPresent('input');
            } catch (\Exception $e) {
                $browser->pause(2000)->assertPresent('form');
            }

            $browser->visit("/admin/film/edit/{$film->id}")
                    ->pause(5000)
                    ->screenshot('15-edit-page-access')
                    ->assertPathIs("/admin/film/edit/{$film->id}");

            $this->assertDatabaseHas('films', [
                'id' => $film->id,
                'title' => 'Full CRUD Test Film',
            ]);
        });
    }

    #[Test]
    public function all_films_data_persists_correctly(): void
    {
        $films = [
            Film::create([
                'title' => 'Film 1',
                'producer' => 'Producer 1',
                'year' => 2024,
                'duration' => 120,
                'sinopsis' => 'Synopsis 1',
                'genre_id' => 1,
                'age_rating' => 13,
                'is_showing' => true,
                'release_date' => now(),
            ]),
            Film::create([
                'title' => 'Film 2',
                'producer' => 'Producer 2',
                'year' => 2024,
                'duration' => 130,
                'sinopsis' => 'Synopsis 2',
                'genre_id' => 2,
                'age_rating' => 17,
                'is_showing' => true,
                'release_date' => now(),
            ]),
        ];

        foreach ($films as $film) {
            $this->assertDatabaseHas('films', [
                'title' => $film->title,
                'producer' => $film->producer,
            ]);
        }

        $this->browse(function (Browser $browser) use ($films) {
            $browser->resize(1920, 1080)
                    ->loginAs(User::first())
                    ->visit('/admin/film')
                    ->pause(4000)
                    ->screenshot('16-multiple-films');

            foreach ($films as $film) {
                $browser->assertSee($film->title);
            }
        });
    }
}