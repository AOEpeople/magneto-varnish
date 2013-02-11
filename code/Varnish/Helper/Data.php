<?php

class Magneto_Varnish_Helper_Data extends Mage_Core_Helper_Abstract
{
    /**#@+
     * Xml paths to different module settings
     */
    const CONFIG_XML_PATH_VARNISH_SERVERS         = 'varnish/options/servers';
    const CONFIG_XML_PATH_PARALLEL_REQUEST_NUMBER = 'varnish/options/parallel_request_number';
    /**#@-*/

    /**
     * List of varnish server(s) request errors
     *
     * @var array
     */
    protected $_errors = array();

    /**
     * Curl options which will be used in each request to varnish server(s)
     *
     * @var array
     */
    protected $_requestOptions = array(
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_CUSTOMREQUEST  => 'PURGE'
    );

    /**
     * Check if varnish is enabled in Cache management.
     *
     * @return boolean  True if varnish is enable din Cache management.
     */
    public function useVarnishCache()
    {
        return Mage::app()->useCache('varnish');
    }

    /**
     * Return varnish servers from configuration
     *
     * @return array
     */
    public function getVarnishServers()
    {
        $serverConfig   = Mage::getStoreConfig(self::CONFIG_XML_PATH_VARNISH_SERVERS);
        $varnishServers = array();

        foreach (explode(',', $serverConfig) as $value) {
            $varnishServers[] = trim($value);
        }

        return $varnishServers;
    }

    /**
     * Return number of parallel curl requests to varnish servers from configuration
     *
     * @return int
     */
    public function getParallelRequestNumber()
    {
        return (int) Mage::getStoreConfig(self::CONFIG_XML_PATH_PARALLEL_REQUEST_NUMBER);
    }

    /**
     * Purges all cache on all Varnish servers.
     *
     * @return array errors if any
     */
    public function purgeAll()
    {
        return $this->purge(array('/.*'));
    }

    /**
     * Purge an array of urls on all varnish servers.
     *
     * @param array $urls
     * @return array with all errors
     */
    public function purge(array $urls)
    {
        $varnishServers = $this->getVarnishServers();
        $this->_errors  = array();
        $rollingCurl    = new Aoe_RollingCurl(array($this, 'analyzeResponse'));

        foreach ($varnishServers as $varnishServer) {
            foreach ($urls as $url) {
                $varnishUrl = "http://" . $varnishServer . $url;
                $rollingCurl->request($varnishUrl, "GET", null, null, $this->_requestOptions);
            }
        }

        $rollingCurl->execute($this->getParallelRequestNumber());

        $this->logAdminAction(
            empty($this->_errors),
            implode(', ', $urls),
            null,
            $this->_errors
        );

        return $this->_errors;
    }

    /**
     * Add varnish request processing error
     *
     * @param int $errorNumber
     * @param resource $handle
     */
    public function analyzeResponse($errorNumber, $handle)
    {
        $info = curl_getinfo($handle);

        if ($errorNumber !== CURLE_OK) {
            $this->_errors[] = "Cannot purge url {$info['url']} due to error" . curl_error($handle);
        } elseif ($info['http_code'] != 200 && $info['http_code'] != 404) {
            $this->_errors[] = "Cannot purge url {$info['url']}, http code: {$info['http_code']}. curl error: "
                . curl_error($handle);
        }
    }

    /**
     * Log admin action
     *
     * @param bool $success
     * @param null $generalInfo
     * @param null $additionalInfo
     * @param array $errors
     * @return void
     */
    protected function logAdminAction($success = true, $generalInfo = null, $additionalInfo = null, $errors = array())
    {
        $eventCode = 'varnish_purge'; // this needs to match the code in logging.xml

        if (!Mage::getSingleton('enterprise_logging/config')->isActive($eventCode, true)) {
            return;
        }

        $username = null;
        $userId   = null;
        if (Mage::getSingleton('admin/session')->isLoggedIn()) {
            $userId   = Mage::getSingleton('admin/session')->getUser()->getId();
            $username = Mage::getSingleton('admin/session')->getUser()->getUsername();
        }

        $request = Mage::app()->getRequest();

        Mage::getSingleton('enterprise_logging/event')->setData(array(
            'ip'              => Mage::helper('core/http')->getRemoteAddr(),
            'x_forwarded_ip'  => Mage::app()->getRequest()->getServer('HTTP_X_FORWARDED_FOR'),
            'user'            => $username,
            'user_id'         => $userId,
            'is_success'      => $success,
            'fullaction'      => "{$request->getRouteName()}_{$request->getControllerName()}_{$request->getActionName()}",
            'event_code'      => $eventCode,
            'action'          => 'purge',
            'info'            => $generalInfo,
            'additional_info' => $additionalInfo,
            'error_message'   => implode("\n", $errors),
        ))->save();
    }
}
