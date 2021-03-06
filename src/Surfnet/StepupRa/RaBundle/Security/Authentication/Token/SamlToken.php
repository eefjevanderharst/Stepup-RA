<?php

/**
 * Copyright 2014 SURFnet bv
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Surfnet\StepupRa\RaBundle\Security\Authentication\Token;

use Surfnet\StepupBundle\Value\Loa;
use Symfony\Component\Security\Core\Authentication\Token\AbstractToken;

class SamlToken extends AbstractToken
{
    /**
     * @var \SAML2_Assertion
     */
    public $assertion;

    /**
     * @var \Surfnet\StepupBundle\Value\Loa
     */
    private $loa;

    public function __construct(Loa $loa, array $roles = array())
    {
        parent::__construct($roles);

        $this->loa = $loa;
        $this->setAuthenticated(count($roles));
    }

    /**
     * Returns the user credentials.
     *
     * @return mixed The user credentials
     */
    public function getCredentials()
    {
        return '';
    }

    /**
     * @return Loa
     */
    public function getLoa()
    {
        return $this->loa;
    }

    public function serialize()
    {
        return serialize([parent::serialize(), $this->loa,]);
    }

    public function unserialize($serialized)
    {
        list($parent, $this->loa) = unserialize($serialized);

        parent::unserialize($parent);
    }
}
