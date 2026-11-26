<?php

namespace CipiGui\Infrastructure;

use RuntimeException;

/** A call to a provider's API that did not go through, with a message fit for the page; its code is the HTTP status when there was one. */
final class ConnectorException extends RuntimeException {}
