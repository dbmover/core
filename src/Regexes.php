<?php

namespace Dbmover\Dbmover;

interface Regexes
{
    const REGEX_PROCEDURES = '@^CREATE (PROCEDURE|FUNCTION).*?^END;$@ms';
    const REGEX_TRIGGERS = '@^CREATE TRIGGER.*?^END;$@ms';
}

