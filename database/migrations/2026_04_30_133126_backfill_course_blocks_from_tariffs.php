<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $blockTariffs = DB::table('tariffs')
            ->where('type', 'block')
            ->whereNotNull('course_id')
            ->whereNotNull('block_number')
            ->whereNull('course_block_id')
            ->orderBy('course_id')
            ->orderBy('block_number')
            ->get();

        $now = now();
        $createdByCourseAndNumber = [];

        foreach ($blockTariffs as $tariff) {
            $key = $tariff->course_id . ':' . $tariff->block_number;

            if (isset($createdByCourseAndNumber[$key])) {
                $blockId = $createdByCourseAndNumber[$key];
            } else {
                $existing = DB::table('course_blocks')
                    ->where('course_id', $tariff->course_id)
                    ->where('number', $tariff->block_number)
                    ->value('id');

                if ($existing) {
                    $blockId = $existing;
                } else {
                    $blockId = DB::table('course_blocks')->insertGetId([
                        'course_id'   => $tariff->course_id,
                        'number'      => $tariff->block_number,
                        'title'       => $tariff->title,
                        'description' => $tariff->description,
                        'is_active'   => (bool) $tariff->is_active,
                        'created_at'  => $now,
                        'updated_at'  => $now,
                    ]);
                }

                $createdByCourseAndNumber[$key] = $blockId;
            }

            DB::table('tariffs')
                ->where('id', $tariff->id)
                ->update(['course_block_id' => $blockId]);
        }
    }

    public function down(): void
    {
        DB::table('tariffs')->whereNotNull('course_block_id')->update(['course_block_id' => null]);
        DB::table('course_blocks')->delete();
    }
};
