<?php

namespace axenox\BDT\Behat\Events;

use Behat\Testwork\Event\Event;

class BeforeUserLoggedIn extends Event
{
    private string $username;
    private array $roleAliases = [];
    
    public function __construct(string $username, array $roles)
    {
        $this->username = $username;
        $this->roleAliases = $roles;
    }
    
    public function getUsername() : string
    {
        return $this->username;
    }

    /**
     * @return string[]
     */
    public function getRoleAliases() : array
    {
        return $this->roleAliases;
    }
}