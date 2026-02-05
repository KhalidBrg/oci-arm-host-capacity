<?php
declare(strict_types=1);

// useful when script is being executed by cron user
$pathPrefix = ''; // e.g. /usr/share/nginx/oci-arm-host-capacity/

require "{$pathPrefix}vendor/autoload.php";

use Dotenv\Dotenv;
use Hitrov\Exception\ApiCallException;
use Hitrov\FileCache;
use Hitrov\OciApi;
use Hitrov\OciConfig;
use Hitrov\TooManyRequestsWaiter;

$envFilename = empty($argv[1]) ? '.env' : $argv[1];
$dotenv = Dotenv::createUnsafeImmutable(__DIR__, $envFilename);
$dotenv->safeLoad();

$config = new OciConfig(
    getenv('OCI_REGION'),
    getenv('OCI_USER_ID'),
    getenv('OCI_TENANCY_ID'),
    getenv('OCI_KEY_FINGERPRINT'),
    getenv('OCI_PRIVATE_KEY_FILENAME'),
    getenv('OCI_AVAILABILITY_DOMAIN') ?: null,
    getenv('OCI_SUBNET_ID'),
    getenv('OCI_IMAGE_ID'),
    (int) getenv('OCI_OCPUS'),
    (int) getenv('OCI_MEMORY_IN_GBS')
);

$bootVolumeSizeInGBs = (string) getenv('OCI_BOOT_VOLUME_SIZE_IN_GBS');
$bootVolumeId = (string) getenv('OCI_BOOT_VOLUME_ID');
if ($bootVolumeSizeInGBs) {
    $config->setBootVolumeSizeInGBs($bootVolumeSizeInGBs);
} elseif ($bootVolumeId) {
    $config->setBootVolumeId($bootVolumeId);
}

$api = new OciApi();

// === ADD GUZZLE LOGGING MIDDLEWARE ===
$reflectionClass = new ReflectionClass($api);
$reflectionProperty = $reflectionClass->getProperty('client');
$reflectionProperty->setAccessible(true);

$container = [];
$history = \GuzzleHttp\Middleware::history($container);
$handlerStack = \GuzzleHttp\HandlerStack::create();
$handlerStack->push($history);

$newClient = new \GuzzleHttp\Client([
    'handler' => $handlerStack,
    'http_errors' => false
]);
$reflectionProperty->setValue($api, $newClient);
// === END LOGGING SETUP ===

if (getenv('CACHE_AVAILABILITY_DOMAINS')) {
    $api->setCache(new FileCache($config));
}
if (getenv('TOO_MANY_REQUESTS_TIME_WAIT')) {
    $api->setWaiter(new TooManyRequestsWaiter((int) getenv('TOO_MANY_REQUESTS_TIME_WAIT')));
}
$notifier = (function (): \Hitrov\Interfaces\NotifierInterface {
    return new \Hitrov\Notification\Telegram();
})();

$shape = getenv('OCI_SHAPE');

$maxRunningInstancesOfThatShape = 1;
if (getenv('OCI_MAX_INSTANCES') !== false) {
    $maxRunningInstancesOfThatShape = (int) getenv('OCI_MAX_INSTANCES');
}

$instances = $api->getInstances($config);

$existingInstances = $api->checkExistingInstances($config, $instances, $shape, $maxRunningInstancesOfThatShape);
if ($existingInstances) {
    echo "$existingInstances\n";
    return;
}

if (!empty($config->availabilityDomains)) {
    if (is_array($config->availabilityDomains)) {
        $availabilityDomains = $config->availabilityDomains;
    } else {
        $availabilityDomains = [ $config->availabilityDomains ];
    }
} else {
    $availabilityDomains = $api->getAvailabilityDomains($config);
}

// Process SSH key
$sshKeyRaw = getenv('OCI_SSH_PUBLIC_KEY');
$sshKeyCleaned = preg_replace('/\s+/', ' ', trim($sshKeyRaw));
$sshKeyToUse = str_replace('\\', '', $sshKeyCleaned);

echo "=== SSH KEY INFO ===\n";
echo "Length: " . strlen($sshKeyToUse) . "\n";
echo "First 80 chars: " . substr($sshKeyToUse, 0, 80) . "\n";
echo "====================\n\n";

foreach ($availabilityDomains as $availabilityDomainEntity) {
    $availabilityDomain = is_array($availabilityDomainEntity) ? $availabilityDomainEntity['name'] : $availabilityDomainEntity;
    try {
        $instanceDetails = $api->createInstance($config, $shape, $sshKeyToUse, $availabilityDomain);
    } catch(ApiCallException $e) {
        $message = $e->getMessage();
        echo "$message\n";

        // === DETAILED ERROR LOGGING ===
        echo "\n=== DETAILED ERROR DEBUG ===\n";
        echo "Exception Code: " . $e->getCode() . "\n";
        echo "Exception Message: " . $message . "\n";
        
        if (!empty($container)) {
            foreach ($container as $transaction) {
                echo "\n--- HTTP REQUEST ---\n";
                echo $transaction['request']->getMethod() . ' ' . $transaction['request']->getUri() . "\n";
                echo "Headers:\n";
                foreach ($transaction['request']->getHeaders() as $name => $values) {
                    if ($name !== 'authorization') { // Don't log auth header
                        echo "  $name: " . implode(', ', $values) . "\n";
                    }
                }
                echo "\nRequest Body:\n";
                $requestBody = (string) $transaction['request']->getBody();
                echo $requestBody . "\n";
                
                // Validate JSON
                $decoded = json_decode($requestBody);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    echo "\n!!! JSON DECODE ERROR: " . json_last_error_msg() . " !!!\n";
                } else {
                    echo "\nJSON is valid\n";
                }
                
                if (isset($transaction['response'])) {
                    echo "\n--- HTTP RESPONSE ---\n";
                    echo "Status: " . $transaction['response']->getStatusCode() . "\n";
                    echo "Response Body:\n";
                    echo $transaction['response']->getBody() . "\n";
                }
            }
        }
        echo "===========================\n\n";
        // === END DETAILED ERROR LOGGING ===

        if (
            $e->getCode() === 500 &&
            strpos($message, 'InternalError') !== false &&
            strpos($message, 'Out of host capacity') !== false
        ) {
            sleep(16);
            continue;
        }

        return;
    }

    $message = json_encode($instanceDetails, JSON_PRETTY_PRINT);
    echo "$message\n";
    if ($notifier->isSupported()) {
        $notifier->notify($message);
    }

    return;
}
