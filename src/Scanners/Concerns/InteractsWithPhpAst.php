<?php

namespace AppGraph\Scanners\Concerns;

use AppGraph\Graph\Graph;
use AppGraph\Graph\Node as GraphNode;
use AppGraph\Support\PhpFileFacts;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;
use Throwable;

/**
 * Shared AST parsing, name/type resolution, and method-node construction for
 * php-parser based scanners. Consumers must define `private FileFinder $files`
 * and a `private array $classes` index keyed by FQCN with an `extends` entry
 * (used for parent:: resolution). Constructors should call
 * initializePhpFileFacts() with their optional shared fact service.
 */
trait InteractsWithPhpAst
{
    private ?PhpFileFacts $phpFileFacts = null;

    abstract protected function scannerName(): string;

    abstract protected function scannerSourceLabel(): string;

    private function initializePhpFileFacts(?PhpFileFacts $phpFileFacts = null): void
    {
        $this->phpFileFacts = $phpFileFacts ?? new PhpFileFacts();
    }

    /**
     * @return array<int, Node>|null
     */
    private function parseFile(string $file, Graph $graph): ?array
    {
        try {
            return ($this->phpFileFacts ??= new PhpFileFacts())->statements($file);
        } catch (Throwable $throwable) {
            $graph->addWarning([
                'scanner' => $this->scannerName(),
                'file' => $this->files->relativePath($file) ?? $file,
                'message' => $throwable->getMessage(),
                'class' => $throwable::class,
            ]);

            return null;
        }
    }

    /**
     * @return array<string, array{class: string, confidence: float, inference: string}>
     */
    private function parameterTypes(Stmt\ClassMethod $method, string $currentClass): array
    {
        $types = [];

        foreach ($method->params as $param) {
            $this->addParameterTypeToArray($types, $param, $currentClass);
        }

        return $types;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function addParameterType(array &$context, Param $param, string $currentClass): void
    {
        $this->addParameterTypeToArray($context['localTypes'], $param, $currentClass);
    }

    /**
     * @param array<string, array{class: string, confidence: float, inference: string}> $types
     */
    private function addParameterTypeToArray(array &$types, Param $param, string $currentClass): void
    {
        if (! $param->var instanceof Expr\Variable || ! is_string($param->var->name)) {
            return;
        }

        $type = $this->resolveType($param->type, $currentClass);

        if ($type === null) {
            return;
        }

        $types[$param->var->name] = [
            'class' => $type,
            'confidence' => 0.9,
            'inference' => 'typed_parameter',
        ];
    }

    private function resolveType(Node|string|null $type, string $currentClass): ?string
    {
        if ($type instanceof NullableType) {
            return $this->resolveType($type->type, $currentClass);
        }

        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            foreach ($type->types as $innerType) {
                $resolved = $this->resolveType($innerType, $currentClass);

                if ($resolved !== null) {
                    return $resolved;
                }
            }

            return null;
        }

        if ($type instanceof Identifier) {
            return null;
        }

        if ($type instanceof Name) {
            if (in_array(strtolower($type->toString()), ['self', 'static'], true)) {
                return $currentClass;
            }

            if (strtolower($type->toString()) === 'parent') {
                return $this->classes[$currentClass]['extends'] ?? null;
            }

            return $this->resolvedName($type);
        }

        return null;
    }

    private function resolveStaticClass(Node|string $class, string $currentClass): ?string
    {
        if ($class instanceof Name && in_array(strtolower($class->toString()), ['self', 'static'], true)) {
            return $currentClass;
        }

        if ($class instanceof Name && strtolower($class->toString()) === 'parent') {
            return $this->classes[$currentClass]['extends'] ?? null;
        }

        return $this->resolvedName($class);
    }

    private function resolvedName(Node|string|null $name): ?string
    {
        if ($name === null) {
            return null;
        }

        if (is_string($name)) {
            return $name;
        }

        if ($name instanceof Name) {
            $resolvedName = $name->getAttribute('resolvedName');

            if ($resolvedName instanceof Name) {
                return $resolvedName->toString();
            }

            return ltrim($name->toString(), '\\');
        }

        return null;
    }

    private function className(Stmt\Class_|Stmt\Trait_ $class): ?string
    {
        $namespacedName = $class->namespacedName ?? null;

        if ($namespacedName instanceof Name) {
            return $namespacedName->toString();
        }

        return $class->name?->toString();
    }

    /**
     * @param array<string, mixed> $record
     */
    private function methodNode(string $class, string $method, array $record): GraphNode
    {
        return GraphNode::make($class.'::'.$method, 'method', class_basename($class).'::'.$method, [
            'namespace' => $this->namespaceFromClass($class),
            'class' => class_basename($class),
            'method' => $method,
            'file' => $record['file'],
            'line' => $record['line'],
            'endLine' => $record['endLine'] ?? null,
            'signature' => $record['signature'],
            'inputs' => $record['inputs'],
            'outputs' => $record['outputs'],
            'metadata' => [
                'visibility' => $record['visibility'],
                'static' => $record['static'],
                'source' => $this->scannerSourceLabel(),
            ],
        ]);
    }

    private function namespaceFromClass(string $class): ?string
    {
        if (! str_contains($class, '\\')) {
            return null;
        }

        return substr($class, 0, (int) strrpos($class, '\\'));
    }

    private function methodSignature(Stmt\ClassMethod $method): string
    {
        $parameters = array_map(fn (Param $param): string => $this->parameterSignature($param), $method->params);
        $signature = $method->name->toString().'('.implode(', ', $parameters).')';
        $returnType = $this->displayType($method->returnType);

        if ($returnType !== null) {
            $signature .= ': '.$returnType;
        }

        return $signature;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function methodInputs(Stmt\ClassMethod $method): array
    {
        return array_map(function (Param $param): array {
            return [
                'name' => $param->var instanceof Expr\Variable && is_string($param->var->name) ? $param->var->name : null,
                'type' => $this->fullType($param->type),
                'allowsNull' => $param->type instanceof NullableType,
                'optional' => $param->default !== null,
                'variadic' => $param->variadic,
                'byReference' => $param->byRef,
            ];
        }, $method->params);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function methodOutputs(Stmt\ClassMethod $method): array
    {
        if ($method->returnType === null) {
            return [];
        }

        return [[
            'type' => $this->fullType($method->returnType),
            'allowsNull' => $method->returnType instanceof NullableType,
        ]];
    }

    private function parameterSignature(Param $param): string
    {
        $signature = '';
        $type = $this->displayType($param->type);

        if ($type !== null) {
            $signature .= $type.' ';
        }

        if ($param->byRef) {
            $signature .= '&';
        }

        if ($param->variadic) {
            $signature .= '...';
        }

        $name = $param->var instanceof Expr\Variable && is_string($param->var->name) ? $param->var->name : 'parameter';

        return $signature.'$'.$name;
    }

    private function displayType(Node|string|null $type): ?string
    {
        $type = $type instanceof NullableType ? $type->type : $type;

        if ($type instanceof Identifier) {
            return $type->toString();
        }

        if ($type instanceof Name) {
            return class_basename($type->toString());
        }

        if ($type instanceof Node\UnionType) {
            return implode('|', array_filter(array_map(fn (Node $inner): ?string => $this->displayType($inner), $type->types)));
        }

        if ($type instanceof Node\IntersectionType) {
            return implode('&', array_filter(array_map(fn (Node $inner): ?string => $this->displayType($inner), $type->types)));
        }

        return null;
    }

    private function fullType(Node|string|null $type): ?string
    {
        if ($type instanceof NullableType) {
            $inner = $this->fullType($type->type);

            return $inner === null ? null : '?'.$inner;
        }

        if ($type instanceof Identifier) {
            return $type->toString();
        }

        if ($type instanceof Name) {
            return $this->resolvedName($type);
        }

        if ($type instanceof Node\UnionType) {
            return implode('|', array_filter(array_map(fn (Node $inner): ?string => $this->fullType($inner), $type->types)));
        }

        if ($type instanceof Node\IntersectionType) {
            return implode('&', array_filter(array_map(fn (Node $inner): ?string => $this->fullType($inner), $type->types)));
        }

        return null;
    }

    private function methodVisibility(Stmt\ClassMethod $method): string
    {
        return match (true) {
            $method->isPublic() => 'public',
            $method->isProtected() => 'protected',
            default => 'private',
        };
    }
}
