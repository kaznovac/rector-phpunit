<?php

declare(strict_types=1);

namespace Rector\PHPUnit\CodeQuality\Rector\MethodCall;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\String_;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\ObjectWithoutClassType;
use PHPStan\Type\TypeWithClassName;
use Rector\Core\Rector\AbstractRector;
use Rector\Core\Reflection\ClassReflectionAnalyzer;
use Rector\PHPUnit\NodeAnalyzer\IdentifierManipulator;
use Rector\PHPUnit\NodeAnalyzer\TestsNodeAnalyzer;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * @see \Rector\PHPUnit\Tests\CodeQuality\Rector\MethodCall\AssertIssetToSpecificMethodRector\AssertIssetToSpecificMethodRectorTest
 */
final class AssertIssetToSpecificMethodRector extends AbstractRector
{
    /**
     * @var string
     */
    private const ASSERT_TRUE = 'assertTrue';

    /**
     * @var string
     */
    private const ASSERT_FALSE = 'assertFalse';

    public function __construct(
        private readonly IdentifierManipulator $identifierManipulator,
        private readonly TestsNodeAnalyzer $testsNodeAnalyzer,
        private readonly ClassReflectionAnalyzer $classReflectionAnalyzer
    ) {
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Turns isset comparisons to their method name alternatives in PHPUnit TestCase',
            [
                new CodeSample(
                    '$this->assertTrue(isset($anything->foo));',
                    '$this->assertObjectHasAttribute("foo", $anything);'
                ),
                new CodeSample(
                    '$this->assertFalse(isset($anything["foo"]), "message");',
                    '$this->assertArrayNotHasKey("foo", $anything, "message");'
                ),
            ]
        );
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [MethodCall::class, StaticCall::class];
    }

    /**
     * @param MethodCall|StaticCall $node
     */
    public function refactor(Node $node): ?Node
    {
        if (! $this->testsNodeAnalyzer->isPHPUnitMethodCallNames($node, [self::ASSERT_TRUE, self::ASSERT_FALSE])) {
            return null;
        }

        if ($node->isFirstClassCallable()) {
            return null;
        }

        $firstArgumentValue = $node->getArgs()[0]
->value;
        // is property access
        if (! $firstArgumentValue instanceof Isset_) {
            return null;
        }

        $variableNodeClass = $firstArgumentValue->vars[0]::class;
        if (! in_array($variableNodeClass, [ArrayDimFetch::class, PropertyFetch::class], true)) {
            return null;
        }

        /** @var Isset_ $issetNode */
        $issetNode = $node->getArgs()[0]
->value;

        $issetNodeArg = $issetNode->vars[0];

        if ($issetNodeArg instanceof PropertyFetch) {
            if ($this->hasMagicIsset($issetNodeArg->var)) {
                return null;
            }

            return $this->refactorPropertyFetchNode($node, $issetNodeArg);
        }

        if ($issetNodeArg instanceof ArrayDimFetch) {
            return $this->refactorArrayDimFetchNode($node, $issetNodeArg);
        }

        return $node;
    }

    private function hasMagicIsset(Node $node): bool
    {
        $type = $this->nodeTypeResolver->getType($node);

        if (! $type instanceof TypeWithClassName) {
            // object not found, skip
            return $type instanceof ObjectWithoutClassType;
        }

        $classReflection = $type->getClassReflection();
        if (! $classReflection instanceof ClassReflection) {
            return false;
        }

        if ($classReflection->hasMethod('__isset')) {
            return true;
        }

        if (! $classReflection->isClass()) {
            return false;
        }

        return $this->classReflectionAnalyzer->resolveParentClassName($classReflection) !== null;
    }

    private function refactorPropertyFetchNode(MethodCall|StaticCall $node, PropertyFetch $propertyFetch): ?Node
    {
        $name = $this->getName($propertyFetch);
        if ($name === null) {
            return null;
        }

        $this->identifierManipulator->renameNodeWithMap($node, [
            self::ASSERT_TRUE => 'assertObjectHasAttribute',
            self::ASSERT_FALSE => 'assertObjectNotHasAttribute',
        ]);

        $oldArgs = $node->getArgs();
        unset($oldArgs[0]);

        $newArgs = $this->nodeFactory->createArgs([new String_($name), $propertyFetch->var]);
        $node->args = array_merge($newArgs, $oldArgs);
        return $node;
    }

    private function refactorArrayDimFetchNode(MethodCall|StaticCall $node, ArrayDimFetch $arrayDimFetch): Node
    {
        $this->identifierManipulator->renameNodeWithMap($node, [
            self::ASSERT_TRUE => 'assertArrayHasKey',
            self::ASSERT_FALSE => 'assertArrayNotHasKey',
        ]);

        $oldArgs = $node->getArgs();
        unset($oldArgs[0]);

        $node->args = array_merge($this->nodeFactory->createArgs([$arrayDimFetch->dim, $arrayDimFetch->var]), $oldArgs);
        return $node;
    }
}
