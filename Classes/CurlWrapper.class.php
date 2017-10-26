<?php

/**
 * Class CurlWrapper
 */
class CurlWrapper{
    /**
     * @var resource
     */
    private $ch;
    /**
     * @var array
     */
    private $ch_headers;

    /**
     * CurlWrapper constructor.
     */
    public function __construct()
    {
        // Create cURL object.
        $this->ch = $this->curl_init();

        // Initialize cURL headers
        $this->ch_headers = $this->curl_headers_init();
    }

    /**
     * @return resource
     */
    private function curl_init()
    {
        $ch = curl_init();
        // Follow any Location: headers that the server sends.
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        // However, don't follow more than five Location: headers.
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        // Automatically set the Referrer: field in requests
        // following a Location: redirect.
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        // Return the transfer as a string instead of dumping to screen.
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // If it takes more than 45 seconds, fail
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);
        // We don't want the header (use curl_getinfo())
        curl_setopt($ch, CURLOPT_HEADER, false);
        // Track the handle's request string
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        // Attempt to retrieve the modification date of the remote document.
        curl_setopt($ch, CURLOPT_FILETIME, true);
        // Follow redirects
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        return $ch;
    }

    /**
     * Sets the custom cURL headers.
     */
    private function curl_headers_init()
    {
        $date = new DateTime(null, new DateTimeZone('UTC'));
        return array(
            'Date: ' . $date->format('D, d M Y H:i:s') . ' GMT', // RFC 1123
            'Accept-Charset: utf-8',
            'Accept-Encoding: none'
        );
    }

    /**
     * @param string $method // HTTP method (GET, POST)
     * @param string $uri // URI fragment to CKAN resource
     * @param string $data // Optional. String in JSON-format that will be in request body
     *
     * @return mixed    // If success, either an array or object. Otherwise FALSE.
     * @throws Exception
     */
    private function curl_make_request(
        $method,
        $uri,
        $data = null
    )
    {
        $method = strtoupper($method);
        if (!in_array($method, array('GET', 'POST'))) {
            throw new Exception('Method ' . $method . ' is not supported');
        }
        // Set cURL URI.
        curl_setopt($this->ch, CURLOPT_URL, $uri);
        if ($method === 'POST') {
            if ($data) {
                curl_setopt($this->ch, CURLOPT_POSTFIELDS, urlencode($data));
            } else {
                $method = 'GET';
            }
        }

        // Set cURL method.
        curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, $method);

        // Set headers.
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, $this->ch_headers);
        // Execute request and get response headers.
        $response = curl_exec($this->ch);
        $info = curl_getinfo($this->ch);
        // Check HTTP response code
        if ($info['http_code'] !== 200) {
            switch ($info['http_code']) {
                case 404:
                    throw new Exception($data);
                    break;
                default:
                    throw new Exception(
                        $info['http_code'] . ': ' .
                        $this->http_status_codes[$info['http_code']] . PHP_EOL . $data . PHP_EOL
                    );
            }
        }

        return $response;
    }

    /**
     * @param $url
     *
     * @return mixed
     */
    public function get(
        $url
    )
    {
        if ('http' != substr($url, 0, 4)) {
            $url = 'https:' . $url;
        }

        try {
            $result = $this->curl_make_request('GET', $url);
        } catch (Exception $ex) {
            echo '<hr />' . $url . '<br />';
            echo $ex->getMessage() . '<hr />';
            $result = false;
        }

        return $result;
    }
}