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

echo "=== ENVIRONMENT VARIABLES DEBUG ===\n";
echo "OCI_REGION: " . getenv('OCI_REGION') . "\n";
echo "OCI_USER_ID: " . getenv('OCI_USER_ID') . "\n";
echo "OCI_TENANCY_ID: " . getenv('OCI_TENANCY_ID') . "\n";
echo "OCI_KEY_FINGERPRINT: " . getenv('OCI_KEY_FINGERPRINT') . "\n";
echo "OCI_PRIVATE_KEY_FILENAME: " . (file_exists(getenv('OCI_PRIVATE_KEY_FILENAME')) ? 'File exists' : 'File NOT found') . "\n";
echo "OCI_SUBNET_ID: " . getenv('OCI_SUBNET_ID') . "\n";
echo "OCI_IMAGE_ID: " . getenv('OCI_IMAGE_ID') . "\n";
echo "OCI_SHAPE: " . getenv('OCI_SHAPE') . "\n";
echo "OCI_OCPUS: " . getenv('OCI_OCPUS') . " (type: " . gettype((int)getenv('OCI_OCPUS')) . ")\n";
echo "OCI_MEMORY_IN_GBS: " . getenv('OCI_MEMORY_IN_GBS') . " (type: " . gettype((int)getenv('OCI_MEMORY_IN_GBS')) . ")\n";
echo "OCI_AVAILABILITY_DOMAIN: " . (getenv('OCI_AVAILABILITY_DOMAIN') ?: 'Not set') . "\n";
echo "===================================\n\n";

/*
 * No need to modify any value in this file anymore!
 * Copy .env.example to .env and adjust there instead.
 *
 * README.md now has all the information.
 */
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
    echo "Boot Volume Size set to: {$bootVolumeSizeInGBs} GB\n";
} elseif ($bootVolumeId) {
    $config->setBootVolumeId($bootVolumeId);
    echo "Boot Volume ID set to: {$bootVolumeId}\n";
}

echo "\n=== SSH PUBLIC KEY DEBUG ===\n";
$sshKeyRaw = getenv('OCI_SSH_PUBLIC_KEY');
echo "Raw SSH Key length: " . strlen($sshKeyRaw) . "\n";
echo "Raw SSH Key (first 80 chars): " . substr($sshKeyRaw, 0, 80) . "\n";
echo "Raw SSH Key (last 80 chars): " . substr($sshKeyRaw, -80) . "\n";
echo "Newlines in raw key: " . substr_count($sshKeyRaw, "\n") . "\n";
echo "Carriage returns in raw key: " . substr_count($sshKeyRaw, "\r") . "\n";
echo "Backslashes in raw key: " . substr_count($sshKeyRaw, "\\") . "\n";

// Clean SSH key
$sshKeyCleaned = preg_replace('/\s+/', ' ', trim($sshKeyRaw));
echo "\nCleaned SSH Key length: " . strlen($sshKeyCleaned) . "\n";
echo "Cleaned SSH Key (first 80 chars): " . substr($sshKeyCleaned, 0, 80) . "\n";
echo "Cleaned SSH Key (last 80 chars): " . substr($sshKeyCleaned, -80) . "\n";
echo "============================\n\n";

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

echo "Fetching existing instances...\n";
$instances = $api->getInstances($config);
echo "Total instances found: " . count($instances) . "\n\n";

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
    echo "Fetching availability domains...\n";
    $availabilityDomains = $api->getAvailabilityDomains($config);
    echo "Available domains: " . implode(', ', $availabilityDomains) . "\n\n";
}

foreach ($availabilityDomains as $availabilityDomainEntity) {
    $availabilityDomain = is_array($availabilityDomainEntity) ? $availabilityDomainEntity['name'] : $availabilityDomainEntity;
    
    echo "=== ATTEMPTING INSTANCE CREATION ===\n";
    echo "Availability Domain: $availabilityDomain\n";
    echo "Shape: $shape\n";
    echo "OCPUs: " . $config->ocpus . "\n";
    echo "Memory: " . $config->memoryInGBs . " GB\n";
    echo "Image ID: " . $config->imageId . "\n";
    echo "Subnet ID: " . $config->subnetId . "\n";
    
    // Show what will be sent in the JSON body
    $displayName = 'instance-' . date('Ymd-Hi');
    echo "\nJSON Body Preview:\n";
    echo "{\n";
    echo "  \"displayName\": \"$displayName\",\n";
    echo "  \"shape\": \"$shape\",\n";
    echo "  \"availabilityDomain\": \"$availabilityDomain\",\n";
    echo "  \"compartmentId\": \"{$config->tenancyId}\",\n";
    echo "  \"subnetId\": \"{$config->subnetId}\",\n";
    echo "  \"imageId\": \"{$config->imageId}\",\n";
    echo "  \"shapeConfig\": {\n";
    echo "    \"ocpus\": {$config->ocpus},\n";
    echo "    \"memoryInGBs\": {$config->memoryInGBs}\n";
    echo "  },\n";
    echo "  \"ssh_authorized_keys\": \"" . substr($sshKeyCleaned, 0, 50) . "...\"\n";
    echo "}\n";
    echo "====================================\n\n";
    
    try {
        $instanceDetails = $api->createInstance($config, $shape, $sshKeyCleaned, $availabilityDomain);
    } catch(ApiCallException $e) {
        $message = $e->getMessage();
        echo "❌ API Error (Code {$e->getCode()}):\n$message\n\n";

        if (
            $e->getCode() === 500 &&
            strpos($message, 'InternalError') !== false &&
            strpos($message, 'Out of host capacity') !== false
        ) {
            echo "Out of capacity, trying next availability domain...\n";
            sleep(16);
            continue;
        }

        // current config is broken
        return;
    }

    // success
    echo "✅ SUCCESS!\n";
    $message = json_encode($instanceDetails, JSON_PRETTY_PRINT);
    echo "$message\n";
    if ($notifier->isSupported()) {
        $notifier->notify($message);
    }

    return;
}

echo "❌ Failed to create instance in all availability domains.\n";
