<?php
/**
 * VAST Endpoint with Enhanced Security and Functionality
 * Supports multiple DSP endpoints (tries each in sequence until a valid response is found).
 */

// CORS + headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

// header('Content-Type: application/xml; charset=utf-8');

// ==================== Configuration ====================
const DSP_ENDPOINTS = [
    'https://h1.screencore.io/?kp=wJyoKOzV6BNqcdbaX46x&kn=viadsctvhumanprebid',
    'https://bid.zmaticoo.com/humanctvdqmedia/bid',
    'https://938711.ortb.adtelligent.com/',
];
const DEFAULT_TIMEOUT_MS   = 2000;
const MAX_RETRIES          = 2;

const DEFAULT_WIDTH        = 1920;
const DEFAULT_HEIGHT       = 1080;
const MAX_WIDTH            = 3840;
const MAX_HEIGHT           = 2160;
const DEFAULT_BIDFLOOR     = 3;
const DEFAULT_BIDFLOOR_CUR = 'USD';
const DEFAULT_MIN_DURATION = 5;
const DEFAULT_MAX_DURATION = 30;

// ==================== Error Handling ====================
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors',     '0'); // Do not log anything

// ==================== Helper Functions ====================

function generateRequestId(): string {
    try {
        return bin2hex(random_bytes(16));
    } catch (Exception $e) {
        return uniqid('fallback-', true);
    }
}

function buildEmptyVAST(): string {
    $xml = new SimpleXMLElement('<VAST/>');
    $xml->addAttribute('version', '4.0');
    return $xml->asXML();
}

function clamp_int(int $value, int $min, int $max): int {
    return max($min, min($value, $max));
}

/**
 * Validate and sanitize only the fields we actually use:
 * width, height, sid, ua, uip, app_name, app_bundle
 */
function sanitizeInput(array $input): array {
    $width = filter_var($input['width']  ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => MAX_WIDTH]
    ]);
    $height = filter_var($input['height'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => MAX_HEIGHT]
    ]);

    // Guarantee sane defaults
    $width  = clamp_int($width  ?: DEFAULT_WIDTH,  1, MAX_WIDTH);
    $height = clamp_int($height ?: DEFAULT_HEIGHT, 1, MAX_HEIGHT);

    $sid = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['sid'] ?? 'default_app');
    $ua  = substr(strip_tags($input['ua']  ?? ($_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0')), 0, 255);
    $uip = filter_var($input['uip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
                     FILTER_VALIDATE_IP) ?: '127.0.0.1';

    $app_name   = substr(preg_replace('/[^\w\s\.\-]/u', '', $input['app_name']  ?? 'CTV App'),  0, 100);
    $app_bundle = preg_replace('/[^a-zA-Z0-9._-]/', '', $input['app_bundle'] ?? 'com.example.ctv');

    return [
        'width'      => $width,
        'height'     => $height,
        'sid'        => $sid,
        'ua'         => $ua,
        'uip'        => $uip,
        'app_name'   => $app_name,
        'app_bundle' => $app_bundle,
    ];
}

function makeDspRequest(string $endpoint, array $ortbRequest, int $timeout = DEFAULT_TIMEOUT_MS): ?array {
    $retryCount = 0;

    do {
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $endpoint,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($ortbRequest),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS     => $timeout,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'X-Forwarded-For: ' . ($ortbRequest['device']['ip'] ?? ''),
                    'User-Agent: '     . ($ortbRequest['device']['ua'] ?? ''),
                    'x-openrtb-version: 2.5',
                    'Accept: application/json'
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FAILONERROR    => false,
            ]);

            $response = curl_exec($ch);
            if ($response === false) {
                $err = curl_error($ch);
                curl_close($ch);
                throw new Exception("CURL error: {$err}", 502);
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $data = json_decode($response, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $data;
                }
                throw new Exception("JSON decode error", 502);
            }

            throw new Exception("HTTP {$httpCode} response", $httpCode);

        } catch (Exception $e) {
            if ($retryCount < MAX_RETRIES) {
                usleep(100000 * (2 ** $retryCount));
            }
        }
    } while (++$retryCount <= MAX_RETRIES);

    return null;
}

function makeMultiDspRequest(array $ortbRequest, int $timeout = DEFAULT_TIMEOUT_MS): array {
    foreach (DSP_ENDPOINTS as $endpoint) {
        $dspResponse = makeDspRequest($endpoint, $ortbRequest, $timeout);
        if (is_array($dspResponse) && !empty($dspResponse['seatbid'])) {
            return $dspResponse;
        }
    }
    throw new Exception("No valid response from any DSP endpoint", 502);
}

function isCreativeCompatible(array $bid, int $width, int $height): bool {
    $cw = $bid['w'] ?? 0;
    $ch = $bid['h'] ?? 0;
    if ($cw <= 0 || $ch <= 0) {
        return true;
    }
    $rReq = $width  / $height;
    $rBid = $cw     / $ch;
    return abs($rReq - $rBid) < 0.1;
}

function processBids(array $dspResponse, int $width, int $height): array {
    if (empty($dspResponse['seatbid'])) {
        throw new Exception("No seatbids in DSP response", 204);
    }
    $bestBid     = null;
    $highestPrice = 0.0;

    foreach ($dspResponse['seatbid'] as $seatbid) {
        foreach ($seatbid['bid'] ?? [] as $bid) {
            if (empty($bid['adm'])) {
                continue;
            }
            $price = $bid['price'] ?? 0;
            if ($price >= $highestPrice && isCreativeCompatible($bid, $width, $height)) {
                $bestBid      = $bid;
                $highestPrice = $price;
            }
        }
    }

    if (!$bestBid) {
        throw new Exception("No valid compatible bids received", 204);
    }

    return $bestBid;
}

function processVAST(string $vastXml, ?float $price = null): string {
    if (trim($vastXml) === '') {
        throw new Exception("Empty VAST response from DSP", 500);
    }
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (!$dom->loadXML($vastXml)) {
        libxml_clear_errors();
        throw new Exception("Invalid VAST XML", 500);
    }

    if (strpos($vastXml, '${AUCTION_PRICE}') !== false) {
        $priceStr = $price !== null
            ? number_format($price, 4, '.', '')
            : "0.0000";
        $vastXml = str_replace('${AUCTION_PRICE}', $priceStr, $vastXml);

        if (!$dom->loadXML($vastXml)) {
            libxml_clear_errors();
            throw new Exception("VAST invalid after macro replacement", 500);
        }
    }

    return $vastXml;
}

// ==================== Main Execution ====================
$requestId = generateRequestId();
header('X-Request-ID: ' . $requestId);

try {
    // Load and parse config.json
    $configFile = __DIR__ . '/config.json';
    if (!file_exists($configFile)) {
        throw new Exception("Config file not found", 500);
    }
    $configData = json_decode(file_get_contents($configFile), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON in config file", 500);
    }

    // Sanitize core inputs
    $params = sanitizeInput($configData);

    // Read and clamp duration settings from config
    $minduration = clamp_int(
        (int)($configData['minduration'] ?? DEFAULT_MIN_DURATION),
        DEFAULT_MIN_DURATION,
        DEFAULT_MAX_DURATION
    );
    $maxduration = clamp_int(
        (int)($configData['maxduration'] ?? DEFAULT_MAX_DURATION),
        DEFAULT_MIN_DURATION,
        DEFAULT_MAX_DURATION
    );
    
    // Build OpenRTB request
    $ortbRequest = [
        'id'   => $requestId,
        'imp'  => [[
            'id'       => generateRequestId(),
            'video'    => [
                'w'           => $params['width'],
                'h'           => $params['height'],
                'mimes'       => ['video/mp4', 'video/webm'],
                'linearity'   => 1,
                'minduration'=> $minduration,
                'maxduration'=> $maxduration,
                'protocols'   => [2,3,5,6,7,8],
                'startdelay'  => 0,
                'api'         => [1,2],
            ],
            'bidfloor'    => DEFAULT_BIDFLOOR,
            'bidfloorcur' => $_POST['currency'] || DEFAULT_BIDFLOOR_CUR,
        ]],
        'app'   => [
            'id'        => $params['sid'],
            'name'      => $params['app_name'],
            'bundle'    => $params['app_bundle'],
            'publisher' => ['id' => $params['sid']],
        ],
        'device'=> [
            'ua'         => $params['ua'],
            'ip'         => $params['uip'],
            'devicetype' => 3,
        ],
        'user'  => [
            'id' => generateRequestId(),
        ],
        'at'    => 1,
        // 'tmax'  => 1500,
        'tmax'  => 0,
    ];

    // Query DSPs
    // $dspResponse = makeMultiDspRequest($ortbRequest);
    $dspIndex = 0;
    if ($_POST['dsp']) {
        $dspIndex = intval($_POST['dsp']) - 1;
    }
    $DSPEndpointURL = DSP_ENDPOINTS[$dspIndex];
    $dspResponse = makeDspRequest($DSPEndpointURL, $ortbRequest, DEFAULT_TIMEOUT_MS);

    if (is_array($dspResponse) && !empty($dspResponse['seatbid'])) {
        $winningBid  = processBids($dspResponse, $params['width'], $params['height']);
        $vastXml     = processVAST($winningBid['adm'], $winningBid['price'] ?? null);

        echo $vastXml;
    } else {
        throw new Exception("No valid response from any DSP endpoint", 502);
    }
    
    exit(0);

} catch (Exception $e) {
    $code = $e->getCode();
    if ($code < 100 || $code >= 600) {
        $code = 500;
    }
    http_response_code($code);
    echo buildEmptyVAST();
    exit(1);
}
