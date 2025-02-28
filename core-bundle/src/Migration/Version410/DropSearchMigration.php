<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Migration\Version410;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * @internal
 */
class DropSearchMigration extends AbstractMigration
{
    public function __construct(private Connection $connection)
    {
    }

    public function shouldRun(): bool
    {
        $schemaManager = $this->connection->createSchemaManager();

        if (
            !$schemaManager->tablesExist('tl_search_index')
            || $schemaManager->tablesExist('tl_search_term')
        ) {
            return false;
        }

        $columns = $schemaManager->listTableColumns('tl_search_index');

        if (isset($columns['termid'])) {
            return false;
        }

        return true;
    }

    public function run(): MigrationResult
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS tl_search_index');
        $this->connection->executeStatement('DROP TABLE IF EXISTS tl_search');

        return $this->createResult(true);
    }
}
