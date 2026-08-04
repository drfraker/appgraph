<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Storage\GraphStore;
use AppGraph\Support\FreshScanRunner;
use AppGraph\Tests\TestCase;

class FreshScanRunnerTest extends TestCase
{
    public function test_it_uses_a_fresh_php_process_and_correlates_the_private_result(): void
    {
        $directory = sys_get_temp_dir().'/appgraph-fresh-runner-'.bin2hex(random_bytes(6));
        mkdir($directory, 0775, true);
        $artisan = $directory.'/artisan';
        file_put_contents($artisan, <<<'PHP'
<?php

$options = [];

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--') && str_contains($argument, '=')) {
        [$name, $value] = explode('=', substr($argument, 2), 2);
        $options[$name] = $value;
    }
}

if (($argv[1] ?? null) !== 'appgraph:scan') {
    fwrite(STDERR, 'unexpected fresh scan arguments');
    exit(9);
}

$result = [
    'created' => true,
    'generation' => ['id' => '18'],
    'previousGeneration' => ['id' => '17'],
    'childPid' => getmypid(),
];
file_put_contents(
    $options['result-file'],
    json_encode(['token' => $options['result-token'], 'result' => $result], JSON_THROW_ON_ERROR),
);
PHP);

        try {
            $runner = new FreshScanRunner(
                new GraphStore($directory.'/appgraph.sqlite'),
                $artisan,
                PHP_BINARY,
            );
            $result = $runner->run();

            $this->assertSame('18', $result['generation']['id']);
            $this->assertSame('17', $result['previousGeneration']['id']);
            $this->assertNotSame(getmypid(), $result['childPid']);
            $this->assertSame([], glob($directory.'/.scan-result-*.json'));
        } finally {
            @unlink($artisan);
            @rmdir($directory);
        }
    }
}
