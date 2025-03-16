<?php
namespace Flagship\Shipping\Helper;

use Flagship\Shipping\Logger\Logger;
use Magento\Framework\App\Config\ScopeConfigInterface;

class Flagship
{
    protected $loggingEnabled;
    protected $redirect;

    public function __construct(
        protected Logger $logger,
        protected ScopeConfigInterface $scopeConfig
    ) {
        if (!defined('SMARTSHIP_WEB_URL')) {
            $this->getEnv();
        }
    }

    public function getSettings() : array
    {
        return [];
        // $collection = $this->config->getCollection();
        // return $collection->count() == 0 ? [] : $this->getSettingsValues($collection->toArray()['items']);
    }
    public function getSettingsValues(array $result) : array
    {
        $settings = [];
        foreach ($result as $row) {
            $settings[$row["name"]] = $row["value"];
        }
        return $settings;
    }

    public function logInfo(string $msg) : bool
    {
        if ($this->loggingEnabled) {
            $this->logger->info(__($msg));
            return true;
        }
        return false;
    }

    public function logError(string $msg) : bool
    {
        if ($this->loggingEnabled) {
            $this->logger->error(__($msg));
            return true;
        }
        return false;
    }

    private function getEnv() : int
    {
        if (isset($this->getSettings()['test_env']) && $this->getSettings()['test_env'] == 1) {
            define('SMARTSHIP_WEB_URL', 'https://test-smartshipng.flagshipcompany.com');
            define('SMARTSHIP_API_URL', 'https://test-api.smartship.io');
            return 0;
        }
        define('SMARTSHIP_WEB_URL', 'https://smartship-ng.flagshipcompany.com');
        define('SMARTSHIP_API_URL', 'https://api.smartship.io');
        return 0;
    }
}

