<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\Graph;
use AppGraph\Graph\Node;
use AppGraph\Scanners\ModelScanner;
use AppGraph\Support\FileFinder;
use AppGraph\Support\PhpFileFacts;
use AppGraph\Tests\TestCase;
use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;

class ModelScannerTest extends TestCase
{
    public function test_model_table_edges_are_possible_when_database_scope_is_ambiguous(): void
    {
        file_put_contents($this->fixturePath.'/app/Models/AmbiguousUser.php', <<<'PHP'
<?php

namespace AppGraph\Tests\AmbiguousDatabaseModels;

use Illuminate\Database\Eloquent\Model;

class AmbiguousUser extends Model
{
    protected $table = 'users';
}
PHP);
        $graph = new Graph();
        $graph->addNode(Node::make('table:users', 'table', 'users', [
            'metadata' => [
                'identityAmbiguous' => true,
                'schemaIdentities' => ['central' => [], 'tenant' => []],
            ],
        ]));

        (new ModelScanner(new FileFinder($this->fixturePath), new PhpFileFacts()))->scan($graph);
        $edge = $this->graphEdge(
            $graph->toArray(),
            'AppGraph\Tests\AmbiguousDatabaseModels\AmbiguousUser',
            'table:users',
            'uses_table',
        );

        $this->assertSame(0.5, $edge['confidence']);
        $this->assertSame('possible', $edge['metadata']['matchCertainty']);
        $this->assertSame('database_scope_unresolved', $edge['metadata']['ambiguity']);
        $this->assertSame(2, $edge['metadata']['schemaIdentityCount']);
    }

    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturePath = sys_get_temp_dir().'/appgraph-model-scanner-'.bin2hex(random_bytes(4));
        mkdir($this->fixturePath.'/app/Models', 0775, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->fixturePath);

        parent::tearDown();
    }

    public function test_it_scans_models_and_infers_table_relationships(): void
    {
        file_put_contents($this->fixturePath.'/app/Models/User.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedModels;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'users';
}
PHP);

        file_put_contents($this->fixturePath.'/app/Models/ProgressNote.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedModels;

use Illuminate\Database\Eloquent\Model;

class ProgressNote extends Model
{
    protected $table = 'progress_notes';

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
PHP);

        $files = new FileFinder($this->fixturePath);
        $scanner = new ModelScanner($files, new PhpFileFacts());
        $graph = new Graph();

        $scanner->scan($graph);

        $array = $graph->toArray();
        $modelId = 'AppGraph\Tests\GeneratedModels\ProgressNote';
        $userId = 'AppGraph\Tests\GeneratedModels\User';

        $this->assertGraphHasNode($array, $modelId, 'model');
        $this->assertGraphHasNode($array, 'table:progress_notes', 'table');
        $this->assertGraphHasEdge($array, $modelId, 'table:progress_notes', 'uses_table');
        $this->assertGraphHasEdge($array, $modelId, $userId, 'belongs_to');

        $model = $this->graphNode($array, $modelId);
        $this->assertSame(7, $model['line']);
        $this->assertSame(15, $model['endLine']);

        $edge = $this->graphEdge($array, $modelId, 'table:progress_notes', 'uses_table');

        $this->assertSame(1.0, $edge['confidence']);
        $this->assertSame('explicit_table_property', $edge['metadata']['source']);
    }

    public function test_it_resolves_authenticatable_and_local_model_ancestry_without_loading_classes(): void
    {
        file_put_contents($this->fixturePath.'/app/Models/BaseRecord.php', <<<'PHP'
<?php

namespace AppGraph\Tests\AstModelAncestry;

use Illuminate\Database\Eloquent\Model;

abstract class BaseRecord extends Model
{
    protected $table = 'shared_records';
}
PHP);
        file_put_contents($this->fixturePath.'/app/Models/Entry.php', <<<'PHP'
<?php

namespace AppGraph\Tests\AstModelAncestry;

class Entry extends BaseRecord
{
}
PHP);
        file_put_contents($this->fixturePath.'/app/Models/User.php', <<<'PHP'
<?php

namespace AppGraph\Tests\AstModelAncestry;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
}
PHP);

        $facts = new PhpFileFacts();
        $graph = new Graph();
        (new ModelScanner(new FileFinder($this->fixturePath), $facts))->scan($graph);
        $array = $graph->toArray();

        $this->assertGraphHasNode($array, 'AppGraph\Tests\AstModelAncestry\Entry', 'model');
        $this->assertGraphHasNode($array, 'AppGraph\Tests\AstModelAncestry\User', 'model');
        $this->assertGraphHasNode($array, 'table:shared_records', 'table');
        $this->assertGraphHasNode($array, 'table:users', 'table');
        $this->assertFalse(collect($array['nodes'])->contains(
            static fn (array $node): bool => $node['id'] === 'AppGraph\Tests\AstModelAncestry\BaseRecord',
        ));
        $this->assertFalse(class_exists('AppGraph\Tests\AstModelAncestry\Entry', false));
        $this->assertFalse(class_exists('AppGraph\Tests\AstModelAncestry\User', false));
    }

    public function test_same_process_rescan_uses_edited_source_instead_of_a_loaded_definition(): void
    {
        $path = $this->fixturePath.'/app/Models/Refreshable.php';
        $source = static fn (string $table): string => <<<PHP
<?php

namespace AppGraph\Tests\RefreshableModels;

use Illuminate\Database\Eloquent\Model;

class Refreshable extends Model
{
    protected \$table = '{$table}';
}
PHP;
        file_put_contents($path, $source('before_refresh'));
        $facts = new PhpFileFacts();
        $scanner = new ModelScanner(new FileFinder($this->fixturePath), $facts);
        $before = new Graph();
        $scanner->scan($before);

        file_put_contents($path, $source('after_refresh'));
        $after = new Graph();
        $scanner->scan($after);

        $model = 'AppGraph\Tests\RefreshableModels\Refreshable';
        $this->assertGraphHasEdge($before->toArray(), $model, 'table:before_refresh', 'uses_table');
        $this->assertGraphHasEdge($after->toArray(), $model, 'table:after_refresh', 'uses_table');
        $this->assertFalse(class_exists($model, false));
    }

    public function test_model_scans_record_every_observed_source_hash_for_aba_rejection(): void
    {
        $path = $this->fixturePath.'/app/Models/Observed.php';
        $source = static fn (string $table): string => <<<PHP
<?php

namespace AppGraph\Tests\ObservedModels;

use Illuminate\Database\Eloquent\Model;

class Observed extends Model
{
    protected \$table = '{$table}';
}
PHP;
        $firstSource = $source('observed_a');
        $intermediateSource = $source('observed_b');
        file_put_contents($path, $firstSource);
        $facts = new PhpFileFacts();
        $scanner = new ModelScanner(new FileFinder($this->fixturePath), $facts);
        $scanner->scan(new Graph());
        file_put_contents($path, $intermediateSource);
        $scanner->scan(new Graph());
        file_put_contents($path, $firstSource);
        $scanner->scan(new Graph());

        $observed = $facts->observedSourceHashes();
        $expectedHashes = [
            hash('sha256', $firstSource),
            hash('sha256', $intermediateSource),
        ];
        sort($expectedHashes);

        $this->assertArrayHasKey(str_replace('\\', '/', $path), $observed);
        $this->assertSame($expectedHashes, $observed[str_replace('\\', '/', $path)]);
    }

    public function test_nested_traits_contribute_table_and_relationship_facts_with_precedence_and_aliases(): void
    {
        file_put_contents($this->fixturePath.'/app/Models/TraitRecord.php', <<<'PHP'
<?php

namespace AppGraph\Tests\TraitModelFacts;

use Illuminate\Database\Eloquent\Model;

trait HasTraitTable
{
    protected $table = 'trait_records';
}

trait PrimaryRelationships
{
    public function related()
    {
        return $this->belongsTo(User::class);
    }
}

trait NestedModelFacts
{
    use HasTraitTable;
    use PrimaryRelationships;
}

trait SecondaryRelationships
{
    public function related()
    {
        return $this->hasOne(Profile::class);
    }
}

class TraitRecord extends Model
{
    use NestedModelFacts, SecondaryRelationships {
        NestedModelFacts::related insteadof SecondaryRelationships;
        SecondaryRelationships::related as profile;
    }
}
PHP);

        $graph = new Graph();
        (new ModelScanner(new FileFinder($this->fixturePath), new PhpFileFacts()))->scan($graph);
        $array = $graph->toArray();
        $model = 'AppGraph\Tests\TraitModelFacts\TraitRecord';
        $user = 'AppGraph\Tests\TraitModelFacts\User';
        $profile = 'AppGraph\Tests\TraitModelFacts\Profile';

        $this->assertGraphHasEdge($array, $model, 'table:trait_records', 'uses_table');
        $this->assertGraphHasEdge($array, $model, $user, 'belongs_to');
        $this->assertGraphHasEdge($array, $model, $profile, 'has_one');

        $table = $this->graphEdge($array, $model, 'table:trait_records', 'uses_table');
        $profileRelationship = $this->graphEdge($array, $model, $profile, 'has_one');

        $this->assertSame(1.0, $table['confidence']);
        $this->assertSame('explicit_table_property', $table['metadata']['source']);
        $this->assertSame('AppGraph\Tests\TraitModelFacts\HasTraitTable', $table['metadata']['declaredOn']);
        $this->assertTrue($table['metadata']['viaTrait']);
        $this->assertSame('profile', $profileRelationship['metadata']['relationshipMethod']);
        $this->assertTrue($profileRelationship['metadata']['viaTrait']);
        $this->assertFalse(class_exists($model, false));
        $this->assertFalse(trait_exists('AppGraph\Tests\TraitModelFacts\NestedModelFacts', false));
    }

    public function test_it_does_not_parse_or_execute_composer_parent_source_and_emits_uncertainty(): void
    {
        $packagePath = $this->fixturePath.'/packages/ExternalPackage';
        mkdir($packagePath, 0775, true);
        file_put_contents($packagePath.'/PackageModel.php', <<<'PHP'
<?php

namespace AppGraph\Tests\ExternalPackage;

use Illuminate\Database\Eloquent\Model;

abstract class PackageModel extends Model
{
    protected $table = 'package_records';

    public function owner()
    {
        return $this->belongsTo(Owner::class);
    }
}
PHP);
        file_put_contents($this->fixturePath.'/app/Models/PackageRecord.php', <<<'PHP'
<?php

namespace AppGraph\Tests\PackageModelConsumer;

use AppGraph\Tests\ExternalPackage\PackageModel;

class PackageRecord extends PackageModel
{
}
PHP);

        $loader = new ClassLoader();
        $loader->addPsr4('AppGraph\\Tests\\ExternalPackage\\', $packagePath);
        $loader->register(true);

        try {
            $facts = new PhpFileFacts();
            $graph = new Graph();
            (new ModelScanner(new FileFinder($this->fixturePath), $facts))->scan($graph);
            $array = $graph->toArray();
            $observed = $facts->observedSourceHashes();
        } finally {
            $loader->unregister();
        }

        $model = 'AppGraph\Tests\PackageModelConsumer\PackageRecord';
        $packageModel = 'AppGraph\Tests\ExternalPackage\PackageModel';
        $warnings = array_values(array_filter(
            $array['meta']['warnings'] ?? [],
            static fn (array $warning): bool => ($warning['class'] ?? null) === $model,
        ));

        $this->assertFalse(collect($array['nodes'])->contains(
            static fn (array $node): bool => $node['id'] === $model,
        ));
        $this->assertCount(1, $warnings);
        $this->assertSame('unindexed_model_parent', $warnings[0]['inference']);
        $this->assertSame($packageModel, $warnings[0]['parent']);
        $this->assertArrayNotHasKey(str_replace('\\', '/', $packagePath.'/PackageModel.php'), $observed);
        $this->assertFalse(class_exists($packageModel, false));
        $this->assertFalse(class_exists($model, false));
    }

    public function test_unindexed_external_parent_emits_bounded_ancestry_uncertainty(): void
    {
        file_put_contents($this->fixturePath.'/app/Models/MaybeRecord.php', <<<'PHP'
<?php

namespace AppGraph\Tests\UnknownModelParent;

use AppGraph\Tests\UnavailablePackage\BaseRecord;

class MaybeRecord extends BaseRecord
{
    protected $table = 'maybe_records';
}
PHP);

        $graph = new Graph();
        (new ModelScanner(new FileFinder($this->fixturePath), new PhpFileFacts()))->scan($graph);
        $array = $graph->toArray();
        $model = 'AppGraph\Tests\UnknownModelParent\MaybeRecord';
        $warnings = array_values(array_filter(
            $array['meta']['warnings'] ?? [],
            static fn (array $warning): bool => ($warning['class'] ?? null) === $model,
        ));

        $this->assertFalse(collect($array['nodes'])->contains(
            static fn (array $node): bool => $node['id'] === $model,
        ));
        $this->assertCount(1, $warnings);
        $this->assertSame('unindexed_model_parent', $warnings[0]['inference']);
        $this->assertSame('AppGraph\Tests\UnavailablePackage\BaseRecord', $warnings[0]['parent']);
        $this->assertLessThanOrEqual(512, strlen($warnings[0]['class']));
        $this->assertLessThanOrEqual(512, strlen($warnings[0]['parent']));
    }

    public function test_table_constants_are_resolved_but_dynamic_table_expressions_emit_no_table_fact(): void
    {
        file_put_contents($this->fixturePath.'/app/Models/TableExpressions.php', <<<'PHP'
<?php

namespace AppGraph\Tests\ModelTableExpressions;

use Illuminate\Database\Eloquent\Model;

class ConstantTable extends Model
{
    private const TABLE = 'ledger_entries';

    protected $table = self::TABLE;
}

class DynamicTable extends Model
{
    protected $table = 'dynamic'.'_entries';
}

class EmptyTable extends Model
{
    protected $table = '';
}
PHP);

        $graph = new Graph();
        (new ModelScanner(new FileFinder($this->fixturePath), new PhpFileFacts()))->scan($graph);
        $array = $graph->toArray();
        $constant = 'AppGraph\Tests\ModelTableExpressions\ConstantTable';
        $dynamic = 'AppGraph\Tests\ModelTableExpressions\DynamicTable';
        $empty = 'AppGraph\Tests\ModelTableExpressions\EmptyTable';

        $this->assertGraphHasEdge($array, $constant, 'table:ledger_entries', 'uses_table');
        $constantEdge = $this->graphEdge($array, $constant, 'table:ledger_entries', 'uses_table');
        $this->assertSame(1.0, $constantEdge['confidence']);
        $this->assertSame('explicit_table_property', $constantEdge['metadata']['source']);

        $this->assertGraphHasNode($array, $dynamic, 'model');
        $this->assertFalse(collect($array['edges'])->contains(
            static fn (array $edge): bool => $edge['from'] === $dynamic && $edge['type'] === 'uses_table',
        ));
        $this->assertFalse(collect($array['nodes'])->contains(
            static fn (array $node): bool => in_array($node['id'], ['table:dynamic_tables', 'table:dynamic_entries'], true),
        ));

        $dynamicNode = $this->graphNode($array, $dynamic);
        $this->assertArrayNotHasKey('table', $dynamicNode['metadata']);
        $this->assertSame('unresolved_table_property', $dynamicNode['metadata']['tableInference']['source']);
        $this->assertTrue(collect($array['meta']['warnings'] ?? [])->contains(
            static fn (array $warning): bool => ($warning['class'] ?? null) === $dynamic
                && ($warning['inference'] ?? null) === 'unresolved_model_table',
        ));

        $this->assertGraphHasNode($array, $empty, 'model');
        $this->assertFalse(collect($array['edges'])->contains(
            static fn (array $edge): bool => $edge['from'] === $empty && $edge['type'] === 'uses_table',
        ));
        $this->assertFalse(collect($array['nodes'])->contains(
            static fn (array $node): bool => $node['id'] === 'table:empty_tables',
        ));
        $this->assertTrue(collect($array['meta']['warnings'] ?? [])->contains(
            static fn (array $warning): bool => ($warning['class'] ?? null) === $empty
                && ($warning['reason'] ?? null) === 'explicit_empty_table_property',
        ));
    }

    public function test_application_get_table_overrides_are_literal_or_explicitly_unknown(): void
    {
        file_put_contents($this->fixturePath.'/app/Models/GetTableOverrides.php', <<<'PHP'
<?php

namespace AppGraph\Tests\ModelGetTableOverrides;

use Illuminate\Database\Eloquent\Model;

class LiteralOverride extends Model
{
    public function getTable()
    {
        return 'audit_events';
    }
}

class DynamicOverride extends Model
{
    protected $table = 'incorrect_fallback';

    public function getTable()
    {
        return 'tenant_'.$this->tenantId;
    }
}
PHP);

        $graph = new Graph();
        (new ModelScanner(new FileFinder($this->fixturePath), new PhpFileFacts()))->scan($graph);
        $array = $graph->toArray();
        $literal = 'AppGraph\Tests\ModelGetTableOverrides\LiteralOverride';
        $dynamic = 'AppGraph\Tests\ModelGetTableOverrides\DynamicOverride';

        $this->assertGraphHasEdge($array, $literal, 'table:audit_events', 'uses_table');
        $literalEdge = $this->graphEdge($array, $literal, 'table:audit_events', 'uses_table');
        $this->assertSame(1.0, $literalEdge['confidence']);
        $this->assertSame('explicit_get_table_return', $literalEdge['metadata']['source']);

        $this->assertGraphHasNode($array, $dynamic, 'model');
        $this->assertFalse(collect($array['edges'])->contains(
            static fn (array $edge): bool => $edge['from'] === $dynamic && $edge['type'] === 'uses_table',
        ));
        $this->assertFalse(collect($array['nodes'])->contains(
            static fn (array $node): bool => in_array(
                $node['id'],
                ['table:dynamic_overrides', 'table:incorrect_fallback'],
                true,
            ),
        ));
        $dynamicNode = $this->graphNode($array, $dynamic);
        $this->assertSame(
            'unresolved_get_table_override',
            $dynamicNode['metadata']['tableInference']['source'],
        );
        $this->assertTrue(collect($array['meta']['warnings'] ?? [])->contains(
            static fn (array $warning): bool => ($warning['class'] ?? null) === $dynamic
                && ($warning['reason'] ?? null) === 'dynamic_get_table_override',
        ));
    }

    public function test_inherited_relationship_static_class_is_late_bound_while_self_class_stays_lexical(): void
    {
        file_put_contents($this->fixturePath.'/app/Models/InheritedRelationships.php', <<<'PHP'
<?php

namespace AppGraph\Tests\InheritedModelRelationships;

use Illuminate\Database\Eloquent\Model;

abstract class BaseRecord extends Model
{
    public function lateBoundChildren()
    {
        return $this->hasMany(static::class);
    }

    public function lexicalChildren()
    {
        return $this->hasMany(self::class);
    }
}

class Entry extends BaseRecord
{
}
PHP);

        $graph = new Graph();
        (new ModelScanner(new FileFinder($this->fixturePath), new PhpFileFacts()))->scan($graph);
        $array = $graph->toArray();
        $base = 'AppGraph\Tests\InheritedModelRelationships\BaseRecord';
        $entry = 'AppGraph\Tests\InheritedModelRelationships\Entry';

        $this->assertGraphHasEdge($array, $entry, $entry, 'has_many');
        $this->assertGraphHasEdge($array, $entry, $base, 'has_many');
        $this->assertSame(
            'lateBoundChildren',
            $this->graphEdge($array, $entry, $entry, 'has_many')['metadata']['relationshipMethod'],
        );
        $this->assertSame(
            'lexicalChildren',
            $this->graphEdge($array, $entry, $base, 'has_many')['metadata']['relationshipMethod'],
        );
    }

    public function test_ancestry_warnings_preserve_long_exact_class_and_parent_references(): void
    {
        $namespace = 'AppGraph\\Tests\\'.str_repeat('LongNamespace', 50);
        $parent = 'Vendor\\Package\\'.str_repeat('LongParent', 60).'\\BaseRecord';
        $class = $namespace.'\\MaybeRecord';
        $source = <<<PHP
<?php

namespace {$namespace};

class MaybeRecord extends \\{$parent}
{
    protected \$table = 'maybe_records';
}
PHP;
        file_put_contents($this->fixturePath.'/app/Models/LongAncestry.php', $source);

        $graph = new Graph();
        (new ModelScanner(new FileFinder($this->fixturePath), new PhpFileFacts()))->scan($graph);
        $warnings = array_values(array_filter(
            $graph->toArray()['meta']['warnings'] ?? [],
            static fn (array $warning): bool => ($warning['inference'] ?? null) === 'unindexed_model_parent',
        ));

        $this->assertGreaterThan(512, strlen($class));
        $this->assertGreaterThan(512, strlen($parent));
        $this->assertCount(1, $warnings);
        $this->assertSame($class, $warnings[0]['class']);
        $this->assertSame($parent, $warnings[0]['parent']);
    }

    public function test_ancestry_warning_with_unrepresentable_reference_is_omitted_and_summarized(): void
    {
        $namespace = 'AppGraph\\Tests\\'.str_repeat('N', 4100);
        $source = <<<PHP
<?php

namespace {$namespace};

class MaybeRecord extends \\Vendor\\Package\\BaseRecord
{
    protected \$table = 'maybe_records';
}
PHP;
        file_put_contents($this->fixturePath.'/app/Models/OversizedAncestry.php', $source);

        $graph = new Graph();
        (new ModelScanner(new FileFinder($this->fixturePath), new PhpFileFacts()))->scan($graph);
        $warnings = $graph->toArray()['meta']['warnings'] ?? [];

        $this->assertFalse(collect($warnings)->contains(
            static fn (array $warning): bool => ($warning['inference'] ?? null) === 'unindexed_model_parent',
        ));
        $summary = collect($warnings)->firstWhere('inference', 'unindexed_model_parent_reference_limit');
        $this->assertIsArray($summary);
        $this->assertSame(1, $summary['omitted']);
    }

    public function test_routine_non_model_external_ancestry_does_not_emit_model_uncertainty(): void
    {
        mkdir($this->fixturePath.'/app/Http/Requests', 0775, true);
        file_put_contents($this->fixturePath.'/app/Http/Requests/UpdateEntryRequest.php', <<<'PHP'
<?php

namespace AppGraph\Tests\NonModelExternalParent;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEntryRequest extends FormRequest
{
    public function rules(): array
    {
        return [];
    }
}
PHP);

        $graph = new Graph();
        (new ModelScanner(new FileFinder($this->fixturePath), new PhpFileFacts()))->scan($graph);
        $array = $graph->toArray();
        $class = 'AppGraph\\Tests\\NonModelExternalParent\\UpdateEntryRequest';

        $this->assertFalse(collect($array['nodes'])->contains(
            static fn (array $node): bool => $node['id'] === $class,
        ));
        $this->assertFalse(collect($array['meta']['warnings'] ?? [])->contains(
            static fn (array $warning): bool => ($warning['class'] ?? null) === $class
                && ($warning['scanner'] ?? null) === 'models',
        ));
    }

    public function test_multiple_same_kind_relationships_to_one_target_preserve_every_declaration(): void
    {
        file_put_contents($this->fixturePath.'/app/Models/AggregatedRelationships.php', <<<'PHP'
<?php

namespace AppGraph\Tests\AggregatedModelRelationships;

use Illuminate\Database\Eloquent\Model;

trait HasAuthor
{
    public function author()
    {
        return $this->belongsTo(User::class);
    }
}

class Post extends Model
{
    use HasAuthor;

    public function editor()
    {
        return $this->belongsTo(User::class);
    }
}

class User extends Model
{
}
PHP);

        $graph = new Graph();
        (new ModelScanner(new FileFinder($this->fixturePath), new PhpFileFacts()))->scan($graph);
        $array = $graph->toArray();
        $post = 'AppGraph\Tests\AggregatedModelRelationships\Post';
        $user = 'AppGraph\Tests\AggregatedModelRelationships\User';
        $edge = $this->graphEdge($array, $post, $user, 'belongs_to');

        $this->assertNotNull($edge);
        $this->assertSame(['author', 'editor'], $edge['metadata']['relationshipMethods']);
        $this->assertArrayNotHasKey('relationshipMethod', $edge['metadata']);
        $this->assertArrayNotHasKey('declaredOn', $edge['metadata']);
        $this->assertSame(
            'AppGraph\Tests\AggregatedModelRelationships\HasAuthor',
            $edge['metadata']['relationshipDeclarations'][$post.'::author']['declaredOn'],
        );
        $this->assertTrue(
            $edge['metadata']['relationshipDeclarations'][$post.'::author']['viaTrait'],
        );
        $this->assertSame(
            $post,
            $edge['metadata']['relationshipDeclarations'][$post.'::editor']['declaredOn'],
        );
        $this->assertSame(
            [$post.'::author', $post.'::editor'],
            array_keys($this->graphNode($array, $user)['metadata']['inferredFromRelationships']),
        );
    }

    public function test_it_maps_polymorphic_and_direct_through_relationship_families_conservatively(): void
    {
        file_put_contents($this->fixturePath.'/app/Models/RelationshipCatalog.php', <<<'PHP'
<?php

namespace AppGraph\Tests\ExtendedModelRelationships;

use Illuminate\Database\Eloquent\Model;

class RelationshipCatalog extends Model
{
    public function image()
    {
        return $this->morphOne(Image::class, 'imageable');
    }

    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function taggedPosts()
    {
        return $this->morphedByMany(TaggedPost::class, 'taggable');
    }

    public function owner()
    {
        return $this->hasOneThrough(Owner::class, Account::class);
    }

    public function deployments()
    {
        return $this->hasManyThrough(Deployment::class, Environment::class);
    }

    public function subject()
    {
        return $this->morphTo();
    }
}
PHP);

        $graph = new Graph();
        (new ModelScanner(new FileFinder($this->fixturePath), new PhpFileFacts()))->scan($graph);
        $array = $graph->toArray();
        $model = 'AppGraph\Tests\ExtendedModelRelationships\RelationshipCatalog';
        $namespace = 'AppGraph\Tests\ExtendedModelRelationships\\';

        foreach ([
            ['Image', 'morph_one'],
            ['Comment', 'morph_many'],
            ['Tag', 'morph_to_many'],
            ['TaggedPost', 'morphed_by_many'],
            ['Owner', 'has_one_through'],
            ['Deployment', 'has_many_through'],
        ] as [$target, $edgeType]) {
            $this->assertGraphHasEdge($array, $model, $namespace.$target, $edgeType);
        }

        $hasOneThrough = $this->graphEdge($array, $model, $namespace.'Owner', 'has_one_through');
        $hasManyThrough = $this->graphEdge($array, $model, $namespace.'Deployment', 'has_many_through');
        $this->assertSame($namespace.'Account', $hasOneThrough['metadata']['through']);
        $this->assertSame($namespace.'Environment', $hasManyThrough['metadata']['through']);
        $this->assertGraphHasNode($array, $namespace.'Account', 'model');
        $this->assertGraphHasNode($array, $namespace.'Environment', 'model');

        $unknown = 'unknown:relationship:'.$model.'::subject';
        $this->assertGraphHasNode($array, $unknown, 'unknown');
        $this->assertGraphHasEdge($array, $model, $unknown, 'morph_to');
        $morphTo = $this->graphEdge($array, $model, $unknown, 'morph_to');
        $this->assertSame(0.45, $morphTo['confidence']);
        $this->assertSame('runtime_polymorphic', $morphTo['metadata']['targetResolution']);
        $this->assertSame(['subject'], $morphTo['metadata']['relationshipMethods']);
        $this->assertFalse(collect($array['nodes'])->contains(
            static fn (array $node): bool => $node['id'] === $namespace.'Subject',
        ));
    }

    public function test_it_maps_fluent_through_relationships_only_when_both_methods_are_proven(): void
    {
        file_put_contents($this->fixturePath.'/app/Models/FluentThroughRelationships.php', <<<'PHP'
<?php

namespace AppGraph\Tests\FluentThroughRelationships;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    public function environments()
    {
        return $this->hasMany(Environment::class);
    }

    public function deployments()
    {
        return $this->through('environments')->has('deployments');
    }

    public function unproven()
    {
        return $this->through('missing')->has('deployments');
    }
}

class Environment extends Model
{
    public function deployments()
    {
        return $this->hasMany(Deployment::class);
    }
}

class Deployment extends Model
{
}

class Mechanic extends Model
{
    public function car()
    {
        return $this->hasOne(Car::class);
    }

    public function owner()
    {
        return $this->throughCar()->hasOwner();
    }
}

class Car extends Model
{
    public function owner()
    {
        return $this->hasOne(Owner::class);
    }
}

class Owner extends Model
{
}
PHP);

        $graph = new Graph();
        (new ModelScanner(new FileFinder($this->fixturePath), new PhpFileFacts()))->scan($graph);
        $array = $graph->toArray();
        $namespace = 'AppGraph\Tests\FluentThroughRelationships\\';

        $many = $this->graphEdge(
            $array,
            $namespace.'Project',
            $namespace.'Deployment',
            'has_many_through',
        );
        $this->assertNotNull($many);
        $this->assertSame(['deployments'], $many['metadata']['relationshipMethods']);
        $this->assertSame($namespace.'Environment', $many['metadata']['through']);
        $this->assertSame('environments', $many['metadata']['throughRelationship']);
        $this->assertSame('deployments', $many['metadata']['distantRelationship']);
        $this->assertSame('fluent_through', $many['metadata']['syntax']);

        $one = $this->graphEdge(
            $array,
            $namespace.'Mechanic',
            $namespace.'Owner',
            'has_one_through',
        );
        $this->assertNotNull($one);
        $this->assertSame($namespace.'Car', $one['metadata']['through']);
        $this->assertSame('car', $one['metadata']['throughRelationship']);
        $this->assertSame('owner', $one['metadata']['distantRelationship']);
        $this->assertFalse(in_array(
            'unproven',
            $many['metadata']['relationshipMethods'],
            true,
        ));
    }
}
