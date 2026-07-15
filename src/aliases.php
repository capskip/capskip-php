<?php

declare(strict_types=1);

// SolverExceptions is kept as an alias of CapSkipError for parity with the other
// CapSkip SDKs (`$solver->exceptions`). Because it is a true alias — not a
// subclass — catching either name catches every CapSkip SDK error.
if (!class_exists(\CapSkip\Exceptions\SolverExceptions::class, false)) {
    class_alias(\CapSkip\Exceptions\CapSkipError::class, \CapSkip\Exceptions\SolverExceptions::class);
}
