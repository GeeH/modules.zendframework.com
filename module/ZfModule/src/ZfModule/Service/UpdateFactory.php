<?php

namespace ZfModule\Service;

use Github\Client;
use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use ZfModule\Mapper\Module;

class UpdateFactory implements FactoryInterface
{
    /**
     * {@inheritDoc}
     *
     * @return Update
     */
    public function createService(ServiceLocatorInterface $serviceLocator)
    {
        $mapper = $serviceLocator->get(Module::class);
        $client = new Client();
        $config = $serviceLocator->get('config')['scn-social-auth'];

        return new Update($mapper, $client, $config);
    }
}
