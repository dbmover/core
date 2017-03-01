<?php

namespace Dbmover\Dbmover\Objects;

use Dbmover\Dbmover\ObjectInterface;
use PDO;

abstract class Table extends Sql
{
    public $name;
    public $current;
    public $requested;
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
                    COLUMN_NAME colname
                FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE (TABLE_CATALOG = ? OR TABLE_SCHEMA = ?) AND TABLE_NAME = ?
                    ORDER BY ORDINAL_POSITION ASC"
            );
        }
        self::$columns->execute([$database, $database, $this->name]);
        $this->current = (object)['columns' => [], 'indexes' => []];
        $this->setCurrentIndexes($pdo, $database);
        $cols = [];
        $class = $this->getObjectName('Column');
        foreach (self::$columns->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $this->current->columns[$column['colname']] = new $class($column['colname'], $this);
            $this->current->columns[$column['colname']]->setCurrentState($pdo, $database);
        }
    }

    protected abstract function setCurrentIndexes(PDO $pdo, string $database);

    public static function fromSql(string $sql, ObjectInterface $parent = null) : ObjectInterface
    {
        preg_match("@CREATE.*?TABLE (\w+) \((.*)\)@ms", $sql, $extr);
        $class = new static($extr[1], $parent);
        $columnClass = $class->getObjectName('Column');
        $indexClass = $class->getObjectName('Index');
        $lines = preg_split('@,$@m', rtrim($extr[2]));
        $class->current = (object)['columns' => [], 'indexes' => []];
        foreach ($lines as $line) {
            $line = trim($line);
            preg_match('@^\w+@', $line, $name);
            $class->current->columns[$name[0]] = $columnClass::fromSql($line, $class);
            if (stripos($line, 'AUTO_INCREMENT')) {
                $class->current->indexes[$name[0]] = $indexClass::fromSql($line, $class);
            }
        }
        return $class;
    }

    public function toSql() : array
    {
        $operations = [];
        foreach (['columns', 'indexes'] as $type) {
            foreach ($this->current->$type as $obj) {
                if (isset($this->requested->current->$type[$obj->name])) {
                    $obj->setComparisonObject($this->requested->current->$type[$obj->name]);
                }
                $operations = array_merge($operations, $obj->toSql());
            }
            foreach ($this->requested->current->$type as $obj) {
                if (!isset($this->current->$type[$obj->name])) {
                    $class = get_class($obj);
                    $newobj = new $class($obj->name, $obj->parent);
                    $newobj->setComparisonObject($obj);
                    $operations = array_merge($operations, $newobj->toSql());
                }
            }
        }
        return $operations;
    }
}

