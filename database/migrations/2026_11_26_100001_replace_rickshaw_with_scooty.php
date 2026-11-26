<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('vehicle_categories')->where('slug', 'rickshaw')->update(['is_active' => false, 'updated_at' => now()]);

        if (DB::table('vehicle_categories')->where('slug', 'scooty')->exists()) {
            DB::table('vehicle_categories')->where('slug', 'scooty')->update(['is_active' => true]);

            return;
        }

        $bike = DB::table('vehicle_categories')->where('slug', 'bike')->first();
        if (! $bike) {
            return; // fresh installs get Scooty from the seeder
        }

        $row = (array) $bike;
        unset($row['id']);
        $row = array_merge($row, [
            'name' => 'FemaleRider Scooty',
            'slug' => 'scooty',
            'icon' => 'scooty',
            'description' => 'Easy scooty rides around town',
            'sort_order' => ((int) $bike->sort_order) + 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $id = DB::table('vehicle_categories')->insertGetId($row);

        foreach (DB::table('fare_rules')->where('vehicle_category_id', $bike->id)->get() as $rule) {
            $r = (array) $rule;
            unset($r['id']);
            $r['vehicle_category_id'] = $id;
            $r['created_at'] = $r['updated_at'] = now();
            DB::table('fare_rules')->insert($r);
        }
    }

    public function down(): void
    {
        DB::table('vehicle_categories')->where('slug', 'rickshaw')->update(['is_active' => true]);
        DB::table('vehicle_categories')->where('slug', 'scooty')->update(['is_active' => false]);
    }
};
