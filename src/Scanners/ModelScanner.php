<?php

namespace AppGraph\Scanners;

use AppGraph\Graph\Edge;
use AppGraph\Graph\Graph;
use AppGraph\Graph\Node;
use AppGraph\Support\FileFinder;
use AppGraph\Support\PhpReflection;
use AppGraph\Support\TypeResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

class ModelScanner
{
    /**
     * @var array<string, string>
     */
    private array $relationshipEdgeTypes = [
        'belongsTo' => 'belongs_to',
        'hasMany' => 'has_many',
        'hasOne' => 'has_one',
        'belongsToMany' => 'belongs_to_many',
    ];

    public function __construct(
        private FileFinder $files,
        private PhpReflection $reflection,
        private TypeResolver $types,
    ) {
    }

    public function scan(Graph $graph): Graph
    {
        foreach ($this->modelClasses() as $class) {
            try {
                $model = new ReflectionClass($class);
            } catch (ReflectionException) {
                continue;
            }

            if ($model->isAbstract() || ! $model->isSubclassOf(Model::class)) {
                continue;
            }

            $this->addModel($graph, $model);
        }

        return $graph;
    }

    /**
     * @return array<int, string>
     */
    private function modelClasses(): array
    {
        $classes = [];

        foreach ($this->files->findPhpFiles(['app/Models', 'app']) as $file) {
            foreach ($this->reflection->classNamesFromFile($file) as $class) {
                if (! class_exists($class)) {
                    require_once $file;
                }

                if (class_exists($class)) {
                    $classes[$class] = $class;
                }
            }
        }

        ksort($classes);

        return array_values($classes);
    }

    private function addModel(Graph $graph, ReflectionClass $model): void
    {
        [$table, $confidence, $metadata] = $this->inferTableName($model);

        $graph->addNode(Node::make($model->getName(), 'model', $model->getShortName(), [
            'namespace' => $model->getNamespaceName() ?: null,
            'class' => $model->getShortName(),
            'file' => $this->files->relativePath($model->getFileName() ?: null),
            'line' => $model->getStartLine(),
            'metadata' => [
                'table' => $table,
                'tableInference' => $metadata,
            ],
        ]));

        $graph->addNode(Node::make('table:'.$table, 'table', $table, [
            'metadata' => [
                'inferredFromModel' => $model->getName(),
            ],
        ]));

        $graph->addEdge(new Edge($model->getName(), 'table:'.$table, 'uses_table', $confidence, $metadata));

        $this->addRelationshipEdges($graph, $model);
    }

    /**
     * @return array{0: string, 1: float, 2: array<string, mixed>}
     */
    private function inferTableName(ReflectionClass $model): array
    {
        $defaults = $model->getDefaultProperties();
        $table = $defaults['table'] ?? null;

        if (is_string($table) && $table !== '') {
            return [$table, 1.0, [
                'source' => 'explicit_table_property',
            ]];
        }

        return [Str::snake(Str::pluralStudly($model->getShortName())), 0.85, [
            'source' => 'eloquent_table_convention',
            'reason' => 'Model does not define a non-empty $table property.',
        ]];
    }

    private function addRelationshipEdges(Graph $graph, ReflectionClass $model): void
    {
        $file = $model->getFileName();

        if ($file === false) {
            return;
        }

        $namespaceData = $this->types->namespaceAndUses($file);

        foreach ($model->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->getDeclaringClass()->getName() !== $model->getName()) {
                continue;
            }

            $body = $this->methodBody($method);

            if ($body === null) {
                continue;
            }

            foreach ($this->relationshipEdgeTypes as $methodName => $edgeType) {
                if (! preg_match('/->'.$methodName.'\s*\((.*?)\)/s', $body, $matches)) {
                    continue;
                }

                $target = $this->types->resolveClassConstant($matches[1], $namespaceData['namespace'], $namespaceData['uses']);
                $targetId = $target ?: 'unknown:relationship:'.$model->getName().'::'.$method->getName();
                $confidence = $target !== null ? 0.75 : 0.45;

                if ($target !== null) {
                    $graph->addNode(Node::make($target, 'model', class_basename($target), [
                        'namespace' => str_contains($target, '\\') ? substr($target, 0, (int) strrpos($target, '\\')) : null,
                        'class' => class_basename($target),
                        'metadata' => [
                            'inferredFromRelationship' => $model->getName().'::'.$method->getName(),
                        ],
                    ]));
                } else {
                    $graph->addNode(Node::make($targetId, 'unknown', $method->getName(), [
                        'metadata' => [
                            'reason' => 'Relationship target class could not be resolved deterministically.',
                        ],
                    ]));
                }

                $graph->addEdge(new Edge($model->getName(), $targetId, $edgeType, $confidence, [
                    'relationshipMethod' => $method->getName(),
                    'inference' => 'eloquent_relationship_call',
                    'call' => $methodName,
                ]));
            }
        }
    }

    private function methodBody(ReflectionMethod $method): ?string
    {
        $file = $method->getFileName();

        if ($file === false) {
            return null;
        }

        $lines = file($file);

        if ($lines === false) {
            return null;
        }

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }
}
