<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Creates the demo admin once. It uses no factory (Faker isn't installed in production) and is safe to run again:
 * an existing demo user keeps its password and only makes sure it is an admin.
 */
class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('demo.email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $user = $this->createDemoUser($email);
        }

        $user->forceFill(['is_admin' => true])->save();
    }

    private function createDemoUser(string $email): User
    {
        $password = config('demo.password');

        if (! is_string($password) || $password === '') {
            throw new RuntimeException('Set DEMO_USER_PASSWORD before creating the demo user.');
        }

        return User::query()->forceCreate([
            'name' => 'Demo User',
            'email' => $email,
            'password' => $password,
            'email_verified_at' => now(),
        ]);
    }
}
