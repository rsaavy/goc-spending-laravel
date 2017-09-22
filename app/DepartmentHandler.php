<?php
namespace App;

use App\Helpers\ContractDataProcessor;
use App\Helpers\Paths;
use GuzzleHttp\Client;
use XPathSelector\Selector;
use App\Helpers\Helpers;

// Includes both fetching and parsing functions
// now combined into one class.
abstract class DepartmentHandler
{

    // Fetching-related variables:
    public $guzzleClient;

    /**
     * The department acronym.
     *
     * @var string
     */
    public $ownerAcronym;

    /**
     * The URL for the page containing links to the quarters.
     *
     * @var string
     */
    public $indexUrl;

    /**
     * The base URL for the department. This is sometimes necessary, depending on its structure.
     *
     * @var string
     */
    public $baseUrl;

    public $activeQuarterPage;
    public $activeFiscalYear;
    public $activeFiscalQuarter;

    public $totalContractsFetched = 0;

    /**
     * XPath selector to, from the index page, get the quarter URLs.
     *
     * @var string
     */
    public $indexToQuarterXpath;

    /**
     * XPath selector to, from the quarter page, get the contract URLs.
     *
     * @var string
     */
    public $quarterToContractXpath;

    /**
     * XPath selector to, in the case of paginated quarter pages, get all quarter page URLs.
     *
     * @var string
     */
    public $quarterMultiPageXpath;

    public $contractContentSubsetXpath;
    public $contentSplitParameters = [];

    public $multiPage = 0;
    public $sleepBetweenDownloads = 0;


    // Parsing variables:
    public static $rowParams = [
        'uuid' => '',
        'vendorName' => '',
        'referenceNumber' => '',
        'contractDate' => '',
        'description' => '',
        'extraDescription' => '',
        'objectCode' => '',
        'contractPeriodStart' => '',
        'contractPeriodEnd' => '',
        'startYear' => '',
        'endYear' => '',
        'deliveryDate' => '',
        'originalValue' => '',
        'contractValue' => '',
        'comments' => '',
        'ownerAcronym' => '',
        'sourceYear' => '',
        'sourceQuarter' => '',
        'sourceFiscal' => '',
        'sourceFilename' => '',
        'sourceURL' => '',
        'amendedValues' => [],
    ];


    public function __construct($detailsArray = [])
    {

        // Suppress Xpath-Selector warnings (based on HTML5 rather than XML input).
        // Thanks to
        // https://stackoverflow.com/a/9149241/756641
        libxml_use_internal_errors(true);

        if ($this->baseUrl) {
            $this->guzzleClient = new Client(['base_uri' => $this->baseUrl]);
        } else {
            $this->guzzleClient = new Client;
        }
    }

    // By default, just return the same
    // Child classes can change this, to eg. add a parent URL
    public function quarterToContractUrlTransform($contractUrl)
    {
        return $contractUrl;
    }

    // Similar to the above, but for index pages
    public function indexToQuarterUrlTransform($contractUrl)
    {
        return $contractUrl;
    }

    // In case we want to filter specific URLs out of the list of quarter URLs
    // Useful for departments (like CBSA) that change their schema halfway through :P
    public function filterQuarterUrls($quarterUrls)
    {
        return $quarterUrls;
    }


    // Primary function to fetch pages
    public function fetch()
    {

        // Run the operation!
        $startDate = date('Y-m-d H:i:s');
        echo "Starting " . $this->ownerAcronym . " at ". $startDate . " \n\n";
        $startTime = microtime(true);


        $indexPage = $this->getPage($this->indexUrl);

        $quarterUrls = Helpers::getArrayFromHtmlViaXpath($indexPage, $this->indexToQuarterXpath);

        $quarterUrls = $this->filterQuarterUrls($quarterUrls);

        if (env('DEV_TEST_INDEX', 0) == 1) {
            echo "DEV_TEST_INDEX\n";
            dd($quarterUrls);
        }

        $quartersFetched = 0;
        foreach ($quarterUrls as $url) {
            if (env('FETCH_LIMIT_QUARTERS', 2) && $quartersFetched >= env('FETCH_LIMIT_QUARTERS', 2)) {
                break;
            }

            $url = $this->indexToQuarterUrlTransform($url);

            echo $url . "\n";

            // If the quarter pages have server-side pagination, then we need to get the multiple pages that represent that quarter. If there's only one page, then we'll put that as a single item in an array below, to simplify any later steps:
            $quarterMultiPages = [];
            if ($this->multiPage == 1) {
                $quarterPage = $this->getPage($url);

                // If there aren't multipages, this just returns the original quarter URL back as a single item array:
                $quarterMultiPages = Helpers::getArrayFromHtmlViaXpath($quarterPage, $this->quarterMultiPageXpath);
            } else {
                $quarterMultiPages = [ $url ];
            }


            $contractsFetched = 0;
            // Retrive all the (potentially multiple) pages from the given quarter:
            foreach ($quarterMultiPages as $url) {
                echo "D: " . $url . "\n";

                $this->activeQuarterPage = $url;

                $quarterPage = $this->getPage($url);

                // Clear it first just in case
                $this->activeFiscalYear = '';
                $this->activeFiscalQuarter = '';

                if (method_exists($this, 'fiscalYearFromQuarterPage')) {
                    $this->activeFiscalYear = $this->fiscalYearFromQuarterPage($quarterPage, $url);
                }
                if (method_exists($this, 'fiscalQuarterFromQuarterPage')) {
                    $this->activeFiscalQuarter = $this->fiscalQuarterFromQuarterPage($quarterPage, $url);
                }


                $contractUrls = Helpers::getArrayFromHtmlViaXpath($quarterPage, $this->quarterToContractXpath);


                if (env('DEV_TEST_QUARTER', 0) == 1) {
                    echo "DEV_TEST_QUARTER\n";
                    dd($contractUrls);
                }

                foreach ($contractUrls as $contractUrl) {
                    if (env('FETCH_LIMIT_CONTRACTS_PER_QUARTER', 2) && $contractsFetched >= env('FETCH_LIMIT_CONTRACTS_PER_QUARTER', 2)) {
                        break;
                    }

                    $contractUrl = $this->quarterToContractUrlTransform($contractUrl);

                    echo "   " . $contractUrl . "\n";

                    $this->downloadPage($contractUrl, $this->ownerAcronym);
                    $this->saveMetadata($contractUrl);

                    $this->totalContractsFetched++;
                    $contractsFetched++;
                }
            }

            echo "$contractsFetched pages downloaded for this quarter.\n\n";

            $quartersFetched++;
        }
        // echo $indexPage;
    }



    // Get a page using the Guzzle library
    // No longer a static function since we're reusing the client object between requests.
    // Ignores SSL verification per http://stackoverflow.com/a/32707976/756641
    public function getPage($url)
    {
        $response = $this->guzzleClient->request(
            'GET',
            $url,
            [
                'verify' => false,
            ]
        );
        return $response->getBody();
    }

    public function removeSessionIdsFromUrl($url)
    {
        // Can be overridden on a per-department basis:
        return $url;
    }

    // Generic page download function
    // Downloads the requested URL and saves it to the specified directory
    // If the same URL has already been downloaded, it avoids re-downloading it again.
    // This makes it easier to stop and re-start the script without having to go from the very beginning again.
    public function downloadPage($url, $subdirectory = '')
    {

        $url = Helpers::cleanIncomingUrl($url);

        $filename = Paths::generateFilenameFromUrl($this->removeSessionIdsFromUrl($url));

        $directoryPath = storage_path() . '/' . env('FETCH_RAW_HTML_FOLDER', 'raw-data');

        if ($subdirectory) {
            $directoryPath .= '/' . $subdirectory;
        }

        // If the folder doesn't exist yet, create it:
        // Thanks to http://stackoverflow.com/a/15075269/756641
        if (! is_dir($directoryPath)) {
            mkdir($directoryPath, 0755, true);
        }

        // If that particular page has already been downloaded,
        // don't download it again.
        // That lets us re-start the script without starting from the very beginning again.
        if (file_exists($directoryPath . '/' . $filename) == false || env('FETCH_REDOWNLOAD_EXISTING_FILES', 1)) {
            // Download the page in question:
            $pageSource = $this->getPage($url);

            // echo "ENCODING IS: ";
            // $encoding = mb_detect_encoding($pageSource, mb_detect_order(), 1);
            // echo $encoding . "\n";

            if ($pageSource) {
                if ($this->contentSplitParameters) {
                    $split = explode($this->contentSplitParameters['startSplit'], $pageSource);
                    $pageSource = explode($this->contentSplitParameters['endSplit'], $split[1])[0];
                }

                if ($this->contractContentSubsetXpath) {
                    $xs = Selector::loadHTML($pageSource);
                    $pageSource = $xs->find($this->contractContentSubsetXpath)->innerHTML();
                }

                // Store it to a local location:
                file_put_contents($directoryPath . '/' . $filename, $pageSource);

                // Optionally sleep for a certain amount of time (eg. 0.1 seconds) in between fetches to avoid angry sysadmins:
                if (env('FETCH_SLEEP_BETWEEN_DOWNLOADS', 0)) {
                    sleep(env('FETCH_SLEEP_BETWEEN_DOWNLOADS', 0));
                }

                // This can now be configured per-department
                // The two are cumulative (eg. you could have a system-wide sleep configuration, and a department-specific, and it'll sleep for both durations.)
                if ($this->sleepBetweenDownloads) {
                    sleep($this->sleepBetweenDownloads);
                }
            }

            
            
            return true;
        } else {
            $this->totalAlreadyDownloaded += 1;
            return false;
        }
    }

    public function saveMetadata($url)
    {

        // Only save metadata if we have anything useful:
        if (! $this->activeFiscalYear) {
            return false;
        }

        $filename = Paths::generateFilenameFromUrl($this->removeSessionIdsFromUrl($url), '.json');
        $directoryPath = storage_path() . '/' . env('FETCH_METADATA_FOLDER', 'metadata') . '/' . $this->ownerAcronym;


        // If the folder doesn't exist yet, create it:
        // Thanks to http://stackoverflow.com/a/15075269/756641
        if (! is_dir($directoryPath)) {
            mkdir($directoryPath, 0755, true);
        }

        $output = [
            'sourceURL' => $this->removeSessionIdsFromUrl($url),
            'sourceYear' => intval($this->activeFiscalYear),
            'sourceQuarter' => intval($this->activeFiscalQuarter),
        ];

        if (file_put_contents($directoryPath . '/' . $filename, json_encode($output, JSON_PRETTY_PRINT))) {
            return true;
        }

        return false;
    }

    // Primary function to parse pages:
    public function parse()
    {

        $startDate = date('Y-m-d H:i:s');
        echo "Starting to parse " . $this->ownerAcronym . " at ". $startDate . " \n";

        $sourceDirectory = Paths::getSourceDirectoryForDepartment($this->ownerAcronym);

        if (env('PARSE_CLEAN_VENDOR_NAMES', 1) == 1) {
            $vendorData = new VendorData;
        } else {
            $vendorData = null;
        }

        // Output directory:
        $directoryPath = storage_path() . '/' . env('PARSE_JSON_OUTPUT_FOLDER', 'generated-data') . '/' . $this->ownerAcronym;

        // If the folder doesn't exist yet, create it:
        // Thanks to http://stackoverflow.com/a/15075269/756641
        if (! is_dir($directoryPath)) {
            mkdir($directoryPath, 0755, true);
        }


        $validFiles = [];
        $files = array_diff(scandir($sourceDirectory), ['..', '.']);

        foreach ($files as $file) {
            // Check if it ends with .html
            $suffix = '.html';
            if (substr_compare($file, $suffix, -strlen($suffix)) === 0) {
                $validFiles[] = $file;
            }
        }

        $filesParsed = 0;
        foreach ($validFiles as $file) {
            if (env('PARSE_LIMIT_FILES', 2) && $filesParsed >= env('PARSE_LIMIT_FILES', 2)) {
                break;
            }

            // echo "$file\n";

            $filehash = explode('.', $file)[0];

            // Retrieve the values from the department-specific file parser
            // And merge these with the default values
            // Just to guarantee that all the array keys are around:
            $fileValues = array_merge(self::$rowParams, $this->parseFile($file));

            $metadata = $this->getMetadata($file);

            if ($fileValues) {
                ContractDataProcessor::cleanParsedArray($fileValues);
                // var_dump($fileValues);

                $fileValues = array_merge($fileValues, $metadata);

                ContractDataProcessor::generateAdditionalMetadata($fileValues);

                $fileValues['ownerAcronym'] = $this->ownerAcronym;

                $fileValues['objectCode'] = Helpers::extractObjectCodeFromDescription($fileValues['description']);

                // Useful for troubleshooting:
                $fileValues['sourceFilename'] = $this->ownerAcronym . '/' . $file;

                // A lot of DND's entries are missing reference numbers:
                if (! $fileValues['referenceNumber']) {
                    echo "Warning: no reference number.\n";

                    $fileValues['referenceNumber'] = $filehash;
                }

                // Final check for missing values, etc.
                if (env('PARSE_CLEAN_CONTRACT_VALUES', 1) == 1) {
                    if (env('PARSE_CLEAN_VENDOR_NAMES', 1) == 1) {
                        Helpers::assureRequiredContractValues($fileValues, $vendorData);
                    } else {
                        Helpers::assureRequiredContractValues($fileValues);
                    }
                }
                

                // TODO - update this to match the schema discussed at 2017-03-28's Civic Tech!
                $fileValues['uuid'] = $this->ownerAcronym . '-' . $fileValues['referenceNumber'];

                if (file_put_contents($directoryPath . '/' . $filehash . '.json', json_encode($fileValues, JSON_PRETTY_PRINT))) {
                    // echo "...saved.\n";
                } else {
                    echo "...failed to save JSON output for $file.\n";
                }
            } else {
                echo "Error: could not parse data for $file\n";
            }



            $filesParsed++;
        }
        // var_dump($validFiles);

        echo "...started " . $this->ownerAcronym . " at " . $startDate . "\n";
        echo "Finished parsing $filesParsed files at ". date('Y-m-d H:i:s') . " \n\n";
    }

    public function getMetadata($htmlFilename)
    {

        $filename = str_replace('.html', '.json', $htmlFilename);

        $filepath = Paths::getMetadataDirectoryForDepartment($this->ownerAcronym) . '/' . $filename;

        if (file_exists($filepath)) {
            $source = file_get_contents($filepath);
            $metadata = json_decode($source, 1);

            if (is_array($metadata)) {
                return $metadata;
            }
        }

        return [];
    }

    public function parseFile($filename)
    {

        $acronym = $this->ownerAcronym;

        $source = file_get_contents(Paths::getSourceDirectoryForDepartment($this->ownerAcronym) . '/' . $filename);

        $source = Helpers::applyInitialSourceHtmlTransformations($source);

        // return call_user_func( array( 'App\\DepartmentHandlers\\' . ucfirst($acronym) . 'Handler', 'parseHtml' ), $source );

        return $this->parseHtml($source);
    }

    /**
     * Parse the HTML of a given contract, converting the data to
     * an associative array.
     *
     * @param string $source  The contract content HTML.
     *
     * @return array  The extracted contract data.
     */
    abstract public function parseHtml($source);
}
