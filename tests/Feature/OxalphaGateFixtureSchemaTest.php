<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * H5062: pins the committed verdict schema SPECIMEN to the shape the
 * oxalpha-review-gate validator actually enforces (tools/oxalpha_gate_verdict.py).
 * The independent review of this PR found the specimen drifting from the
 * enforced contract (wrong schema tag, spec.evidence without evidence_links);
 * this test makes that drift a red CI run instead of a silent trap for the
 * next reviewer copying the specimen.
 */
class OxalphaGateFixtureSchemaTest extends TestCase
{
    private function specimen(): array
    {
        $path = base_path('tests/Fixtures/OxalphaReviewGate/verdict-example.json');
        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_specimen_carries_the_enforced_schema_tag(): void
    {
        $this->assertSame('oxalpha-review-verdict/1', $this->specimen()['schema']);
    }

    public function test_specimen_reviewer_marks_independence(): void
    {
        $reviewer = $this->specimen()['reviewer'];
        $this->assertArrayHasKey('name', $reviewer);
        $this->assertTrue($reviewer['independent']);
    }

    public function test_specimen_has_separate_standards_and_spec_axes_with_evidence(): void
    {
        $specimen = $this->specimen();
        foreach (['standards', 'spec'] as $axis) {
            $this->assertArrayHasKey($axis, $specimen);
            $this->assertContains($specimen[$axis]['verdict'], ['pass', 'fail'], $axis);
            $this->assertNotEmpty($specimen[$axis]['evidence'], $axis);
        }
        // Validator rule (tools/oxalpha_gate_verdict.py): spec axis must cite
        // spec surfaces via evidence_links (or carry findings).
        $this->assertNotEmpty($specimen['spec']['evidence_links']);
    }

    public function test_specimen_head_sha_placeholder_is_not_a_real_sha(): void
    {
        // The committed specimen must never look like a real, postable verdict:
        // its head_sha is the all-zero placeholder, which the gate's own
        // stale-head check would reject.
        $this->assertSame(str_repeat('0', 40), $this->specimen()['head_sha']);
    }
}
