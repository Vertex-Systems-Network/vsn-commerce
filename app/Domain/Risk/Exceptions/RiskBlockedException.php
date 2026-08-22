<?php
namespace App\Domain\Risk\Exceptions;
/** Defines the RiskBlockedException class and its project responsibilities. */
class RiskBlockedException extends \RuntimeException { public function __construct(string $message, public readonly string $scope='account'){parent::__construct($message);} }
