<?php

declare(strict_types=1);

namespace CapSkip;

/**
 * Alias of {@see CapSkip} kept for parity with the other CapSkip SDKs.
 *
 * PHP executes synchronously, so every solve method already blocks until the
 * captcha is solved. `AsyncCapSkip` exists so code ported from the async CapSkip
 * clients keeps working unchanged — there is no separate asynchronous client.
 */
class AsyncCapSkip extends CapSkip
{
}
