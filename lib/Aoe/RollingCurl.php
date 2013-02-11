<?php
/*
Authored by Josh Fraser (www.joshfraser.com)
Released under Apache License 2.0

Maintained by Alexander Makarov, http://rmcreative.ru/

$Id$
*/

/**
 * Class that represent a single curl request
 */
class Aoe_RollingCurlRequest
{
    public $url = false;
    public $method = 'GET';
    public $postData = null;
    public $headers = null;
    public $options = null;

    /**
     * @param string $url
     * @param string $method
     * @param  $postData
     * @param  $headers
     * @param  $options
     * @return Aoe_RollingCurlRequest
     */
    public function __construct($url, $method = "GET", $postData = null, $headers = null, $options = null)
    {
        $this->url      = $url;
        $this->method   = $method;
        $this->postData = $postData;
        $this->headers  = $headers;
        $this->options  = $options;
    }

    /**
     * @return void
     */
    public function __destruct()
    {
        unset($this->url, $this->method, $this->postData, $this->headers, $this->options);
    }
}

/**
 * RollingCurl custom exception
 */
class Aoe_RollingCurlException extends Exception
{
}

/**
 * Class that holds a rolling queue of curl requests.
 *
 * @throws Aoe_RollingCurlException
 */
class Aoe_RollingCurl
{
    /**
     * Window size is the max number of simultaneous connections allowed.
     *
     * REMEMBER TO RESPECT THE SERVERS:
     * Sending too many requests at one time can easily be perceived
     * as a DOS attack. Increase this window_size if you are making requests
     * to multiple servers or have permission from the receving server admins.
     *
     * @var int
     */
    private $windowSize = 5;

    /**
     * Timeout is the timeout used for curl_multi_select.
     *
     * @var float
     */
    private $timeout = 10;

    /**
     * Callback function to be applied to each result.
     *
     * @var string|array
     */
    private $callback;

    /**
     * Set your base options that you want to be used with EVERY request.
     *
     * @var array
     */
    protected $options = array(
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT        => 30
    );

    /**
     * @var array
     */
    private $headers = array();

    /**
     * The request queue
     *
     * @var Aoe_RollingCurlRequest[]
     */
    private $requests = array();

    /**
     * Maps handles to request indexes
     *
     * @var array
     */
    private $requestMap = array();

    /**
     * @param  $callback
     * Callback function to be applied to each result.
     *
     * Can be specified as 'my_callback_function'
     * or array($object, 'my_callback_method').
     *
     * Function should take three parameters: $response, $info, $request.
     * $response is response body, $info is additional curl info.
     * $request is the original request
     *
     * @return Aoe_RollingCurl
     */
    public function __construct($callback = null)
    {
        $this->callback = $callback;
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function __get($name)
    {
        return (isset($this->{$name})) ? $this->{$name} : null;
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return bool
     */
    public function __set($name, $value)
    {
        // append the base options & headers
        if ($name == "options" || $name == "headers") {
            $this->{$name} = $value + $this->{$name};
        } else {
            $this->{$name} = $value;
        }

        return true;
    }

    /**
     * Add a request to the request queue
     *
     * @param Aoe_RollingCurlRequest $request
     * @return bool
     */
    public function add($request)
    {
        $this->requests[] = $request;

        return true;
    }

    /**
     * Create new Request and add it to the request queue
     *
     * @param string $url
     * @param string $method
     * @param  $post_data
     * @param  $headers
     * @param  $options
     * @return bool
     */
    public function request($url, $method = "GET", $post_data = null, $headers = null, $options = null)
    {
        $this->requests[] = new Aoe_RollingCurlRequest($url, $method, $post_data, $headers, $options);

        return true;
    }

    /**
     * Perform GET request
     *
     * @param string $url
     * @param  $headers
     * @param  $options
     * @return bool
     */
    public function get($url, $headers = null, $options = null)
    {
        return $this->request($url, "GET", null, $headers, $options);
    }

    /**
     * Perform POST request
     *
     * @param string $url
     * @param  $post_data
     * @param  $headers
     * @param  $options
     * @return bool
     */
    public function post($url, $post_data = null, $headers = null, $options = null)
    {
        return $this->request($url, "POST", $post_data, $headers, $options);
    }

    /**
     * Execute processing
     *
     * @param int $windowSize Max number of simultaneous connections
     * @return string|bool
     */
    public function execute($windowSize = null)
    {
        // rolling curl window must always be greater than 1
        if (sizeof($this->requests) == 1) {
            return $this->singleCurl();
        } else {
            // start the rolling curl. window_size is the max number of simultaneous connections
            return $this->rollingCurl($windowSize);
        }
    }

    /**
     * Performs a single curl request
     *
     * @return string
     */
    private function singleCurl()
    {
        $ch      = curl_init();
        $request = array_shift($this->requests);
        $options = $this->getOptions($request);
        curl_setopt_array($ch, $options);
        $output = curl_exec($ch);

        // it's not necessary to set a callback for one-off requests
        if ($this->callback) {
            $callback = $this->callback;
            if (is_callable($this->callback)) {
                call_user_func($callback, curl_errno($ch), $ch, $request);
            }
        } else {
            return $output;
        }

        return true;
    }

    /**
     * Performs multiple curl requests
     *
     * @throws Aoe_RollingCurlException
     * @param int $windowSize Max number of simultaneous connections
     * @return bool
     */
    private function rollingCurl($windowSize = null)
    {
        if ($windowSize) {
            $this->windowSize = $windowSize;
        }

        // make sure the rolling window isn't greater than the # of urls
        if (sizeof($this->requests) < $this->windowSize) {
            $this->windowSize = sizeof($this->requests);
        }

        if ($this->windowSize < 2) {
            throw new Aoe_RollingCurlException("Window size must be greater than 1");
        }

        $master = curl_multi_init();

        // start the first batch of requests
        for ($i = 0; $i < $this->windowSize; $i++) {
            $ch = curl_init();

            $options = $this->getOptions($this->requests[$i]);

            curl_setopt_array($ch, $options);
            curl_multi_add_handle($master, $ch);

            // Add to our request Maps
            $key                    = (string)$ch;
            $this->requestMap[$key] = $i;
        }

        do {
            while (($execrun = curl_multi_exec($master, $running)) == CURLM_CALL_MULTI_PERFORM) ;
            if ($execrun != CURLM_OK) {
                break;
            }
            // a request was just completed -- find out which one
            while ($done = curl_multi_info_read($master)) {

                // send the return values to the callback function.
                $callback = $this->callback;
                if (is_callable($callback)) {
                    $key     = (string)$done['handle'];
                    $request = $this->requests[$this->requestMap[$key]];
                    unset($this->requestMap[$key]);
                    call_user_func($callback, $done['result'], $done['handle'], $request);
                }

                // start a new request (it's important to do this before removing the old one)
                if ($i < sizeof($this->requests) && isset($this->requests[$i]) && $i < count($this->requests)) {
                    $ch      = curl_init();
                    $options = $this->getOptions($this->requests[$i]);
                    curl_setopt_array($ch, $options);
                    curl_multi_add_handle($master, $ch);

                    // Add to our request Maps
                    $key                    = (string)$ch;
                    $this->requestMap[$key] = $i;
                    $i++;
                }

                // remove the curl handle that just completed
                curl_multi_remove_handle($master, $done['handle']);
            }

            // Block for data in / output; error handling is done by curl_multi_exec
            if ($running) {
                curl_multi_select($master, $this->timeout);
            }

        } while ($running);
        curl_multi_close($master);

        return true;
    }

    /**
     * Helper function to set up a new request by setting the appropriate options
     *
     * @param Aoe_RollingCurlRequest $request
     * @return array
     */
    private function getOptions($request)
    {
        // options for this entire curl object
        $options = $this->__get('options');
        if (ini_get('safe_mode') == 'Off' || !ini_get('safe_mode')) {
            $options[CURLOPT_FOLLOWLOCATION] = 1;
            $options[CURLOPT_MAXREDIRS]      = 5;
        }
        $headers = $this->__get('headers');

        // append custom options for this specific request
        if ($request->options) {
            $options = $request->options + $options;
        }

        // set the request URL
        $options[CURLOPT_URL] = $request->url;

        // posting data w/ this request?
        if ($request->postData) {
            $options[CURLOPT_POST]       = 1;
            $options[CURLOPT_POSTFIELDS] = $request->postData;
        }
        if ($headers) {
            $options[CURLOPT_HEADER]     = 0;
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        return $options;
    }

    /**
     * @return void
     */
    public function __destruct()
    {
        unset($this->windowSize, $this->callback, $this->options, $this->headers, $this->requests);
    }
}
