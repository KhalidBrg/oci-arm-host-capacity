    - name: Run debug wrapper
      run: |
        cat > debug_wrapper.php << 'EOFPHP'
        <?php
        declare(strict_types=1);

        $pathPrefix = '';
        require "{$pathPrefix}vendor/autoload.php";

        use Dotenv\Dotenv;
        use Hitrov\OciApi;
        use Hitrov\OciConfig;

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

        $api = new OciApi();
        $availabilityDomains = $api->getAvailabilityDomains($config);
        $shape = getenv('OCI_SHAPE');

        $sshKeyRaw = getenv('OCI_SSH_PUBLIC_KEY');
        $sshKeyCleaned = preg_replace('/\s+/', ' ', trim($sshKeyRaw));
        $sshKeyNoBackslash = str_replace('\\', '', $sshKeyCleaned);

        $displayName = 'instance-' . date('Ymd-Hi');
        $availabilityDomain = is_array($availabilityDomains[0]) ? $availabilityDomains[0]['name'] : $availabilityDomains[0];

        $sourceDetails = $config->getSourceDetails();
        
        echo "=== SOURCE DETAILS DEBUG ===\n";
        echo "Type: " . gettype($sourceDetails) . "\n";
        echo "Value: " . var_export($sourceDetails, true) . "\n";
        echo "JSON encoded: " . json_encode($sourceDetails) . "\n";
        echo "===========================\n\n";

        // Build body with proper JSON encoding
        $bodyArray = [
            "metadata" => [
                "ssh_authorized_keys" => $sshKeyNoBackslash
            ],
            "shape" => $shape,
            "compartmentId" => $config->tenancyId,
            "displayName" => $displayName,
            "availabilityDomain" => $availabilityDomain,
            "sourceDetails" => json_decode($sourceDetails, true), // Decode if it's a string
            "createVnicDetails" => [
                "assignPublicIp" => false,
                "subnetId" => $config->subnetId,
                "assignPrivateDnsRecord" => true
            ],
            "agentConfig" => [
                "pluginsConfig" => [
                    [
                        "name" => "Compute Instance Monitoring",
                        "desiredState" => "ENABLED"
                    ]
                ],
                "isMonitoringDisabled" => false,
                "isManagementDisabled" => false
            ],
            "definedTags" => new stdClass(),
            "freeformTags" => new stdClass(),
            "instanceOptions" => [
                "areLegacyImdsEndpointsDisabled" => false
            ],
            "availabilityConfig" => [
                "recoveryAction" => "RESTORE_INSTANCE"
            ],
            "shapeConfig" => [
                "ocpus" => $config->ocpus,
                "memoryInGBs" => $config->memoryInGBs
            ]
        ];

        $body = json_encode($bodyArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        echo "=== PROPERLY ENCODED REQUEST BODY ===\n";
        echo $body;
        echo "\n=====================================\n\n";

        $decoded = json_decode($body);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "JSON ERROR: " . json_last_error_msg() . "\n";
            exit(1);
        } else {
            echo "JSON is VALID!\n";
        }
        EOFPHP
        php debug_wrapper.php
      env:
        OCI_REGION: ${{ secrets.OCI_REGION }}
        OCI_USER_ID: ${{ secrets.OCI_USER_ID }}
        OCI_TENANCY_ID: ${{ secrets.OCI_TENANCY_ID }}
        OCI_KEY_FINGERPRINT: ${{ secrets.OCI_KEY_FINGERPRINT }}
        OCI_PRIVATE_KEY_FILENAME: ${{ secrets.OCI_PRIVATE_KEY_FILENAME }}
        OCI_SUBNET_ID: ${{ secrets.OCI_SUBNET_ID }}
        OCI_IMAGE_ID: ${{ secrets.OCI_IMAGE_ID }}
        OCI_SSH_PUBLIC_KEY: ${{ secrets.OCI_SSH_PUBLIC_KEY }}
        OCI_SHAPE: ${{ secrets.OCI_SHAPE }}
        OCI_OCPUS: ${{ secrets.OCI_OCPUS }}
        OCI_MEMORY_IN_GBS: ${{ secrets.OCI_MEMORY_IN_GBS }}
        OCI_AVAILABILITY_DOMAIN: ${{ secrets.OCI_AVAILABILITY_DOMAIN }}
        OCI_MAX_INSTANCES: ${{ secrets.OCI_MAX_INSTANCES }}
