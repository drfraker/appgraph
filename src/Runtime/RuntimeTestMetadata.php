<?php

namespace AppGraph\Runtime;

use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use Stringable;
use Throwable;

/**
 * Test-file resolution is adapted from Pest v5.0.2's TIA recorder (MIT),
 * generalized here for ordinary PHPUnit metadata and reflection.
 * See THIRD_PARTY_NOTICES.md.
 */
final class RuntimeTestMetadata
{
    private function __construct(
        private readonly string $id,
        private readonly string $file,
    ) {
    }

    public static function fromStrings(string $id, string $file): ?self
    {
        $id = trim($id);
        $file = trim($file);

        if ($id === '' || $file === '' || str_contains($id, "\0") || str_contains($file, "\0")) {
            return null;
        }

        return new self($id, $file);
    }

    /**
     * Resolve metadata from a PHPUnit TestCase or PHPUnit event test value.
     *
     * Pest's generated __filename property identifies the authored test file
     * and therefore wins over generated-code metadata. Ordinary PHPUnit tests
     * use public event metadata and reflection without Pest-specific coupling.
     */
    public static function fromPhpUnitTest(object $test): ?self
    {
        $className = self::stringFromMethod($test, ['className', 'class']);
        $methodName = self::stringFromMethod($test, ['methodName', 'nameWithDataSet', 'name', 'getName']);
        $id = self::stringFromMethod($test, ['id', 'testId']);

        if ($id === null && $className !== null && $methodName !== null) {
            $id = $className.'::'.$methodName;
        }

        if ($id === null && $methodName !== null) {
            $id = $test::class.'::'.$methodName;
        }

        // Pest-generated test classes expose the authored source file here. It
        // must win over PHPUnit event metadata that may identify generated code.
        $file = $className === null ? null : self::pestFilenameFallback($className);

        if ($file === null) {
            $file = self::pestFilenameFallback($test::class);
        }

        if ($file === null) {
            $file = self::stringFromMethod($test, ['file', 'fileName', 'filename']);
        }

        if ($file === null && $className !== null) {
            $file = self::reflectedFile($className);
        }

        if ($file === null) {
            $file = self::reflectedFile($test::class);
        }

        return $id === null || $file === null ? null : self::fromStrings($id, $file);
    }

    public static function fromPhpUnitEvent(object $event): ?self
    {
        try {
            $test = method_exists($event, 'test') ? $event->test() : null;
        } catch (Throwable) {
            return null;
        }

        return is_object($test) ? self::fromPhpUnitTest($test) : null;
    }

    /**
     * Direct driver capture runs in the PHPUnit worker process. Tests executed
     * in a separate child process must use PHPUnit's own coverage transport or
     * be left untouched, otherwise an empty parent trace could replace useful
     * evidence from an earlier run.
     */
    public static function isProcessIsolatedEvent(object $event): bool
    {
        try {
            $test = method_exists($event, 'test') ? $event->test() : null;

            if (! is_object($test) || ! method_exists($test, 'metadata')) {
                return false;
            }

            $metadata = $test->metadata();

            if (! is_object($metadata)) {
                return false;
            }

            foreach ([
                'isRunInSeparateProcess',
                'isRunClassInSeparateProcess',
                'isRunTestsInSeparateProcesses',
            ] as $selector) {
                if (! method_exists($metadata, $selector)) {
                    continue;
                }

                $selected = $metadata->{$selector}();

                if (is_object($selected)
                    && method_exists($selected, 'isNotEmpty')
                    && $selected->isNotEmpty() === true) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function file(): string
    {
        return $this->file;
    }

    /** @param list<string> $methods */
    private static function stringFromMethod(object $object, array $methods): ?string
    {
        foreach ($methods as $method) {
            if (! method_exists($object, $method)) {
                continue;
            }

            try {
                $value = $object->{$method}();
            } catch (Throwable) {
                continue;
            }

            $string = self::stringValue($value);

            if ($string !== null) {
                return $string;
            }
        }

        return null;
    }

    private static function stringValue(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        if (is_object($value) && method_exists($value, 'asString')) {
            try {
                return self::stringValue($value->asString());
            } catch (Throwable) {
                return null;
            }
        }

        if ($value instanceof Stringable) {
            return self::stringValue((string) $value);
        }

        return null;
    }

    private static function reflectedFile(string $className): ?string
    {
        try {
            $file = (new ReflectionClass($className))->getFileName();
        } catch (ReflectionException) {
            return null;
        }

        return is_string($file) && $file !== '' ? $file : null;
    }

    private static function pestFilenameFallback(string $className): ?string
    {
        try {
            if (! property_exists($className, '__filename')) {
                return null;
            }

            $property = new ReflectionProperty($className, '__filename');

            if (! $property->isStatic()) {
                return null;
            }

            return self::stringValue($property->getValue());
        } catch (Throwable) {
            return null;
        }
    }
}
