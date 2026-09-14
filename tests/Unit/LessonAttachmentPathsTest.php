<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Lesson;
use PHPUnit\Framework\TestCase;

class LessonAttachmentPathsTest extends TestCase
{
    /** @test */
    public function flattens_string_and_array_attachments_to_basenames(): void
    {
        $lesson = new Lesson;
        $lesson->attachments = [
            'lesson-materials/week-3.pdf',
            ['path' => 'lesson-materials/audio.mp3'],
        ];

        $this->assertSame(
            ['lesson-materials/week-3.pdf', 'lesson-materials/audio.mp3'],
            $lesson->attachmentPaths(),
        );
        $this->assertSame(['week-3.pdf', 'audio.mp3'], $lesson->attachmentBasenames());
    }
}
