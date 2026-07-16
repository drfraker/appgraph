<?php

namespace AppGraph\Support;

use AppGraph\Graph\Node;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

class PhpReflection
{
    public function __construct(private FileFinder $files)
    {
    }

    /**
     * @return array<int, string>
     */
    public function classNamesFromFile(string $file): array
    {
        $source = file_get_contents($file);

        if ($source === false) {
            return [];
        }

        $tokens = token_get_all($source);
        $namespace = '';
        $classes = [];

        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if ($this->isToken($token, T_NAMESPACE)) {
                $namespace = $this->collectNamespace($tokens, $i + 1);
                continue;
            }

            if (! $this->isToken($token, T_CLASS)) {
                continue;
            }

            if ($this->previousSignificantTokenIs($tokens, $i, T_DOUBLE_COLON)) {
                continue;
            }

            $class = $this->nextStringToken($tokens, $i + 1);

            if ($class === null) {
                continue;
            }

            $classes[] = ltrim($namespace.'\\'.$class, '\\');
        }

        return $classes;
    }

    public function classNode(ReflectionClass $class, string $type = 'class'): Node
    {
        return Node::make($class->getName(), $type, $class->getShortName(), [
            'namespace' => $class->getNamespaceName() ?: null,
            'class' => $class->getShortName(),
            'file' => $this->files->relativePath($class->getFileName() ?: null),
            'line' => $class->getStartLine(),
            'endLine' => $class->getEndLine(),
            'metadata' => [
                'abstract' => $class->isAbstract(),
            ],
        ]);
    }

    public function methodNode(ReflectionMethod $method, string $type = 'method'): Node
    {
        $class = $method->getDeclaringClass();

        return Node::make($class->getName().'::'.$method->getName(), $type, $class->getShortName().'::'.$method->getName(), [
            'namespace' => $class->getNamespaceName() ?: null,
            'class' => $class->getShortName(),
            'method' => $method->getName(),
            'file' => $this->files->relativePath($method->getFileName() ?: null),
            'line' => $method->getStartLine(),
            'endLine' => $method->getEndLine(),
            'signature' => $this->methodSignature($method),
            'inputs' => $this->methodInputs($method),
            'outputs' => $this->methodOutputs($method),
            'metadata' => [
                'visibility' => $this->methodVisibility($method),
                'static' => $method->isStatic(),
            ],
        ]);
    }

    public function methodSignature(ReflectionMethod $method): string
    {
        $parameters = array_map(
            fn (ReflectionParameter $parameter): string => $this->parameterSignature($parameter),
            $method->getParameters()
        );

        $signature = $method->getName().'('.implode(', ', $parameters).')';
        $returnType = $this->displayTypeToString($method->getReturnType());

        if ($returnType !== null) {
            $signature .= ': '.$returnType;
        }

        return $signature;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function methodInputs(ReflectionMethod $method): array
    {
        return array_map(function (ReflectionParameter $parameter): array {
            return [
                'name' => $parameter->getName(),
                'type' => $this->typeToString($parameter->getType()),
                'allowsNull' => $parameter->allowsNull(),
                'optional' => $parameter->isOptional(),
                'variadic' => $parameter->isVariadic(),
                'byReference' => $parameter->isPassedByReference(),
            ];
        }, $method->getParameters());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function methodOutputs(ReflectionMethod $method): array
    {
        $returnType = $method->getReturnType();

        if ($returnType === null) {
            return [];
        }

        return [[
            'type' => $this->typeToString($returnType),
            'allowsNull' => $returnType->allowsNull(),
        ]];
    }

    public function typeToString(?ReflectionType $type): ?string
    {
        if ($type === null) {
            return null;
        }

        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();

            return ($type->allowsNull() && $name !== 'mixed' && $name !== 'null' ? '?' : '').$name;
        }

        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map(fn (ReflectionType $inner): string => $this->typeToString($inner) ?? 'mixed', $type->getTypes()));
        }

        if ($type instanceof ReflectionIntersectionType) {
            return implode('&', array_map(fn (ReflectionType $inner): string => $this->typeToString($inner) ?? 'mixed', $type->getTypes()));
        }

        return (string) $type;
    }

    private function displayTypeToString(?ReflectionType $type): ?string
    {
        if ($type === null) {
            return null;
        }

        if ($type instanceof ReflectionNamedType) {
            $name = $type->isBuiltin() ? $type->getName() : class_basename($type->getName());

            return ($type->allowsNull() && $name !== 'mixed' && $name !== 'null' ? '?' : '').$name;
        }

        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map(fn (ReflectionType $inner): string => $this->displayTypeToString($inner) ?? 'mixed', $type->getTypes()));
        }

        if ($type instanceof ReflectionIntersectionType) {
            return implode('&', array_map(fn (ReflectionType $inner): string => $this->displayTypeToString($inner) ?? 'mixed', $type->getTypes()));
        }

        return (string) $type;
    }

    private function parameterSignature(ReflectionParameter $parameter): string
    {
        $signature = '';
        $type = $this->displayTypeToString($parameter->getType());

        if ($type !== null) {
            $signature .= $type.' ';
        }

        if ($parameter->isPassedByReference()) {
            $signature .= '&';
        }

        if ($parameter->isVariadic()) {
            $signature .= '...';
        }

        $signature .= '$'.$parameter->getName();

        if ($parameter->isDefaultValueAvailable()) {
            $signature .= ' = '.$this->defaultValueToString($parameter->getDefaultValue());
        }

        return $signature;
    }

    private function defaultValueToString(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => "'".$value."'",
            is_array($value) => '[]',
            default => (string) $value,
        };
    }

    private function methodVisibility(ReflectionMethod $method): string
    {
        return match (true) {
            $method->isPublic() => 'public',
            $method->isProtected() => 'protected',
            default => 'private',
        };
    }

    /**
     * @param array<int, mixed> $tokens
     */
    private function collectNamespace(array $tokens, int $start): string
    {
        $namespace = '';

        for ($i = $start, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === ';' || $token === '{') {
                break;
            }

            if (is_array($token) && in_array($token[0], $this->nameTokenTypes(), true)) {
                $namespace .= $token[1];
            }
        }

        return trim($namespace, '\\');
    }

    /**
     * @param array<int, mixed> $tokens
     */
    private function nextStringToken(array $tokens, int $start): ?string
    {
        for ($i = $start, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '{' || $token === '(' || $token === ';') {
                return null;
            }

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if ($this->isToken($token, T_STRING)) {
                return $token[1];
            }
        }

        return null;
    }

    /**
     * @param array<int, mixed> $tokens
     */
    private function previousSignificantTokenIs(array $tokens, int $index, int $type): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $this->isToken($token, $type);
        }

        return false;
    }

    /**
     * @return array<int, int>
     */
    private function nameTokenTypes(): array
    {
        return array_filter([
            T_STRING,
            T_NS_SEPARATOR,
            defined('T_NAME_QUALIFIED') ? T_NAME_QUALIFIED : null,
            defined('T_NAME_FULLY_QUALIFIED') ? T_NAME_FULLY_QUALIFIED : null,
        ]);
    }

    private function isToken(mixed $token, int $type): bool
    {
        return is_array($token) && $token[0] === $type;
    }
}
