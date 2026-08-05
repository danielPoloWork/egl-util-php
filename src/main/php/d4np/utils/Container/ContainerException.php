<?php

declare(strict_types=1);

namespace D4np\Utils\Container;

use D4np\Utils\Support\UtilsException;
use Psr\Container\ContainerExceptionInterface;

/**
 * A container failed to resolve something it was asked for (spec FR-04, ADR-0028).
 *
 * **Why this lives in `Container/` and not in `Support/` with the rest of the hierarchy.** Every
 * other library exception sits in `Support`, and this one cannot: PSR-11 requires it to implement
 * `ContainerExceptionInterface`, and `Support` is declared in `deptrac.yaml` as depending on
 * *nothing*. That empty list is load-bearing — it is what makes an inverted import a build failure
 * rather than a review opinion — so the exception moves to the layer that is already allowed to see
 * PSR-11, instead of the rule bending to accommodate it.
 *
 * It still extends {@see UtilsException}, so ADR-0004's contract holds unchanged: a consumer
 * catching `UtilsThrowable` catches this too, and one catching `ContainerExceptionInterface` gets
 * the PSR behaviour. Both audiences are served without either being told about the other.
 */
class ContainerException extends UtilsException implements ContainerExceptionInterface
{
}
