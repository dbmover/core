<?php

namespace Dbmover\Dbmover;

trait TableHelper
{
    /**
     * Get an array of all table names in this database.
     *
     * @return array An array of table names.
     */
    public function getTables($type = 'BASE TABLE')
    {
        $stmt = $this->pdo->prepare(sprintf(
            "SELECT TABLE_NAME
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_%s = ? AND TABLE_TYPE = ?",
            static::CATALOG_COLUMN
        ));
        $stmt->execute([$this->database, $type]);
        $names = [];
        while (false !== ($table = $stmt->fetchColumn())) {
            $names[] = $table;
        }
        return $names;
    }

    /**
     * Check if a table exists on the given database.
     *
     * @param string $name The table name to check.
     * @return bool True or false.
     */
    public function tableExists($name, $type = 'BASE TABLE')
    {
        $tables = $this->getTables($type);
        return in_array($name, $tables);
    }

    /**
     * Get the relevant parts of a table schema from INFORMATION_SCHEMA in a
     * vendor-independent way.
     *
     * @param string $name The name of the table.
     * @return array A hash of columns, where the key is also the column name.
     */
    public abstract function getTableDefinition($name);

    /**
     * Parse the table definition as specified in the schema into a format
     * similar to what `getTableDefinition` returns.
     *
     * @param string $schema The schema for this table (CREATE TABLE .. (...);)
     * @return array A hash of columns, where the key is also the column name.
     */
    public function parseTableDefinition($schema)
    {
        preg_match("@CREATE.*?TABLE \w+ \((.*)\)@ms", $schema, $extr);
        $lines = preg_split('@,$@m', rtrim($extr[1]));
        $cols = [];
        foreach ($lines as $line) {
            $line = trim($line);
            $column = [
                'colname' => '',
                'def' => null,
                'nullable' => 'YES',
                'coltype' => '',
                'is_serial' => false,
            ];
            // Extract the name
            preg_match('@^\w+@', $line, $name);
            $column['colname'] = $name[0];
            $line = preg_replace("@^{$name[0]}@", '', $line);
            $sql = new \StdClass;
            $sql->sql = $line;
            if (!$this->isNullable($sql)) {
                $column['nullable'] = 'NO';
            }
            if ($this->isPrimaryKey($sql)) {
                $column['key'] = 'PRI';
            }
            if ($this->isSerial($sql)) {
                $column['is_serial'] = true;
            }
            if (null !== ($default = $this->getDefaultValue($sql))) {
                $column['def'] = $default;
            }
            if (!isset($column['def'])) {
                $column['def'] = 'NULL';
            }
            $line = preg_replace('@REFERENCES.*?$@', '', $sql->sql);
            $column['coltype'] = strtolower(trim($line));
            $cols[$name[0]] = $column;
        }
        return $cols;
    }

    public function dropViews()
    {
        $operations = [];
        foreach ($this->getTables('VIEW') as $view) {
            if (!$this->shouldIgnore($view)) {
                $operations[] = "DROP VIEW IF EXISTS $view";
            }
        }
        return $operations;
    }
}

