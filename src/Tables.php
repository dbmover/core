<?php

/**
 * @package Dbmover
 * @subpackage Core
 */

namespace Dbmover\Core;

use PDO;

abstract class Tables extends Plugin
{
    /** @var string */
    const DESCRIPTION = 'Updating schemas...';

    /** @var string */
    const DEFERRED = 'Dropping deprecated tables...';

    /** @var PdoStatement */
    protected $columns;

    /**
     * @param string $sql
     * @return string
     */
    public function __invoke(string $sql) : string
    {
        $tables = [];
        $exists = $this->loader->getPdo()->prepare(
            "SELECT * FROM INFORMATION_SCHEMA.TABLES
                WHERE ((TABLE_CATALOG = ? AND TABLE_SCHEMA = 'public') OR TABLE_SCHEMA = ?)
                AND TABLE_TYPE = 'BASE TABLE'
                AND TABLE_NAME = ?");
        foreach ($this->extractOperations("@^CREATE TABLE\s*([^\s]+)\s*\((.*?)^\).*?;$@ms", $sql) as $table) {
            $tables[] = $table[1];
            // If no such table exists, create verbatim.
            $exists->execute([$this->loader->getDatabase(), $this->loader->getDatabase(), $table[1]]);
            if (false === $exists->fetch(PDO::FETCH_ASSOC)) {
                $this->addOperation($table[0]);
            } else {
                // ...else check if the table needs modifications.
                $this->checkTableStatus($table[1], $table[2]);
            }
        }

        // Drop tables that are no longer needed.
        $exists = $this->loader->getPdo()->prepare(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                WHERE ((TABLE_CATALOG = ? AND TABLE_SCHEMA = 'public') OR TABLE_SCHEMA = ?)
                AND TABLE_TYPE = 'BASE TABLE'");
        $exists->execute([$this->loader->getDatabase(), $this->loader->getDatabase()]);
        while (false !== ($table = $exists->fetchColumn())) {
            if (!in_array($table, $tables)) {
                $this->addOperation("DROP TABLE $table;");
            }
        }

        // Extract explicit ALTER TABLE statements.
        foreach ($this->extractOperations("@^ALTER TABLE.*?;$@ms", $sql) as $match) {
            $this->addOperation($match[0]);
        }
        return $sql;
    }

    public function __destruct()
    {
        parent::__destruct();
    }

    /**
     * Compare the table status against the requested SQL, and generate ALTER
     * statements accordingly.
     *
     * @param string $table Name of the table
     * @param string $sql SQL of the requested table definition
     * @return void
     */
    protected function checkTableStatus(string $table, string $sql) : void
    {
        $sql = preg_replace("@^\s+@ms", '', $sql);
        $requestedColumns = [];
        foreach (preg_split("@,\n@m", $sql) as $reqCol) {
            if (preg_match("@^PRIMARY KEY\s*\((.*?)\)@", $reqCol, $pk)) {
                continue;
            }
            preg_match("@^[^\s]+@", $reqCol, $name);
            $name = $name[0];
            $requestedColumns[$name] = [
                'column_name' => $name,
                'column_default' => null,
                'is_nullable' => true,
                'column_type' => '',
                '_definition' => trim($reqCol),
            ];
            $reqCol = str_replace('PRIMARY KEY', '', $reqCol);
            $reqCol = preg_replace("@^$name\s+@", '', $reqCol);
            if (strpos($reqCol, 'NOT NULL')) {
                $requestedColumns[$name]['is_nullable'] = false;
                $reqCol = str_replace('NOT NULL', '', $reqCol);
            }
            if (preg_match("@DEFAULT\s+(.*?)$@", $reqCol, $default)) {
                $parsed = preg_replace("@(^'|'$)@", '', $default[1]);
                $requestedColumns[$name]['column_default'] = $parsed;
                $requestedColumns[$name]['_default'] = $default[1];
                $reqCol = str_replace($default[0], '', $reqCol);
            }
            $requestedColumns[$name]['column_type'] = trim($reqCol);
        }
        $this->columns->execute([$this->loader->getDatabase(), $table]);
        $currentColumns = [];
        foreach ($this->columns->fetchAll(PDO::FETCH_ASSOC) as $column) {
            if (!isset($requestedColumns[$column['column_name']])) {
                $this->addOperation("ALTER TABLE $table DROP COLUMN {$column['column_name']};");
            } else {
                $column['is_nullable'] = $column['is_nullable'] == 'YES';
                $column['column_type'] = strtoupper($column['column_type']);
                $currentColumns[$column['column_name']] = $column;
            }
        }
        foreach ($requestedColumns as $name => $col) {
            if (!isset($currentColumns[$name])) {
                $this->addOperation("ALTER TABLE $table ADD COLUMN {$col['_definition']};");
            } else {
                foreach ($this->modifyColumn($table, $name, $col, $currentColumns[$name]) as $sql) {
                    $this->addOperation($sql);
                }
            }
        }
    }

    /**
     * @param string $table
     * @param string $column
     * @param array $definition
     * @param array $current
     * @return array
     */
    protected abstract function modifyColumn(string $table, string $column, array $definition, array $current) : array;
}

