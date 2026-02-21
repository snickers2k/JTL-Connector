<?php
declare(strict_types=1);

namespace CsCartJtlConnector\Auth;

use Jtl\Connector\Core\Authentication\TokenValidatorInterface;

final class VendorTokenValidator implements TokenValidatorInterface
{
    private string $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    public function validate(string $token): bool
    {
        return hash_equals($this->token, $token);
    }
}
