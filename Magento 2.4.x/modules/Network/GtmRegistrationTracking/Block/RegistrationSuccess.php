<?php
declare(strict_types=1);

namespace Network\GtmRegistrationTracking\Block;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\View\Element\Template;

class RegistrationSuccess extends Template
{
    public function __construct(
        Template\Context $context,
        private readonly CustomerSession $customerSession,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return bool
     */
    public function shouldRender(): bool
    {
        return (bool) $this->customerSession->getJustRegisteredGtm();
    }

    /**
     * Renderujemy szablon TYLKO jeśli flaga jest ustawiona, po czym
     * natychmiast ją kasujemy - tak, żeby odświeżenie strony konta
     * (F5) nie wysyłało eventu drugi raz.
     */
    protected function _toHtml(): string
    {
        if (!$this->shouldRender()) {
            return '';
        }

        $this->customerSession->unsJustRegisteredGtm();

        return parent::_toHtml();
    }
}
