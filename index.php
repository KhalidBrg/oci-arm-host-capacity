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

// Validation des variables obligatoires
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
    $value = env($var);
    if (empty($value)) {
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

// === GESTION DE LA CLÉ PRIVÉE ===
$privateKeyInput = env('OCI_PRIVATE_KEY_FILENAME');
$tempKeyFile = null;

// Vérifier si c'est un chemin de fichier existant ou le contenu de la clé
if (file_exists($privateKeyInput)) {
    echo "Using existing private key file: $privateKeyInput\n";
    $privateKeyPath = $privateKeyInput;
} elseif (strpos($privateKeyInput, '-----BEGIN') !== false) {
    // C'est le contenu de la clé, créer un fichier temporaire
    echo "Creating temporary private key file...\n";
    $tempKeyFile = sys_get_temp_dir() . '/oci_private_key_' . uniqid() . '.pem';
    
    // Écrire la clé dans le fichier
    if (file_put_contents($tempKeyFile, $privateKeyInput) === false) {
        echo "❌ ERROR: Failed to create temporary key file\n";
        exit(1);
    }
    
    // Définir les permissions appropriées
    chmod($tempKeyFile, 0600);
    
    $privateKeyPath = $tempKeyFile;
    echo "Temporary key file created: $privateKeyPath\n";
} else {
    echo "❌ ERROR: OCI_PRIVATE_KEY_FILENAME is neither a valid file path nor a PEM key\n";
    exit(1);
}

echo "\n";

// === NETTOYER LA CLÉ SSH ===
$sshKeyRaw = env('OCI_SSH_PUBLIC_KEY');
$sshKey = trim(str_replace(["\r", "\n"], '', $sshKeyRaw));

echo "=== SSH Key Debug ===\n";
echo "Raw SSH Key length: " . strlen($sshKeyRaw) . "\n";
echo "Cleaned SSH Key length: " . strlen($sshKey) . "\n";
echo "SSH Key starts with: " . substr($sshKey, 0, 30) . "...\n";
echo "SSH Key ends with: ..." . substr($sshKey, -30) . "\n";
echo "=====================\n\n";

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
echo "User ID: " . substr($config->userId, 0, 20) . "...\n";
echo "Tenancy ID: " . substr($config->tenancyId, 0, 20) . "...\n";
echo "Key Fingerprint: " . $config->keyFingerprint . "\n";
echo "Subnet ID: " . substr($config->subnetId, 0, 20) . "...\n";
echo "Image ID: " . substr($config->imageId, 0, 20) . "...\n";
echo "OCPUs: " . $config->ocpus . " (type: " . gettype($config->ocpus) . ")\n";
echo "Memory: " . $config->memoryInGBs . " GB (type: " . gettype($config->memoryInGBs) . ")\n";
echo "Availability Domain: " . ($config->availabilityDomains ?: 'null') . "\n";
echo "===========================\n\n";

$bootVolumeSizeInGBs = (string) env('OCI_BOOT_VOLUME_SIZE_IN_GBS');
$bootVolumeId = (string) env('OCI_BOOT_VOLUME_ID');
if ($bootVolumeSizeInGBs) {
    $config->setBootVolumeSizeInGBs($bootVolumeSizeInGBs);
    echo "Boot Volume Size: $bootVolumeSizeInGBs GB\n";
} elseif ($bootVolumeId) {
    $config->setBootVolumeId($bootVolumeId);
    echo "Boot Volume ID: " . substr($bootVolumeId, 0, 20) . "...\n";
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

echo "\nFetching existing instances...\n";
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
    
    // Nettoyer le fichier temporaire
    if ($tempKeyFile && file_exists($tempKeyFile)) {
        unlink($tempKeyFile);
    }
    
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
    echo "Fetching availability domains...\n";
    $availabilityDomains = $api->getAvailabilityDomains($config);
    echo "Available domains: " . count($availabilityDomains) . "\n\n";
}

foreach ($availabilityDomains as $availabilityDomainEntity) {
    $availabilityDomain = is_array($availabilityDomainEntity) ? $availabilityDomainEntity['name'] : $availabilityDomainEntity;
    echo "=== Trying availability domain: $availabilityDomain ===\n";
    
    // Debug de la requête qui va être envoyée
    echo "\n--- Request Details ---\n";
    echo "Shape: $shape\n";
    echo "SSH Key length: " . strlen($sshKey) . "\n";
    echo "Availability Domain: $availabilityDomain\n";
    echo "-----------------------\n\n";
    
    try {
        $instanceDetails = $api->createInstance($config, $shape, $sshKey, $availabilityDomain);
    } catch(ApiCallException $e) {
        $message = $e->getMessage();
        echo "❌ Error: $message\n";
        echo "Error Code: " . $e->getCode() . "\n\n";

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
        
        // Nettoyer le fichier temporaire
        if ($tempKeyFile && file_exists($tempKeyFile)) {
            unlink($tempKeyFile);
        }
        
        exit(1);
    }

    // Success
    $message = "✅ Instance created successfully!\n" . json_encode($instanceDetails, JSON_PRETTY_PRINT);
    echo "$message\n";
    
    if ($notifier->isSupported()) {
        $notifier->notify($message);
    }

    // Nettoyer le fichier temporaire
    if ($tempKeyFile && file_exists($tempKeyFile)) {
        unlink($tempKeyFile);
    }

    exit(0);
}

echo "❌ Failed to create instance in all availability domains.\n";

// Nettoyer le fichier temporaire
if ($tempKeyFile && file_exists($tempKeyFile)) {
    unlink($tempKeyFile);
}

exit(1);
