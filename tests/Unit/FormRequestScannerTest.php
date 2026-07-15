<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\Graph;
use AppGraph\Scanners\FormRequestScanner;
use AppGraph\Support\ContainerBindingRegistry;
use AppGraph\Support\FileFinder;
use AppGraph\Tests\TestCase;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Http\FormRequest as LaravelFormRequest;

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
        $graph->addEdge(new \AppGraph\Graph\Edge(
            'AppGraph\Tests\GeneratedRequests\MergeController::store',
            'AppGraph\Tests\GeneratedRequests\MergeRequest',
            'validates_with',
        ));

        (new FormRequestScanner(new FileFinder($this->fixturePath)))->scan($graph);

        $array = $graph->toArray();
        $node = $this->graphNode($array, 'AppGraph\Tests\GeneratedRequests\MergeRequest');

        $this->assertSame('app/Http/Requests/MergeRequest.php', $node['file']);
        $this->assertSame(['name' => 'required'], $node['metadata']['rules']);
        $this->assertSame('controller_method_parameter', $node['metadata']['source']);
        $this->assertGraphHasEdge(
            $array,
            'AppGraph\Tests\GeneratedRequests\MergeController::store',
            'AppGraph\Tests\GeneratedRequests\MergeRequest',
            'validates_with',
        );
    }

    public function test_it_maps_form_request_lifecycle_hooks_in_framework_order(): void
    {
        file_put_contents($this->fixturePath.'/app/Http/Requests/BaseLifecycleRequest.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedRequests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

abstract class BaseLifecycleRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
    }

    protected function failedAuthorization(): void
    {
    }

    public function validationData(): array
    {
        return [];
    }

    public function messages(): array
    {
        return [];
    }

    public function attributes(): array
    {
        return [];
    }

    public function withValidator(Validator $validator): void
    {
    }

    public function after(): array
    {
        return [];
    }

    protected function configureFromAttributes(): void
    {
    }

    protected function shouldFailOnUnknownFields(): bool
    {
        return true;
    }

    protected function validateNoUnknownFields(Validator $validator): void
    {
    }

    protected function failedValidation(Validator $validator): void
    {
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Http/Requests/DefaultLifecycleRequest.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedRequests;

class DefaultLifecycleRequest extends BaseLifecycleRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['title' => 'required'];
    }

    protected function passedValidation(): void
    {
    }
}
PHP);

        $graph = new Graph();
        (new FormRequestScanner(new FileFinder($this->fixturePath)))->scan($graph);
        $array = $graph->toArray();
        $namespace = 'AppGraph\Tests\GeneratedRequests';
        $request = $namespace.'\DefaultLifecycleRequest';
        $base = $namespace.'\BaseLifecycleRequest';

        $edges = array_values(array_filter(
            $array['edges'],
            static fn (array $edge): bool => $edge['from'] === $request
                && $edge['type'] === 'framework_invokes'
        ));
        usort($edges, static fn (array $left, array $right): int => $left['metadata']['order'] <=> $right['metadata']['order']);

        $expected = [
            10 => $base.'::prepareForValidation',
            20 => $request.'::authorize',
            30 => $base.'::failedAuthorization',
            40 => $request.'::rules',
            41 => $base.'::validationData',
            42 => $base.'::messages',
            43 => $base.'::attributes',
            50 => $base.'::withValidator',
            60 => $base.'::after',
            70 => $base.'::failedValidation',
            80 => $request.'::passedValidation',
        ];

        if (method_exists(LaravelFormRequest::class, 'configureFromAttributes')) {
            $expected[35] = $base.'::configureFromAttributes';
        }

        if (method_exists(LaravelFormRequest::class, 'shouldFailOnUnknownFields')) {
            $expected[61] = $base.'::shouldFailOnUnknownFields';
            $expected[62] = $base.'::validateNoUnknownFields';
        }

        ksort($expected);

        $this->assertSame(array_values($expected), array_column($edges, 'to'));
        $this->assertSame(array_keys($expected), array_column(array_column($edges, 'metadata'), 'order'));

        foreach ($edges as $edge) {
            $this->assertGraphHasNode($array, $edge['to'], 'method');
        }

        $prepare = $this->graphEdge($array, $request, $base.'::prepareForValidation', 'framework_invokes');
        $this->assertTrue($prepare['metadata']['inherited']);
        $this->assertFalse($prepare['metadata']['conditional']);
        $this->assertFalse($prepare['metadata']['terminal']);

        $rules = $this->graphEdge($array, $request, $request.'::rules', 'framework_invokes');
        $this->assertSame('default_validator', $rules['metadata']['branch']);

        $failedAuthorization = $this->graphEdge($array, $request, $base.'::failedAuthorization', 'framework_invokes');
        $this->assertTrue($failedAuthorization['metadata']['conditional']);
        $this->assertFalse($failedAuthorization['metadata']['terminal']);
        $this->assertTrue($failedAuthorization['metadata']['expectedTerminal']);
        $this->assertSame('authorization_failed', $failedAuthorization['metadata']['condition']);

        $failedValidation = $this->graphEdge($array, $request, $base.'::failedValidation', 'framework_invokes');
        $this->assertTrue($failedValidation['metadata']['conditional']);
        $this->assertFalse($failedValidation['metadata']['terminal']);
        $this->assertTrue($failedValidation['metadata']['expectedTerminal']);
        $this->assertSame('validation_failed', $failedValidation['metadata']['condition']);

        $passedValidation = $this->graphEdge($array, $request, $request.'::passedValidation', 'framework_invokes');
        $this->assertTrue($passedValidation['metadata']['conditional']);
        $this->assertFalse($passedValidation['metadata']['terminal']);
        $this->assertTrue($passedValidation['metadata']['beforeController']);
        $this->assertSame('validation_passed_or_failure_handler_returned', $passedValidation['metadata']['condition']);
    }

    public function test_a_custom_validator_only_reaches_rules_through_laravel_12_unknown_field_validation(): void
    {
        file_put_contents($this->fixturePath.'/app/Http/Requests/CustomValidatorRequest.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedRequests;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class CustomValidatorRequest extends FormRequest
{
    public function __construct()
    {
        throw new \RuntimeException('The scanner must not instantiate requests.');
    }

    public function authorize(): bool
    {
        return true;
    }

    public function validator(Factory $factory): Validator
    {
        throw new \RuntimeException('The scanner must not invoke hooks.');
    }

    public function rules(): array
    {
        return ['incorrect' => 'required'];
    }

    public function validationData(): array
    {
        return [];
    }

    public function messages(): array
    {
        return [];
    }

    public function attributes(): array
    {
        return [];
    }
}
PHP);

        $graph = new Graph();
        (new FormRequestScanner(new FileFinder($this->fixturePath)))->scan($graph);
        $array = $graph->toArray();
        $request = 'AppGraph\Tests\GeneratedRequests\CustomValidatorRequest';
        $node = $this->graphNode($array, $request);
        $edges = array_values(array_filter(
            $array['edges'],
            static fn (array $edge): bool => $edge['from'] === $request
                && $edge['type'] === 'framework_invokes'
        ));

        $this->assertSame(['incorrect' => 'required'], $node['metadata']['rules']);
        $this->assertTrue($node['metadata']['rulesConditional']);
        $this->assertSame(
            'unknown_field_validation_enabled_and_validator_after_callbacks_run',
            $node['metadata']['rulesCondition'],
        );
        $this->assertSame([
            $request.'::authorize',
            $request.'::rules',
            $request.'::validator',
        ], array_column($edges, 'to'));

        $validator = $this->graphEdge($array, $request, $request.'::validator', 'framework_invokes');
        $this->assertSame(40, $validator['metadata']['order']);
        $this->assertSame('custom_validator', $validator['metadata']['branch']);
        $rules = $this->graphEdge($array, $request, $request.'::rules', 'framework_invokes');
        $this->assertSame(1.0, $rules['confidence']);
        $this->assertSame(63, $rules['metadata']['order']);
        $this->assertTrue($rules['metadata']['conditional']);
        $this->assertSame('custom_validator_unknown_fields', $rules['metadata']['branch']);
        $this->assertSame('validateNoUnknownFields', $rules['metadata']['invokedBy']);
        $this->assertSame(
            'unknown_field_validation_enabled_and_validator_after_callbacks_run',
            $rules['metadata']['condition'],
        );
        $this->assertNull($this->graphEdge($array, $request, $request.'::validationData', 'framework_invokes'));
        $this->assertNull($this->graphEdge($array, $request, $request.'::messages', 'framework_invokes'));
        $this->assertNull($this->graphEdge($array, $request, $request.'::attributes', 'framework_invokes'));
    }

    public function test_framework_orchestration_overrides_suppress_default_hook_claims(): void
    {
        file_put_contents($this->fixturePath.'/app/Http/Requests/OverriddenLifecycleRequests.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedRequests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ValidateResolvedOverrideRequest extends FormRequest
{
    public function validateResolved(): void
    {
    }

    public function rules(): array
    {
        return ['not_active' => 'required'];
    }
}

class ValidatorInstanceOverrideRequest extends FormRequest
{
    protected function passesAuthorization(): bool
    {
        return true;
    }

    protected function getValidatorInstance(): Validator
    {
        throw new \RuntimeException('Scanner must not invoke hooks.');
    }

    protected function failedAuthorization(): void
    {
    }

    protected function failedValidation(Validator $validator): void
    {
    }

    protected function passedValidation(): void
    {
    }

    public function rules(): array
    {
        return ['not_active' => 'required'];
    }
}

class DefaultFactoryOverrideRequest extends FormRequest
{
    protected function createDefaultValidator($factory): Validator
    {
        throw new \RuntimeException('Scanner must not invoke hooks.');
    }

    public function rules(): array
    {
        return ['not_active' => 'required'];
    }
}

class ValidationRulesOverrideRequest extends FormRequest
{
    protected function validationRules(): array
    {
        return ['replacement' => 'required'];
    }

    public function rules(): array
    {
        return ['not_active' => 'required'];
    }
}
PHP);

        $graph = new Graph();
        (new FormRequestScanner(new FileFinder($this->fixturePath)))->scan($graph);
        $array = $graph->toArray();
        $namespace = 'AppGraph\Tests\GeneratedRequests\\';

        $validateResolved = $namespace.'ValidateResolvedOverrideRequest';
        $validateEdges = array_values(array_filter(
            $array['edges'],
            static fn (array $edge): bool => $edge['from'] === $validateResolved
                && $edge['type'] === 'framework_invokes'
        ));
        $this->assertSame([$validateResolved.'::validateResolved'], array_column($validateEdges, 'to'));
        $this->assertSame('application_override', $validateEdges[0]['metadata']['branch']);
        $this->assertArrayNotHasKey('metadata', $this->graphNode($array, $validateResolved));

        $validatorInstance = $namespace.'ValidatorInstanceOverrideRequest';
        $validatorTargets = array_column(array_values(array_filter(
            $array['edges'],
            static fn (array $edge): bool => $edge['from'] === $validatorInstance
                && $edge['type'] === 'framework_invokes'
        )), 'to');
        $this->assertContains($validatorInstance.'::passesAuthorization', $validatorTargets);
        $this->assertContains($validatorInstance.'::getValidatorInstance', $validatorTargets);
        $this->assertNotContains($validatorInstance.'::rules', $validatorTargets);
        $this->assertArrayNotHasKey('metadata', $this->graphNode($array, $validatorInstance));

        $factory = $namespace.'DefaultFactoryOverrideRequest';
        $this->assertGraphHasEdge($array, $factory, $factory.'::createDefaultValidator', 'framework_invokes');
        $this->assertNull($this->graphEdge($array, $factory, $factory.'::rules', 'framework_invokes'));
        $this->assertArrayNotHasKey('metadata', $this->graphNode($array, $factory));

        $validationRules = $namespace.'ValidationRulesOverrideRequest';
        $this->assertGraphHasEdge($array, $validationRules, $validationRules.'::validationRules', 'framework_invokes');
        $this->assertNull($this->graphEdge($array, $validationRules, $validationRules.'::rules', 'framework_invokes'));
        $this->assertArrayNotHasKey('metadata', $this->graphNode($array, $validationRules));
    }

    public function test_trait_supplied_orchestration_overrides_include_nested_trait_use(): void
    {
        file_put_contents($this->fixturePath.'/app/Http/Requests/TraitLifecycleRequests.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedRequests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

trait ReplacesValidationLifecycle
{
    public function validateResolved(): void
    {
    }
}

trait UsesReplacementLifecycle
{
    use ReplacesValidationLifecycle;
}

class NestedTraitLifecycleRequest extends FormRequest
{
    use UsesReplacementLifecycle;

    public function rules(): array
    {
        return ['not_active' => 'required'];
    }
}

trait ReplacesValidatorResolution
{
    protected function getValidatorInstance(): Validator
    {
        throw new \RuntimeException('The scanner must not invoke trait hooks.');
    }
}

class TraitValidatorRequest extends FormRequest
{
    use ReplacesValidatorResolution;

    public function rules(): array
    {
        return ['not_active' => 'required'];
    }

    public function withValidator(Validator $validator): void
    {
    }
}
PHP);

        $graph = new Graph();
        (new FormRequestScanner(new FileFinder($this->fixturePath)))->scan($graph);
        $array = $graph->toArray();
        $namespace = 'AppGraph\Tests\GeneratedRequests\\';
        $nestedRequest = $namespace.'NestedTraitLifecycleRequest';
        $lifecycleTrait = $namespace.'ReplacesValidationLifecycle';
        $nestedEdges = array_values(array_filter(
            $array['edges'],
            static fn (array $edge): bool => $edge['from'] === $nestedRequest
                && $edge['type'] === 'framework_invokes'
        ));

        $this->assertSame([$lifecycleTrait.'::validateResolved'], array_column($nestedEdges, 'to'));
        $this->assertTrue($nestedEdges[0]['metadata']['viaTrait']);
        $this->assertSame($lifecycleTrait, $nestedEdges[0]['metadata']['declaredOn']);
        $this->assertGraphHasNode($array, $lifecycleTrait.'::validateResolved', 'method');
        $this->assertArrayNotHasKey('metadata', $this->graphNode($array, $nestedRequest));

        $validatorRequest = $namespace.'TraitValidatorRequest';
        $validatorTrait = $namespace.'ReplacesValidatorResolution';
        $validatorTargets = array_column(array_values(array_filter(
            $array['edges'],
            static fn (array $edge): bool => $edge['from'] === $validatorRequest
                && $edge['type'] === 'framework_invokes'
        )), 'to');

        $this->assertContains($validatorTrait.'::getValidatorInstance', $validatorTargets);
        $this->assertNotContains($validatorRequest.'::rules', $validatorTargets);
        $this->assertNotContains($validatorRequest.'::withValidator', $validatorTargets);
        $this->assertArrayNotHasKey('metadata', $this->graphNode($array, $validatorRequest));
    }

    public function test_unindexed_parent_orchestration_overrides_veto_unsupported_default_claims(): void
    {
        file_put_contents($this->fixturePath.'/app/Http/Requests/ExternalParentRequests.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedRequests;

use AppGraph\Tests\Unit\ExternalCreateDefaultValidatorFormRequest;
use AppGraph\Tests\Unit\ExternalGetValidatorFormRequest;
use AppGraph\Tests\Unit\ExternalPassesAuthorizationFormRequest;
use AppGraph\Tests\Unit\ExternalValidateResolvedFormRequest;
use AppGraph\Tests\Unit\ExternalValidationRulesFormRequest;
use Illuminate\Contracts\Validation\Validator;

class ExternalValidateResolvedRequest extends ExternalValidateResolvedFormRequest
{
    public function rules(): array
    {
        return ['not_active' => 'required'];
    }
}

class ExternalPassesAuthorizationRequest extends ExternalPassesAuthorizationFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedAuthorization(): void
    {
    }

    public function rules(): array
    {
        return ['active' => 'required'];
    }
}

class ExternalGetValidatorRequest extends ExternalGetValidatorFormRequest
{
    public function rules(): array
    {
        return ['not_active' => 'required'];
    }

    public function withValidator(Validator $validator): void
    {
    }
}

class ExternalCreateDefaultValidatorRequest extends ExternalCreateDefaultValidatorFormRequest
{
    public function rules(): array
    {
        return ['not_active' => 'required'];
    }

    public function validationData(): array
    {
        return [];
    }

    public function withValidator(Validator $validator): void
    {
    }
}

class ExternalValidationRulesRequest extends ExternalValidationRulesFormRequest
{
    public function rules(): array
    {
        return ['not_active' => 'required'];
    }

    public function validationData(): array
    {
        return [];
    }
}
PHP);

        $graph = new Graph();
        (new FormRequestScanner(new FileFinder($this->fixturePath)))->scan($graph);
        $array = $graph->toArray();
        $namespace = 'AppGraph\Tests\GeneratedRequests\\';
        $targetsFor = static fn (string $request): array => array_column(array_values(array_filter(
            $array['edges'],
            static fn (array $edge): bool => $edge['from'] === $request
                && $edge['type'] === 'framework_invokes'
        )), 'to');

        $validateResolved = $namespace.'ExternalValidateResolvedRequest';
        $this->assertSame([], $targetsFor($validateResolved));
        $this->assertArrayNotHasKey('metadata', $this->graphNode($array, $validateResolved));

        $passesAuthorization = $namespace.'ExternalPassesAuthorizationRequest';
        $passesTargets = $targetsFor($passesAuthorization);
        $this->assertNotContains($passesAuthorization.'::authorize', $passesTargets);
        $this->assertContains($passesAuthorization.'::failedAuthorization', $passesTargets);
        $this->assertContains($passesAuthorization.'::rules', $passesTargets);
        $this->assertSame(
            ['active' => 'required'],
            $this->graphNode($array, $passesAuthorization)['metadata']['rules'],
        );

        $getValidator = $namespace.'ExternalGetValidatorRequest';
        $getValidatorTargets = $targetsFor($getValidator);
        $this->assertNotContains($getValidator.'::rules', $getValidatorTargets);
        $this->assertNotContains($getValidator.'::withValidator', $getValidatorTargets);
        $this->assertArrayNotHasKey('metadata', $this->graphNode($array, $getValidator));

        $createDefault = $namespace.'ExternalCreateDefaultValidatorRequest';
        $createDefaultTargets = $targetsFor($createDefault);
        $this->assertNotContains($createDefault.'::rules', $createDefaultTargets);
        $this->assertNotContains($createDefault.'::validationData', $createDefaultTargets);
        $this->assertContains($createDefault.'::withValidator', $createDefaultTargets);
        $this->assertArrayNotHasKey('metadata', $this->graphNode($array, $createDefault));

        $validationRules = $namespace.'ExternalValidationRulesRequest';
        $validationRulesTargets = $targetsFor($validationRules);
        $this->assertNotContains($validationRules.'::rules', $validationRulesTargets);
        $this->assertContains($validationRules.'::validationData', $validationRulesTargets);
        $this->assertArrayNotHasKey('metadata', $this->graphNode($array, $validationRules));

        $warnings = array_values(array_filter(
            $array['meta']['warnings'] ?? [],
            static fn (array $warning): bool => ($warning['inference'] ?? null) === 'unindexed_parent_override'
        ));
        $warningMethods = array_column($warnings, 'method');
        sort($warningMethods);

        $this->assertSame([
            'createDefaultValidator',
            'getValidatorInstance',
            'passesAuthorization',
            'validateResolved',
            'validationRules',
        ], $warningMethods);
    }

    public function test_private_hooks_stop_lifecycle_claims_at_the_runtime_failure_point(): void
    {
        file_put_contents($this->fixturePath.'/app/Http/Requests/PrivateLifecycleRequests.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedRequests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

abstract class PrivateValidatorBaseRequest extends FormRequest
{
    private function validator(): Validator
    {
        throw new \RuntimeException('Laravel cannot access this method.');
    }
}

class InheritedPrivateValidatorRequest extends PrivateValidatorBaseRequest
{
    public function rules(): array
    {
        return ['not_reached' => 'required'];
    }

    public function withValidator(Validator $validator): void
    {
    }
}

class DirectPrivateRulesRequest extends FormRequest
{
    private function rules(): array
    {
        return ['not_reached' => 'required'];
    }

    public function withValidator(Validator $validator): void
    {
    }
}

class PrivateWithValidatorRequest extends FormRequest
{
    public function rules(): array
    {
        return ['reached' => 'required'];
    }

    private function withValidator(Validator $validator): void
    {
    }

    public function after(): array
    {
        return [];
    }

    protected function passedValidation(): void
    {
    }
}
PHP);

        $graph = new Graph();
        (new FormRequestScanner(new FileFinder($this->fixturePath)))->scan($graph);
        $array = $graph->toArray();
        $namespace = 'AppGraph\Tests\GeneratedRequests\\';
        $targetsFor = static fn (string $request): array => array_column(array_values(array_filter(
            $array['edges'],
            static fn (array $edge): bool => $edge['from'] === $request
                && $edge['type'] === 'framework_invokes'
        )), 'to');

        $inherited = $namespace.'InheritedPrivateValidatorRequest';
        $this->assertNotContains($inherited.'::rules', $targetsFor($inherited));
        $this->assertNotContains($inherited.'::withValidator', $targetsFor($inherited));
        $this->assertArrayNotHasKey('metadata', $this->graphNode($array, $inherited));

        $directRules = $namespace.'DirectPrivateRulesRequest';
        $this->assertNotContains($directRules.'::rules', $targetsFor($directRules));
        $this->assertNotContains($directRules.'::withValidator', $targetsFor($directRules));
        $this->assertArrayNotHasKey('metadata', $this->graphNode($array, $directRules));

        $privateWithValidator = $namespace.'PrivateWithValidatorRequest';
        $privateWithValidatorTargets = $targetsFor($privateWithValidator);
        $this->assertContains($privateWithValidator.'::rules', $privateWithValidatorTargets);
        $this->assertNotContains($privateWithValidator.'::withValidator', $privateWithValidatorTargets);
        $this->assertNotContains($privateWithValidator.'::after', $privateWithValidatorTargets);
        $this->assertNotContains($privateWithValidator.'::passedValidation', $privateWithValidatorTargets);

        $warnings = array_values(array_filter(
            $array['meta']['warnings'] ?? [],
            static fn (array $warning): bool => ($warning['inference'] ?? null) === 'private_lifecycle_hook'
                && in_array($warning['class'] ?? null, [$inherited, $directRules, $privateWithValidator], true)
        ));

        $this->assertCount(3, $warnings);
        $warningMethods = array_values(array_unique(array_column($warnings, 'method')));
        sort($warningMethods);
        $this->assertSame(['rules', 'validator', 'withValidator'], $warningMethods);
        $this->assertSame([true], array_values(array_unique(array_column($warnings, 'terminal'))));
    }

    public function test_trait_adaptations_select_alias_and_visibility_without_false_edges(): void
    {
        file_put_contents($this->fixturePath.'/app/Http/Requests/AdaptedTraitRequests.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedRequests;

use Illuminate\Foundation\Http\FormRequest;

trait FirstRules
{
    public function rules(): array
    {
        return ['first' => 'required'];
    }
}

trait SelectedRules
{
    public function rules(): array
    {
        return ['selected' => 'required'];
    }
}

trait NamedLifecycle
{
    public function replacementLifecycle(): void
    {
    }
}

trait HiddenRules
{
    public function rules(): array
    {
        return ['hidden' => 'required'];
    }
}

class PrecedenceRequest extends FormRequest
{
    use FirstRules, SelectedRules {
        SelectedRules::rules insteadof FirstRules;
        FirstRules::rules as firstRules;
    }
}

class AliasedLifecycleRequest extends FormRequest
{
    use NamedLifecycle {
        replacementLifecycle as validateResolved;
    }
}

class PrivateAdaptedRulesRequest extends FormRequest
{
    use HiddenRules {
        rules as private;
    }
}
PHP);

        $graph = new Graph();
        (new FormRequestScanner(new FileFinder($this->fixturePath)))->scan($graph);
        $array = $graph->toArray();
        $namespace = 'AppGraph\Tests\GeneratedRequests\\';

        $precedence = $namespace.'PrecedenceRequest';
        $selected = $namespace.'SelectedRules::rules';
        $this->assertGraphHasEdge($array, $precedence, $selected, 'framework_invokes');
        $this->assertNull($this->graphEdge($array, $precedence, $namespace.'FirstRules::rules', 'framework_invokes'));
        $this->assertSame(
            ['selected' => 'required'],
            $this->graphNode($array, $precedence)['metadata']['rules'],
        );

        $aliased = $namespace.'AliasedLifecycleRequest';
        $aliasEdge = $this->graphEdge(
            $array,
            $aliased,
            $namespace.'NamedLifecycle::replacementLifecycle',
            'framework_invokes',
        );
        $this->assertNotNull($aliasEdge);
        $this->assertSame('validateResolved', $aliasEdge['metadata']['hook']);
        $this->assertSame('replacementLifecycle', $aliasEdge['metadata']['declaredMethod']);
        $this->assertSame([$namespace.'NamedLifecycle::replacementLifecycle'], array_column(array_values(array_filter(
            $array['edges'],
            static fn (array $edge): bool => $edge['from'] === $aliased
                && $edge['type'] === 'framework_invokes'
        )), 'to'));

        $private = $namespace.'PrivateAdaptedRulesRequest';
        $this->assertSame([], array_values(array_filter(
            $array['edges'],
            static fn (array $edge): bool => $edge['from'] === $private
                && $edge['type'] === 'framework_invokes'
        )));
        $this->assertNotEmpty(array_filter(
            $array['meta']['warnings'] ?? [],
            static fn (array $warning): bool => ($warning['class'] ?? null) === $private
                && ($warning['method'] ?? null) === 'rules'
                && ($warning['inference'] ?? null) === 'private_lifecycle_hook'
        ));
    }

    public function test_unindexed_traits_are_reflected_when_loaded_and_otherwise_veto_lifecycle_claims(): void
    {
        file_put_contents($this->fixturePath.'/app/Http/Requests/ExternalTraitRequests.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedRequests;

use AppGraph\Tests\Unit\ExternalFormRequestRulesTrait;
use Illuminate\Foundation\Http\FormRequest;
use Vendor\Package\UnloadedLifecycleTrait;

class ReflectedTraitRequest extends FormRequest
{
    use ExternalFormRequestRulesTrait;
}

class UnknownTraitRequest extends FormRequest
{
    use UnloadedLifecycleTrait;

    public function rules(): array
    {
        return ['unsafe_claim' => 'required'];
    }
}
PHP);

        $graph = new Graph();
        (new FormRequestScanner(new FileFinder($this->fixturePath)))->scan($graph);
        $array = $graph->toArray();
        $namespace = 'AppGraph\Tests\GeneratedRequests\\';
        $reflected = $namespace.'ReflectedTraitRequest';

        $this->assertGraphHasEdge(
            $array,
            $reflected,
            ExternalFormRequestRulesTrait::class.'::rules',
            'framework_invokes',
        );

        $unknown = $namespace.'UnknownTraitRequest';
        $this->assertSame([], array_values(array_filter(
            $array['edges'],
            static fn (array $edge): bool => $edge['from'] === $unknown
                && $edge['type'] === 'framework_invokes'
        )));
        $warning = array_values(array_filter(
            $array['meta']['warnings'] ?? [],
            static fn (array $warning): bool => ($warning['class'] ?? null) === $unknown
                && ($warning['inference'] ?? null) === 'unindexed_trait'
        ))[0] ?? null;
        $this->assertNotNull($warning);
        $this->assertSame('validateResolved', $warning['method']);
        $this->assertSame('Vendor\Package\UnloadedLifecycleTrait', $warning['trait']);
        $this->assertTrue($warning['terminal']);
    }

    public function test_php_method_and_trait_adaptation_names_are_case_insensitive_but_declared_spelling_is_preserved(): void
    {
        file_put_contents($this->fixturePath.'/app/Http/Requests/MixedCaseRequests.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedRequests;

use Illuminate\Foundation\Http\FormRequest as FrameworkRequest;

trait FirstMixedRules
{
    public function RULES(): array
    {
        return ['first' => 'required'];
    }
}

trait SelectedMixedRules
{
    public function RuLeS(): array
    {
        return ['selected' => 'required'];
    }
}

trait MixedLifecycleBody
{
    public function ReplacementLifecycle(): void
    {
    }
}

class MixedCaseRequest extends FrameworkRequest
{
    use FirstMixedRules, SelectedMixedRules {
        SelectedMixedRules::rUlEs insteadof FirstMixedRules;
    }

    public function AuThOrIzE(): bool
    {
        return true;
    }
}

class MixedCaseAliasRequest extends FrameworkRequest
{
    use MixedLifecycleBody {
        replacementLIFECYCLE as VaLiDaTeReSoLvEd;
    }
}
PHP);

        $graph = new Graph();
        (new FormRequestScanner(new FileFinder($this->fixturePath)))->scan($graph);
        $array = $graph->toArray();
        $namespace = 'AppGraph\Tests\GeneratedRequests\\';
        $request = $namespace.'MixedCaseRequest';

        $authorize = $this->graphEdge(
            $array,
            $request,
            $request.'::AuThOrIzE',
            'framework_invokes',
        );
        $this->assertNotNull($authorize);
        $this->assertSame('authorize', $authorize['metadata']['hook']);
        $this->assertSame('AuThOrIzE', $authorize['metadata']['declaredMethod']);

        $rules = $this->graphEdge(
            $array,
            $request,
            $namespace.'SelectedMixedRules::RuLeS',
            'framework_invokes',
        );
        $this->assertNotNull($rules);
        $this->assertSame('RuLeS', $rules['metadata']['declaredMethod']);
        $this->assertNull($this->graphEdge(
            $array,
            $request,
            $namespace.'FirstMixedRules::RULES',
            'framework_invokes',
        ));
        $this->assertSame(['selected' => 'required'], $this->graphNode($array, $request)['metadata']['rules']);

        $aliased = $namespace.'MixedCaseAliasRequest';
        $alias = $this->graphEdge(
            $array,
            $aliased,
            $namespace.'MixedLifecycleBody::ReplacementLifecycle',
            'framework_invokes',
        );
        $this->assertNotNull($alias);
        $this->assertSame('validateResolved', $alias['metadata']['hook']);
        $this->assertSame('ReplacementLifecycle', $alias['metadata']['declaredMethod']);
        $this->assertSame('VaLiDaTeReSoLvEd', $alias['metadata']['composedAs']);
    }

    public function test_protected_authorize_and_rules_are_terminal_container_call_hazards(): void
    {
        file_put_contents($this->fixturePath.'/app/Http/Requests/ProtectedContainerCallRequests.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedRequests;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ProtectedAuthorizeRequest extends FormRequest
{
    protected function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['not_reached' => 'required'];
    }
}

class ProtectedRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function rules(): array
    {
        return ['not_reached' => 'required'];
    }

    public function withValidator(Validator $validator): void
    {
    }
}

class ConditionalProtectedRulesRequest extends FormRequest
{
    public function validator(Factory $factory): Validator
    {
        throw new \RuntimeException('The scanner must not invoke hooks.');
    }

    protected function rules(): array
    {
        return ['conditionally_not_callable' => 'required'];
    }

    protected function passedValidation(): void
    {
    }
}
PHP);

        $graph = new Graph();
        (new FormRequestScanner(new FileFinder($this->fixturePath)))->scan($graph);
        $array = $graph->toArray();
        $namespace = 'AppGraph\Tests\GeneratedRequests\\';
        $targetsFor = static fn (string $request): array => array_column(array_values(array_filter(
            $array['edges'],
            static fn (array $edge): bool => $edge['from'] === $request
                && $edge['type'] === 'framework_invokes'
        )), 'to');

        $protectedAuthorize = $namespace.'ProtectedAuthorizeRequest';
        $this->assertSame([], $targetsFor($protectedAuthorize));
        $this->assertArrayNotHasKey('metadata', $this->graphNode($array, $protectedAuthorize));

        $protectedRules = $namespace.'ProtectedRulesRequest';
        $this->assertContains($protectedRules.'::authorize', $targetsFor($protectedRules));
        $this->assertNotContains($protectedRules.'::rules', $targetsFor($protectedRules));
        $this->assertNotContains($protectedRules.'::withValidator', $targetsFor($protectedRules));
        $this->assertArrayNotHasKey('metadata', $this->graphNode($array, $protectedRules));

        $conditional = $namespace.'ConditionalProtectedRulesRequest';
        $this->assertContains($conditional.'::validator', $targetsFor($conditional));
        $this->assertNotContains($conditional.'::rules', $targetsFor($conditional));
        $this->assertNotContains($conditional.'::passedValidation', $targetsFor($conditional));

        $warnings = array_values(array_filter(
            $array['meta']['warnings'] ?? [],
            static fn (array $warning): bool => ($warning['inference'] ?? null) === 'protected_container_call_hook'
        ));
        $this->assertCount(3, $warnings);
        $this->assertEqualsCanonicalizing(
            ['authorize', 'rules', 'rules'],
            array_column($warnings, 'method'),
        );
        $this->assertSame([true], array_values(array_unique(array_column($warnings, 'terminal'))));
    }

    public function test_form_request_parent_detection_never_autoloads_an_unindexed_parent(): void
    {
        file_put_contents($this->fixturePath.'/app/Http/Requests/UnloadedParentRequest.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedRequests;

class UnloadedParentRequest extends \Vendor\Package\UnloadedFormRequestParent
{
    public function rules(): array
    {
        return ['unsafe' => 'required'];
    }
}
PHP);

        $autoloaded = false;
        $loader = static function (string $class) use (&$autoloaded): void {
            if (strcasecmp($class, 'Vendor\Package\UnloadedFormRequestParent') === 0) {
                $autoloaded = true;
            }
        };
        spl_autoload_register($loader);

        try {
            $graph = new Graph();
            (new FormRequestScanner(new FileFinder($this->fixturePath)))->scan($graph);
        } finally {
            spl_autoload_unregister($loader);
        }

        $array = $graph->toArray();
        $request = 'AppGraph\Tests\GeneratedRequests\UnloadedParentRequest';
        $this->assertFalse($autoloaded);
        $this->assertNull($this->graphNode($array, $request));
        $warning = array_values(array_filter(
            $array['meta']['warnings'] ?? [],
            static fn (array $warning): bool => ($warning['class'] ?? null) === $request
                && ($warning['inference'] ?? null) === 'unloaded_form_request_parent'
        ))[0] ?? null;
        $this->assertNotNull($warning);
        $this->assertSame('Vendor\Package\UnloadedFormRequestParent', $warning['parent']);
    }

    public function test_abstract_form_requests_require_concrete_container_binding_evidence_for_lifecycle_edges(): void
    {
        file_put_contents($this->fixturePath.'/app/Http/Requests/AbstractBoundRequests.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedRequests;

use Illuminate\Foundation\Http\FormRequest;

abstract class BoundAbstractRequest extends FormRequest
{
    public function rules(): array
    {
        return ['abstract' => 'required'];
    }
}

class ConcreteBoundRequest extends BoundAbstractRequest
{
    public function rules(): array
    {
        return ['concrete' => 'required'];
    }

    protected function passedValidation(): void
    {
    }
}

abstract class UnboundAbstractRequest extends FormRequest
{
    public function rules(): array
    {
        return ['must_not_execute' => 'required'];
    }
}
PHP);

        $namespace = 'AppGraph\Tests\GeneratedRequests\\';
        $abstract = $namespace.'BoundAbstractRequest';
        $concrete = $namespace.'ConcreteBoundRequest';
        $unbound = $namespace.'UnboundAbstractRequest';
        $container = new Container();
        $container->bind($abstract, $concrete);
        $registry = new ContainerBindingRegistry($container, 'testing', $namespace);
        $graph = new Graph();
        (new FormRequestScanner(new FileFinder($this->fixturePath), $registry))->scan($graph);
        $array = $graph->toArray();

        $this->assertGraphHasEdge($array, $abstract, $concrete, 'resolves_to');
        $rules = $this->graphEdge($array, $abstract, $concrete.'::rules', 'framework_invokes');
        $this->assertNotNull($rules);
        $this->assertSame(1.0, $rules['confidence']);
        $this->assertSame($abstract, $rules['metadata']['requestedRequest']);
        $this->assertSame($concrete, $rules['metadata']['runtimeRequest']);
        $this->assertSame('laravel_class_string_wrapper', $rules['metadata']['containerBinding']['inference']);
        $this->assertGraphHasEdge($array, $abstract, $concrete.'::passedValidation', 'framework_invokes');

        $this->assertSame([], array_values(array_filter(
            $array['edges'],
            static fn (array $edge): bool => $edge['from'] === $unbound
                && $edge['type'] === 'framework_invokes'
        )));
        $warning = array_values(array_filter(
            $array['meta']['warnings'] ?? [],
            static fn (array $warning): bool => ($warning['class'] ?? null) === $unbound
                && ($warning['inference'] ?? null) === 'abstract_form_request_unbound'
        ))[0] ?? null;
        $this->assertNotNull($warning);
    }

    public function test_concrete_form_request_bindings_retarget_lifecycle_and_unknown_or_existing_instance_bindings_suppress_it(): void
    {
        $fixture = $this->fixturePath.'/app/Http/Requests/ConcreteBoundRequests.php';
        file_put_contents($fixture, <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedConcreteRequests;

use Illuminate\Foundation\Http\FormRequest;

class RequestedConcreteRequest extends FormRequest
{
    public function rules(): array
    {
        return ['requested' => 'required'];
    }
}

class RuntimeConcreteRequest extends RequestedConcreteRequest
{
    public function rules(): array
    {
        return ['runtime' => 'required'];
    }

    protected function passedValidation(): void
    {
    }
}

class UnknownConcreteRequest extends FormRequest
{
    public function rules(): array
    {
        return ['unknown' => 'must-not-run'];
    }
}

class ExistingRequestedRequest extends FormRequest
{
    public function rules(): array
    {
        return ['existing-requested' => 'must-not-run'];
    }
}

class ExistingRuntimeRequest extends ExistingRequestedRequest
{
    public function rules(): array
    {
        return ['existing-runtime' => 'required'];
    }
}
PHP);

        require_once $fixture;

        $root = 'AppGraph\\Tests\\GeneratedConcreteRequests\\';
        $requested = $root.'RequestedConcreteRequest';
        $runtime = $root.'RuntimeConcreteRequest';
        $unknown = $root.'UnknownConcreteRequest';
        $existingRequested = $root.'ExistingRequestedRequest';
        $existingRuntime = $root.'ExistingRuntimeRequest';
        $factoryRan = false;
        $container = new Container();
        $container->bind($requested, $runtime);
        $container->bind($unknown, static function () use (&$factoryRan): object {
            $factoryRan = true;

            return new \stdClass();
        });
        $container->instance($existingRequested, new $existingRuntime());

        $registry = new ContainerBindingRegistry($container, 'testing', $root);
        $graph = new Graph();
        (new FormRequestScanner(new FileFinder($this->fixturePath), $registry))->scan($graph);
        $array = $graph->toArray();

        $this->assertFalse($factoryRan);
        $this->assertGraphHasEdge($array, $requested, $runtime, 'resolves_to');
        $runtimeRules = $this->graphEdge($array, $requested, $runtime.'::rules', 'framework_invokes');
        $this->assertNotNull($runtimeRules);
        $this->assertSame($requested, $runtimeRules['metadata']['requestedRequest']);
        $this->assertSame($runtime, $runtimeRules['metadata']['runtimeRequest']);
        $this->assertNull($this->graphEdge($array, $requested, $requested.'::rules', 'framework_invokes'));
        $this->assertGraphHasEdge($array, $requested, $runtime.'::passedValidation', 'framework_invokes');
        $this->assertSame($runtime, $this->graphNode($array, $requested)['metadata']['runtimeRequest']);
        $this->assertSame(['runtime' => 'required'], $this->graphNode($array, $runtime)['metadata']['rules']);

        foreach ([$unknown, $existingRequested] as $suppressed) {
            $this->assertSame([], array_values(array_filter(
                $array['edges'],
                static fn (array $edge): bool => $edge['from'] === $suppressed
                    && $edge['type'] === 'framework_invokes'
            )));
        }

        $this->assertGraphHasEdge($array, $existingRequested, $existingRuntime, 'resolves_to');
        $warningReasons = array_column($array['meta']['warnings'] ?? [], 'inference');
        $this->assertContains('form_request_binding_unknown', $warningReasons);
        $this->assertContains('form_request_existing_instance_bypasses_lifecycle', $warningReasons);
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

abstract class ExternalValidateResolvedFormRequest extends LaravelFormRequest
{
    public function validateResolved()
    {
    }
}

abstract class ExternalPassesAuthorizationFormRequest extends LaravelFormRequest
{
    protected function passesAuthorization()
    {
        return true;
    }
}

abstract class ExternalGetValidatorFormRequest extends LaravelFormRequest
{
    protected function getValidatorInstance()
    {
        throw new \RuntimeException('The scanner must not invoke external parent hooks.');
    }
}

abstract class ExternalCreateDefaultValidatorFormRequest extends LaravelFormRequest
{
    protected function createDefaultValidator(\Illuminate\Contracts\Validation\Factory $factory)
    {
        throw new \RuntimeException('The scanner must not invoke external parent hooks.');
    }
}

abstract class ExternalValidationRulesFormRequest extends LaravelFormRequest
{
    protected function validationRules()
    {
        return ['replacement' => 'required'];
    }
}

trait ExternalFormRequestRulesTrait
{
    public function rules(): array
    {
        throw new \RuntimeException('The scanner must not invoke external trait hooks.');
    }
}
