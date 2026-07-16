<?php

namespace AppGraph\Support;

use Composer\InstalledVersions;
use InvalidArgumentException;
use PhpParser\Error;
use PhpParser\JsonDecoder;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use RuntimeException;
use Throwable;

final class PhpFileFacts
{
    private const CACHE_FORMAT = 'appgraph.php-file-facts.v2';

    private const CACHE_ENVELOPE_FORMAT = 'appgraph.php-file-facts-envelope.v1';

    /** @var array{preserveOriginalNames: bool, replaceNodes: bool} */
    private const DEFAULT_RESOLVER_OPTIONS = [
        'preserveOriginalNames' => true,
        'replaceNodes' => false,
    ];

    /** @var array<string, int> */
    private const EMPTY_COUNTERS = [
        'requests' => 0,
        'memoryHits' => 0,
        'diskHits' => 0,
        'misses' => 0,
        'parses' => 0,
        'parseErrors' => 0,
        'cachedErrors' => 0,
        'contentInvalidations' => 0,
        'corruptEntries' => 0,
        'serializationBypasses' => 0,
        'diskWrites' => 0,
        'diskReadFailures' => 0,
        'diskWriteFailures' => 0,
    ];

    private Parser $parser;

    private JsonDecoder $decoder;

    private ?string $cacheDirectory;

    /** @var array{preserveOriginalNames: bool, replaceNodes: bool} */
    private array $resolverOptions;

    /** @var array<string, mixed> */
    private array $identity;

    private string $identityHash;

    /** @var array<string, array<string, mixed>> */
    private array $memory = [];

    /** @var array<string, string> */
    private array $fileKeys = [];

    /** @var array<string, int> */
    private array $counters = self::EMPTY_COUNTERS;

    private SourceFileObservations $sourceObservations;

    /**
     * @param array{preserveOriginalNames?: bool, replaceNodes?: bool} $resolverOptions
     */
    public function __construct(
        ?string $cacheDirectory = null,
        ?Parser $parser = null,
        array $resolverOptions = self::DEFAULT_RESOLVER_OPTIONS,
        ?string $parserIdentity = null,
        ?SourceFileObservations $sourceObservations = null,
    ) {
        $unknownOptions = array_diff(array_keys($resolverOptions), array_keys(self::DEFAULT_RESOLVER_OPTIONS));

        if ($unknownOptions !== []) {
            throw new InvalidArgumentException('Unknown NameResolver option: '.implode(', ', $unknownOptions));
        }

        if ($cacheDirectory !== null && trim($cacheDirectory) === '') {
            throw new InvalidArgumentException('The persistent cache directory cannot be empty.');
        }

        if ($parserIdentity !== null && trim($parserIdentity) === '') {
            throw new InvalidArgumentException('The parser identity cannot be empty.');
        }

        if ($parser !== null && $cacheDirectory !== null && $parserIdentity === null) {
            throw new InvalidArgumentException(
                'A stable parser identity is required when an injected parser uses persistent caching.',
            );
        }

        $parserWasInjected = $parser !== null;
        $this->parser = $parser ?? (new ParserFactory())->createForNewestSupportedVersion();
        $this->decoder = new JsonDecoder();
        $this->cacheDirectory = $cacheDirectory === null
            ? null
            : $this->normalizeDirectory($cacheDirectory);
        $this->resolverOptions = [
            'preserveOriginalNames' => (bool) ($resolverOptions['preserveOriginalNames'] ?? true),
            'replaceNodes' => (bool) ($resolverOptions['replaceNodes'] ?? false),
        ];

        [$parserVersion, $parserReference] = $this->phpParserPackageIdentity();
        $this->identity = [
            'cacheFormat' => self::CACHE_FORMAT,
            'phpVersionId' => PHP_VERSION_ID,
            'parserClass' => $this->parser::class,
            'phpParserVersion' => $parserVersion,
            'phpParserReference' => $parserReference,
            'parserTargetPhpVersionId' => $parserWasInjected
                ? null
                : PhpVersion::getNewestSupported()->id,
            'parserIdentity' => $parserIdentity
                ?? ($parserWasInjected ? 'injected-class:'.$this->parser::class : 'php-parser:newest'),
            'resolver' => $this->resolverOptions,
        ];
        $this->identityHash = hash('sha256', json_encode(
            $this->identity,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
        $this->sourceObservations = $sourceObservations ?? new SourceFileObservations();
    }

    /**
     * Returns a shared, read-only AST. Consumers must not mutate the returned
     * nodes; sharing is what lets multiple scanners reuse one resolved parse.
     *
     * @return array<int, Node\Stmt>
     */
    public function statements(string $file): array
    {
        $this->counters['requests']++;
        $source = @file_get_contents($file);

        if ($source === false) {
            throw new RuntimeException("Unable to read PHP source file [{$file}].");
        }

        $sourceHash = $this->sourceObservations->record($file, $source);
        $key = hash('sha256', $this->identityHash."\0".$sourceHash);
        $fileIdentity = realpath($file) ?: $file;
        $previousKey = $this->fileKeys[$fileIdentity] ?? null;

        if ($previousKey !== null && $previousKey !== $key) {
            $this->counters['contentInvalidations']++;
        }

        $this->fileKeys[$fileIdentity] = $key;

        if (isset($this->memory[$key])) {
            $this->counters['memoryHits']++;

            return $this->materialize($this->memory[$key], $file, $sourceHash, true);
        }

        $entry = $this->readPersistent($key, $sourceHash);

        if ($entry !== null) {
            $this->counters['diskHits']++;
            $this->memory[$key] = $entry;

            return $this->materialize($entry, $file, $sourceHash, true);
        }

        $this->counters['misses']++;
        $this->counters['parses']++;

        try {
            $statements = $this->parser->parse($source) ?? [];
            $traverser = new NodeTraverser(new NameResolver(null, $this->resolverOptions));
            $statements = $traverser->traverse($statements);
            $statements = $this->stripSelfReferentialAttributes($statements);
            $entry = $this->statementEntry($sourceHash, $statements);
        } catch (Error $error) {
            $this->counters['parseErrors']++;
            $entry = $this->errorEntry($sourceHash, $error);
        }

        $encodedEntry = $this->cacheDirectory === null ? null : $this->encodeEntry($entry);

        if ($this->cacheDirectory !== null && $encodedEntry === null) {
            return $this->materialize($entry, $file, $sourceHash, false);
        }

        $this->memory[$key] = $entry;

        if ($encodedEntry !== null) {
            $this->writePersistent($key, $encodedEntry);
        }

        return $this->materialize($entry, $file, $sourceHash, false);
    }

    public function resetStats(): void
    {
        $this->counters = self::EMPTY_COUNTERS;
        $this->sourceObservations->reset();
    }

    /** @return array<string, array<int, string>> */
    public function observedSourceHashes(): array
    {
        return $this->sourceObservations->hashes();
    }

    /** @return array<string, mixed> */
    public function stats(): array
    {
        return [
            'identity' => $this->identityHash,
            'format' => self::CACHE_FORMAT,
            'phpVersionId' => PHP_VERSION_ID,
            'parser' => [
                'class' => $this->identity['parserClass'],
                'version' => $this->identity['phpParserVersion'],
                'reference' => $this->identity['phpParserReference'],
                'targetPhpVersionId' => $this->identity['parserTargetPhpVersionId'],
                'identity' => $this->identity['parserIdentity'],
            ],
            'resolver' => $this->resolverOptions,
            'persistent' => $this->cacheDirectory !== null,
            'memoryEntries' => count($this->memory),
            'counters' => $this->counters,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<int, Node\Stmt>
     */
    private function materialize(array $entry, string $file, string $sourceHash, bool $cached): array
    {
        if (($entry['result'] ?? null) === 'error') {
            if ($cached) {
                $this->counters['cachedErrors']++;
            }

            throw PhpFileFactsParseException::fromRecord(
                $file,
                $sourceHash,
                is_array($entry['error'] ?? null) ? $entry['error'] : [],
            );
        }

        /** @var array<int, Node\Stmt> $statements */
        $statements = $entry['statements'];

        return $statements;
    }

    /**
     * NameResolver may attach a resolvedName attribute that points back to the
     * same Name node. The attribute is redundant and makes otherwise valid ASTs
     * cyclic, so remove only exact self references before JSON persistence.
     *
     * @param array<int, Node\Stmt> $statements
     * @return array<int, Node\Stmt>
     */
    private function stripSelfReferentialAttributes(array $statements): array
    {
        $traverser = new NodeTraverser(new class extends NodeVisitorAbstract
        {
            public function enterNode(Node $node): null
            {
                $attributes = $node->getAttributes();

                foreach ($attributes as $name => $value) {
                    if ($value === $node) {
                        unset($attributes[$name]);
                    }
                }

                $node->setAttributes($attributes);

                return null;
            }
        });

        /** @var array<int, Node\Stmt> $statements */
        return $traverser->traverse($statements);
    }

    /**
     * @param array<int, Node\Stmt> $statements
     * @return array<string, mixed>
     */
    private function statementEntry(string $sourceHash, array $statements): array
    {
        return [
            'format' => self::CACHE_FORMAT,
            'identity' => $this->identityHash,
            'sourceHash' => $sourceHash,
            'result' => 'statements',
            'statements' => $statements,
        ];
    }

    /** @return array<string, mixed> */
    private function errorEntry(string $sourceHash, Error $error): array
    {
        return [
            'format' => self::CACHE_FORMAT,
            'identity' => $this->identityHash,
            'sourceHash' => $sourceHash,
            'result' => 'error',
            'error' => [
                'class' => $error::class,
                'message' => $error->getRawMessage(),
                'startLine' => $error->getStartLine(),
                'endLine' => $error->getEndLine(),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function readPersistent(string $key, string $sourceHash): ?array
    {
        if ($this->cacheDirectory === null) {
            return null;
        }

        $path = $this->cachePath($key);

        if (! is_file($path)) {
            return null;
        }

        $json = @file_get_contents($path);

        if ($json === false) {
            $this->counters['diskReadFailures']++;

            return null;
        }

        try {
            $entry = $this->decoder->decode($this->verifiedPayload($json));

            if (! is_array($entry) || ! $this->validEntry($entry, $sourceHash)) {
                throw new RuntimeException('Invalid PhpFileFacts cache entry.');
            }

            return $entry;
        } catch (Throwable) {
            $this->counters['corruptEntries']++;
            @unlink($path);

            return null;
        }
    }

    /** @param array<string, mixed> $entry */
    private function validEntry(array $entry, string $sourceHash): bool
    {
        if (($entry['format'] ?? null) !== self::CACHE_FORMAT
            || ($entry['identity'] ?? null) !== $this->identityHash
            || ($entry['sourceHash'] ?? null) !== $sourceHash) {
            return false;
        }

        if (($entry['result'] ?? null) === 'error') {
            return is_array($entry['error'] ?? null)
                && is_string($entry['error']['class'] ?? null)
                && is_string($entry['error']['message'] ?? null)
                && is_int($entry['error']['startLine'] ?? null)
                && is_int($entry['error']['endLine'] ?? null);
        }

        if (($entry['result'] ?? null) !== 'statements'
            || ! is_array($entry['statements'] ?? null)
            || ! array_is_list($entry['statements'])) {
            return false;
        }

        foreach ($entry['statements'] as $statement) {
            if (! $statement instanceof Node\Stmt) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $entry */
    private function encodeEntry(array $entry): ?string
    {
        try {
            $payload = json_encode(
                $entry,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );

            return '{"format":"'.self::CACHE_ENVELOPE_FORMAT
                .'","checksum":"'.hash('sha256', $payload)
                .'","payload":'.$payload.'}';
        } catch (Throwable) {
            $this->counters['serializationBypasses']++;

            return null;
        }
    }

    private function verifiedPayload(string $json): string
    {
        $prefix = '{"format":"'.self::CACHE_ENVELOPE_FORMAT.'","checksum":"';
        $separator = '","payload":';

        if (! str_starts_with($json, $prefix) || ! str_ends_with($json, '}')) {
            throw new RuntimeException('Invalid PhpFileFacts cache envelope.');
        }

        $checksumStart = strlen($prefix);
        $separatorPosition = strpos($json, $separator, $checksumStart);

        if ($separatorPosition !== $checksumStart + 64) {
            throw new RuntimeException('Invalid PhpFileFacts cache checksum.');
        }

        $checksum = substr($json, $checksumStart, 64);
        $payload = substr($json, $separatorPosition + strlen($separator), -1);

        if (preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1
            || $payload === ''
            || ! hash_equals($checksum, hash('sha256', $payload))) {
            throw new RuntimeException('Invalid PhpFileFacts cache checksum.');
        }

        return $payload;
    }

    private function writePersistent(string $key, string $json): void
    {
        if ($this->cacheDirectory === null) {
            return;
        }

        if (! $this->ensureCacheDirectory()) {
            $this->counters['diskWriteFailures']++;

            return;
        }

        $temporary = @tempnam($this->cacheDirectory, '.php-file-facts-');

        if (! is_string($temporary)
            || realpath(dirname($temporary)) !== realpath($this->cacheDirectory)) {
            if (is_string($temporary)) {
                @unlink($temporary);
            }
            $this->counters['diskWriteFailures']++;

            return;
        }

        $written = @file_put_contents($temporary, $json, LOCK_EX);

        $target = $this->cachePath($key);

        if ($written !== strlen($json)) {
            @unlink($temporary);
            $this->counters['diskWriteFailures']++;

            return;
        }

        if (! @rename($temporary, $target)) {
            @unlink($temporary);

            // Another process may have won the immutable content-addressed write.
            if (is_file($target)) {
                return;
            }

            $this->counters['diskWriteFailures']++;

            return;
        }

        $this->counters['diskWrites']++;
    }

    private function ensureCacheDirectory(): bool
    {
        if ($this->cacheDirectory === null) {
            return false;
        }

        if (is_dir($this->cacheDirectory)) {
            return true;
        }

        if (file_exists($this->cacheDirectory)) {
            return false;
        }

        return @mkdir($this->cacheDirectory, 0775, true) || is_dir($this->cacheDirectory);
    }

    private function cachePath(string $key): string
    {
        return $this->cacheDirectory.DIRECTORY_SEPARATOR.$key.'.json';
    }

    private function normalizeDirectory(string $directory): string
    {
        $normalized = rtrim($directory, '/\\');

        return $normalized === '' ? DIRECTORY_SEPARATOR : $normalized;
    }

    /** @return array{string, string|null} */
    private function phpParserPackageIdentity(): array
    {
        try {
            if (class_exists(InstalledVersions::class)
                && InstalledVersions::isInstalled('nikic/php-parser')) {
                return [
                    InstalledVersions::getPrettyVersion('nikic/php-parser') ?? 'unknown',
                    InstalledVersions::getReference('nikic/php-parser'),
                ];
            }
        } catch (Throwable) {
            // Package identity still has a deterministic fallback below.
        }

        return ['unknown', null];
    }
}
