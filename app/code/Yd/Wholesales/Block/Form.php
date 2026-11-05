<?php

declare(strict_types=1);

namespace Yd\Wholesales\Block;

use Magento\Framework\View\Element\Template;

class Form extends Template
{
    public function getSubmitUrl(): string
    {
        return $this->getUrl('wholesales/index/submit');
    }
}
