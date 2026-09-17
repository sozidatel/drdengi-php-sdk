<?php

declare(strict_types=1);

// A loopback-only HTTP fixture. No external endpoint or real credentials are used.
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

/** @var list<string> $requests */
$requests = [];
while (($connection = @stream_socket_accept($listener, -1)) !== false) {
    stream_set_timeout($connection, 5);
    $requestLine = fgets($connection);
    if ($requestLine === false) {
        fclose($connection);
        continue;
    }
    $path = explode(' ', $requestLine)[1] ?? '/';
    $contentLength = 0;
    while (($header = fgets($connection)) !== false && trim($header) !== '') {
        if (preg_match('/^Content-Length:\s*(\d+)/i', $header, $matches) === 1) {
            $contentLength = (int)$matches[1];
        }
    }
    $requestBody = '';
    while (($remaining = $contentLength - strlen($requestBody)) > 0) {
        $chunk = fread($connection, $remaining);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $requestBody .= $chunk;
    }

    $status = '200 OK';
    if ($path === '/requests') {
        $body = json_encode($requests, JSON_THROW_ON_ERROR);
    } elseif (str_ends_with($path, '/soap/dd.wsdl')) {
        $body = file_get_contents(__DIR__ . '/transport.wsdl');
        if ($body === false) {
            exit(1);
        }
    } else {
        $requests[] = $path;
        $scenario = explode('/', $path)[1] ?? '';
        $method = str_contains($requestBody, ':setRecordList') ? 'setRecordList' : 'getAccessStatus';
        if (str_starts_with($scenario, 'mutation-')) {
            $scenario = $method === 'setRecordList' ? substr($scenario, strlen('mutation-')) : 'success';
        }
        $success = '<' . $method . 'Response><return xsi:type="xsd:string">1</return></' . $method . 'Response>';
        $body = match ($scenario) {
            'non-xml' => '<html><body>Proxy error</body></html>',
            'truncated' => '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>',
            'missing-envelope' => '<s:Other xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"/>',
            'missing-body' => '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"></s:Envelope>',
            'fault' => '<s:Fault><faultcode>s:Client</faultcode><faultstring>Record is invalid</faultstring></s:Fault>',
            'fault-server' => '<s:Fault><faultcode>s:Server</faultcode><faultstring>Business rule rejected</faultstring></s:Fault>',
            'fault-parser-message' => '<s:Fault><faultcode>s:Client</faultcode><faultstring>looks like we got no XML document</faultstring></s:Fault>',
            default => $success,
        };
        if (!in_array($scenario, ['non-xml', 'truncated', 'missing-envelope', 'missing-body'], true)) {
            $body = '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" '
                . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" '
                . 'xmlns:xsd="http://www.w3.org/2001/XMLSchema"><s:Body>' . $body . '</s:Body></s:Envelope>';
        }
        if (str_starts_with($scenario, 'fault')) {
            $status = '500 Internal Server Error';
        }
    }

    $response = "HTTP/1.1 {$status}\r\nContent-Type: text/xml; charset=utf-8\r\n"
        . 'Content-Length: ' . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
    @fwrite($connection, $response);
    fclose($connection);
}
fclose($listener);
