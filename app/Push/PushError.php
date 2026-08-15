<?php

declare(strict_types=1);

namespace App\Push;

use RuntimeException;

/**
 * A push that could not be built or sent for a reason the operator has to
 * fix: a missing key pair, a malformed one, an endpoint that is not a URL.
 * Never a subscription that has simply expired: that is an everyday
 * event and WebPush clears it away rather than raising it.
 */
class PushError extends RuntimeException {}
