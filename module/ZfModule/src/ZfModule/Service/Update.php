<?php
/**
 * Created by Gary Hockin.
 * Date: 25/04/15
 * @GeeH
 */

namespace ZfModule\Service;


use Github\Client;
use Zend\Console\Adapter\AdapterInterface;
use ZF\Console\Route;
use ZfModule\Entity\Module as ModuleEntity;
use ZfModule\Mapper\Module;

class Update
{
    const GITHUB_URL = 'https://api.github.com';
    /**
     * @var Module
     */
    protected $moduleMapper;
    /**
     * @var Client
     */
    private $client;
    /**
     * @var array
     */
    private $config;
    /**
     * @var AdapterInterface
     */
    private $console;


    /**
     * @param Module $moduleMapper
     * @param Client $client
     * @param array $config
     */
    function __construct(Module $moduleMapper, Client $client, array $config)
    {
        $this->moduleMapper = $moduleMapper;
        $this->client       = $client;
        $this->config       = $config;
    }

    /**
     * @param Route $route
     * @param AdapterInterface $console
     * @return int
     */
    function __invoke(Route $route, AdapterInterface $console)
    {
        $this->console = $console;

        $this->setupClient();


        $modules = $this->moduleMapper->findAll();

        foreach ($modules as $module) {
            $this->updateModule($module);
        }

        return 0;
    }

    public function updateModule(ModuleEntity $module)
    {
        $this->console->write($module->getName() . ': ');

        $score = 0;

        $repo = $this->getFromGithub($module);

        if (!$repo) {
            $this->console->writeLine('E');
            return $score;
        }

        // Add fork count
        if (isset($repo['forks_count'])) {
            $score += (int) $repo['forks_count'];
        }

        // add star count
        if (isset($repo['stargazers_count'])) {
            $score += (int) $repo['stargazers_count'];
        }

        // add watcher count
        if (isset($repo['watchers_count'])) {
            $score += (int) $repo['watchers_count'];
        }

        $this->console->writeLine($score);

        $module->setScore($score);
        $module->setUpdatedAt(date('Y-m-d H:i:s'));

        $this->moduleMapper->update($module);
    }

    /**
     * @param ModuleEntity $module
     * @return array|bool
     */
    public function getFromGithub(ModuleEntity $module)
    {
        try {
            $data = $this->client->repo()->show($module->getOwner(), $module->getName());
        } catch (\Exception $e) {
            return false;
        }

        if (!is_array($data)) {
            return false;
        }

        return $data;
    }


    private function setupClient()
    {
        $this->client->authenticate(
            $this->config['github_client_id'],
            $this->config['github_secret'],
            Client::AUTH_URL_CLIENT_ID
        );
    }

}