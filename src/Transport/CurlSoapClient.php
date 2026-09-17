<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Transport;

use CurlHandle;
use SoapClient;
use SoapFault;
use Soz\Drebedengi\Exception\InvalidArgumentException;

/** @internal SOAP encoding stays native; cURL supplies a per-request deadline. */
final class CurlSoapClient extends SoapClient
{
    private CurlHandle $http;
    private bool $tracing;
    private int $compression;
    private ?string $requestHeaders = null;
    private ?string $responseHeaders = null;
    private ?string $requestBody = null;
    private ?string $responseBody = null;
    /** @var array<string, mixed> */
    private array $httpContext;
    /** @var list<string> */
    private array $extraHeaders;

    /** @param array<string, mixed> $options */
    public function __construct(string $wsdl, private readonly array $options, float $timeout)
    {
        if (!is_finite($timeout) || $timeout <= 0 || ceil($timeout * 1000) >= PHP_INT_MAX) {
            throw new InvalidArgumentException('SOAP request timeout must be a positive finite number of seconds representable in milliseconds.');
        }
        $context = $options['stream_context'] ?? null;
        if ($context !== null && (!is_resource($context) || get_resource_type($context) !== 'stream-context')) {
            throw new InvalidArgumentException('SOAP stream_context must be a stream context resource.');
        }
        $contextOptions = [];
        foreach ($context === null ? [] : stream_context_get_options($context) as $wrapper => $values) {
            if (!is_string($wrapper) || !is_array($values)) {
                throw new InvalidArgumentException('Invalid SOAP stream context options.');
            }
            foreach ($values as $name => $value) {
                if (!is_string($name)) {
                    throw new InvalidArgumentException('Invalid SOAP stream context option name.');
                }
                $contextOptions[$wrapper][$name] = $value;
            }
        }
        $supported = [
            'http' => ['timeout', 'header', 'user_agent', 'content_type', 'protocol_version', 'max_redirects'],
            'ssl' => ['verify_peer', 'verify_peer_name', 'allow_self_signed', 'cafile', 'capath', 'local_cert', 'local_pk', 'passphrase'],
        ];
        foreach ($contextOptions as $wrapper => $values) {
            foreach ($values as $name => $value) {
                if (!in_array($name, $supported[$wrapper] ?? [], true)) {
                    self::unsupported('stream_context option "' . $wrapper . '.' . $name . '"');
                }
            }
        }
        if ($context !== null) {
            foreach (stream_context_get_params($context) as $name => $value) {
                if ($name === 'notification') {
                    self::unsupported('stream_context notification callback');
                }
            }
        }
        if (array_key_exists('ssl_method', $options)) {
            self::unsupported('option "ssl_method"');
        }
        $this->httpContext = $contextOptions['http'] ?? [];
        if (isset($this->httpContext['max_redirects']) && $this->httpContext['max_redirects'] !== 0) {
            self::unsupported('stream_context option "http.max_redirects" other than 0');
        }
        $protocol = $this->httpContext['protocol_version'] ?? 1.1;
        if (!in_array($protocol, [1, 1.0, 1.1], true)) {
            self::unsupported('stream_context option "http.protocol_version" other than 1.0 or 1.1');
        }
        $this->extraHeaders = self::headers($this->httpContext['header'] ?? []);
        $this->tracing = (bool) ($options['trace'] ?? false);
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_FOLLOWLOCATION => false,
            // Avoid libcurl's transparent retry of a stale pooled connection:
            // after sending a mutation, a replay could duplicate records.
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT_MS => (int) ceil($timeout * 1000),
            CURLOPT_HTTP_VERSION => $protocol == 1.0 ? CURL_HTTP_VERSION_1_0 : CURL_HTTP_VERSION_1_1,
            // Do not inherit process-level proxy environment variables.
            CURLOPT_PROXY => '',
            CURLOPT_COOKIEFILE => '',
            CURLOPT_ENCODING => 'gzip, deflate',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLINFO_HEADER_OUT => $this->tracing,
        ];
        $connectTimeout = $options['connection_timeout'] ?? 0;
        if (!is_int($connectTimeout) || $connectTimeout < 0) {
            throw new InvalidArgumentException('SOAP connection_timeout must be a non-negative integer.');
        }
        $connectTimeout = $connectTimeout > 0 ? $connectTimeout : (int) ini_get('default_socket_timeout');
        if ($connectTimeout > 0) {
            $curlOptions[CURLOPT_CONNECTTIMEOUT] = $connectTimeout;
        } else {
            // Native PHP allows an unlimited socket timeout (-1). cURL does
            // not; bound connecting by the request deadline in that case.
            $curlOptions[CURLOPT_CONNECTTIMEOUT_MS] = (int) ceil($timeout * 1000);
        }
        $userAgent = $options['user_agent'] ?? $this->httpContext['user_agent'] ?? 'PHP-SOAP/' . PHP_VERSION;
        $curlOptions[CURLOPT_USERAGENT] = self::headerValue($userAgent, 'user_agent');
        if (isset($options['login'])) {
            $curlOptions[CURLOPT_USERNAME] = self::stringOption($options['login'], 'login');
            $curlOptions[CURLOPT_PASSWORD] = self::stringOption($options['password'] ?? '', 'password');
            $authentication = $options['authentication'] ?? SOAP_AUTHENTICATION_BASIC;
            if (!in_array($authentication, [SOAP_AUTHENTICATION_BASIC, SOAP_AUTHENTICATION_DIGEST], true)) {
                self::unsupported('authentication method');
            }
            $curlOptions[CURLOPT_HTTPAUTH] = $authentication === SOAP_AUTHENTICATION_DIGEST ? CURLAUTH_DIGEST : CURLAUTH_BASIC;
        }
        if (isset($options['proxy_host'])) {
            $proxy = self::stringOption($options['proxy_host'], 'proxy_host');
            if (strpbrk($proxy, "/\r\n\t") !== false || str_contains($proxy, '@')) {
                throw new InvalidArgumentException('SOAP proxy_host must contain only a host name or IP address.');
            }
            $curlOptions[CURLOPT_PROXY] = $proxy;
            $port = $options['proxy_port'] ?? null;
            if (!is_int($port) || $port < 1 || $port > 65535) {
                throw new InvalidArgumentException('SOAP proxy_port must be between 1 and 65535.');
            }
            $curlOptions[CURLOPT_PROXYPORT] = $port;
            // Explicit proxy settings must also work for local endpoints.
            $curlOptions[CURLOPT_NOPROXY] = '';
            if (isset($options['proxy_login'])) {
                $curlOptions[CURLOPT_PROXYUSERNAME] = self::stringOption($options['proxy_login'], 'proxy_login');
                $curlOptions[CURLOPT_PROXYPASSWORD] = self::stringOption($options['proxy_password'] ?? '', 'proxy_password');
                $curlOptions[CURLOPT_PROXYAUTH] = CURLAUTH_BASIC;
            }
        }
        $ssl = $contextOptions['ssl'] ?? [];
        if (array_key_exists('allow_self_signed', $ssl) && $ssl['allow_self_signed'] !== false) {
            self::unsupported('stream_context option "ssl.allow_self_signed" other than false');
        }
        foreach (['verify_peer' => CURLOPT_SSL_VERIFYPEER, 'verify_peer_name' => CURLOPT_SSL_VERIFYHOST] as $name => $option) {
            if (isset($ssl[$name])) {
                if (!is_bool($ssl[$name])) {
                    throw new InvalidArgumentException('SOAP ssl.' . $name . ' must be a boolean.');
                }
                $curlOptions[$option] = $name === 'verify_peer_name' ? ($ssl[$name] ? 2 : 0) : $ssl[$name];
            }
        }
        foreach (['cafile' => CURLOPT_CAINFO, 'capath' => CURLOPT_CAPATH, 'local_cert' => CURLOPT_SSLCERT,
            'local_pk' => CURLOPT_SSLKEY, 'passphrase' => CURLOPT_KEYPASSWD] as $name => $option) {
            $value = $options[$name] ?? $ssl[$name] ?? null;
            if ($value !== null) {
                $curlOptions[$option] = self::stringOption($value, 'ssl.' . $name);
            }
        }
        if (isset($this->httpContext['content_type'])) {
            self::headerValue($this->httpContext['content_type'], 'http.content_type');
        }
        $compression = $options['compression'] ?? 0;
        if (!is_int($compression) || $compression < 0 || ($compression & ~0x3f) !== 0) {
            throw new InvalidArgumentException('SOAP compression must be a supported compression bitmask.');
        }
        if (($compression & 0xf) > 0 && (!function_exists('gzencode') || !function_exists('gzcompress'))) {
            throw new InvalidArgumentException('SOAP request compression requires the zlib extension.');
        }
        $this->compression = $compression;
        $http = curl_init();
        if ($http === false || !curl_setopt_array($http, $curlOptions)) {
            throw new InvalidArgumentException('Cannot initialize the SOAP HTTP transport.');
        }
        $this->http = $http;
        // Validation happens before the native constructor downloads any WSDL.
        parent::__construct($wsdl, $options);
    }

    public function __doRequest(string $request, string $location, string $action, int $version, bool $oneWay = false, ?string $uriParserClass = null): ?string
    {
        $this->requestHeaders = $this->responseHeaders = $this->responseBody = null;
        $this->requestBody = $this->tracing ? $request : null;
        self::headerValue($action, 'SOAPAction');
        $contentType = $this->httpContext['content_type'] ?? ($version === SOAP_1_2 ? 'application/soap+xml; charset=utf-8' : 'text/xml; charset=utf-8');
        $contentType = self::headerValue($contentType, 'http.content_type');
        $quotedAction = addcslashes($action, "\\\"");
        $headers = ['Content-Type: ' . $contentType . ($version === SOAP_1_2 ? '; action="' . $quotedAction . '"' : '')];
        if ($version !== SOAP_1_2) {
            $headers[] = 'SOAPAction: "' . $quotedAction . '"';
        }
        $headers[] = 'Connection: ' . (($this->options['keep_alive'] ?? true) ? 'Keep-Alive' : 'close');
        $headers[] = 'Expect:';
        $compression = $this->compression;
        // Setting ENCODING enables transparent decoding; this suppresses its
        // advertised header unless SOAP_COMPRESSION_ACCEPT was requested.
        if (($compression & SOAP_COMPRESSION_ACCEPT) === 0) {
            $headers[] = 'Accept-Encoding:';
        }
        $level = min(9, $compression & 0xf);
        if ($level > 0) {
            $deflate = ($compression & SOAP_COMPRESSION_DEFLATE) !== 0;
            $compressed = $deflate ? gzcompress($request, $level) : gzencode($request, $level);
            if ($compressed === false) {
                throw new SoapFault('HTTP', 'Cannot compress the SOAP request.');
            }
            $request = $compressed;
            $headers[] = 'Content-Encoding: ' . ($deflate ? 'deflate' : 'gzip');
        }
        foreach ($this->extraHeaders as $header) {
            $name = strtolower(substr($header, 0, (int) strpos($header, ':')));
            if (in_array($name, ['host', 'connection', 'user-agent', 'content-length', 'content-type', 'soapaction', 'transfer-encoding'], true)
                || ($name === 'authorization' && isset($this->options['login']))
                || ($name === 'proxy-authorization' && isset($this->options['proxy_login']))) {
                continue;
            }
            $headers[] = $header;
        }
        $receivedHeaders = '';
        if (!curl_setopt_array($this->http, [
            CURLOPT_URL => $location,
            CURLOPT_POSTFIELDS => $request,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function (CurlHandle $handle, string $line) use (&$receivedHeaders): int {
                $receivedHeaders .= $line;
                return strlen($line);
            },
        ])) {
            throw new SoapFault('HTTP', 'Cannot configure the SOAP HTTP request.');
        }
        $response = curl_exec($this->http);
        if ($this->tracing) {
            $sent = curl_getinfo($this->http, CURLINFO_HEADER_OUT);
            $this->requestHeaders = is_string($sent) ? $sent : null;
            $this->responseHeaders = $receivedHeaders !== '' ? $receivedHeaders : null;
            $this->responseBody = is_string($response) ? $response : null;
        }
        if (!is_string($response)) {
            // Do not include libcurl's error text: it can contain caller-supplied
            // paths, proxy details or credentials. The numeric code is stable.
            throw new SoapFault('HTTP', sprintf('SOAP HTTP request failed (cURL error %d).', curl_errno($this->http)));
        }
        $status = curl_getinfo($this->http, CURLINFO_RESPONSE_CODE);
        if ($status >= 300 && $status < 400) {
            throw new SoapFault('HTTP', 'SOAP HTTP redirects are disabled.');
        }
        if ($status >= 400 && !preg_match('/<(?:[A-Za-z_][\w.-]*:)?Envelope(?:\s|>)/', $response)) {
            throw new SoapFault('HTTP', sprintf('SOAP HTTP request failed with status %d.', $status));
        }
        return $oneWay ? null : $response;
    }

    public function __getLastRequestHeaders(): ?string
    {
        return $this->requestHeaders;
    }

    public function __getLastResponseHeaders(): ?string
    {
        return $this->responseHeaders;
    }

    public function __getLastRequest(): ?string
    {
        return $this->requestBody;
    }

    public function __getLastResponse(): ?string
    {
        return $this->responseBody;
    }

    public function __setCookie(string $name, ?string $value = null): void
    {
        if (!preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $name)) {
            throw new InvalidArgumentException('SOAP cookie name must be an HTTP token.');
        }
        if ($value !== null && preg_match('/[\x00-\x20\x7f;,]/', $value)) {
            throw new InvalidArgumentException('SOAP cookie value contains invalid characters.');
        }
        $host = parse_url(self::stringOption($this->options['location'] ?? '', 'location'), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new InvalidArgumentException('SOAP cookies require an absolute HTTP location.');
        }
        // Delete all server cookies bearing this name before setting a manual
        // value, so a path-specific response cookie cannot shadow it.
        foreach ($this->cookieLines() as $line) {
            $fields = explode("\t", $line);
            if (count($fields) === 7 && $fields[5] === $name) {
                $fields[4] = '1';
                curl_setopt($this->http, CURLOPT_COOKIELIST, implode("\t", $fields));
            }
        }
        if ($value !== null) {
            curl_setopt($this->http, CURLOPT_COOKIELIST, $host . "\tFALSE\t/\tFALSE\t0\t" . $name . "\t" . $value);
        }
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3?: bool}> */
    public function __getCookies(): array
    {
        $cookies = [];
        foreach ($this->cookieLines() as $line) {
            $fields = explode("\t", $line);
            if (count($fields) !== 7 || ($fields[4] !== '0' && (int) $fields[4] < time())) {
                continue;
            }
            $cookie = [$fields[6], $fields[2], preg_replace('/^#HttpOnly_/', '', $fields[0]) ?? $fields[0]];
            if ($fields[3] === 'TRUE') {
                $cookie[] = true;
            }
            $cookies[$fields[5]] = $cookie;
        }
        return $cookies;
    }

    /** @return list<string> */
    private function cookieLines(): array
    {
        return curl_getinfo($this->http, CURLINFO_COOKIELIST);
    }

    private static function unsupported(string $option): never
    {
        throw new InvalidArgumentException('SOAP timeout transport does not support ' . $option . '; use readTimeout: null without stream_context.http.timeout for native SoapClient.');
    }

    private static function stringOption(mixed $value, string $name): string
    {
        if (!is_string($value) || str_contains($value, "\0")) {
            throw new InvalidArgumentException('SOAP ' . $name . ' must be a string without NUL bytes.');
        }
        return $value;
    }

    private static function headerValue(mixed $value, string $name): string
    {
        $value = self::stringOption($value, $name);
        if (preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('SOAP ' . $name . ' contains invalid HTTP header characters.');
        }
        return $value;
    }

    /** @return list<string> */
    private static function headers(mixed $headers): array
    {
        if (is_string($headers)) {
            $headers = preg_split('/\r?\n/', $headers);
        }
        if (!is_array($headers)) {
            throw new InvalidArgumentException('SOAP http.header must be a string or an array of strings.');
        }
        $result = [];
        foreach ($headers as $header) {
            if (!is_string($header)) {
                throw new InvalidArgumentException('SOAP HTTP headers must be strings.');
            }
            if ($header === '') {
                continue;
            }
            if (!preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+:[\x20-\x7e\x80-\xff]*$/D', $header)) {
                throw new InvalidArgumentException('SOAP HTTP header has an invalid name or value.');
            }
            $result[] = $header;
        }
        return $result;
    }
}
