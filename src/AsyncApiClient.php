<?php

declare(strict_types=1);

namespace CapSkip;

/**
 * Alias of {@see ApiClient} kept for parity with the other CapSkip SDKs.
 *
 * PHP executes synchronously, so this behaves identically to {@see ApiClient}.
 */
class AsyncApiClient extends ApiClient
{
}
