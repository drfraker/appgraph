<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Support\PhpFileFacts;
use AppGraph\Support\PhpFileFactsParseException;
use Illuminate\Filesystem\Filesystem;
use LogicException;
use PhpParser\ErrorHandler;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PHPUnit\Framework\TestCase;

final class PhpFileFactsCountingParser implements Parser
{
    public int $parseCalls = 0;

    public function __construct(private readonly Parser $parser) {}

    public function parse(string $code, ?ErrorHandler $errorHandler = null): ?array
    {
        $this->parseCalls++;

        return $this->parser->parse($code, $errorHandler);
    }

    public function getTokens(): array
    {
        return $this->parser->getTokens();
    }
}

final class PhpFileFactsThrowingParser implements Parser
{
    public int $parseCalls = 0;

    public function parse(string $code, ?ErrorHandler $errorHandler = null): ?array
    {
        $this->parseCalls++;

        throw new LogicException('Deterministic parser failure.');
    }

    public function getTokens(): array
    {
        return [];
    }
}

class PhpFileFactsTest extends TestCase
{
    private string $temporaryPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryPath = sys_get_temp_dir().'/appgraph-php-file-facts-'.bin2hex(random_bytes(4));
        mkdir($this->temporaryPath, 0775, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->temporaryPath);
        parent::tearDown();
    }

    public function test_it_reuses_an_in_memory_parse_and_resets_only_stats(): void
    {
        $file = $this->writeSource('Memory.php', $this->resolvedNameSource());
        $parser = $this->countingParser();
        $facts = new PhpFileFacts(parser: $parser);

        $first = $facts->statements($file);
        $second = $facts->statements($file);
        $stats = $facts->stats();

        $this->assertSame(1, $parser->parseCalls);
        $this->assertSame('Vendor\Package\BaseThing', $this->classNode($first)->extends?->getAttribute('resolvedName')?->toString());
        $this->assertSame('AppGraph\Fixtures\ChildThing', $this->classNode($second)->namespacedName?->toString());
        $this->assertSame(2, $stats['counters']['requests']);
        $this->assertSame(1, $stats['counters']['misses']);
        $this->assertSame(1, $stats['counters']['parses']);
        $this->assertSame(1, $stats['counters']['memoryHits']);
        $this->assertFalse($stats['persistent']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $stats['identity']);
        $this->assertNull($stats['parser']['targetPhpVersionId']);

        $identity = $stats['identity'];
        $facts->resetStats();
        $reset = $facts->stats();

        $this->assertSame($identity, $reset['identity']);
        $this->assertSame(1, $reset['memoryEntries']);
        $this->assertSame([], array_filter($reset['counters']));
    }

    public function test_it_rehydrates_resolved_names_and_comments_from_a_cross_instance_disk_hit(): void
    {
        $file = $this->writeSource('Persistent.php', $this->resolvedNameSource());
        $cache = $this->temporaryPath.'/cache';
        $cold = new PhpFileFacts($cache);

        $coldStatements = $cold->statements($file);
        $warm = new PhpFileFacts($cache);
        $warmStatements = $warm->statements($file);
        $class = $this->classNode($warmStatements);

        $this->assertSame('AppGraph\Fixtures\ChildThing', $class->namespacedName?->toString());
        $this->assertSame('BaseAlias', $class->extends?->toString());
        $this->assertSame('Vendor\Package\BaseThing', $class->extends?->getAttribute('resolvedName')?->toString());
        $this->assertSame('/** Cached class facts. */', $class->getDocComment()?->getText());
        $this->assertSame($this->classNode($coldStatements)->namespacedName?->toString(), $class->namespacedName?->toString());
        $this->assertSame(1, $cold->stats()['counters']['parses']);
        $this->assertSame(1, $cold->stats()['counters']['diskWrites']);
        $this->assertSame(PhpVersion::getNewestSupported()->id, $cold->stats()['parser']['targetPhpVersionId']);
        $this->assertSame(0, $warm->stats()['counters']['parses']);
        $this->assertSame(1, $warm->stats()['counters']['diskHits']);
        $this->assertCount(1, glob($cache.'/*.json') ?: []);
        $this->assertNoTemporaryCacheFiles($cache);
    }

    public function test_it_caches_invalid_source_as_the_same_deterministic_exception(): void
    {
        $file = $this->writeSource('Broken.php', '<?php function broken( {');
        $cache = $this->temporaryPath.'/errors';
        $cold = new PhpFileFacts($cache);
        $coldError = $this->captureParseException(fn () => $cold->statements($file));
        $warm = new PhpFileFacts($cache);
        $diskError = $this->captureParseException(fn () => $warm->statements($file));
        $memoryError = $this->captureParseException(fn () => $warm->statements($file));

        $this->assertSame($file, $coldError->sourceFile);
        $this->assertSame(hash_file('sha256', $file), $coldError->sourceHash);
        $this->assertSame($coldError->parserErrorClass, $diskError->parserErrorClass);
        $this->assertSame($coldError->rawMessage, $diskError->rawMessage);
        $this->assertSame($coldError->startLine, $diskError->startLine);
        $this->assertSame($coldError->endLine, $diskError->endLine);
        $this->assertSame($coldError->getMessage(), $diskError->getMessage());
        $this->assertSame($diskError->getMessage(), $memoryError->getMessage());
        $this->assertSame(1, $cold->stats()['counters']['parseErrors']);
        $this->assertSame(1, $warm->stats()['counters']['diskHits']);
        $this->assertSame(1, $warm->stats()['counters']['memoryHits']);
        $this->assertSame(2, $warm->stats()['counters']['cachedErrors']);
    }

    public function test_injected_persistent_parsers_require_and_partition_by_a_stable_identity(): void
    {
        $file = $this->writeSource('CustomParser.php', '<?php class CustomParsed {}');
        $cache = $this->temporaryPath.'/custom-parser';

        try {
            new PhpFileFacts($cache, $this->countingParser());
            $this->fail('Expected an injected persistent parser to require an identity.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('stable parser identity', $exception->getMessage());
        }

        $firstParser = $this->countingParser();
        $first = new PhpFileFacts($cache, $firstParser, parserIdentity: 'custom-target-a');
        $first->statements($file);
        $otherParser = $this->countingParser();
        $other = new PhpFileFacts($cache, $otherParser, parserIdentity: 'custom-target-b');
        $other->statements($file);
        $warmParser = $this->countingParser();
        $warm = new PhpFileFacts($cache, $warmParser, parserIdentity: 'custom-target-a');
        $warm->statements($file);

        $this->assertSame(1, $firstParser->parseCalls);
        $this->assertSame(1, $otherParser->parseCalls);
        $this->assertSame(0, $warmParser->parseCalls);
        $this->assertNotSame($first->stats()['identity'], $other->stats()['identity']);
        $this->assertSame(1, $warm->stats()['counters']['diskHits']);
        $this->assertCount(2, glob($cache.'/*.json') ?: []);
    }

    public function test_it_recovers_from_a_corrupt_persistent_entry(): void
    {
        $file = $this->writeSource('Corrupt.php', '<?php namespace AppGraph\Fixtures; class Healthy {}');
        $cache = $this->temporaryPath.'/corrupt';
        (new PhpFileFacts($cache))->statements($file);
        $entry = $this->onlyCacheEntry($cache);
        file_put_contents($entry, '{not-json');

        $recovered = new PhpFileFacts($cache);
        $statements = $recovered->statements($file);
        $verified = new PhpFileFacts($cache);
        $verified->statements($file);

        $this->assertSame('AppGraph\Fixtures\Healthy', $this->classNode($statements)->namespacedName?->toString());
        $this->assertSame(1, $recovered->stats()['counters']['corruptEntries']);
        $this->assertSame(1, $recovered->stats()['counters']['parses']);
        $this->assertSame(1, $recovered->stats()['counters']['diskWrites']);
        $this->assertSame(1, $verified->stats()['counters']['diskHits']);
        $this->assertNoTemporaryCacheFiles($cache);
    }

    public function test_it_rejects_valid_json_when_the_payload_checksum_does_not_match(): void
    {
        $file = $this->writeSource('Poisoned.php', '<?php namespace AppGraph\Fixtures; class Healthy {}');
        $cache = $this->temporaryPath.'/poisoned';
        (new PhpFileFacts($cache))->statements($file);
        $entry = $this->onlyCacheEntry($cache);
        $json = file_get_contents($entry);
        $this->assertIsString($json);
        $poisoned = str_replace('Healthy', 'Corrupt', $json, $replacements);
        $this->assertGreaterThan(0, $replacements);
        $this->assertJson($poisoned);
        file_put_contents($entry, $poisoned);

        $recovered = new PhpFileFacts($cache);
        $statements = $recovered->statements($file);
        $verified = new PhpFileFacts($cache);
        $verified->statements($file);

        $this->assertSame('AppGraph\Fixtures\Healthy', $this->classNode($statements)->namespacedName?->toString());
        $this->assertSame(1, $recovered->stats()['counters']['corruptEntries']);
        $this->assertSame(1, $recovered->stats()['counters']['parses']);
        $this->assertSame(1, $recovered->stats()['counters']['diskWrites']);
        $this->assertSame(1, $verified->stats()['counters']['diskHits']);
        $this->assertNoTemporaryCacheFiles($cache);
    }

    public function test_content_hash_changes_invalidate_without_discarding_other_content_facts(): void
    {
        $firstSource = '<?php class AlphaVersion {}';
        $secondSource = '<?php class BravoVersion {}';
        $this->assertSame(strlen($firstSource), strlen($secondSource));

        $file = $this->writeSource('Changing.php', $firstSource);
        $originalModifiedAt = filemtime($file);
        $originalSize = filesize($file);
        $parser = $this->countingParser();
        $facts = new PhpFileFacts(parser: $parser);

        $facts->statements($file);
        file_put_contents($file, $secondSource);
        touch($file, $originalModifiedAt);
        clearstatcache(true, $file);
        $second = $facts->statements($file);
        file_put_contents($file, $firstSource);
        touch($file, $originalModifiedAt);
        clearstatcache(true, $file);
        $firstAgain = $facts->statements($file);

        $this->assertSame($originalModifiedAt, filemtime($file));
        $this->assertSame($originalSize, filesize($file));
        $this->assertSame('BravoVersion', $this->classNode($second)->name?->toString());
        $this->assertSame('AlphaVersion', $this->classNode($firstAgain)->name?->toString());
        $this->assertSame(2, $parser->parseCalls);
        $this->assertSame(2, $facts->stats()['counters']['contentInvalidations']);
        $this->assertSame(1, $facts->stats()['counters']['memoryHits']);
        $this->assertSame(2, $facts->stats()['memoryEntries']);
    }

    public function test_disk_write_failures_return_parsed_statements_and_clean_temporary_files(): void
    {
        $file = $this->writeSource('WriteFailure.php', '<?php class StillParsed {}');
        $cache = $this->temporaryPath.'/write-failure';
        (new PhpFileFacts($cache))->statements($file);
        $target = $this->onlyCacheEntry($cache);
        unlink($target);
        mkdir($target);

        $facts = new PhpFileFacts($cache);
        $statements = $facts->statements($file);

        $this->assertSame('StillParsed', $this->classNode($statements)->name?->toString());
        $this->assertSame(1, $facts->stats()['counters']['parses']);
        $this->assertSame(1, $facts->stats()['counters']['diskWriteFailures']);
        $this->assertNoTemporaryCacheFiles($cache);
    }

    public function test_non_php_parser_throwables_propagate_and_are_retried(): void
    {
        $file = $this->writeSource('Throwable.php', '<?php class NeverReturned {}');
        $parser = new PhpFileFactsThrowingParser();
        $facts = new PhpFileFacts(parser: $parser);
        $first = $this->captureLogicException(fn () => $facts->statements($file));
        $second = $this->captureLogicException(fn () => $facts->statements($file));

        $this->assertSame('Deterministic parser failure.', $first->getMessage());
        $this->assertSame($first->getMessage(), $second->getMessage());
        $this->assertSame(2, $parser->parseCalls);
        $this->assertSame(0, $facts->stats()['counters']['parseErrors']);
        $this->assertSame(0, $facts->stats()['memoryEntries']);
    }

    public function test_non_utf8_source_remains_analyzable_while_caching_is_bypassed(): void
    {
        $file = $this->writeSource('Bytes.php', "<?php // \xff\nclass ByteSafe {}");
        $cache = $this->temporaryPath.'/bytes';
        $facts = new PhpFileFacts($cache);

        $first = $facts->statements($file);
        $second = $facts->statements($file);

        $this->assertSame('ByteSafe', $this->classNode($first)->name?->toString());
        $this->assertSame('ByteSafe', $this->classNode($second)->name?->toString());
        $this->assertSame(2, $facts->stats()['counters']['parses']);
        $this->assertSame(0, $facts->stats()['counters']['memoryHits']);
        $this->assertSame(2, $facts->stats()['counters']['serializationBypasses']);
        $this->assertSame(0, $facts->stats()['counters']['diskWriteFailures']);
        $this->assertSame([], glob($cache.'/*.json') ?: []);
        $this->assertNoTemporaryCacheFiles($cache);
    }

    private function writeSource(string $name, string $source): string
    {
        $path = $this->temporaryPath.'/'.$name;
        file_put_contents($path, $source);

        return $path;
    }

    private function countingParser(): PhpFileFactsCountingParser
    {
        return new PhpFileFactsCountingParser((new ParserFactory())->createForNewestSupportedVersion());
    }

    /** @param array<int, Node\Stmt> $statements */
    private function classNode(array $statements): Stmt\Class_
    {
        $class = (new NodeFinder())->findFirstInstanceOf($statements, Stmt\Class_::class);
        $this->assertInstanceOf(Stmt\Class_::class, $class);

        return $class;
    }

    private function captureParseException(callable $callback): PhpFileFactsParseException
    {
        try {
            $callback();
        } catch (PhpFileFactsParseException $exception) {
            return $exception;
        }

        $this->fail('Expected a PhpFileFactsParseException.');
    }

    private function captureLogicException(callable $callback): LogicException
    {
        try {
            $callback();
        } catch (LogicException $exception) {
            return $exception;
        }

        $this->fail('Expected a LogicException.');
    }

    private function onlyCacheEntry(string $cache): string
    {
        $entries = glob($cache.'/*.json') ?: [];
        $this->assertCount(1, $entries);

        return $entries[0];
    }

    private function assertNoTemporaryCacheFiles(string $cache): void
    {
        $this->assertSame([], glob($cache.'/.php-file-facts-*') ?: []);
    }

    private function resolvedNameSource(): string
    {
        return <<<'PHP'
<?php

namespace AppGraph\Fixtures;

use Vendor\Package\BaseThing as BaseAlias;

/** Cached class facts. */
class ChildThing extends BaseAlias {}
PHP;
    }
}
