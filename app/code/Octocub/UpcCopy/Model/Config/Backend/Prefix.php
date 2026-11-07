<?php

declare(strict_types=1);

namespace Octocub\UpcCopy\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Octocub\UpcCopy\Model\PrefixValidator;

class Prefix extends Value
{
    /**
     * @return $this
     */
    public function beforeSave()
    {
        $this->setValue(PrefixValidator::normalize((string) $this->getValue()));

        return parent::beforeSave();
    }
}
