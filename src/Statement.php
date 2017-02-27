<?php

namespace Dbmover\Dbmover;

abstract class Statement
{
    protected $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public abstract function __toString();
}

