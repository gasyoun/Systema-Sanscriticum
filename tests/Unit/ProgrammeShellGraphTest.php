<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Course;
use App\Services\HindiProgrammePlaylist;
use App\Services\ProgrammeShellGraph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H2442 — cycle-safe predecessor walk for programme shells.
 */
class ProgrammeShellGraphTest extends TestCase
{
    use RefreshDatabase;

    public function test_chain_walks_earliest_first(): void
    {
        $gr2 = Course::factory()->create(['title' => 'Хинди гр. 2', 'slug' => 'h-gr2']);
        $gr3 = Course::factory()->create([
            'title' => 'Хинди гр. 3',
            'slug' => 'h-gr3',
            'predecessor_course_id' => $gr2->id,
            'continues_from_lesson' => 8,
        ]);
        $gr5 = Course::factory()->create([
            'title' => 'Хинди гр. 5',
            'slug' => 'h-gr5',
            'predecessor_course_id' => $gr3->id,
            'continues_from_lesson' => 13,
        ]);

        $ids = app(ProgrammeShellGraph::class)->walkFrom($gr5)->pluck('id')->all();

        $this->assertSame([$gr2->id, $gr3->id, $gr5->id], $ids);
    }

    public function test_cycle_does_not_loop(): void
    {
        $a = Course::factory()->create(['title' => 'Цикл A', 'slug' => 'cyc-a']);
        $b = Course::factory()->create([
            'title' => 'Цикл B',
            'slug' => 'cyc-b',
            'predecessor_course_id' => $a->id,
        ]);
        $a->update(['predecessor_course_id' => $b->id]);
        $a->refresh();
        $b->refresh();

        $started = microtime(true);
        $ids = app(ProgrammeShellGraph::class)->walkFrom($b)->pluck('id')->all();
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(1.0, $elapsed, 'cycle walk must terminate quickly');
        $this->assertCount(2, $ids);
        $this->assertSame(array_values(array_unique($ids)), $ids);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids);
    }

    public function test_missing_fk_is_skipped(): void
    {
        $leaf = Course::factory()->create([
            'title' => 'Висячий предшественник',
            'slug' => 'dangling-pred',
        ]);
        // SQLite enforces the FK inside RefreshDatabase's transaction, so
        // persist a real row then point the in-memory model at a missing id.
        $leaf->predecessor_course_id = 999999;

        $ids = app(ProgrammeShellGraph::class)->walkFrom($leaf)->pluck('id')->all();

        $this->assertSame([$leaf->id], $ids);
    }

    public function test_inactive_predecessor_is_skipped(): void
    {
        $archive = Course::factory()->create([
            'title' => 'Архив выключен',
            'slug' => 'inactive-pred',
            'is_active' => false,
        ]);
        $live = Course::factory()->create([
            'title' => 'Живой поток',
            'slug' => 'live-shell',
            'predecessor_course_id' => $archive->id,
        ]);

        $ids = app(ProgrammeShellGraph::class)->walkFrom($live)->pluck('id')->all();

        $this->assertSame([$live->id], $ids);
    }

    public function test_would_cycle_detects_back_edge(): void
    {
        $graph = app(ProgrammeShellGraph::class);
        $a = Course::factory()->create();
        $b = Course::factory()->create(['predecessor_course_id' => $a->id]);

        $this->assertTrue($graph->wouldCycle((int) $a->id, (int) $b->id));
        $this->assertTrue($graph->wouldCycle((int) $a->id, (int) $a->id));
        $this->assertFalse($graph->wouldCycle((int) $b->id, (int) $a->id));
        $this->assertFalse($graph->wouldCycle((int) $a->id, null));
    }

    public function test_unknown_programme_key_is_empty(): void
    {
        Course::factory()->create(['title' => 'Хинди гр. 2']);

        $this->assertTrue(app(ProgrammeShellGraph::class)->orderedShells('no-such-programme')->isEmpty());
    }

    public function test_hindi_programme_key_uses_title_heuristic(): void
    {
        $hindi = Course::factory()->create(['title' => 'Грамматика хинди гр. 3', 'slug' => 'gram-xindi-3']);
        Course::factory()->create(['title' => 'Санскрит гр. 1', 'slug' => 'sa-1']);

        $ids = app(ProgrammeShellGraph::class)->orderedShells('hindi')->pluck('id')->all();

        $this->assertSame([$hindi->id], $ids);
    }

    public function test_playlist_consumes_the_graph(): void
    {
        $hindi = Category::factory()->create(['slug' => 'hindi']);
        $gr2 = Course::factory()->create(['title' => 'Хинди гр. 2', 'slug' => 'pl-gr2']);
        $gr3 = Course::factory()->create([
            'title' => 'Хинди гр. 3',
            'slug' => 'pl-gr3',
            'predecessor_course_id' => $gr2->id,
        ]);
        $gr2->categories()->attach($hindi->id);
        $gr3->categories()->attach($hindi->id);

        $fromGraph = app(ProgrammeShellGraph::class)->orderedShells('hindi')->pluck('id')->all();
        $fromPlaylist = app(HindiProgrammePlaylist::class)->orderedShells()->pluck('id')->all();

        $this->assertSame([$gr2->id, $gr3->id], $fromGraph);
        $this->assertSame($fromGraph, $fromPlaylist);
    }
}
