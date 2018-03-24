<?php

namespace Dbmover\Core;

use DomainException;

/**
 * Thrown if a plugin was requested, but it is not available.
 */
class PluginUnavailableException extends DomainException
{
}

