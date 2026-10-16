<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MaterialContentSeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode(file_get_contents(database_path('seeders/data/material_descriptions.json')), true);
        // CHECK: change 'material_code' if your materials table names the code column differently.
        foreach ($data as $code => $description) {
            DB::table('materials')->where('material_code', $code)->update([
                'description' => $description,
                'image_path'  => "material-images/{$code}.webp",
            ]);
        }
    }
}
