<?php

namespace Dbmover\Core;

interface PluginInterface
{
    public function __construct(Loader $loader);
    public function __invoke(string $sql) : string;
}

