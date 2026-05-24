<?php
// Example with a string value containing a space.
// Place at: custom/Espo/Custom/Classes/Select/Lead/PrimaryFilters/InProcess.php

namespace Espo\Custom\Classes\Select\Lead\PrimaryFilters;

use Espo\Core\Select\Primary\Filter;
use Espo\ORM\Query\SelectBuilder;

class InProcess implements Filter
{
    public function apply(SelectBuilder $queryBuilder): void
    {
        $queryBuilder->where(['status' => 'In Process']);
    }
}
