<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create Admin
        $admin = User::create([
            'username' => 'admin',
            'password' => Hash::make('admin123'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        // Create Drivers
        $driver1 = User::create([
            'username' => 'driver1',
            'password' => Hash::make('driver123'),
            'role' => 'driver',
            'is_active' => true,
        ]);

        $driver2 = User::create([
            'username' => 'driver2',
            'password' => Hash::make('driver123'),
            'role' => 'driver',
            'is_active' => true,
        ]);

        // Create Technicians
        $teknisi1 = User::create([
            'username' => 'teknisi1',
            'password' => Hash::make('teknisi123'),
            'role' => 'teknisi',
            'is_active' => true,
        ]);

        $teknisi2 = User::create([
            'username' => 'teknisi2',
            'password' => Hash::make('teknisi123'),
            'role' => 'teknisi',
            'is_active' => true,
        ]);

        // Create Vehicles
        $vehicle1 = Vehicle::create([
            'brand' => 'Toyota',
            'model' => 'Avanza',
            'plate_number' => 'B1234XYZ',
            'year' => 2023,
        ]);

        $vehicle2 = Vehicle::create([
            'brand' => 'Honda',
            'model' => 'Brio',
            'plate_number' => 'B5678ABC',
            'year' => 2022,
        ]);

        $vehicle3 = Vehicle::create([
            'brand' => 'Daihatsu',
            'model' => 'Xenia',
            'plate_number' => 'B9999DEF',
            'year' => 2021,
        ]);

        // Assign Vehicles to Drivers
        VehicleAssignment::create([
            'vehicle_id' => $vehicle1->id,
            'driver_id' => $driver1->id,
            'assigned_at' => now(),
        ]);

        VehicleAssignment::create([
            'vehicle_id' => $vehicle2->id,
            'driver_id' => $driver2->id,
            'assigned_at' => now(),
        ]);

        echo "✅ Seeder berhasil!\n\n";
        echo "=== LOGIN CREDENTIALS ===\n";
        echo "Admin:\n";
        echo "  Username: admin\n";
        echo "  Password: admin123\n\n";
        echo "Driver 1:\n";
        echo "  Username: driver1\n";
        echo "  Password: driver123\n";
        echo "  Kendaraan: {$vehicle1->plate_number} ({$vehicle1->brand} {$vehicle1->model})\n\n";
        echo "Driver 2:\n";
        echo "  Username: driver2\n";
        echo "  Password: driver123\n";
        echo "  Kendaraan: {$vehicle2->plate_number} ({$vehicle2->brand} {$vehicle2->model})\n\n";
        echo "Teknisi 1:\n";
        echo "  Username: teknisi1\n";
        echo "  Password: teknisi123\n\n";
        echo "Teknisi 2:\n";
        echo "  Username: teknisi2\n";
        echo "  Password: teknisi123\n";
    }
}

