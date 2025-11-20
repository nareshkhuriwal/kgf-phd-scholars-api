<?php

namespace Database\Seeders;

use App\Models\Paper;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::first() ?? User::factory()->create(['email'=>'demo@example.com']);
        Paper::factory()->count(10)->create([
            'created_by' => $user->id,
        ]);
    }
}
