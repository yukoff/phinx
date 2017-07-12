<?php

namespace Phinx\Console\Command;

use Phinx\Util\Util;
use Symfony\Component\Console\Input\InputArgument,
    Symfony\Component\Console\Input\InputInterface,
    Symfony\Component\Console\Output\OutputInterface,
    Symfony\Component\Console\Input\InputOption,
    Symfony\Component\Console\Question\ConfirmationQuestion;

class SchemaLoad extends AbstractCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();

        $this->addOption('--environment', '-e', InputArgument::OPTIONAL, 'The target environment');
        $this->addOption('--destroy', '-d', InputArgument::OPTIONAL, 'Destroy database without asking');

        $this->setName('schema:load')
             ->setDescription('Load schema to the database.')
             ->setHelp(
<<<EOT
The <info>schema:load</info> command will load the initial database schema.

<info>phinx schema:load -e development</info>

EOT
             );

        // Allow the migration path to be chosen non-interactively.
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'Specify the path in which to create this migration');
    }

    /**
     * Get the question that lets the user confirm it wants to destroy the
     * database.
     *
     * @param string $name
     * @return ChoiceQuestion
     */
    protected function getDestroyDatabaseConfirmationQuestion($name)
    {
        return new ConfirmationQuestion(
            'Hey! You must be pretty damn sure that you want to destroy \''.$name.'\'. Are you sure? (y/n) ', false
        );
    }

    /**
     * Get the question that allows the user to select which migration path to use.
     *
     * @param string[] $paths
     * @return ChoiceQuestion
     */
    protected function getSelectMigrationPathQuestion(array $paths)
    {
        return new ChoiceQuestion('Which migrations path would you like to use?', $paths, 0);
    }

    /**
     * Returns the migration path to create the migration in.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return mixed
     * @throws \Exception
     */
    protected function getMigrationPath(InputInterface $input, OutputInterface $output)
    {
        // First, try the non-interactive option:
        $path = $input->getOption('path');

        if (!empty($path)) {
            return $path;
        }

        $paths = $this->getConfig()->getMigrationPaths();

        // No paths? That's a problem.
        if (empty($paths)) {
            throw new \Exception('No migration paths set in your Phinx configuration file.');
        }

        $paths = Util::globAll($paths);

        if (empty($paths)) {
            throw new \Exception(
                'You probably used curly braces to define migration path in your Phinx configuration file, ' .
                'but no directories have been matched using this pattern. ' .
                'You need to create a migration directory manually.'
            );
        }

        // Only one path set, so select that:
        if (1 === count($paths)) {
            return array_shift($paths);
        }

        // Ask the user which of their defined paths they'd like to use:
        $helper = $this->getHelper('question');
        $question = $this->getSelectMigrationPathQuestion($paths);

        return $helper->ask($input, $output, $question);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->bootstrap($input, $output);

        // get the migration path from the config
        $path = $this->getMigrationPath($input, $output).DIRECTORY_SEPARATOR.'schema';

        $environment = $input->getOption('environment');
        $destroy = $input->getOption('destroy');

        if (null === $environment) {
            $environment = $this->getConfig()->getDefaultEnvironment();
            $output->writeln('<comment>warning</comment> no environment specified, defaulting to: ' . $environment);
        } else {
            $output->writeln('<info>using environment</info> ' . $environment);
        }

        $envOptions = $this->getConfig()->getEnvironment($environment);
        $output->writeln('<info>using adapter</info> ' . $envOptions['adapter']);
        $output->writeln('<info>using database</info> ' . $envOptions['name']);

        $schemaName = isset($envOptions['schema_name']) ? $envOptions['schema_name'] : '';

        if ($schemaName) {
            $filePath = $path . DIRECTORY_SEPARATOR . $schemaName . '_schema.php';
        } else {
            $filePath = $path . DIRECTORY_SEPARATOR . 'schema.php';
        }

        if (!file_exists($filePath)) {
            $output->writeln('<comment>Schema file missing. Nothing to load.</comment>');
            return;
        }

        if (null === $destroy) {
            $helper = $this->getHelperSet()->get('question');
            $question = $this->getDestroyDatabaseConfirmationQuestion($envOptions['name']);

            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('Aborting.');
                return;
            }
        }

        $start = microtime(true);
        $this->getManager()->schemaLoad($environment, $filePath);
        $end = microtime(true);

        $output->writeln('');
        $output->writeln('<comment>All Done. Took ' . sprintf('%.4fs', $end - $start) . '</comment>');
    }
}
