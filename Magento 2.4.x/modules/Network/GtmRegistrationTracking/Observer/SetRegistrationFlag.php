<?php
declare(strict_types=1);

namespace Network\GtmRegistrationTracking\Observer;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;

/**
 * Event customer_register_success odpala się w trakcie requestu POST
 * (kontroler CreatePost), zanim nastąpi redirect do /customer/account/.
 * Nie możemy tu jeszcze wypchnąć dataLayer.push (to request serwerowy,
 * nie strona w przeglądarce) - zapisujemy więc flagę w sesji, którą
 * odczytamy i skonsumujemy na kolejnej stronie (patrz Block/RegistrationSuccess.php).
 */
class SetRegistrationFlag implements ObserverInterface
{
    public function __construct(
        private readonly CustomerSession $customerSession
    ) {
    }

    public function execute(EventObserver $observer): void
    {
        $this->customerSession->setJustRegisteredGtm(true);
    }
}
