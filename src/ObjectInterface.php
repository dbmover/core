<?php

namespace Dbmover\Dbmover;

use PDO;

interface ObjectInterface
{
    public function __construct(string $name, ObjectInterface $parent = null);

    public function setCurrentState(PDO $pdo, string $database);

    public static function fromSql(string $sql, ObjectInterface $parent = null) : ObjectInterface;

    public function toSql() : array;

    public function drop() : string;

    public function setComparisonObject(ObjectInterface $object);
}

