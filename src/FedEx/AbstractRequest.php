<?php
namespace FedEx;

/**
 * Abstract class for Request classes
 *
 * @author      Jeremy Dunn <jeremy@jsdunn.info>
 * @package     PHP FedEx API wrapper
 */
use FedEx\Http\HttpClient;

abstract class AbstractRequest
{
    /**
     * URL to production environment
     */
    const PRODUCTION_URL = null;

    /**
     * URL to testing environment
     */
    const TESTING_URL = null;

    /**
     * SoapClient object
     *
     * @var SoapClient|null
     */
    private $soapClient;

    /**
     * @var HttpClient|null
     */
    private $httpClient;

    /**
     * @var bool
     */
    protected $useProductionEndpoint = false;

    /**
     * @var bool
     */
    protected static $usesSoap = true;

    /**
     * Full, absolute path to WSDL file
     *
     * @var string
     */
    protected static $wsdlPath;

    /**
     * WSDL file name
     *
     * @var string
     */
    protected static $wsdlFileName;

    /**
     * Constructor
     *
     * @param \SoapClient|null $soapClient
     */
    public function __construct(?\SoapClient $soapClient = null, ?HttpClient $httpClient = null)
    {
        if (static::$usesSoap) {
            $this->soapClient = $soapClient ?: new \SoapClient(static::getWsdlPath(), ['trace' => true]);
        } else {
            $this->soapClient = $soapClient;
        }

        $this->httpClient = $httpClient;
    }

    /**
     * Returns absolute path to .wsdl file
     *
     * @return string|bool
     */
    public static function getWsdlPath()
    {
        return realpath(__DIR__ . '/../FedEx/_wsdl/' . static::$wsdlFileName);
    }

    /**
     * Returns the SoapClient instance
     * for backwards compatibility
     *
     * @return \SoapClient
     */
    public function getSoapClient()
    {
        return $this->soapClient;
    }

    /**
     * Returns the HttpClient instance used for REST requests
     *
     * @return HttpClient
     */
    protected function getHttpClient(): HttpClient
    {
        if (!$this->httpClient) {
            $this->httpClient = new HttpClient();
        }

        return $this->httpClient;
    }

    /**
     * Toggle between production and test environments.
     *
     * @param bool $useProductionEndpoint
     * @return $this
     */
    public function useProductionEnvironment(bool $useProductionEndpoint = true)
    {
        $this->useProductionEndpoint = $useProductionEndpoint;

        return $this;
    }

    /**
     * Builds a fully-qualified endpoint URL for REST requests.
     *
     * @param string $path
     * @return string
     */
    protected function resolveEndpoint(string $path = ''): string
    {
        $base = $this->useProductionEndpoint ? static::PRODUCTION_URL : static::TESTING_URL;
        if ($base === null) {
            throw new \RuntimeException('Endpoint URL not configured for this request.');
        }

        if ($path === '') {
            return rtrim($base, '/');
        }

        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}
