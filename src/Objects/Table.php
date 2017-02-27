<?php

namespace Dbmover\Dbmover\Objects;

use Dbmover\Dbmover\ObjectInterface;
use PDO;

abstract class Table extends Sql
{
    public $name;
    public $current;
    protected static $columns;
    protected static $indexes;

    public function __construct(string $name, ObjectInterface $parent = null)
    {
        $this->name = $name;
    }

    public function drop() : string
    {
        return "DROP TABLE $table";
    }

    public function setCurrentState(PDO $pdo, string $database)
    {
        if (!isset(self::$columns)) {
            self::$columns = $pdo->prepare(
                "SELECT
                    COLUMN_NAME colname,
                    COLUMN_DEFAULT def,
                    IS_NULLABLE nullable,
                    DATA_TYPE coltype,
                    (EXTRA = 'auto_increment') is_serial
                FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE (TABLE_CATALOG = ? OR TABLE_SCHEMA = ?) AND TABLE_NAME = ?
                    ORDER BY ORDINAL_POSITION ASC"
            );
        }
        self::$columns->execute([$database, $database, $this->name]);
        $this->current = (object)['columns' => []];
        $cols = [];
        $class = $this->getObjectName('Column');
        foreach (self::$columns->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $this->current->columns[$column['colname']] = new $class($column['colname'], $this);
            $this->current->columns[$column['colname']]->setCurrentState($pdo, $database);
            /*
            if (is_null($column['def']) && $column['nullable'] == 'YES') {
                $column['def'] = 'NULL';
            } elseif (!is_null($column['def'])) {
                $column['def'] = $pdo->quote($column['def']);
            } else {
                $column['def'] = '';
            }
            $cols[$column['colname']] = $column;
            */
        }
        $this->setCurrentIndexes($pdo, $database);
    }

    protected abstract function setCurrentIndexes(PDO $pdo, string $database);

    public static function fromSql(string $sql, ObjectInterface $parent = null) : ObjectInterface
    {
        preg_match("@CREATE.*?TABLE (\w+) \((.*)\)@ms", $sql, $extr);
        $class = new static($extr[1], $parent);
        $columnClass = $class->getObjectName('Column');
        $lines = preg_split('@,$@m', rtrim($extr[2]));
        $class->current = (object)['columns' => []];
        foreach ($lines as $line) {
            $line = trim($line);
            // Extract the name
            preg_match('@^\w+@', $line, $name);
            $class->current->columns[$name[0]] = $columnClass::fromSql($line, $class);
            /*
            $column = [
                'colname' => '',
                'def' => null,
                'nullable' => 'YES',
                'coltype' => '',
                'is_serial' => false,
            ];
            $column['colname'] = $name[0];
            $line = preg_replace("@^{$name[0]}@", '', $line);
            $sql = new \StdClass;
            $sql->sql = $line;
            if (!$this->isNullable($sql)) {
                $column['nullable'] = 'NO';
                $column['def'] = '';
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
            $columns[$name[0]] = $column;
            */
        }
        return $class;
    }

    public function toSql() : array
    {
        $operations = [];
        foreach ($this->current->columns as $col) {
            if (isset($this->requested->current->columns[$col->name])) {
                $col->setComparisonObject($this->requested->current->columns[$col->name]);
            }
            $operations = array_merge($operations, $col->toSql());
            /*
            if (!isset($this->requested->current->columns[$col->name])) {
                $operations[] = sprintf(
                    "ALTER TABLE {$this->name} ADD COLUMN {$col->name} {$col->coltype}%s%s",
                    $col['nullable'] == 'YES' ? ' NOT NULL' : '',
                    $col['def'] != '' ? 'DEFAULT '.$col['def'] : ''
                );
            } else {
                $operations[] = sprintf(
                    "ALTER TABLE {$this->name} CHANGE COLUMN {$col->name} {$col->name} {$col->coltype}%s%s",
                    $col['nullable'] == 'YES' ? ' NOT NULL' : '',
                    $col['def'] != '' ? 'DEFAULT '.$col['def'] : ''
                );
            }
            */
        }
        foreach ($this->requested->current->columns as $col) {
            if (!isset($this->current->columns[$col->name])) {
                $operations[] = "ALTER TABLE {$this->parent->name} DROP COLUMN {$col->name}";
            }
        }
        return $operations;
    }
}

