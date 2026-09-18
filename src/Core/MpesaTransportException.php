<?php

namespace Rider\Core;

use RuntimeException;

/**
 * Thrown when an M-Pesa HTTP call failed at the transport level (DNS,
 * timeout, connection reset) or came back as something that isn't a Daraja
 * answer (empty body, gateway error page) — i.e. we can't know whether Daraja
 * actually received and acted on the request. Distinct from a plain RuntimeException
 * (Daraja answered and rejected it), because for money-moving calls like B2C
 * the two cases need opposite handling: a rejection is safe to retry, a
 * transport failure is not (see Escrow::disburse).
 */
final class MpesaTransportException extends RuntimeException
{
}
