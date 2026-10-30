<?php

declare(strict_types=1);

use App\Entity\ThirdParty;
use App\Entity\User;

require dirname(__DIR__).'/vendor/autoload.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$thirdParty = new ThirdParty('  Société Exemple  ', ThirdParty::CATEGORY_CUSTOMER);
expect($thirdParty->getName() === 'Société Exemple', 'The name must be trimmed.');
expect($thirdParty->getCategory() === ThirdParty::CATEGORY_CUSTOMER, 'The customer category must be preserved.');
expect($thirdParty->getCreatedAt() instanceof DateTimeImmutable, 'Creation date must be immutable.');

$thirdParty->setEmail('  contact@example.test  ');
$thirdParty->setPhone('  0102030405  ');
expect($thirdParty->getEmail() === 'contact@example.test', 'The email must be trimmed.');
expect($thirdParty->getPhone() === '0102030405', 'The phone must be trimmed.');

$thirdParty->setEmail(' ');
$thirdParty->setPhone('');
expect($thirdParty->getEmail() === null, 'An empty email must be normalized to null.');
expect($thirdParty->getPhone() === null, 'An empty phone must be normalized to null.');

foreach (['', '   '] as $invalidName) {
    try {
        $thirdParty->setName($invalidName);
        throw new RuntimeException('An empty name was accepted.');
    } catch (InvalidArgumentException) {
        // Expected validation failure.
    }
}

try {
    $thirdParty->setCategory('unknown');
    throw new RuntimeException('An unsupported category was accepted.');
} catch (InvalidArgumentException) {
    // Expected validation failure.
}


foreach ([
    fn () => $thirdParty->setName(str_repeat('A', 181)),
    fn () => $thirdParty->setEmail('invalid-email'),
    fn () => $thirdParty->setEmail(str_repeat('a', 170).'@example.test'),
    fn () => $thirdParty->setPhone(str_repeat('1', 41)),
] as $invalidSetter) {
    try {
        $invalidSetter();
        throw new RuntimeException('An invalid third-party field value was accepted.');
    } catch (InvalidArgumentException) {
        // Expected validation failure.
    }
}

$user = new User('  ADMIN@EXAMPLE.TEST ');
expect($user->getEmail() === 'admin@example.test', 'User email must be normalized.');
expect(in_array('ROLE_USER', $user->getRoles(), true), 'Every user must receive ROLE_USER.');
$user->setRoles(['ROLE_ADMIN']);
expect(in_array('ROLE_ADMIN', $user->getRoles(), true), 'Administrator role must be retained.');
expect(in_array('ROLE_USER', $user->getRoles(), true), 'Administrator must inherit ROLE_USER.');

try {
    new User('not-an-email');
    throw new RuntimeException('An invalid email address was accepted.');
} catch (InvalidArgumentException) {
    // Expected validation failure.
}

try {
    $user->setRoles(['ADMIN']);
    throw new RuntimeException('An invalid role was accepted.');
} catch (InvalidArgumentException) {
    // Expected validation failure.
}

echo "Third-party and user entity smoke tests passed.\n";
