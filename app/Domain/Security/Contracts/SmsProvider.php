<?php
namespace App\Domain\Security\Contracts;
/** Defines the SmsProvider interface and its project responsibilities. */
interface SmsProvider { public function name(): string; public function send(string $phone, string $message): void; }
