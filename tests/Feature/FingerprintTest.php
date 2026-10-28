<?php

namespace Tests\Feature;

use App\Models\Proposal;
use App\Services\Contracts\HealthPlanInstaller;
use App\Services\Contracts\OriginalWordContract;
use App\Services\Domain\Fingerprint;
use App\Services\Domain\ProposalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FingerprintTest extends TestCase
{
    use RefreshDatabase;

    public function test_key_order_does_not_change_the_fingerprint_but_list_order_does(): void
    {
        $this->assertSame(Fingerprint::sha256(['b' => 1, 'a' => ['y' => 1, 'x' => 2]]), Fingerprint::sha256(['a' => ['x' => 2, 'y' => 1], 'b' => 1]));
        $this->assertNotSame(Fingerprint::sha256(['items' => [1, 2]]), Fingerprint::sha256(['items' => [2, 1]]));
    }

    public function test_snapshot_and_word_layout_read_back_with_reordered_keys_still_match(): void
    {
        // MySQL's JSON type returns object keys sorted by length, not in the order they were written.
        foreach (app(HealthPlanInstaller::class)->installAll() as $version) {
            $layout = $version->contract_layout;
            $snapshot = ['version' => ['contract_layout' => $layout, 'contract_template' => $version->contract_template], 'customer' => ['name' => 'Tutor', 'email' => 'tutor@example.test']];
            $stored = $this->reorder($snapshot);
            $this->assertNotSame(json_encode($snapshot), json_encode($stored));

            $service = app(ProposalService::class);
            $this->assertSame($service->reviewFingerprint(new Proposal(['snapshot' => $snapshot])), $service->reviewFingerprint(new Proposal(['snapshot' => $stored])));
            $this->assertSame(app(OriginalWordContract::class)->entry($snapshot), app(OriginalWordContract::class)->entry($stored));
        }
    }

    private function reorder(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            krsort($value, SORT_STRING);
        }

        return array_map(fn ($item) => $this->reorder($item), $value);
    }
}
