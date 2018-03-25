<?php

use Gentry\Gentry\Wrapper;
use Dbmover\Core\Loader;
use Dbmover\Core\Procedures;

/** Testsuite for Dbmover\Core\Procedures */
return function () : Generator {
    $loader = new Loader('mysql:dbname=dbmover_test', ['user' => 'dbmover_test', 'pass' => 'moveit'], true);
    $object = Wrapper::createObject(Procedures::class, $loader);
    /** Functions get stripped */
    yield function () use ($object) {
        $result = $object->__invoke(<<<EOT
CREATE FUNCTION foo

END;
EOT
        );
        assert($result === '');
    };
    /** Procedures get stripped */
    yield function () use ($object) {
        $result = $object->__invoke(<<<EOT
CREATE PROCEDURE foo

END;
EOT
        );
        assert($result === '');
    };
};

