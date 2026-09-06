<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        // Satu peran admin (keputusan #8). Tidak ada registrasi publik.
        User::updateOrCreate(
            ['email' => 'admin@vpn.local'],
            ['name' => 'Administrator VPN', 'password' => Hash::make('admin12345')],
        );
    }
}
