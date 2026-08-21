<?php

namespace TwigPlus\Metadata\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use TwigPlus\Metadata\TwigPlusMetadataBundle;

final class TwigPlusMetadataBundleTest extends TestCase
{
    public function testBuildDeclaresVoidReturnType()
    {
        $method = new ReflectionMethod(TwigPlusMetadataBundle::class, 'build');

        $this->assertSame('void', (string) $method->getReturnType());
    }
}
