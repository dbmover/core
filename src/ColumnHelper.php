<?php

namespace Dbmover\Dbmover;

trait ColumnHelper
{
    /**
     * Default implementation for adding a column.
     *
     * @param string $table The table to modify.
     * @param array $definition Key/value hash of column definition.
     * @return string SQL that adds this column to the table.
     */
    public function addColumn($table, array $definition)
    {
        return sprintf(
            "ALTER TABLE %s ADD COLUMN %s %s%s%s",
            $table,
            $definition['colname'],
            $definition['coltype'],
            $definition['nullable'] == 'NO' ?
                ' NOT NULL' :
                '',
            $definition['def'] != '' ?
                sprintf(
                    " DEFAULT %s",
                    is_null($definition['def']) ?
                    'NULL' :
                    $definition['def']
                ) :
                ''
        );
    }
    
    /**
     * Default implementation for altering a column.
     *
     * @param string $table The table to modify.
     * @param array $definition Key/value hash of column definition.
     * @return array An array of SQL statements that bring this column into the
     *  desired state.
     */
    public function alterColumn($table, array $definition)
    {
        $operations = [];
        $base = sprintf(
            "ALTER TABLE %s ALTER COLUMN %s",
            $table,
            $definition['colname']
        );
        $operations[] = "$base TYPE {$definition['coltype']}";
        if ($definition['nullable'] == 'NO') {
            $operations[] = "$base SET NOT NULL";
        } else {
            $operations[] = "$base DROP NOT NULL";
        }
        if ($definition['def'] != '') {
            $operations[] = "$base SET DEFAULT "
                .(is_null($definition['def']) ?
                    'NULL' :
                    $definition['def']);
        } elseif (!$definition['is_serial']) {
            $operations[] = "$base DROP DEFAULT";
        }
        return $operations;
    }
    
    /**
     * Extract and return the default value for a column, if specified.
     *
     * @param string $column The referenced column definition.
     * @return string|null The default value of the column, if specified. If no
     *  default was specified, null.
     */
    public function getDefaultValue($column)
    {
        if (preg_match('@DEFAULT (.*?)($| )@', $column->sql, $default)) {
            $column->sql = str_replace($default[0], '', $column->sql);
            return $default[1];
        }
        return null;
    }
    
    /**
     * Checks whether a column is nullable.
     *
     * @param string $column The referenced column definition.
     * @return bool
     */
    public function isNullable($column)
    {
        if (strpos($column->sql, 'NOT NULL')) {
            $column->sql = str_replace('NOT NULL', '', $column->sql);
            return false;
        }
        return true;
    }
    
    /**
     * Checks whether a column is a "serial" column.
     *
     * Vendors have different implementations of this, e.g. MySQL "tags" the
     * column as "auto_increment" whilst PostgreSQL uses a `SERIAL` data type.
     *
     * @param string $column The referenced column definition.
     * @return bool
     */
    public abstract function isSerial($column);
    
    /**
     * Checks whether a column is a primary key.
     *
     * @param string $column The referenced column definition.
     * @return bool
     */
    public abstract function isPrimaryKey($column);
}

