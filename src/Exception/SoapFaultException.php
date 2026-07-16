<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Exception;

/**
 * Drebedengi returned a valid SOAP fault rather than an infrastructure failure.
 */
final class SoapFaultException extends TransportException
{
}
