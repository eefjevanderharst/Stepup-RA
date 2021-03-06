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

namespace Surfnet\StepupRa\RaBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('surfnet_stepup_ra_ra');

        $this->createRaConfiguration($rootNode);

        return $treeBuilder;
    }

    private function createRaConfiguration(ArrayNodeDefinition $root)
    {
        $root
            ->children()
                ->scalarNode('required_loa')
                    ->info('The required LOA to be able to log in, should match the loa defined at the gateway')
                    ->isRequired()
                    ->validate()
                    ->ifTrue(function ($value) {
                        return !is_string($value);
                    })
                        ->thenInvalid('the required loa must be a string')
                    ->end()
                ->end()
            ->end();
    }
}
