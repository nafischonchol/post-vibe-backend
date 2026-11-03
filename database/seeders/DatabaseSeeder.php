<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // $this->call([
        //     SkinCareProductSeeder::class,
        // ]);

        // return;
        $this->call([
            AdminSeeder::class,
            AccountSeeder::class,
            DivisionSeeder::class,
            DistrictSeeder::class,
            UpazilaSeeder::class,
            AreaSeeder::class,
            // CosmeticsSeeder::class,
            // ProductSeeder::class,
        ]);
    }
}
