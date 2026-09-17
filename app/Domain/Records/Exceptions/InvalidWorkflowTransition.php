<?php

declare(strict_types=1);

namespace App\Domain\Records\Exceptions;

use DomainException;

final class InvalidWorkflowTransition extends DomainException {}
