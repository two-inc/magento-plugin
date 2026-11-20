<?php
/**
 * The admin order view surface the toolbar plugin works on: the order view
 * block (a real AbstractBlock, as Magento's is, so the plugin's type hint
 * holds) and the button list, with Magento's add() and remove() signatures.
 * Per-symbol guards, as the other stubs.
 */
declare(strict_types=1);

namespace Magento\Sales\Block\Adminhtml\Order;

if (!class_exists(View::class, false)) {
    class View extends \Magento\Framework\View\Element\AbstractBlock
    {
        public function getOrder()
        {
            return $this->getData('order');
        }
    }
}

namespace Magento\Backend\Block\Widget\Button;

if (!class_exists(ButtonList::class, false)) {
    class ButtonList
    {
        /** @var array[] */
        public $buttons = [];

        public function add($buttonId, $data, $level = 0, $sortOrder = 0, $region = 'toolbar')
        {
            $this->buttons[$buttonId] = $data;
        }

        public function remove($buttonId)
        {
            unset($this->buttons[$buttonId]);
        }
    }
}
