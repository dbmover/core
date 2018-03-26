<?php

/**
 * @package Dbmover
 * @subpackage Core
 */

namespace Dbmover\Core;

use PDO;

/**
 * Migrate all indexes and constraints.
 */
abstract class IndexesAndConstraints extends Plugin
{
    /** @var string */
    const REGEX = "@^CREATE\s+(UNIQUE\s+)?INDEX\s+([^\s]+?)?\s*ON\s+([^\s\(]+)(\s+USING \w+)?\s*\((.*)\).*?;$@m";

    /** @var string */
    const DEFAULT_INDEX_TYPE = '';

    /** @var string */
    const DESCRIPTION = 'Checking index (re)creation...';

    /** @var string */
    const DEFERRED = 'Recreating requested indexes...';

    /** @var array */
    protected $requestedIndexes = [];

    /**
     * @param string $sql
     * @return string
     */
    public function __invoke(string $sql) : string
    {
        $stmt = $this->loader->getPdo()->prepare(
            "SELECT TABLE_NAME tbl, CONSTRAINT_NAME constr, CONSTRAINT_TYPE ctype
                FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                WHERE (CONSTRAINT_CATALOG = ? AND CONSTRAINT_SCHEMA = 'public') OR CONSTRAINT_SCHEMA = ?"
        );
        $stmt->execute([$this->loader->getDatabase(), $this->loader->getDatabase()]);
        while (false !== ($constraint = $stmt->fetch(PDO::FETCH_ASSOC))) {
            if (!$this->loader->shouldBeIgnored($constraint['constr'])) {
                $this->dropConstraint($constraint['tbl'], $constraint['constr'], $constraint['ctype']);
            }
        }
        foreach ($this->extractOperations(static::REGEX, $sql) as $index) {
            $name = strlen($index[2])
                ? $index[2]
                : preg_replace("@[\W_]+@", '_', "{$index[3]}_{$index[5]}_idx");
            $index[5] = preg_replace("@,\s+@", ',', $index[5]);
            $index[1] = trim($index[1]);
            $index[4] = trim($index[4]);
            $this->requestedIndexes[$name] = $index;
        }
        foreach ($this->extractOperations("@^ALTER TABLE\s+([^\s]+?)\s+ADD PRIMARY KEY\((.*?)\)@", $sql) as $index) {
            $index[5] = $index[4];
            $name = "{$index[1]}_PRIMARY";
            $index[4] = $name;
            $this->requestedIndexes[$name] = $index;
        }
        foreach ($this->findOperations("@^CREATE TABLE\s+([^\s]+?)\s+\($(.*?)^\)@ms", $sql) as $pktable) {
            if (preg_match("@^\s+([^\s]+).*?PRIMARY KEY@m", $pktable[0], $pk)
                || preg_match("@^\s+PRIMARY KEY\((.*?)\)@m", $pktable[0], $pk)
            ) {
                $name = "{$pktable[1]}_PRIMARY";
                $this->requestedIndexes[$name] = [
                    "ALTER TABLE {$pktable[1]} ADD PRIMARY KEY({$pk[1]})",
                    'UNIQUE',
                    '',
                    '',
                    static::DEFAULT_INDEX_TYPE,
                    preg_replace('@,\s+@', ',', $pk[1])
                ];
                if (substr(trim($pk[0]), 0, 11) == 'PRIMARY KEY') {
                    $pktable[0] = str_replace($pk[0], '', $pktable[0]);
                    $corrected = preg_replace('@,$^\)@m', "\n)", $pktable[0]);
                    $sql = str_replace($pktable[0], $corrected, $sql);
                }
            }
        }

        // Check against existing indexes
        foreach ($this->existingIndexes() as $old) {
            if ($old['index_name'] == 'PRIMARY') {
                $old['index_name'] = "{$old['table_name']}_PRIMARY";
            }
            if (!isset($this->requestedIndexes[$old['index_name']])
                || strtolower($old['column_name']) != strtolower($this->requestedIndexes[$old['index_name']][5])
                || $old['non_unique'] != ($this->requestedIndexes[$old['index_name']][1] == 'UNIQUE' ? 0 : 1)
                || (!preg_match("@_PRIMARY$@", $old['index_name'])
                    && strtolower($old['type']) != strtolower($this->requestedIndexes[$old['index_name']][4])
                )
            ) {
                // Index has changed, so it needs to be rebuilt.
                if (preg_match('@_PRIMARY$@', $old['index_name'])) {
                    $this->defer($this->dropPrimaryKey($old['index_name'], $old['table_name']));
                } else {
                    $this->defer($this->dropIndex($old['index_name'], $old['table_name']));
                }
            } else {
                // Index is already there, so ignore it.
                unset($this->requestedIndexes[$old['index_name']]);
            }
        }
        foreach ($this->requestedIndexes as $index) {
            $this->defer($index[0]);
        }
        foreach ($this->extractOperations("@^ALTER TABLE \S+ ADD FOREIGN KEY.*?;@ms", $sql) as $constraint) {
            $this->defer($constraint[0]);
        }
        return $sql;
    }

    /** @return array */
    protected abstract function existingIndexes() : array;
}

