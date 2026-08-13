<?php

namespace TwigPlus\Metadata\Tests;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use TwigPlus\Metadata\MetadataGenerator;

final class MetadataGeneratorTest extends TestCase
{
    public function testGeneratesTypedGlobalsCallablesAndMembers()
    {
        $twig = new Environment(new ArrayLoader(array()));
        $twig->addGlobal('catalog', new FixtureCatalog());
        $twig->addFunction(new TwigFunction('make_catalog', function (): FixtureCatalog { return new FixtureCatalog(); }));
        $twig->addFilter(new TwigFilter('fixture_name', function ($value): string { return (string) $value; }));

        $metadata = (new MetadataGenerator())->generate($twig, dirname(__DIR__));
        $global = $this->find($metadata['symbols']['globals'], 'catalog');
        $this->assertSame(FixtureCatalog::class, $global['type']);
        $this->assertSame(FixtureCatalog::class, $this->find($metadata['symbols']['functions'], 'make_catalog')['returnType']);
        $this->assertSame('string', $this->find($metadata['symbols']['filters'], 'fixture_name')['returnType']);
        $members = array_column($metadata['types'][FixtureCatalog::class]['members'], 'name');
        $this->assertContains('navigation', $members);
        $this->assertContains('refresh', $members);
    }

    private function find(array $entries, $name)
    {
        foreach ($entries as $entry) if ($entry['name'] === $name) return $entry;
        $this->fail('Missing metadata entry '.$name);
    }
}

final class FixtureCatalog
{
    public function getNavigation(): FixtureNavigation { return new FixtureNavigation(); }
    public function refresh(): void {}
}

final class FixtureNavigation
{
    public function getHeader(): array { return array(); }
}
