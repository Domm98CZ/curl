<?php
declare(strict_types=1);

namespace Domm98CZ\CurlClient;

use CurlHandle;
use Domm98CZ\CurlClient\Exceptions\ShouldNotHappenException;
use Domm98CZ\CurlClient\Exceptions\SingleRuntimeException;

class CurlClient
{
    public const
        CURL_METHOD_GET = 'GET',
        CURL_METHOD_POST = 'POST',
        CURL_METHOD_PUT = 'PUT',
        CURL_METHOD_HEAD = 'HEAD',
        CURL_METHOD_DELETE = 'DELETE',
        CURL_METHOD_PATCH = 'PATCH',
        CURL_METHOD_OPTIONS = 'OPTIONS'
    ;

    public const
        CURL_PROTOCOL_HTTP = 'http',
        CURL_PROTOCOL_HTTPS = 'https',
        CURL_PROTOCOL_FTP = 'ftp',
        CURL_PROTOCOL_FTPS = 'ftps',
        CURL_PROTOCOL_SMTP = 'smtp',
        CURL_PROTOCOL_NTP = 'ntp'
    ;

    private CurlHandle $ch;

    private bool $use_cache = false;
    private ?bool $ssl_verify_host = null;
    private ?bool $ssl_verify_peer = null;

    private array $headers = [];
    private array $customOptions = [];

    private ?array $curlInfo;

    private string $postFields = '';
    private ?string $method = null;
    private ?string $curlError;
    private ?string $uri = null;
    private ?string $cert = null;
    private string|bool $response;

    private int $timeout = 0;
    private ?int $httpCode;
    private array $response_header = [];

    /**
     * @param string $uri
     * @param array $queryArgs
     * @param bool $doUseUrlEncode
     * @param bool $useCache
     * @return void
     * @throws SingleRuntimeException|ShouldNotHappenException
     */
    public function createRequest(string $uri, array $queryArgs = [], bool $doUseUrlEncode = false, bool $useCache = false): void
    {
        $this->setUseCache($useCache);

        if(!empty($queryArgs)) {
            $query = parse_url($uri, PHP_URL_QUERY);
            $firstArg = !$query;
            foreach($queryArgs as $key => $value) {

                if($doUseUrlEncode) {
                    $value = rawurlencode(utf8_encode($value));
                }

                if($firstArg) {
                    $uri .= sprintf('?%s=%s', $key, $value);
                    $firstArg = false;
                } else {
                    $uri .= sprintf('&%s=%s', $key, $value);
                }
            }
        }

        if (!isset($this->ch)) {
            $this->ch = $this->configureCurlClientBase($uri);
        } else {
            throw new SingleRuntimeException('Curl client is already running.');
        }
    }

    /**
     * @return array
     */
    public function getResponseHeader(): array
    {
        return $this->response_header;
    }

    /**
     * @param array $response_header
     * @return $this
     */
    public function setResponseHeader(array $response_header): CurlClient
    {
        $this->response_header = $response_header;
        return $this;
    }

    /**
     *
     */
    public function execute(): void
    {
        $this->configureCurlClientRequest($this->ch);
        $this->configureCurlClientResponse($this->ch);

        unset($this->ch);
    }

    /**
     * @param CurlHandle $curlClient
     * @return CurlHandle
     */
    private function configureCurlClientRequest(CurlHandle &$curlClient): CurlHandle
    {
        if($this->isSslVerifyHost() !== null) {
            curl_setopt($curlClient, CURLOPT_SSL_VERIFYHOST, $this->isSslVerifyHost() ? '2' : false);
        }

        if($this->isSslVerifyPeer() !== null) {
            curl_setopt($curlClient, CURLOPT_SSL_VERIFYPEER, $this->isSslVerifyPeer());
        }

        if($this->getCert() !== null) {
            curl_setopt($curlClient, CURLOPT_CAINFO, $this->getCert());
        }

        curl_setopt($curlClient, CURLOPT_HEADER, true);
        curl_setopt($curlClient, CURLOPT_RETURNTRANSFER, true);

        if(!$this->isUseCache()) {
            $this->addHeaders('Cache-Control: no-cache');
            curl_setopt($curlClient, CURLOPT_FRESH_CONNECT, true);
        }

        curl_setopt($curlClient, CURLOPT_HTTPHEADER, $this->getHeaders());

        if($this->getMethod() === self::CURL_METHOD_POST) {
            curl_setopt($curlClient, CURLOPT_POST, true);
        } else {
            curl_setopt($curlClient, CURLOPT_CUSTOMREQUEST, $this->getMethod() ?? self::CURL_METHOD_GET);
        }

        if (!empty($this->getCustomOptions())) {
            foreach ($this->getCustomOptions() as $option => $value) {
                curl_setopt($curlClient, $option, $value);
            }
        }

        curl_setopt($curlClient, CURLOPT_FRESH_CONNECT, $this->isUseCache());
        curl_setopt($curlClient, CURLOPT_POSTFIELDS, $this->getPostFields());
        curl_setopt($curlClient, CURLOPT_TIMEOUT, $this->getTimeout());

        return $curlClient;
    }

    /**
     * @param CurlHandle $curlClient
     * @return CurlHandle
     */
    private function configureCurlClientResponse(CurlHandle &$curlClient): CurlHandle
    {
        $result = curl_exec($curlClient);

        if (curl_errno($curlClient)) {
            $this->setCurlError(curl_error($curlClient));
        }

        $header_size = curl_getinfo($curlClient, CURLINFO_HEADER_SIZE);
        $header = substr($result, 0, $header_size);
        $body = substr($result, $header_size);
        $this->setResponseHeader(self::parseHttpResponseHeaders($header));

        unset($result);

        $this->setResponse($body);

        $this->setHttpCode(curl_getinfo($curlClient, CURLINFO_HTTP_CODE));
        $this->setCurlInfo(curl_getinfo($curlClient));
        curl_close($curlClient);

        return $curlClient;
    }

    /**
     * @param string $uri
     * @return CurlHandle
     * @throws ShouldNotHappenException
     */
    private function configureCurlClientBase(string $uri): CurlHandle
    {
        $this->setUri($uri);

        $curlClient = curl_init($uri);
        if (!$curlClient instanceof CurlHandle) {
            throw new ShouldNotHappenException('CurlHandle is not valid-');
        }

        curl_setopt($curlClient, CURLOPT_FAILONERROR, true);
        curl_setopt($curlClient, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curlClient, CURLOPT_RETURNTRANSFER, true);

        return $curlClient;
    }

    /**
     * @return bool
     */
    public function isUseCache(): bool
    {
        return $this->use_cache;
    }

    /**
     * @param bool $use_cache
     */
    public function setUseCache(bool $use_cache): void
    {
        $this->use_cache = $use_cache;
    }

    /**
     * @return string|null
     */
    public function getCert(): ?string
    {
        return $this->cert;
    }

    /**
     * @param string|null $cert
     */
    public function setCert(?string $cert): void
    {
        $this->cert = $cert;
    }

    /**
     * @return string|null
     */
    public function getUri(): ?string
    {
        return $this->uri;
    }

    /**
     * @param string|null $uri
     */
    private function setUri(?string $uri): void
    {
        $this->uri = $uri;
    }

    /**
     * @return int
     */
    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * @param int $timeout
     */
    public function setTimeout(int $timeout): void
    {
        $this->timeout = $timeout;
    }

    /**
     * @return string
     */
    public function getPostFields(): string
    {
        return $this->postFields;
    }

    /**
     * @param string $postFields
     */
    public function setPostFields(string $postFields): void
    {
        $this->postFields = $postFields;
    }

    /**
     * @return bool|string
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * @param bool|string $response
     */
    private function setResponse($response): void
    {
        $this->response = $response;
    }

    /**
     * @return int|null
     */
    public function getHttpCode(): ?int
    {
        return $this->httpCode;
    }

    /**
     * @param int|null $httpCode
     */
    private function setHttpCode(?int $httpCode): void
    {
        $this->httpCode = $httpCode;
    }

    /**
     * @return array|null
     */
    public function getCurlInfo(): ?array
    {
        return $this->curlInfo;
    }

    /**
     * @param array|null $curlInfo
     */
    private function setCurlInfo(?array $curlInfo): void
    {
        $this->curlInfo = $curlInfo;
    }

    /**
     * @return string|null
     */
    public function getCurlError(): ?string
    {
        return $this->curlError;
    }

    /**
     * @param string|null $curlError
     */
    private function setCurlError(?string $curlError): void
    {
        $this->curlError = $curlError;
    }

    /**
     * @return string|null
     */
    public function getMethod(): ?string
    {
        return $this->method;
    }

    /**
     * @param string $method
     */
    public function setMethod(string $method): void
    {
        $this->method = $method;
    }

    /**
     * @return array
     */
    public function getCustomOptions(): array
    {
        return $this->customOptions;
    }

    /**
     * @param array $customOptions
     */
    public function setCustomOptions(array $customOptions): void
    {
        $this->customOptions = $customOptions;
    }

    /**
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * @param array $headers
     */
    public function setHeaders(array $headers): void
    {
        $this->headers = $headers;
    }

    /**
     * @param string $header
     */
    public function addHeaders(string $header): void
    {
        $this->headers[] = $header;
    }

    /**
     * @return bool|null
     */
    public function isSslVerifyHost(): ?bool
    {
        return $this->ssl_verify_host;
    }

    /**
     * @param bool|null $ssl_verify_host
     */
    public function setSslVerifyHost(?bool $ssl_verify_host): void
    {
        $this->ssl_verify_host = $ssl_verify_host;
    }

    /**
     * @return bool|null
     */
    public function isSslVerifyPeer(): ?bool
    {
        return $this->ssl_verify_peer;
    }

    /**
     * @param bool|null $ssl_verify_peer
     */
    public function setSslVerifyPeer(?bool $ssl_verify_peer): void
    {
        $this->ssl_verify_peer = $ssl_verify_peer;
    }

    /**
     * @return void
     */
    public function debug(): void
    {
        bdump($this);
    }

    /**
     * @param string $headerString
     * @return array
     */
    public static function parseHttpResponseHeaders(string $headerString): array
    {
        $headers = [];
        $lines = explode("\n", trim($headerString));

        foreach ($lines as $line) {
            $line = trim($line);

            // První řádek je stavová řádka (např. "HTTP/1.1 200 OK"), tak ji uložíme samostatně
            if (strpos($line, 'HTTP/') === 0) {
                $headers['Status'] = $line;
            } else {
                // Rozdělíme řádku na klíč a hodnotu
                $parts = explode(':', $line, 2);
                if (count($parts) == 2) {
                    $key = trim($parts[0]);
                    $value = trim($parts[1]);

                    // Pokud klíč už existuje (např. Set-Cookie), přidáme hodnotu do pole
                    if (isset($headers[$key])) {
                        if (!is_array($headers[$key])) {
                            $headers[$key] = [$headers[$key]];
                        }
                        $headers[$key][] = $value;
                    } else {
                        $headers[$key] = $value;
                    }
                }
            }
        }

        return $headers;
    }

}

