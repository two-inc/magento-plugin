<?php
namespace Magento\Framework\App\Config\Storage;

interface WriterInterface
{
    public function save($path, $value, $scope = 'default', $scopeId = 0);
    public function delete($path, $scope = 'default', $scopeId = 0);
}
