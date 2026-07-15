<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\Graph;
use AppGraph\Scanners\FormRequestScanner;
use AppGraph\Support\FileFinder;
use AppGraph\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

class FormRequestScannerTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturePath = sys_get_temp_dir().'/appgraph-form-request-scanner-'.bin2hex(random_bytes(4));
        mkdir($this->fixturePath.'/app/Http/Requests', 0775, true);
        mkdir($this->fixturePath.'/app/Http/Controllers', 0775, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->fixturePath);

        parent::tearDown();
    }

    public function test_it_extracts_literal_rules_and_flags_dynamic_ones(): void
    {
        file_put_contents($this->fixturePath.'/app/Http/Requests/UpdateNoteRequest.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedRequests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateNoteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'status' => ['required', new Enum(NoteStatus::class)],
            'tags' => ['array', Rule::class],
            'body' => $this->bodyRules(),
        ];
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Http/Requests/BaseRequest.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedRequests;

use Illuminate\Foundation\Http\FormRequest;

abstract class BaseRequest extends FormRequest
{
}
PHP);

        file_put_contents($this->fixturePath.'/app/Http/Requests/StoreNoteRequest.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedRequests;

class StoreNoteRequest extends BaseRequest
{
    public function rules(): array
    {
        return $this->isMethod('post') ? ['title' => 'required'] : [];
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Http/Requests/NotARequest.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedRequests;

class NotARequest
{
    public function rules(): array
    {
        return ['ignored' => 'string'];
    }
}
PHP);

        $graph = new Graph();
        (new FormRequestScanner(new FileFinder($this->fixturePath)))->scan($graph);

        $array = $graph->toArray();
        $namespace = 'AppGraph\Tests\GeneratedRequests';

        $this->assertGraphHasNode($array, $namespace.'\UpdateNoteRequest', 'form_request');
        $this->assertGraphHasNode($array, $namespace.'\BaseRequest', 'form_request');
        $this->assertGraphHasNode($array, $namespace.'\StoreNoteRequest', 'form_request');
        $this->assertNull($this->graphNode($array, $namespace.'\NotARequest'));

        $update = $this->graphNode($array, $namespace.'\UpdateNoteRequest');

        $this->assertSame('app/Http/Requests/UpdateNoteRequest.php', $update['file']);
        $this->assertSame([
            'body' => '{expr}',
            'status' => ['required', 'Enum'],
            'tags' => ['array', 'Rule'],
            'title' => 'required|string|max:255',
        ], $update['metadata']['rules']);
        $this->assertTrue($update['metadata']['rulesDynamic']);

        $store = $this->graphNode($array, $namespace.'\StoreNoteRequest');

        $this->assertArrayNotHasKey('rules', $store['metadata']);
        $this->assertTrue($store['metadata']['rulesDynamic']);

        $base = $this->graphNode($array, $namespace.'\BaseRequest');

        $this->assertArrayNotHasKey('metadata', $base);
    }

    public function test_it_merges_with_route_scanner_created_nodes(): void
    {
        file_put_contents($this->fixturePath.'/app/Http/Requests/MergeRequest.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedRequests;

use Illuminate\Foundation\Http\FormRequest;

class MergeRequest extends FormRequest
{
    public function rules(): array
    {
        return ['name' => 'required'];
    }
}
PHP);

        $graph = new Graph();

        // Simulate the bare node RouteScanner emits for a typed controller parameter.
        $graph->addNode(\AppGraph\Graph\Node::make('AppGraph\Tests\GeneratedRequests\MergeRequest', 'form_request', 'MergeRequest', [
            'metadata' => [
                'source' => 'controller_method_parameter',
                'parameter' => 'request',
            ],
        ]));

        (new FormRequestScanner(new FileFinder($this->fixturePath)))->scan($graph);

        $node = $this->graphNode($graph->toArray(), 'AppGraph\Tests\GeneratedRequests\MergeRequest');

        $this->assertSame('app/Http/Requests/MergeRequest.php', $node['file']);
        $this->assertSame(['name' => 'required'], $node['metadata']['rules']);
        $this->assertSame('controller_method_parameter', $node['metadata']['source']);
    }

    public function test_it_maps_inline_request_controller_and_validator_rules(): void
    {
        file_put_contents($this->fixturePath.'/app/Http/Controllers/InlineValidationController.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedRequests;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

class InlineValidationController extends Controller
{
    public function update(Request $request): void
    {
        $request->validate([
            'title' => 'required|string',
        ]);

        $this->validate($request, [
            'body' => ['nullable', 'string'],
        ]);

        Validator::make($request->all(), [
            'status' => 'required',
        ]);

        validator($request->all(), $this->dynamicRules());
    }

    private function dynamicRules(): array
    {
        return [];
    }
}
PHP);

        $graph = new Graph();
        (new FormRequestScanner(new FileFinder($this->fixturePath)))->scan($graph);
        $array = $graph->toArray();
        $caller = 'AppGraph\Tests\GeneratedRequests\InlineValidationController::update';

        $validationEdges = array_values(array_filter(
            $array['edges'],
            static fn (array $edge): bool => $edge['from'] === $caller && $edge['type'] === 'validates_with'
        ));

        $this->assertCount(4, $validationEdges);
        $this->assertGraphHasNode($array, $caller, 'method');

        $validations = array_values(array_filter(
            $array['nodes'],
            static fn (array $node): bool => ($node['metadata']['source'] ?? null) === 'inline_validation'
        ));
        $rules = array_column(array_column($validations, 'metadata'), 'rules');

        $this->assertContains(['title' => 'required|string'], $rules);
        $this->assertContains(['body' => ['nullable', 'string']], $rules);
        $this->assertContains(['status' => 'required'], $rules);
        $this->assertTrue((bool) array_filter(
            $validations,
            static fn (array $node): bool => ($node['metadata']['rulesDynamic'] ?? false) === true
        ));
    }
}
