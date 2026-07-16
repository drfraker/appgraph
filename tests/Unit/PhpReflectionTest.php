<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Support\FileFinder;
use AppGraph\Support\PhpReflection;
use AppGraph\Tests\Fixtures\ProgressNoteController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class PhpReflectionTest extends TestCase
{
    public function test_class_and_method_nodes_include_exact_source_end_lines(): void
    {
        $class = new ReflectionClass(ProgressNoteController::class);
        $method = $class->getMethod('update');
        $reflection = new PhpReflection(new FileFinder(dirname(__DIR__, 2)));

        $classNode = $reflection->classNode($class);
        $methodNode = $reflection->methodNode($method);

        $this->assertSame($class->getEndLine(), $classNode->endLine);
        $this->assertSame($class->getEndLine(), $classNode->toArray()['endLine']);
        $this->assertSame($method->getEndLine(), $methodNode->endLine);
        $this->assertSame($method->getEndLine(), $methodNode->toArray()['endLine']);
    }
}
