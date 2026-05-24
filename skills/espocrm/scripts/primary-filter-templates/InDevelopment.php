<?php
// Place at: custom/Espo/Custom/Classes/Select/<EntityName>/PrimaryFilters/<FilterName>.php
// Then chown -R www-data:www-data + rebuild.

namespace Espo\Custom\Classes\Select\CSubscription\PrimaryFilters;

use Espo\Core\Select\Primary\Filter;
use Espo\ORM\Query\SelectBuilder;

class InDevelopment implements Filter
{
    public function apply(SelectBuilder $queryBuilder): void
    {
        $queryBuilder->where(['status' => 'InDevelopment']);
    }
}
