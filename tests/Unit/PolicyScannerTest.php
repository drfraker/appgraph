<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\Graph;
use AppGraph\Scanners\PolicyScanner;
use AppGraph\Support\FileFinder;
use AppGraph\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

class PolicyScannerTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturePath = sys_get_temp_dir().'/appgraph-policies-'.bin2hex(random_bytes(4));

        foreach (['app/Models', 'app/Policies', 'app/Http/Controllers'] as $directory) {
            mkdir($this->fixturePath.'/'.$directory, 0775, true);
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->fixturePath);
        parent::tearDown();
    }

    public function test_it_maps_controller_authorization_to_conventional_policy_methods(): void
    {
        file_put_contents($this->fixturePath.'/app/Models/ProgressNote.php', <<<'PHP'
<?php
namespace AppGraph\Tests\GeneratedPolicies\Models;
class ProgressNote {}
PHP);
        file_put_contents($this->fixturePath.'/app/Policies/ProgressNotePolicy.php', <<<'PHP'
<?php
namespace AppGraph\Tests\GeneratedPolicies\Policies;
class ProgressNotePolicy { public function sign(object $user, object $note): bool { return true; } }
PHP);
        file_put_contents($this->fixturePath.'/app/Http/Controllers/ProgressNoteController.php', <<<'PHP'
<?php
namespace AppGraph\Tests\GeneratedPolicies\Http\Controllers;
use AppGraph\Tests\GeneratedPolicies\Models\ProgressNote;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Gate;
class ProgressNoteController
{
    use AuthorizesRequests;

    public function sign(ProgressNote $note): void
    {
        $this->authorize('sign', $note);
    }

    public function signForUser(object $user, ProgressNote $note): void
    {
        $target = $note;
        Gate::authorizeForUser($user, 'sign', [$target]);
    }
}
PHP);

        $graph = new Graph();
        (new PolicyScanner(new FileFinder($this->fixturePath)))->scan($graph);
        $array = $graph->toArray();

        $controller = 'AppGraph\Tests\GeneratedPolicies\Http\Controllers\ProgressNoteController::sign';
        $policy = 'AppGraph\Tests\GeneratedPolicies\Policies\ProgressNotePolicy::sign';
        $this->assertGraphHasNode($array, 'AppGraph\Tests\GeneratedPolicies\Policies\ProgressNotePolicy', 'policy');
        $policyNode = $this->graphNode($array, 'AppGraph\Tests\GeneratedPolicies\Policies\ProgressNotePolicy');
        $this->assertSame(3, $policyNode['line']);
        $this->assertSame(3, $policyNode['endLine']);
        $this->assertGraphHasEdge($array, $controller, $policy, 'authorizes_via');
        $this->assertGraphHasEdge(
            $array,
            'AppGraph\Tests\GeneratedPolicies\Http\Controllers\ProgressNoteController::signForUser',
            $policy,
            'authorizes_via'
        );
    }
}
