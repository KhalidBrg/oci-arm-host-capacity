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

// === CONFIGURATION OCI ===
$config = new OciConfig(
    env('OCI_REGION'),
    env('OCI_USER_ID'),
    env('OCI_TENANCY_ID'),
    env('OCI_KEY_FINGERPRINT'),
    $privateKeyPath,  // Utiliser le chemin du fichier (existant ou temporaire)
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
        
        // Nettoyer le fichier temporaire
        if ($tempKeyFile && file_exists($tempKeyFile)) {
            unlink($tempKeyFile);
        }
        
        return;
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

    return;
}

echo "❌ Failed to create instance in all availability domains.\n";

// Nettoyer le fichier temporaire
if ($tempKeyFile && file_exists($tempKeyFile)) {
    unlink($tempKeyFile);
}
