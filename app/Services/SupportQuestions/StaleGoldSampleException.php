<?php

namespace App\Services\SupportQuestions;

/**
 * H5773 — выборка заморожена под ДРУГУЮ версию классификатора: досылать
 * метки в неё нельзя, нужна новая заморозка. Отдельный класс, чтобы экран
 * отличал «устарела версия» от прочих ошибок.
 */
class StaleGoldSampleException extends GoldReviewException {}
