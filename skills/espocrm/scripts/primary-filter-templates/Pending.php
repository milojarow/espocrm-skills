<?php
// Example for an "in" filter (multiple acceptable values).
// Place at: custom/Espo/Custom/Classes/Select/CInvoice/PrimaryFilters/Pending.php

namespace Espo\Custom\Classes\Select\CInvoice\PrimaryFilters;

use Espo\Core\Select\Primary\Filter;
use Espo\ORM\Query\SelectBuilder;

class Pending implements Filter
{
    public function apply(SelectBuilder $queryBuilder): void
    {
        $queryBuilder->where([
            'status' => ['PartiallyPaid', 'Sent', 'Draft']
        ]);
    }
}
