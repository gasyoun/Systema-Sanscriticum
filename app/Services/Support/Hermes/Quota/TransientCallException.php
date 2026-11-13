<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\Quota;

/**
 * H6174: маркер временной ошибки LLM-вызова — единственный класс ошибок,
 * который TransientRetryLimiter разрешает повторить (≤2 повторов).
 *
 * Стойкие ошибки (некорректный вход, отказ гейта, программный дефект) не
 * повторяются; повтор — только итеративный, без рекурсивных агентных циклов.
 */
class TransientCallException extends \RuntimeException {}
