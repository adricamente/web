<?php
// Sonda temporal. Mira si este alojamiento puede sostener el portal
// del paciente sin alquilar un servidor. Se borra en cuanto conteste.
header('Content-Type: application/json');
header('X-Robots-Tag: noindex, nofollow');
$dir = __DIR__ . '/w';
$escribible = @mkdir($dir) || is_dir($dir);
if ($escribible) { $escribible = @file_put_contents($dir . '/t', 'x') !== false; @unlink($dir . '/t'); @rmdir($dir); }
echo json_encode([
  'php'        => PHP_VERSION,
  'sodium'     => extension_loaded('sodium'),
  'argon2id'   => defined('PASSWORD_ARGON2ID'),
  'pdo_sqlite' => extension_loaded('pdo_sqlite'),
  'mysqli'     => extension_loaded('mysqli'),
  'json'       => extension_loaded('json'),
  'escribible' => $escribible,
  'https'      => !empty($_SERVER['HTTPS']),
  'servidor'   => $_SERVER['SERVER_SOFTWARE'] ?? '?',
], JSON_PRETTY_PRINT);
