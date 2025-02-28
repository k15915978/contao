<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Command;

use Contao\CoreBundle\Doctrine\Backup\BackupManager;
use Contao\CoreBundle\Doctrine\Schema\MysqlInnodbRowSizeCalculator;
use Contao\CoreBundle\Doctrine\Schema\SchemaProvider;
use Contao\CoreBundle\Migration\MigrationCollection;
use Contao\CoreBundle\Migration\MigrationResult;
use Contao\InstallationBundle\Database\Installer;
use Doctrine\DBAL\Schema\Table;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class MigrateCommand extends Command
{
    protected static $defaultName = 'contao:migrate';
    protected static $defaultDescription = 'Executes migrations and updates the database schema.';

    private SymfonyStyle|null $io = null;

    public function __construct(
        private MigrationCollection $migrations,
        private BackupManager $backupManager,
        private SchemaProvider $schemaProvider,
        private MysqlInnodbRowSizeCalculator $rowSizeCalculator,
        private Installer|null $installer = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('with-deletes', null, InputOption::VALUE_NONE, 'Execute all database migrations including DROP queries. Can be used together with --no-interaction.')
            ->addOption('schema-only', null, InputOption::VALUE_NONE, 'Only update the database schema.')
            ->addOption('migrations-only', null, InputOption::VALUE_NONE, 'Only execute the migrations.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show pending migrations and schema updates without executing them.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'The output format (txt, ndjson)', 'txt')
            ->addOption('no-backup', null, InputOption::VALUE_NONE, 'Disable the database backup which is created by default before executing the migrations.')
            ->addOption('hash', null, InputOption::VALUE_REQUIRED, 'A hash value from a --dry-run result')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);

        if (!$input->getOption('dry-run') && !$input->getOption('no-backup')) {
            $this->backup($input);
        }

        if ('ndjson' !== $input->getOption('format')) {
            return $this->executeCommand($input);
        }

        try {
            return $this->executeCommand($input);
        } catch (\Throwable $exception) {
            $this->writeNdjson('error', [
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);
        }

        return 1;
    }

    private function backup(InputInterface $input): void
    {
        $asJson = 'ndjson' === $input->getOption('format');
        $config = $this->backupManager->createCreateConfig();

        if (!$asJson) {
            $this->io->info(sprintf(
                'Creating a database dump to "%s" with the default options. Use --no-backup to disable this feature.',
                $config->getBackup()->getFilename()
            ));
        }

        try {
            $this->backupManager->create($config);

            if ($asJson) {
                $this->writeNdjson('backup-result', $config->getBackup()->toArray());
            }
        } catch (\Throwable $exception) {
            if ($asJson) {
                $this->writeNdjson('error', [
                    'message' => $exception->getMessage(),
                    'code' => $exception->getCode(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'trace' => $exception->getTraceAsString(),
                ]);
            } else {
                $this->io->error($exception->getMessage());
            }
        }
    }

    private function executeCommand(InputInterface $input): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $asJson = 'ndjson' === $input->getOption('format');
        $specifiedHash = $input->getOption('hash');

        if (!\in_array($input->getOption('format'), ['txt', 'ndjson'], true)) {
            throw new InvalidOptionException(sprintf('Unsupported format "%s".', $input->getOption('format')));
        }

        if ($asJson && !$dryRun && $input->isInteractive()) {
            throw new InvalidOptionException('Use --no-interaction or --dry-run together with --format=ndjson');
        }

        if ($input->getOption('migrations-only')) {
            if ($input->getOption('schema-only')) {
                throw new InvalidOptionException('--migrations-only cannot be combined with --schema-only');
            }

            if ($input->getOption('with-deletes')) {
                throw new InvalidOptionException('--migrations-only cannot be combined with --with-deletes');
            }

            return $this->executeMigrations($dryRun, $asJson, $specifiedHash) ? 0 : 1;
        }

        if ($input->getOption('schema-only')) {
            return $this->executeSchemaDiff($dryRun, $asJson, $input->getOption('with-deletes'), $specifiedHash) ? 0 : 1;
        }

        if (!$this->executeMigrations($dryRun, $asJson, $specifiedHash)) {
            return 1;
        }

        if (!$this->executeSchemaDiff($dryRun, $asJson, $input->getOption('with-deletes'), $specifiedHash)) {
            return 1;
        }

        if (!$dryRun && null === $specifiedHash && !$this->executeMigrations($dryRun, $asJson)) {
            return 1;
        }

        if (!$asJson) {
            $this->io->success('All migrations completed.');
        }

        return 0;
    }

    private function executeMigrations(bool &$dryRun, bool $asJson, string $specifiedHash = null): bool
    {
        while (true) {
            $first = true;
            $migrationLabels = [];

            foreach ($this->migrations->getPendingNames() as $migration) {
                if ($first) {
                    if (!$asJson) {
                        $this->io->section('Pending migrations');
                    }

                    $first = false;
                }

                $migrationLabels[] = $migration;

                if (!$asJson) {
                    $this->io->writeln(' * '.$migration);
                }
            }

            $actualHash = hash('sha256', json_encode($migrationLabels));

            if ($asJson) {
                $this->writeNdjson('migration-pending', ['names' => $migrationLabels, 'hash' => $actualHash]);
            }

            if ($first || $dryRun) {
                break;
            }

            if (null !== $specifiedHash && $specifiedHash !== $actualHash) {
                throw new InvalidOptionException(sprintf('Specified hash "%s" does not match the actual hash "%s"', $specifiedHash, $actualHash));
            }

            if (!$asJson) {
                if (!$this->io->confirm('Execute the listed migrations?')) {
                    return false;
                }

                $this->io->section('Execute migrations');
            }

            $count = 0;

            /** @var MigrationResult $result */
            foreach ($this->migrations->run() as $result) {
                ++$count;

                if ($asJson) {
                    $this->writeNdjson('migration-result', [
                        'message' => $result->getMessage(),
                        'isSuccessful' => $result->isSuccessful(),
                    ]);
                } else {
                    $this->io->writeln(' * '.$result->getMessage());

                    if (!$result->isSuccessful()) {
                        $this->io->error('Migration failed');
                    }
                }
            }

            if (!$asJson) {
                $this->io->success('Executed '.$count.' migrations.');
            }

            if (null !== $specifiedHash) {
                // Do not run the schema update after migrations got executed
                // if a hash was specified, because that hash could never match
                // both, migrations and schema updates
                $dryRun = true;

                // Do not run the update recursive if a hash was specified
                break;
            }
        }

        return true;
    }

    private function executeSchemaDiff(bool $dryRun, bool $asJson, bool $withDeletesOption, string $specifiedHash = null): bool
    {
        if (null === $this->installer) {
            $this->io->error('Service "contao_installation.database.installer" not found. The installation bundle needs to be installed in order to execute schema diff migrations.');

            return false;
        }

        if ($schemaWarnings = $this->compileSchemaWarnings()) {
            $this->io->warning(implode("\n\n", $schemaWarnings));

            if (!$this->io->confirm('Continue regardless of the warnings?')) {
                return false;
            }
        }

        $commandsByHash = [];

        while (true) {
            $this->installer->compileCommands();

            $commands = $this->installer->getCommands(false);

            $hasNewCommands = \count(array_filter(
                array_keys($commands),
                static fn ($hash) => !isset($commandsByHash[$hash])
            ));

            $commandsByHash = $commands;
            $actualHash = hash('sha256', json_encode($commands));

            if ($asJson) {
                $this->writeNdjson('schema-pending', [
                    'commands' => array_values($commandsByHash),
                    'hash' => $actualHash,
                ]);
            }

            if (!$hasNewCommands) {
                return true;
            }

            if (!$asJson) {
                $this->io->section("Pending database migrations ($actualHash)");
                $this->io->listing($commandsByHash);
            }

            if ($dryRun) {
                return true;
            }

            if (null !== $specifiedHash && $specifiedHash !== $actualHash) {
                throw new InvalidOptionException(sprintf('Specified hash "%s" does not match the actual hash "%s"', $specifiedHash, $actualHash));
            }

            $options = $withDeletesOption
                ? ['yes, with deletes', 'no']
                : ['yes', 'yes, with deletes', 'no'];

            if ($asJson) {
                $answer = $options[0];
            } else {
                $answer = $this->io->choice('Execute the listed database updates?', $options, $options[0]);
            }

            if ('no' === $answer) {
                return false;
            }

            if (!$asJson) {
                $this->io->section('Execute database migrations');
            }

            $count = 0;
            $commandHashes = $this->getCommandHashes($commands, 'yes, with deletes' === $answer);

            do {
                $commandExecuted = false;
                $exceptions = [];

                foreach ($commandHashes as $key => $hash) {
                    if ($asJson) {
                        $this->writeNdjson('schema-execute', [
                            'command' => $commandsByHash[$hash],
                        ]);
                    } else {
                        $this->io->write(' * '.$commandsByHash[$hash]);
                    }

                    try {
                        $this->installer->execCommand($hash);

                        ++$count;
                        $commandExecuted = true;
                        unset($commandHashes[$key]);

                        if ($asJson) {
                            $this->writeNdjson('schema-result', [
                                'command' => $commandsByHash[$hash],
                                'isSuccessful' => true,
                            ]);
                        } else {
                            $this->io->writeln('');
                        }
                    } catch (\Throwable $e) {
                        $exceptions[] = $e;

                        if ($asJson) {
                            $this->writeNdjson('schema-result', [
                                'command' => $commandsByHash[$hash],
                                'isSuccessful' => false,
                                'message' => $e->getMessage(),
                            ]);
                        } else {
                            $this->io->writeln('......FAILED');
                        }
                    }
                }
            } while ($commandExecuted);

            if (!$asJson) {
                $this->io->success('Executed '.$count.' SQL queries.');

                if (\count($exceptions)) {
                    foreach ($exceptions as $exception) {
                        $this->io->error($exception->getMessage());
                    }
                }
            }

            if (\count($exceptions)) {
                return false;
            }

            // Do not run the update recursive if a hash was specified
            if (null !== $specifiedHash) {
                break;
            }
        }

        return true;
    }

    private function getCommandHashes(array $commands, bool $withDrops): array
    {
        if (!$withDrops) {
            foreach ($commands as $hash => $command) {
                if (
                    preg_match('/^ALTER TABLE [^ ]+ DROP /', (string) $command)
                    || (str_starts_with($command, 'DROP ') && !str_starts_with($command, 'DROP INDEX'))
                ) {
                    unset($commands[$hash]);
                }
            }
        }

        return array_keys($commands);
    }

    private function writeNdjson(string $type, array $data): void
    {
        $this->io->writeln(
            json_encode(
                array_merge(['type' => $type], $data, ['type' => $type]),
                JSON_INVALID_UTF8_SUBSTITUTE
            )
        );

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new \JsonException(json_last_error_msg());
        }
    }

    /**
     * @return array<int,string>
     */
    private function compileSchemaWarnings(): array
    {
        $warnings = [];
        $schema = $this->schemaProvider->createSchema();

        foreach ($schema->getTables() as $table) {
            $warnings = [...$warnings, ...$this->compileTableWarnings($table)];
        }

        return $warnings;
    }

    /**
     * @return array<int,string>
     */
    private function compileTableWarnings(Table $table): array
    {
        $warnings = [];

        if ($table->hasOption('engine') && 'innodb' !== strtolower($table->getOption('engine'))) {
            return $warnings;
        }

        $mysqlSize = $this->rowSizeCalculator->getMysqlRowSize($table);
        $mysqlLimit = $this->rowSizeCalculator->getMysqlRowSizeLimit();
        $innodbSize = $this->rowSizeCalculator->getInnodbRowSize($table);
        $innodbLimit = $this->rowSizeCalculator->getInnodbRowSizeLimit();

        if ($mysqlSize > $mysqlLimit || $innodbSize > $innodbLimit) {
            $warnings[] = "The row size of table {$table->getName()} is too large:\n - MySQL row size: $mysqlSize of $mysqlLimit bytes\n - InnoDB row size: $innodbSize of $innodbLimit bytes";
        }

        return $warnings;
    }
}
