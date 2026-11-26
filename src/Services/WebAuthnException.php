<?php

namespace CipiGui\Services;

use RuntimeException;

/** A passkey the panel does not accept, with the reason, fit for the person who tried it. */
final class WebAuthnException extends RuntimeException {}
