<?php

declare(strict_types=1);

// Веса и пороги скора платёжной дисциплины (см. docs/discipline-score-spec.md).
// Стартовые значения — требуют калибровки после первого прогона на реальных данных.
return [

    /*
     * Вес каждого сигнала в композите (0..100 очков за 100% отклонения сигнала
     * от идеала). requires calibration after first real run.
     */
    'weight_on_time' => 30,
    'weight_promise' => 20,
    'weight_credit' => 15,

    /*
     * Штраф за одно событие «молчания» и предел суммарного штрафа этой
     * категории. requires calibration after first real run.
     */
    'silence_weight_per_event' => 25,
    'silence_cap' => 60,

    /*
     * Пороги 3-уровневой шкалы: score >= good_threshold → Good;
     * score >= watch_threshold → Watch; иначе → Poor.
     * requires calibration after first real run.
     */
    'good_threshold' => 75,
    'watch_threshold' => 40,

];
