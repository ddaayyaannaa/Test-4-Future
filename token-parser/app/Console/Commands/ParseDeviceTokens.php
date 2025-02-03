<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use League\Csv\Writer;

class ParseDeviceTokens extends Command
{
    protected $signature = 'parse:device-tokens {directory : Directory containing the token files}';
    protected $description = 'Parse device token files and convert to CSV format';

    private $appCodes = [];
    private $validTags = [
        'subscription_status' => [
            'active_subscriber',
            'expired_subscriber',
            'never_subscribed',
            'subscription_unknown'
        ],
        'has_downloaded_free_product_status' => [
            'has_downloaded_free_product',
            'not_downloaded_free_product',
            'downloaded_free_product_unknown'
        ],
        'has_downloaded_iap_product_status' => [
            'has_downloaded_iap_product',
            'not_downloaded_free_product',
            'downloaded_iap_product_unknown'
        ]
    ];

    private $invalidTags = [];
    private $id = 1;

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->loadAppCodes();
        
        $directory = $this->argument('directory');
        if (!File::isDirectory($directory)) {
            $this->error("Directory not found: {$directory}");
            return 1;
        }

        $csv = Writer::createFromPath(storage_path('app/parsed_tokens.csv'), 'w+');
        $csv->insertOne([
            'id',
            'appCode',
            'deviceId',
            'contactable',
            'subscription_status',
            'has_downloaded_free_product_status',
            'has_downloaded_iap_product_status'
        ]);

        foreach (File::directories($directory) as $subDir) {
            $this->processDirectory($subDir, $csv);
        }

        if (!empty($this->invalidTags)) {
            File::put(
                storage_path('app/invalid_tags.log'),
                json_encode($this->invalidTags, JSON_PRETTY_PRINT)
            );
            $this->info("Invalid tags have been logged to invalid_tags.log");
        }

        $this->info("Processing complete! Output saved to parsed_tokens.csv");
        return 0;
    }

    private function loadAppCodes()
    {
        $iniContent = parse_ini_file(base_path('appCodes.ini'), true);
        $this->appCodes = $iniContent['appcodes'] ?? [];
    }

    private function processDirectory($directory, $csv)
    {
        foreach (File::files($directory) as $file) {
            $this->processFile($file, $csv);
        }
    }

    private function processFile($file, $csv)
    {
        $rows = array_map('str_getcsv', array_slice(explode("\n", File::get($file)), 1));
        
        foreach ($rows as $row) {
            if (count($row) >= 4) {
                $record = [
                    'appCode' => $row[0],
                    'deviceToken' => $row[1],
                    'deviceTokenStatus' => $row[2],
                    'tags' => $row[3]
                ];
                $this->processRecord($record, $csv);
            }
        }
    }

    private function processRecord($record, $csv)
    {
        $appCode = $record['appCode'] ?? '';
        $mappedAppCode = array_search($appCode, $this->appCodes) ?: 'unknown';

        $tags = explode('|', $record['tags'] ?? '');
        $tagGroups = $this->categorizeTags($tags);

        $csvRecord = [
            'id' => $this->id++,
            'appCode' => $mappedAppCode,
            'deviceId' => $record['deviceToken'] ?? '',
            'contactable' => $record['deviceTokenStatus'] ?? 0,
            'subscription_status' => $tagGroups['subscription_status'] ?? 'subscription_unknown',
            'has_downloaded_free_product_status' => $tagGroups['has_downloaded_free_product_status'] ?? 'downloaded_free_product_unknown',
            'has_downloaded_iap_product_status' => $tagGroups['has_downloaded_iap_product_status'] ?? 'downloaded_iap_product_unknown'
        ];

        $csv->insertOne($csvRecord);
    }

    private function categorizeTags(array $tags): array
    {
        $result = [];

        foreach ($tags as $tag) {
            $tag = trim($tag);
            if (empty($tag)) continue;

            $categorized = false;
            foreach ($this->validTags as $group => $validGroupTags) {
                if (in_array($tag, $validGroupTags)) {
                    if (!isset($result[$group])) {
                        $result[$group] = $tag;
                    }
                    $categorized = true;
                    break;
                }
            }

            if (!$categorized) {
                $this->invalidTags[] = $tag;
            }
        }

        return $result;
    }
}