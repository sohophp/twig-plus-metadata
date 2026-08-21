<?php

namespace TwigPlus\Metadata;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use TwigPlus\Metadata\Command\GenerateMetadataCommand;

final class TwigPlusMetadataBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $generator = new Definition(MetadataGenerator::class);
        $generator->setPublic(false);
        $container->setDefinition(MetadataGenerator::class, $generator);

        $command = new Definition(GenerateMetadataCommand::class, array(
            new Reference('twig'),
            new Reference(MetadataGenerator::class),
            '%kernel.project_dir%',
            new Reference('filesystem'),
        ));
        $command->addTag('console.command', array('command' => 'twig-plus:metadata'));
        $container->setDefinition(GenerateMetadataCommand::class, $command);
    }
}
