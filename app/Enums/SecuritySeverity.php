<?php
namespace App\Enums;
/** Defines the SecuritySeverity enum and its project responsibilities. */
enum SecuritySeverity:string { case Low='low'; case Medium='medium'; case High='high'; case Critical='critical'; }
