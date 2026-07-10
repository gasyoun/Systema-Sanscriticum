<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H476: самоотчётный «источник прихода» при регистрации. UTM (H267) покрывает
 * только размеченные ссылки; сарафан/поиск/давно-подписан приходят без меток —
 * их видит только сам студент. Аддитивно и nullable, как вся ветка атрибуции.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('signup_source', 32)->nullable()->after('birth_year');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('signup_source');
        });
    }
};
