<?php

namespace AppGraph\Tests\Support;

use AppGraph\Support\ScanResultRegistry;
use AppGraph\Support\ScanRunner;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/** Test-only bridge for Testbench, whose package fixture has no artisan file. */
final class InProcessScanRunner implements ScanRunner
{
    public function __construct(private ScanResultRegistry $results)
    {
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        $token = bin2hex(random_bytes(16));
        $status = Artisan::call('appgraph:scan', ['--result-token' => $token]);
        $result = $this->results->take($token);

        if ($status !== 0 || ! is_array($result)) {
            throw new RuntimeException(
                'The Testbench AppGraph scan failed. '.trim(Artisan::output())
            );
        }

        return $result;
    }
}
