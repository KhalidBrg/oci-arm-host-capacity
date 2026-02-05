<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Hitrov\Exception\ApiCallException;
use Hitrov\FileCache;
use Hitrov\OciApi;
use Hitrov\OciConfig;
use Hitrov\TooManyRequestsWaiter;

function env(string $key, $default = false) {
    return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default;
}

echo "=== OCI Instance Creation Script ===\n";

$requiredVars = [
    'OCI_REGION',
    'OCI_USER_ID',
    'OCI_TENANCY_ID',
    'OCI_KEY_FINGERPRINT',
    'OCI_PRIVATE_KEY_FILENAME',
    'OCI_SUBNET_ID',
    'OCI_IMAGE_ID',
    'OCI_SHAPE',
    'OCI_OCPUS',
    'OCI_MEMORY_IN_GBS',
    'OCI_SSH_PUBLIC_KEY'
];

$missingVars = [];
foreach ($requiredVars as $var) {
    if (empty(env($var))) {
        $missingVars[] = $var;
    }
}

if (!empty($missingVars)) {
    echo "❌ ERROR: Missing required environment variables:\n";
    foreach ($missingVars as $var) {
        echo "  - $var\n";
    }
    exit(1);
}

echo "Region: " . env('OCI_REGION') . "\n";
echo "Shape: " . env('OCI_SHAPE') . "\n";
echo "OCPUs: " . env('OCI_OCPUS') . "\n";
echo "Memory: " . env('OCI_MEMORY_IN_GBS') . " GB\n";
echo "Max Instances: " . env('OCI_MAX_INSTANCES', 1) . "\n\n";

// === GESTION CLÉ PRIVÉE ===
$privateKeyInput = env('OCI_PRIVATE_KEY_FILENAME');
$tempKeyFile = null;

if (file_exists($privateKeyInput)) {
    $privateKeyPath = $privateKeyInput;
} elseif (strpos($privateKeyInput, '-----BEGIN') !== false) {
    echo "Creating temporary private key file...\n";
    $tempKeyFile = sys_get_temp_dir() . '/oci_private_key_' . uniqid() . '.pem';
    file_put_contents($tempKeyFile, $privateKeyInput);
    chmod($tempKeyFile, 0600);
    $privateKeyPath = $tempKeyFile;
    echo "Temporary key file created: $privateKeyPath\n";
} else {
    echo "❌ ERROR: Invalid OCI_PRIVATE_KEY_FILENAME\n";
    exit(1);
}

// === NETTOYER LA CLÉ SSH ===
$sshKeyRaw = env('OCI_SSH_PUBLIC_KEY');

// 1. Supprimer tous les retours à la ligne et espaces multiples
$sshKey = preg_replace('/\s+/', ' ', trim($sshKeyRaw));

// 2. Échapper les backslashes pour JSON
$sshKey = str_replace('\\', '\\\\', $sshKey);

echo "\n=== SSH Key Cleaning ===\n";
echo "Raw length: " . strlen($sshKeyRaw) . "\n";
echo "Cleaned length: " . strlen($sshKey) . "\n";
echo "Backslashes in raw: " . substr_count($sshKeyRaw, '\\') . "\n";
echo "Backslashes in cleaned: " . substr_count($sshKey, '\\') . "\n";
echo "First 50 chars: " . substr($sshKey, 0, 50) . "\n";
echo "Last 50 chars: " . substr($sshKey, -50) . "\n";
echo "========================\n\n";

// === CONFIGURATION OCI ===
$config = new OciConfig(
    env('OCI_REGION'),
    env('OCI_USER_ID'),
    env('OCI_TENANCY_ID'),
    env('OCI_KEY_FINGERPRINT'),
    $privateKeyPath,
    env('OCI_AVAILABILITY_DOMAIN') ?: null,
    env('OCI_SUBNET_ID'),
    env('OCI_IMAGE_ID'),
    (int) env('OCI_OCPUS'),
    (int) env('OCI_MEMORY_IN_GBS')
);

echo "=== Configuration Debug ===\n";
echo "Region: " . $config->region . "\n";
echo "User ID: " . $config->ociUserId . "\n";
echo "Tenancy ID: " . $config->tenancyId . "\n";
echo "Key Fingerprint: " . $config->keyFingerPrint . "\n";
echo "Subnet ID: " . $config->subnetId . "\n";
echo "Image ID: " . $config->imageId . "\n";
echo "OCPUs: " . $config->ocpus . " (type: " . gettype($config->ocpus) . ")\n";
echo "Memory: " . $config->memoryInGBs . " GB (type: " . gettype($config->memoryInGBs) . ")\n";
echo "Availability Domain: " . ($config->availabilityDomain ?? 'Not set') . "\n";
echo "===========================\n\n";

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

// Compter les instances avec le shape spécifique
$instancesWithShape = array_filter($instances, function($instance) use ($shape) {
    return $instance['shape'] === $shape && $instance['lifecycleState'] !== 'TERMINATED';
});
echo "Instances with shape '$shape' (not terminated): " . count($instancesWithShape) . "\n\n";

$existingInstances = $api->checkExistingInstances($config, $instances, $shape, $maxRunningInstancesOfThatShape);
if ($existingInstances) {
    echo "Result: $existingInstances\n";
    if ($tempKeyFile && file_exists($tempKeyFile)) {
        unlink($tempKeyFile);
    }
    exit(0);
}

echo "No existing instances found. Attempting to create new instance...\n\n";

$availabilityDomains = $config->availabilityDomain
    ? [$config->availabilityDomain]
    : $api->getAvailabilityDomains($config);

foreach ($availabilityDomains as $availabilityDomain) {
    echo "=== Trying availability domain: $availabilityDomain ===\n\n";
    
    try {
        $instance = $api->createInstance($config, $shape, $sshKey, $availabilityDomain);
        
        echo "✅ SUCCESS! Instance created:\n";
        echo "Instance ID: " . $instance['id'] . "\n";
        echo "Display Name: " . $instance['displayName'] . "\n";
        echo "State: " . $instance['lifecycleState'] . "\n";
        echo "Availability Domain: " . $instance['availabilityDomain'] . "\n";
        echo "Shape: " . $instance['shape'] . "\n";
        echo "Time Created: " . $instance['timeCreated'] . "\n";
        
        $message = sprintf(
            "✅ OCI Instance Created!\n\nID: %s\nName: %s\nShape: %s\nAD: %s\nState: %s",
            $instance['id'],
            $instance['displayName'],
            $instance['shape'],
            $instance['availabilityDomain'],
            $instance['lifecycleState']
        );
        
        $notifier->send($message);
        
        if ($tempKeyFile && file_exists($tempKeyFile)) {
            unlink($tempKeyFile);
        }
        
        exit(0);
        
    } catch (ApiCallException $e) {
        $errorMessage = $e->getMessage();
        $errorCode = $e->getCode();
        
        echo "❌ Error: $errorMessage\n";
        echo "Error Code: $errorCode\n\n";
        
        // Si c'est une erreur 400 (Bad Request), arrêter complètement
        if ($errorCode == 400) {
            echo "Fatal error, stopping.\n";
            
            if ($tempKeyFile && file_exists($tempKeyFile)) {
                unlink($tempKeyFile);
            }
            
            exit(1);
        }
        
        // Pour les autres erreurs, continuer avec le prochain AD
        echo "Continuing to next availability domain...\n\n";
        continue;
        
    } catch (\Exception $e) {
        echo "❌ Unexpected error: " . $e->getMessage() . "\n\n";
        continue;
    }
}

echo "❌ Failed to create instance in all availability domains.\n";

if ($tempKeyFile && file_exists($tempKeyFile)) {
    unlink($tempKeyFile);
}

exit(1);
