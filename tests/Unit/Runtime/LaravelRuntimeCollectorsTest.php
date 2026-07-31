<?php

namespace AppGraph\Tests\Unit\Runtime;

use AppGraph\Runtime\Laravel\InertiaTracker;
use AppGraph\Runtime\Laravel\LaravelRuntimeCollectors;
use AppGraph\Runtime\Laravel\TableExtractor;
use AppGraph\Runtime\RuntimeEvidenceCollector;
use AppGraph\Runtime\RuntimeTestMetadata;
use AppGraph\Runtime\RuntimeTestEvidence;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class LaravelRuntimeCollectorsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/appgraph-laravel-runtime-'.bin2hex(random_bytes(6));
        mkdir($this->directory.'/tests/Feature', 0755, true);
        mkdir($this->directory.'/resources/views/notes', 0755, true);
        file_put_contents($this->directory.'/tests/Feature/NoteTest.php', '<?php final class NoteTest {}');
        file_put_contents($this->directory.'/resources/views/notes/show.blade.php', '<p>Note</p>');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }

            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_table_extractor_handles_qualified_quoted_dml_and_ignores_schema_metadata(): void
    {
        $this->assertSame(
            ['accounts', 'users'],
            TableExtractor::fromSql(
                'SELECT * FROM "public"."Users" JOIN `billing`.`accounts` ON accounts.user_id = users.id',
            ),
        );
        $this->assertSame(['notes'], TableExtractor::fromSql('insert into [dbo].[Notes] (body) values (?)'));
        $this->assertSame(['events'], TableExtractor::fromSql('WITH recent AS (SELECT * FROM events) SELECT * FROM recent'));
        $this->assertSame([], TableExtractor::fromSql('select * from information_schema.tables'));
        $this->assertSame([], TableExtractor::fromSql('create table users (id integer)'));
    }

    public function test_laravel_collectors_are_idempotent_and_attach_links_to_the_active_test(): void
    {
        $collector = new RuntimeEvidenceCollector($this->directory, 'laravel-session');
        $metadata = RuntimeTestMetadata::fromStrings(
            'NoteTest::test_show',
            $this->directory.'/tests/Feature/NoteTest.php',
        );
        $database = new RuntimeFakeDatabase();
        $views = new RuntimeFakeViews();
        $events = new RuntimeFakeEvents();
        $application = new RuntimeFakeApplication([
            'db' => $database,
            'view' => $views,
            'events' => $events,
        ]);

        $this->assertNotNull($metadata);
        $this->assertTrue($collector->beginTest($metadata));

        LaravelRuntimeCollectors::arm($application, $collector);
        LaravelRuntimeCollectors::arm($application, $collector);

        $this->assertCount(1, $database->listeners);
        $this->assertCount(1, $views->composers);
        $this->assertCount(1, $events->listeners['Illuminate\Foundation\Http\Events\RequestHandled']);

        ($database->listeners[0])((object) ['sql' => 'select * from users join roles on roles.id = users.role_id']);
        ($views->composers[0])(new class($this->directory)
        {
            public function __construct(private readonly string $directory)
            {
            }

            public function getPath(): string
            {
                return $this->directory.'/resources/views/notes/show.blade.php';
            }
        });
        ($events->listeners['Illuminate\Foundation\Http\Events\RequestHandled'][0])((object) [
            'response' => RuntimeFakeResponse::inertiaJson('Notes/Show'),
        ]);
        $collector->endTest();

        $test = $collector->snapshot()->tests()[0];

        $this->assertSame(['roles', 'users'], $test->tables());
        $this->assertSame(['resources/views/notes/show.blade.php'], $test->blades());
        $this->assertSame(['Notes/Show'], $test->inertiaComponents());
    }

    public function test_inertia_component_detection_supports_json_and_html_payloads_with_bounds(): void
    {
        $this->assertSame(
            'Notes/Index',
            InertiaTracker::componentFromResponse(RuntimeFakeResponse::inertiaJson('Notes/Index')),
        );
        $this->assertSame(
            'Notes/Edit',
            InertiaTracker::componentFromResponse(new RuntimeFakeResponse(
                '<div id="app" data-page="{&quot;component&quot;:&quot;Notes/Edit&quot;}"></div>',
            )),
        );
        $this->assertNull(InertiaTracker::componentFromResponse(new RuntimeFakeResponse(
            str_repeat('x', 2_097_153),
        )));
    }

    public function test_laravel_listener_failures_never_escape_into_the_test_run(): void
    {
        $collector = new RuntimeEvidenceCollector($this->directory, 'bounded-listeners');
        $metadata = RuntimeTestMetadata::fromStrings(
            'NoteTest::test_listener_bounds',
            $this->directory.'/tests/Feature/NoteTest.php',
        );
        $database = new RuntimeFakeDatabase();
        $views = new RuntimeFakeViews();
        $events = new RuntimeFakeEvents();
        $application = new RuntimeFakeApplication([
            'db' => $database,
            'view' => $views,
            'events' => $events,
        ]);

        $this->assertNotNull($metadata);
        $this->assertTrue($collector->beginTest($metadata));
        LaravelRuntimeCollectors::arm($application, $collector);

        for ($index = 0; $index <= RuntimeTestEvidence::MAX_LINKS_PER_KIND; $index++) {
            ($database->listeners[0])((object) ['sql' => 'select * from runtime_table_'.$index]);
        }

        ($views->composers[0])(new class
        {
            public function getPath(): string
            {
                throw new \RuntimeException('view failed');
            }
        });
        ($events->listeners['Illuminate\Foundation\Http\Events\RequestHandled'][0])((object) [
            'response' => new class
            {
                public function getContent(): string
                {
                    throw new \RuntimeException('response failed');
                }
            },
        ]);
        $collector->endTest();

        $this->assertCount(
            RuntimeTestEvidence::MAX_LINKS_PER_KIND,
            $collector->snapshot()->tests()[0]->tables(),
        );
    }
}

final class RuntimeFakeApplication
{
    /** @param array<string, object> $bindings */
    public function __construct(private array $bindings)
    {
    }

    public function bound(string $key): bool
    {
        return array_key_exists($key, $this->bindings);
    }

    public function make(string $key): object
    {
        return $this->bindings[$key];
    }

    public function instance(string $key, mixed $value): void
    {
        $this->bindings[$key] = (object) ['value' => $value];
    }
}

final class RuntimeFakeDatabase
{
    /** @var list<callable> */
    public array $listeners = [];

    public function listen(callable $listener): void
    {
        $this->listeners[] = $listener;
    }
}

final class RuntimeFakeViews
{
    /** @var list<callable> */
    public array $composers = [];

    public function composer(string $pattern, callable $composer): void
    {
        $this->composers[] = $composer;
    }
}

final class RuntimeFakeEvents
{
    /** @var array<string, list<callable>> */
    public array $listeners = [];

    public function listen(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }
}

final class RuntimeFakeResponse
{
    public object $headers;

    public function __construct(private readonly string $content, bool $inertia = false)
    {
        $this->headers = new class($inertia)
        {
            public function __construct(private readonly bool $inertia)
            {
            }

            public function has(string $header): bool
            {
                return $this->inertia && strtolower($header) === 'x-inertia';
            }
        };
    }

    public static function inertiaJson(string $component): self
    {
        return new self(json_encode(['component' => $component], JSON_THROW_ON_ERROR), true);
    }

    public function getContent(): string
    {
        return $this->content;
    }
}
