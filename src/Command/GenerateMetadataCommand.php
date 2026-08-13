<?php

namespace TwigPlus\Metadata\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Twig\Environment;
use TwigPlus\Metadata\MetadataGenerator;

final class GenerateMetadataCommand extends Command
{
    protected static $defaultName = 'twig-plus:metadata';
    protected static $defaultDescription = 'Generate typed TwigPlus metadata from the real Twig environment.';

    private $twig;
    private $generator;
    private $projectDir;
    private $filesystem;

    public function __construct(Environment $twig, MetadataGenerator $generator, $projectDir, Filesystem $filesystem)
    {
        $this->twig = $twig;
        $this->generator = $generator;
        $this->projectDir = $projectDir;
        $this->filesystem = $filesystem;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('twig-plus:metadata')->setDescription(self::$defaultDescription);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $metadata = $this->generator->generate($this->twig, $this->projectDir);
        $directory = $this->projectDir.'/.twig-plus';
        $file = $directory.'/symfony-metadata.json';
        $this->filesystem->mkdir($directory);
        $this->filesystem->dumpFile($file, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        $output->writeln('<info>Generated '.$file.'</info>');
        $output->writeln(sprintf('%d globals, %d functions, %d filters, %d tests, %d types', count($metadata['symbols']['globals']), count($metadata['symbols']['functions']), count($metadata['symbols']['filters']), count($metadata['symbols']['tests']), count($metadata['types'])));
        return 0;
    }
}
