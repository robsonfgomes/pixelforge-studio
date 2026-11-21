<?php

declare(strict_types=1);

namespace Tests\Feature\Database\Seeders;

use App\Models\User;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class DemoUserSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function itCreatesTheDemoAdminWithTheConfiguredPassword(): void
    {
        config(['demo.password' => 'first-demo-password']);

        $this->seed(DemoUserSeeder::class);

        $user = User::query()->where('email', 'demo@example.com')->sole();
        $this->assertTrue(Hash::check('first-demo-password', $user->password));
        $this->assertDatabaseHas('users', ['email' => 'demo@example.com', 'is_admin' => true]);
    }

    #[Test]
    public function itChangesNothingWhenItRunsAgain(): void
    {
        config(['demo.password' => 'first-demo-password']);
        $this->seed(DemoUserSeeder::class);
        config(['demo.password' => 'another-password']);

        $this->seed(DemoUserSeeder::class);

        $this->assertSame(1, User::query()->where('email', 'demo@example.com')->count());
        $user = User::query()->where('email', 'demo@example.com')->sole();
        $this->assertTrue(Hash::check('first-demo-password', $user->password));
    }

    #[Test]
    public function itMakesAnExistingDemoUserAnAdminWithoutTouchingItsPassword(): void
    {
        config(['demo.password' => 'another-password']);
        $existing = User::factory()->create(['email' => 'demo@example.com', 'password' => 'kept-password']);

        $this->seed(DemoUserSeeder::class);

        $this->assertDatabaseHas('users', ['id' => $existing->id, 'is_admin' => true]);
        $this->assertTrue(Hash::check('kept-password', $existing->refresh()->password));
    }

    #[Test]
    public function itKeepsRunningWithoutAPasswordOnceTheDemoUserExists(): void
    {
        config(['demo.password' => 'first-demo-password']);
        $this->seed(DemoUserSeeder::class);
        config(['demo.password' => null]);

        $this->seed(DemoUserSeeder::class);

        $this->assertSame(1, User::query()->where('email', 'demo@example.com')->count());
    }

    #[Test]
    public function itRefusesToCreateTheDemoUserWithoutAPassword(): void
    {
        config(['demo.password' => null]);

        $this->assertThrows(fn () => $this->seed(DemoUserSeeder::class), RuntimeException::class);

        $this->assertDatabaseMissing('users', ['email' => 'demo@example.com']);
    }
}
