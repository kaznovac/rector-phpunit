<?php

namespace Rector\PHPUnit\Tests\CodeQuality\Rector\MethodCall\RemoveSetMethodsMethodCallRector\Fixture;

use PHPUnit\Framework\TestCase;

final class KeepUnrelatedSetMethods extends TestCase
{
    public function test()
    {
        $someMock = $this->setMethods(['run']);
    }

    public function setMethods(): array
    {
    }
}
