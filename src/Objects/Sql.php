<?php

namespace Dbmover\Dbmover\Objects;

use Dbmover\Dbmover\ObjectInterface;
use PDO;

class Sql implements ObjectInterface
{
    private $sql;
    public $parent;
    protected $requested;

    public function __construct(string $sql, ObjectInterface $parent = null)
    {
        $this->name = $sql;
        $this->parent = $parent;
    }

    public static function fromSql(string $sql, ObjectInterface $parent = null) : ObjectInterface
    {
        return new self($sql, $parent);
    }

    public function toSql() : array
    {
        return [$this->name];
    }

    public function setCurrentState(PDO $pdo, string $name)
    {
    }

    public function drop() : string
    {
        return '';
    }

    public function setComparisonObject(ObjectInterface $object)
    {
        $this->requested = $object;
    }
}

