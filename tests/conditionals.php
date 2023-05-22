<?php

use Gentry\Gentry\Wrapper;
use Dbmover\Core\Conditionals;
use Dbmover\Core\Loader;

/** Testsuite for Dbmover\Core\Conditionals */
return function () : Generator {
    $loader = new Loader('mysql:dbname=dbmover_test', ['user' => 'dbmover_test', 'pass' => 'moveit'], true);
    $object = new Wrapper(new Dbmover\Core\Conditionals($loader));
    /** Conditionals get extracted */
    yield function () use ($object) {
        $result = $object->__invoke(<<<EOT

IF foo = 'bar' THEN

END IF;
EOT
        );
        assert(trim($result) === '');
    };

};

