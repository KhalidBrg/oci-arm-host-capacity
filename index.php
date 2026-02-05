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

// Test different escaping strategies
echo "=== SSH KEY ANALYSIS ===\n";
echo "Raw length: " . strlen($sshKeyRaw) . "\n";
echo "Cleaned length: " . strlen($sshKeyCleaned) . "\n";
echo "Backslashes in cleaned: " . substr_count($sshKeyCleaned, "\\") . "\n";

// Strategy 1: Double escape
$strategy1 = str_replace(['\\', '"'], ['\\\\', '\\"'], $sshKeyCleaned);
echo "\nStrategy 1 (double escape): " . substr_count($strategy1, "\\") . " backslashes\n";

// Strategy 2: Remove backslashes entirely
$strategy2 = str_replace('\\', '', $sshKeyCleaned);
echo "Strategy 2 (remove backslashes): " . substr_count($strategy2, "\\") . " backslashes\n";

// Strategy 3: Use json_encode then strip quotes
$strategy3 = json_encode($sshKeyCleaned, JSON_UNESCAPED_SLASHES);
$strategy3 = trim($strategy3, '"');
echo "Strategy 3 (json_encode): " . substr_count($strategy3, "\\") . " backslashes\n";

// Test JSON validity
$testJson1 = '{"key":"' . $strategy1 . '"}';
$testJson2 = '{"key":"' . $strategy2 . '"}';
$testJson3 = '{"key":"' . $strategy3 . '"}';

echo "\nJSON validity tests:\n";
echo "Strategy 1: " . (json_decode($testJson1) !== null ? "VALID" : "INVALID") . "\n";
echo "Strategy 2: " . (json_decode($testJson2) !== null ? "VALID" : "INVALID") . "\n";
echo "Strategy 3: " . (json_decode($testJson3) !== null ? "VALID" : "INVALID") . "\n";

// Show first 150 chars of each
echo "\nFirst 150 chars:\n";
echo "Strategy 1: " . substr($strategy1, 0, 150) . "\n";
echo "Strategy 2: " . substr($strategy2, 0, 150) . "\n";
echo "Strategy 3: " . substr($strategy3, 0, 150) . "\n";
echo "========================\n\n";

// Use the strategy that produces valid JSON
$sshKeyToUse = $strategy2; // Try removing backslashes first

foreach ($availabilityDomains as $availabilityDomainEntity) {
    $availabilityDomain = is_array($availabilityDomainEntity) ? $availabilityDomainEntity['name'] : $availabilityDomainEntity;
    try {
        $instanceDetails = $api->createInstance($config, $shape, $sshKeyToUse, $availabilityDomain);
    } catch(ApiCallException $e) {
        $message = $e->getMessage();
        echo "$message\n";

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
