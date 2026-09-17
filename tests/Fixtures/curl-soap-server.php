<?php

declare(strict_types=1);

// Local HTTP compatibility fixture. Every credential and cookie is synthetic.
$listener = stream_socket_server('tcp://127.0.0.1:0');
if ($listener === false) {
    exit(1);
}
$address = stream_socket_get_name($listener, false);
if ($address === false) {
    exit(1);
}
fwrite(STDOUT, $address . "\n");
fflush(STDOUT);

$requests = [];
while (($connection = @stream_socket_accept($listener, -1)) !== false) {
    stream_set_timeout($connection, 5);
    $requestLine = fgets($connection);
    if ($requestLine === false) {
        fclose($connection);
        continue;
    }
    $target = explode(' ', $requestLine)[1] ?? '/';
    $path = parse_url($target, PHP_URL_PATH) ?: '/';
    $headers = [];
    while (($line = fgets($connection)) !== false && trim($line) !== '') {
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }
    if (strtolower($headers['expect'] ?? '') === '100-continue') {
        fwrite($connection, "HTTP/1.1 100 Continue\r\n\r\n");
    }
    $length = (int)($headers['content-length'] ?? 0);
    $requestBody = '';
    while (($remaining = $length - strlen($requestBody)) > 0) {
        $chunk = fread($connection, $remaining);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $requestBody .= $chunk;
    }
    $decodedBody = match ($headers['content-encoding'] ?? '') {
        'gzip' => gzdecode($requestBody),
        'deflate' => gzuncompress($requestBody),
        default => $requestBody,
    };
    if ($decodedBody === false) {
        $decodedBody = '';
    }

    $status = '200 OK';
    $responseHeaders = ['Content-Type: text/xml; charset=utf-8'];
    $scenario = explode('/', $path)[1] ?? '';
    if ($path === '/requests') {
        $body = json_encode($requests, JSON_THROW_ON_ERROR);
    } elseif (str_ends_with($path, '/soap/dd.wsdl')) {
        $requests[] = ['target' => $target, 'path' => $path, 'headers' => $headers, 'body' => $decodedBody];
        $body = file_get_contents(__DIR__ . '/transport.wsdl');
        if ($body === false) {
            exit(1);
        }
    } else {
        $requests[] = ['target' => $target, 'path' => $path, 'headers' => $headers, 'body' => $decodedBody];
        $isSoap12 = str_contains($decodedBody, 'http://www.w3.org/2003/05/soap-envelope');
        $namespace = $isSoap12 ? 'http://www.w3.org/2003/05/soap-envelope' : 'http://schemas.xmlsoap.org/soap/envelope/';
        $payload = '<getAccessStatusResponse><return xsi:type="xsd:string">1</return></getAccessStatusResponse>';
        if ($scenario === 'fault') {
            $status = '500 Internal Server Error';
            $payload = '<s:Fault><faultcode>s:Server</faultcode><faultstring>Business rule rejected</faultstring></s:Fault>';
        }
        $body = '<s:Envelope xmlns:s="' . $namespace . '" '
            . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" '
            . 'xmlns:xsd="http://www.w3.org/2001/XMLSchema"><s:Body>' . $payload . '</s:Body></s:Envelope>';
        if ($isSoap12) {
            $responseHeaders = ['Content-Type: application/soap+xml; charset=utf-8'];
        }
        if ($scenario === 'cookies') {
            $responseHeaders[] = 'Set-Cookie: session=fixture-session; Path=/; HttpOnly';
        }
        if ($scenario === 'redirect') {
            $status = '307 Temporary Redirect';
            $responseHeaders[] = 'Location: http://' . $address . '/redirect-target/soap/';
            $body = '';
        }
        if ($scenario === 'gzip') {
            $body = gzencode($body);
            $responseHeaders[] = 'Content-Encoding: gzip';
        } elseif ($scenario === 'deflate') {
            $body = gzcompress($body);
            $responseHeaders[] = 'Content-Encoding: deflate';
        }
        if ($body === false) {
            exit(1);
        }
    }

    if ($scenario === 'chunked' && !str_ends_with($path, '.wsdl')) {
        $responseHeaders[] = 'Transfer-Encoding: chunked';
        $split = intdiv(strlen($body), 2);
        $first = substr($body, 0, $split);
        $second = substr($body, $split);
        $body = dechex(strlen($first)) . "\r\n" . $first . "\r\n"
            . dechex(strlen($second)) . "\r\n" . $second . "\r\n0\r\n\r\n";
    } else {
        $responseHeaders[] = 'Content-Length: ' . strlen($body);
    }
    $responseHeaders[] = 'Connection: close';
    @fwrite($connection, "HTTP/1.1 {$status}\r\n" . implode("\r\n", $responseHeaders) . "\r\n\r\n" . $body);
    fclose($connection);
}
fclose($listener);
