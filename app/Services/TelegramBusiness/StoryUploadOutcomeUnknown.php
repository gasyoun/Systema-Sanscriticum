<?php

declare(strict_types=1);

namespace App\Services\TelegramBusiness;

use RuntimeException;

/** Telegram may have accepted the upload even though no Story ID was received. */
final class StoryUploadOutcomeUnknown extends RuntimeException {}
