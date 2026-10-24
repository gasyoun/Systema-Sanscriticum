<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H5773 — защищённое gold-ревью 100-сообщений (residual H5709/H5768).
 *
 * Три таблицы:
 *  - support_question_review_samples — замороженная выборка: окно, версия
 *    классификатора, размер и fingerprint состава; уникальный fingerprint
 *    не даёт заморозить тот же состав дважды, а смена версии классификатора
 *    блокирует досывку меток в старый сэмпл (сервис-гард, не схема);
 *  - support_question_review_items — члены выборки: ссылка на classification,
 *    снапшот предсказания на момент заморозки (до записи gold-метки скрыт
 *    от ревьюера) и детерминированная позиция;
 *  - support_question_review_labels — gold-метки: одна живая метка на item
 *    (unique item_id — повторные сабмиты обновляют, а не дублируют), автор
 *    метки фиксируется user_id.
 *
 * Тексты сообщений НЕ копируются: ревью-экран читает их живой связью
 * classification→message внутри доверенного окружения.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_question_review_samples', function (Blueprint $table) {
            $table->id();
            $table->date('window_from');
            $table->date('window_to'); // эксклюзивно, как у CLI-ревью
            $table->string('classifier_version', 32);
            $table->unsignedInteger('sample_size');
            $table->string('fingerprint', 64)->unique(); // sha256 состава+предсказаний
            $table->string('status', 16)->default('open'); // open|completed
            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('support_question_review_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sample_id')
                ->constrained('support_question_review_samples')->cascadeOnDelete();
            $table->foreignId('classification_id')
                ->constrained('support_question_classifications')->cascadeOnDelete();
            $table->string('population', 24); // student|enquiry
            $table->string('predicted_primary', 32); // снапшот на заморозке: A..I|unclassified
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['sample_id', 'classification_id'], 'sqri_sample_classification_unique');
            $table->unique(['sample_id', 'position'], 'sqri_sample_position_unique');
        });

        Schema::create('support_question_review_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sample_id')
                ->constrained('support_question_review_samples')->cascadeOnDelete();
            $table->foreignId('item_id')
                ->constrained('support_question_review_items')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('gold_label', 16); // A..I|unclassified|not_question|other
            $table->timestamps();

            $table->unique('item_id'); // одна живая метка на item — анти-replay
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_question_review_labels');
        Schema::dropIfExists('support_question_review_items');
        Schema::dropIfExists('support_question_review_samples');
    }
};
