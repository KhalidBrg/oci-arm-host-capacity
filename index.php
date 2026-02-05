<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Hitrov\Exception\ApiCallException;
use Hitrov\FileCache;
use Hitrov\OciApi;
use Hitrov\OciConfig;
use Hitrov\TooManyRequestsWaiter;

// Fonction pour récupérer les variables d'environnement
function env(string $key, $default = false) {
    return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default;
}

echo "=== OCI Instance Creation Script ===\n";
echo "Region: " . env('OCI_REGION') . "\n";
echo "Shape: " . env('OCI_SHAPE') . "\n";
echo "Max Instances: " . env('OCI_MAX_INSTANCES', 1) . "\n\n";

$config = new OciConfig(
    env('OCI_REGION'),
    env('OCI_USER_ID'),
    env('OCI_TENANCY_ID'),
    env('OCI_KEY_FINGERPRINT'),
    env('OCI_PRIVATE_KEY_FILENAME'),
    env('OCI_AVAILABILITY_DOMAIN') ?: null,
    env('OCI_SUBNET_ID'),
    env('OCI_IMAGE_ID'),
    (int) env('OCI_OCPUS'),
    (int) env('OCI_MEMORY_IN_GBS')
);

$bootVolumeSizeInGBs = (string) env('OCI_BOOT_VOLUME_SIZE_IN_GBS');
$bootVolumeId = (string) env('OCI_BOOT_VOLUME_ID');
if ($bootVolumeSizeInGBs) {
    $config->setBootVolumeSizeInGBs($bootVolumeSizeInGBs);
} elseif ($bootVolumeId) {
    $config->setBootVolumeId($bootVolumeId);
}

$api = new OciApi();
if (env('CACHE_AVAILABILITY_DOMAINS')) {
    $api->setCache(new FileCache($config));
}
if (env('TOO_MANY_REQUESTS_TIME_WAIT')) {
    $api->setWaiter(new TooManyRequestsWaiter((int) env('TOO_MANY_REQUESTS_TIME_WAIT')));
}

$notifier = new \Hitrov\Notification\Telegram();

$shape = env('OCI_SHAPE');
$maxRunningInstancesOfThatShape = (int) env('OCI_MAX_INSTANCES', 1);

echo "Fetching existing instances...\n";
$instances = $api->getInstances($config);
echo "Total instances found: " . count($instances) . "\n";

// Filtrer et afficher les instances
$filteredCount = 0;
foreach ($instances as $instance) {
    if ($instance['shape'] === $shape && $instance['lifecycleState'] !== 'TERMINATED') {
        $filteredCount++;
        echo "  - Instance: {$instance['displayName']} | State: {$instance['lifecycleState']}\n";
    }
}
echo "Instances with shape '$shape' (not terminated): $filteredCount\n\n";

$existingInstances = $api->checkExistingInstances($config, $instances, $shape, $maxRunningInstancesOfThatShape);
if ($existingInstances) {
    echo "Result: $existingInstances\n";
    return;
}

echo "No existing instances found. Attempting to create new instance...\n\n";

if (!empty($config->availabilityDomains)) {
    if (is_array($config->availabilityDomains)) {
        $availabilityDomains = $config->availabilityDomains;
    } else {
        $availabilityDomains = [ $config->availabilityDomains ];
    }
} else {
    $availabilityDomains = $api->getAvailabilityDomains($config);
}

foreach ($availabilityDomains as $availabilityDomainEntity) {
    $availabilityDomain = is_array($availabilityDomainEntity) ? $availabilityDomainEntity['name'] : $availabilityDomainEntity;
    echo "Trying availability domain: $availabilityDomain\n";
    
    try {
        $instanceDetails = $api->createInstance($config, $shape, env('OCI_SSH_PUBLIC_KEY'), $availabilityDomain);
    } catch(ApiCallException $e) {
        $message = $e->getMessage();
        echo "Error: $message\n";

        if (
            $e->getCode() === 500 &&
            strpos($message, 'InternalError') !== false &&
            strpos($message, 'Out of host capacity') !== false
        ) {
            echo "Out of capacity, trying next domain...\n";
            sleep(16);
            continue;
        }

        echo "Fatal error, stopping.\n";
        return;
    }

    // Success
    $message = "✅ Instance created successfully!\n" . json_encode($instanceDetails, JSON_PRETTY_PRINT);
    echo "$message\n";
    
    if ($notifier->isSupported()) {
        $notifier->notify($message);
    }

    return;
}

echo "❌ Failed to create instance in all availability domains.\n";
