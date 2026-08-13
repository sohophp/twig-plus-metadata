# TwigPlus Metadata

Typed project metadata generator for the [TwigPlus](https://github.com/sohophp/twig-plus) language server. It inspects the real, already-booted Twig environment when the developer explicitly runs the command; the editor itself only reads the generated JSON and never executes project PHP.

## Compatibility

- PHP 7.2 through PHP 8.5
- Twig 2.12 and Twig 3.x
- Symfony 4.4 through Symfony 8.x

The package source uses PHP 7.2 syntax. Runtime feature detection handles newer reflection types, so separate PHP-version branches are unnecessary.

## Installation

```bash
composer require --dev sohophp/twig-plus-metadata
```

Register the bundle when Symfony Flex has not done so:

```php
// config/bundles.php
return [
    TwigPlus\Metadata\TwigPlusMetadataBundle::class => ['dev' => true, 'test' => true],
];
```

Generate metadata:

```bash
bin/console twig-plus:metadata
```

The command writes `.twig-plus/symfony-metadata.json`. Add `/.twig-plus/` to the project `.gitignore` because the file records the absolute project root.

Regenerate after changing Twig extensions, global object APIs, callable return types, or relevant dependencies.

## Exported facts

- Registered globals and their runtime PHP classes
- Registered functions, filters and tests
- Callable signatures and declared return types
- Public methods and properties on related object types
- Twig getter mapping such as `getNavigation()` to `navigation`
- Bounded recursive member types for project namespaces

Dynamic callables remain available by name when reflection cannot prove a signature or type. The generator does not invent member data.
