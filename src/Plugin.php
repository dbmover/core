<?php

namespace Dbmover\Core;

abstract class Plugin implements PluginInterface
{
    protected $loader;

    public function __construct(Loader $loader)
    {
        $this->loader = $loader;
    }

    public function __invoke(string $sql) : string
    {
        return $sql;
    }
}

