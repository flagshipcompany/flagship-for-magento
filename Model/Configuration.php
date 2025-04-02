<?php

declare(strict_types=1);

namespace Flagship\Shipping\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

class Configuration
{
    public function __construct(
        protected ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue('carriers/flagship/active');
    }

    public function getToken(): string
    {
        return (string) $this->scopeConfig->getValue('carriers/flagship/token');
    }

    public function getEnvironment(): string
    {
        return (string) $this->scopeConfig->getValue('carriers/flagship/env');
    }

    public function getInsurance(): bool
    {
        return (bool) $this->scopeConfig->getValue('carriers/flagship/insurance');
    }

    public function getResidential(): bool
    {
        return (bool) $this->scopeConfig->getValue('carriers/flagship/residential');
    }

    public function isPackingEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue('carriers/flagship/packing');
    }

    public function getBoxes(): array
    {
        $boxes = $this->scopeConfig->getValue('carriers/flagship/boxes');
        $boxes = $boxes ? array_values(json_decode($boxes, true)) : [];
        return $boxes;
    }

    public function getTaxes(): bool
    {
        return (bool) $this->scopeConfig->getValue('carriers/flagship/taxes');
    }

    public function getMarkup(): float
    {
        return (float) $this->scopeConfig->getValue('carriers/flagship/markup');
    }

    public function getFee(): float
    {
        return (float) $this->scopeConfig->getValue('carriers/flagship/flat_fee');
    }

    public function getDisplayDelivery(): bool
    {
        return (bool) $this->scopeConfig->getValue('carriers/flagship/delivery_date');
    }

    public function getLogging(): bool
    {
        return (bool) $this->scopeConfig->getValue('carriers/flagship/logging');
    }

    public function getAllowedMethods(): array
    {
        return explode(',', $this->scopeConfig->getValue('carriers/flagship/allowed_methods'));
    }

    public function useCustomerEmailForTracking(): bool
    {
        return (bool) $this->scopeConfig->getValue('carriers/flagship/customer_email_tracking');
    }

    public function getApiUrl(): string
    {
        $testEnv = $this->getEnvironment();
        return $testEnv == '1' ? 'https://test-api.smartship.io' : 'https://api.smartship.io';
    }

    public function getUrl(): string
    {
        $testEnv = $this->getEnvironment();
        return $testEnv == '1'
            ? 'https://test-smartshipng.flagshipcompany.com'
            : 'https://smartship-ng.flagshipcompany.com';
    }

}
