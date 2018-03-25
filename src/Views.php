<?php

/**
 * @package Dbmover
 * @subpackage Core
 */

namespace Dbmover\Core;

/**
 * Plugin to drop and recreate all views.
 */
class Views extends Plugin
{
    /** @var string */
    const DESCRIPTION = 'Dropping existing views...';

    /** @var string */
    const DEFERRED = 'Creating views...';

    /**
     * @param string $sql
     * @return string
     */
    public function __invoke(string $sql) : string
    {
        foreach ($this->extractOperations('@^CREATE\s+VIEW.*?;$@ms', $sql) as $view) {
            $this->defer($view[0]);
        }
        $stmt = $this->loader->getPdo()->prepare(
            "SELECT TABLE_NAME
                FROM INFORMATION_SCHEMA.TABLES
                WHERE ((TABLE_CATALOG = ? AND TABLE_SCHEMA = 'public') OR TABLE_SCHEMA = ?)
                    AND TABLE_TYPE = 'VIEW'"
        );
        $stmt->execute([$this->loader->getDatabase(), $this->loader->getDatabase()]);
        while (false !== ($view = $stmt->fetchColumn())) {
            if (!$this->loader->shouldBeIgnored($view)) {
                $this->addOperation("DROP VIEW IF EXISTS $view;");
            }
        }
        return $sql;
    }
}

